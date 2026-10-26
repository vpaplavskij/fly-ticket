# fly-ticket ✈️

A lightweight flight-price monitor that watches a few city pairs, stores the fares in MySQL, and reports them to you through a **Telegram bot** with tap-to-navigate buttons.

No web server, no framework — just PHP 8.5 CLI scripts driven by cron.

## Requirements

- PHP 8.5 with `curl` and `pdo_mysql`
- MySQL (or MariaDB)
- A Telegram bot token from [@BotFather](https://t.me/BotFather)
- [Composer](https://getcomposer.org/)

## Setup

```bash
# 1. Dependencies (vendor/ is committed, so this only verifies the install)
composer install

# 2. Create your .env from the template and fill in real values
cp .env.example .env
#    → set DB_* to your MySQL connection
#    → set TELEGRAM_<NAME>_TOKEN to your bot token

# 3. Verify everything is wired up (checks .env + DB connectivity)
php src/index.php status
```

`.env` is gitignored and never committed — `.env.example` is the template you copy from. See [Configuration](#configuration) for every option.

## Running it

Everything runs through two entry points:

| Command | What it does |
|---|---|
| `php src/index.php status` | Validate `.env` + DB connectivity, print a summary |
| `php src/jobs.php fetch` | Run all three fetchers concurrently (one subprocess each) |
| `php src/jobs.php bot [name]` | Start the long-poll Telegram loop(s). No name = one process per configured bot. |
| `php src/jobs.php report [bot] [chat] [cmd] [arg]` | Send a price report. No args = fan out to every bot. |

For day-to-day operation you don't run these by hand — **cron does**. The schedule lives in [`crontab.txt`](crontab.txt):

```cron
# Fetch prices 4×/day
0 6,11,16,20 * * * cd /path/to/fly-ticket && php src/jobs.php fetch >> storage/logs/cron.log 2>&1

# Daily price report at 08:00 (fan-out to every bot)
0 8 * * *         cd /path/to/fly-ticket && php src/jobs.php report >> storage/logs/cron.log 2>&1

# Keep the bots alive: restart if not running (every minute)
* * * * *         pgrep -f "php src/jobs.php bot" > /dev/null || (cd /path/to/fly-ticket && nohup php src/jobs.php bot >> storage/logs/bot.log 2>&1 &)

# Log retention: drop logs older than 30 days
0 3 * * *         find /path/to/fly-ticket/storage/logs -name "app-*.log" -mtime +30 -delete >> /dev/null 2>&1
```

Install it with `crontab crontab.txt` (adjust the paths first). The watchdog line means you never have to think about keeping the bot alive — if it dies, cron brings it back within a minute.

## Configuration

All configuration is in `.env`. Bots are **discovered automatically**: any variable named `TELEGRAM_<NAME>_TOKEN` registers a bot called after the lowercased `<NAME>`. To add a bot, create it in @BotFather and add its token line — no code changes needed.

Each bot can also be tuned with optional per-bot overrides (same `<NAME>`):

| Variable | Purpose |
|---|---|
| `TELEGRAM_<NAME>_TOKEN` | **Required.** The bot token from @BotFather. |
| `TELEGRAM_<NAME>_ROUTE` | Override the primary route, e.g. `RIX MLA`. Defaults to the global route in `src/Config/Route.php`. |
| `TELEGRAM_<NAME>_EXTRA_ROUTES` | Extra pairs offered alongside the primary, e.g. `RIX LCA RIX PFO`. Each gets its own menu buttons. |
| `TELEGRAM_<NAME>_CHAT_ID` | Where the daily report posts. Falls back to a shared test chat when unset. |

Full details (with placeholders) are in [`.env.example`](.env.example).

## Project layout

```
src/
├── bootstrap.php            # autoloader + DB + .env — every entry script requires this first
├── index.php                # front door: status / fetch / report / bot / help
├── jobs.php                 # cron entrypoint — all orchestration lives here
├── Database.php             # global \Database helper (intentionally namespace-free)
├── Config/                  # Route, Bot, BotRegistry, EnvLoader, Database config
├── Api/
│   ├── Ryanair/             # ryanair-api.php + RyanairClient
│   ├── Airbaltic/           # airbaltic-api.php (Malta), airbaltic-cyprus-api.php (Cyprus)
│   └── Telegram/            # bot loop, command handlers, TelegramClient, Translator
├── Database/                # Schema, FlightRepository, UserRepository, TranslationRepository
│   └── seed/                # {en,ru,lv}.php — translation seeds
└── Support/Logger.php       # file logger → storage/logs/app-YYYY-MM-DD.log
```

## Testing

```bash
vendor/bin/phpunit --testsuite Unit       # 76 tests — runs against the live DB (synthetic route)
vendor/bin/phpunit --testsuite Endpoints # hits the real APIs; flaky by design, don't gate on it
```

The unit suite uses a synthetic route (`TST→TST2`) so it never clobbers real data. `CommandCoverageTest` exercises every text command and inline-button callback through the real dispatch path — add a case there whenever you add or rename one.

## Localization

All user-facing strings go through `Translator::t($key, $placeholders)`, backed by a `translations` DB table (the single source of truth at runtime). Missing keys are seeded on boot from `src/Database/seed/{en,ru,lv}.php`; existing rows are never overwritten, so manual edits to the DB persist. **When you add a string, add it to all three seed files.**

## Notes

- Logs live in `storage/logs/` (one file per day, 30-day retention via cron).
- Seasonal routes can legitimately come back empty (e.g. Cyprus in winter) — that's not a bug.
- For the deeper "why is it built this way" constraints and conventions, see [`AGENTS.md`](AGENTS.md).
