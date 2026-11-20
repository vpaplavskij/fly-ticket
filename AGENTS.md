# AGENTS.md — fly-ticket

Flight-price monitor: PHP 8.5 CLI + cron app (no framework, no web server). Fetches one-way fares from three sources 4×/day (06:00, 11:00, 16:00, 20:00) into PostgreSQL and reports via a Telegram bot with inline-button UIs.

- `.env` is gitignored and never committed.

## Quick start / commands

```bash
composer install                          # vendor/ is gitignored; run after clone/dependency changes
php src/index.php migrate                 # create the DB schema (once per install; drops legacy leftovers)
php src/index.php status                  # validate .env + DB connectivity, print summary
php src/jobs.php fetch                    # run all three fetch scripts (concurrent sub-processes)
php src/jobs.php bot [bot_name]           # start long-poll loop(s); watchdog restarts missing bots
php src/jobs.php report [bot] [chat] [cmd] [arg]   # send a price report (daily 08:00 job)
php src/Api/Telegram/render-commands.php [bot_name]  # print handler output to stdout (no token/poll loop)
vendor/bin/phpunit --testsuite Unit       # 82 test methods — runs against fly_ticket_test (see Testing)
vendor/bin/phpunit --testsuite Endpoints  # hits live APIs; flaky by nature
```

- Every entry script begins with `require_once __DIR__ . '/.../bootstrap.php'` (path depends on depth). Bootstrap loads the Composer autoloader, the global `\Database`, and `.env` in one line.
- Cron is defined in `crontab.txt`: fetch 4×/day (`0 6,11,16,20 * * *`), report daily 08:00, bot watchdog every minute, log retention 30 days (date-stamped `app-`/`cron-`/`bot-` logs).

## Architecture (where things live)

```
src/
├── bootstrap.php            # autoloader + \Database + .env — require this, not the pieces
├── index.php                # front door: migrate / status / fetch / report / bot / help
├── jobs.php                 # cron entrypoint; all orchestration lives here
├── Database.php             # global \Database class — NO namespace (see conventions)
├── Config/                  # Route, Bot, BotRegistry, EnvLoader, Database config
├── Api/<Source>/            # per-source Client.php + <source>-api.php fetch script
│   ├── Ryanair/             # ryanair-api.php + RyanairClient (MCP endpoint)
│   ├── Airbaltic/           # airbaltic-api.php (Malta), airbaltic-cyprus-api.php (Cyprus) + AirbalticClient
│   └── Telegram/            # bot loop, handlers (global fns), TelegramClient, Translator
├── Database/                # Schema::ensureTables(), FlightRepository, UserRepository, TranslationRepository
│   └── seed/                # {en,ru,lv}.php — translation seed files (fill missing keys on boot)
└── Support/Logger.php       # file logger → storage/logs/app-YYYY-MM-DD.log (daily rotation)
```

- **Fetch pattern (all three scripts):** connect → build row arrays → `FlightRepository::upsertFlights()` + `recordHistoryBatch()`. Never write one row at a time. The schema is created once by `php src/index.php migrate` — fetch scripts and bots run no DDL. After each route's upsert, `FlightRepository::deleteUnseen()` prunes rows the run did not see (scoped by route + NULL-safe `source` + fetch window); an empty result deletes the whole scoped window (seasonal routes legitimately empty). Rows whose price came from the month's-lowest fallback carry `estimated` (new `flights.estimated` column), rendered as `≈€` via `fmtPrice()`.
  - `ryanair-api.php` — RIX ⇄ MLA + RIX ⇄ PFO (both directions), via the Ryanair MCP endpoint.
  - `airbaltic-api.php` — RIX ⇄ MLA (both directions), via the AirBaltic public API.
  - `airbaltic-cyprus-api.php` — RIX ⇄ LCA (both directions, seasonal), via the AirBaltic public API.
- **Bots** are discovered from `.env`: every `TELEGRAM_<NAME>_TOKEN` registers a bot named after lowercased `<NAME>`. Per-bot overrides: `_ROUTE`, `_EXTRA_ROUTES`, `_CHAT_ID`. Adding a bot = add env lines, no code change. Currently one bot is configured (`flycheap`).
- **Localization:** all user-facing strings go through `Translator::t($key, $placeholders)`, backed by the `translations` DB table (the single source of truth at runtime — no file fallback). Missing keys are seeded on boot from `src/Database/seed/{en,ru,lv}.php`; existing rows are never overwritten, so DB edits persist. **Add new keys to all three seed files.**
- **Route/country naming is dynamic.** Human names for every IATA code live in two maps in `Config\Route`: `AIRPORTS` (city names) and `COUNTRIES` (country names). The UI resolves codes through them — `Route::airportName()`, `Route::countryName()`, `Route::countryLabel()`/`countryPairLabel()` (menu + view titles), plus the handler wrappers `routeLabel()`/`activeRouteLabel()`. **Adding a new route's airports = add them to both `AIRPORTS` and `COUNTRIES`** — no label code changes. Unknown codes fall back to the bare code, so a new route never breaks rendering. View titles use `routeLabel()` (process state, per active leg), NOT `activeRoute()`/`$argv[1]` — that's what lets a multi-route bot's extra legs name themselves.
- **Alternative-airport feature.** `Route::ALTERNATIVE_ORIGINS` (`VNO`, `KUN`) are the Baltic airports offered as alternate departures for a primary origin (via `Route::alternatives($origin, $dest)` → `[['VNO',$dest], ['KUN',$dest]]`). `Route::alternativeDestinations($origin)` offers sibling airports in the same country (e.g. `LCA`→`['PFO']` for Cyprus) so a return leg can offer "arrive at the other airport instead". The `/today` and `/nextweek` views query these alternates and render them as flag + airport-label lines under the primary flights.
- **Country-pair menu labels.** Main-menu direction buttons use `btn_route_country` (country names, e.g. "Latvia Cyprus (LCA)"); a country with multiple airports always shows its IATA code in parentheses (`Cyprus (LCA)` vs `Cyprus (PFO)`).

## Conventions & load-bearing constraints

- **No framework.** Plain PHP; Telegram handlers are plain functions (no classes) in `telegram-bot-handlers.php`, namespaced under `FlyTicket\Api\Telegram`.
- **Composer autoload quirks:** `src/Config/` classes use namespace `Config\` (its own PSR-4 mapping, not `FlyTicket\Config`), and `telegram-bot-handlers.php` + `telegram-bot-render.php` are loaded via `autoload.files` — never `require` them directly.
- **PostgreSQL dialect (the app migrated off MySQL).** `Schema::ensureTables()` writes PG DDL (identity columns, `TIMESTAMP`, separate `CREATE INDEX IF NOT EXISTS`); upserts use `ON CONFLICT (...) DO UPDATE SET ... = EXCLUDED.*` — never MySQL's `ON DUPLICATE KEY UPDATE`. PG has no `ON UPDATE CURRENT_TIMESTAMP`, so `updated_at` is bumped explicitly in the upserts (`now()`). Date comparisons use the cast `depart::date`, not `DATE(depart)`; NULL-safe equality is `IS NOT DISTINCT FROM`, not `<=>`; `GROUP BY` must repeat expressions, not SELECT aliases (the `dd`/`company` group keys in `getTopPerDay` are grouped by the expressions). `flights.price` is `DECIMAL(10,2)` so `MIN(price)` compares numerically — keep it numeric. Bot reconnect detection matches PG SQLSTATE `08000`/`57014`, not MySQL driver codes 2006/2013.
- **`src/Database.php` must stay namespace-free** — it defines the global `\Database`, is `require_once`d (not autoloaded). Don't add a `namespace` line.
- **Telegram HTML parse mode always on.** Any literal `<...>` in user-facing output that isn't valid Telegram HTML causes a 400. Escape as `&lt;…&gt;`.
- **MCP responses can be SSE or plain JSON** — `RyanairClient::parseMcpResponse()` handles both; keep it that way.
- **AirBaltic MLA rows store `source = NULL` in `flights`** (the script passes `null`; the column is nullable), and `FlightRepository` coalesces that to `''` for the NOT-NULL `price_history.source`. The Cyprus script stores `'airbaltic-cyprus'`. Don't "fix" this; queries and company display rely on it.
- **PHP defines no `CURLM_*` constants.** The `curl_multi` wait loops compare against numeric literals (`$status === -1`, `$status !== 0`). Do not rename them back to constants. `curl_multi_select()` returns -1 while handles are still running (libcurl 8.x); wait loops must treat -1 as "re-exec now", not "all done".
- **Ryanair one-way tool returns only the single cheapest flight for multi-day ranges** — queries must be 1-day ranges. Don't widen the window.
- **Seasonal routes legitimately go empty** (Cyprus in winter). Empty results are not bugs.
- **Bot watchdog pgrep pattern is load-bearing.** `crontab.txt` matches `telegram-bot[.]php` (the child poller, not `jobs.php`); the `[.]` prevents pgrep from self-matching cron's own shell. README shows the older `php src/jobs.php bot` pattern — crontab.txt is the truth; don't "fix" either pattern.
- **Retention:** `\Database::prunePriceHistory(90)` runs once per fetch cycle from `jobs.php fetch` (after the children finish). Don't add per-run DELETEs.
- **`.env` never overrides a real environment variable** (`Config\EnvLoader`), and a missing `.env` is a valid deployment (Docker/systemd inject the env). Don't "fix" either behavior — `phpunit.xml.dist` relies on it to point tests at `fly_ticket_test`.

## Testing

- Unit suite runs against a **separate database** — `phpunit.xml.dist` sets `DB_NAME=fly_ticket_test`, which wins over `.env` (real env beats `.env` in `EnvLoader`). Create it once: `CREATE DATABASE fly_ticket_test`. It also uses a synthetic route (`TST→TST2`) so nothing real is touched (`tests/DatabaseTestTrait.php`).
- `tests/Unit/CommandCoverageTest.php` exercises the full command + callback surface (every text command and every inline-button callback data string) through the real dispatch path — add a case there whenever you add or rename a command/callback.
- After any handler change, verify both the unit suite and — when feasible — a live render (`render-commands.php` or `jobs.php report`), because HTML-escaping bugs only surface through Telegram's API.
- `Endpoints` suite hits live APIs and is flaky by design; don't gate on it.

## Notes for agents

- **Do not read every file.** This file plus the directory layout above covers the architecture. Read a specific source file only when changing that behavior.
- The Ryanair MCP endpoint is **hardcoded** in `RyanairClient` (`MCP_ENDPOINT`) — there is no env var for it.
- Logs: `storage/logs/app-YYYY-MM-DD.log` (Logger writes one file per day; 30-day retention via cron). Cron job output → `storage/logs/cron-YYYY-MM-DD.log`; bot watchdog output → `storage/logs/bot-YYYY-MM-DD.log` (all date-stamped, so the `*.log` retention job can age them).
- `.env` is gitignored; never commit credentials.
