<?php

namespace FlyTicket\Api\Telegram;

use Config\BotRegistry;
use Config\Route;
use FlyTicket\Database\FlightRepository;
use FlyTicket\Support\Logger;

/**
 * Shared command handlers for the Telegram bot.
 *
 * This file is safe to require from any script: it only defines functions
 * (handleToday, handleNext, handleHelp) and never starts the long-polling
 * loop or talks to the network on its own.
 *
 * All user-facing strings are localized via Translator::t(). The active
 * language is set per-chat before dispatch (see telegram-bot.php).
 *
 * Directions: every view belongs to a direction of the bot's route pair —
 * 'o' (outbound, e.g. RIX → MLA) or 'r' (return, e.g. MLA → RIX). The
 * direction is carried in the callback data (v:<view>:<dir>, d:<dir>) and in
 * process-global state ($flyticket_active_direction) so a rendered view always
 * shows flights for the leg the user picked.
 */

/**
 * Per-process active direction ('o' or 'r'). One poller process serves one bot,
 * and views are rendered in the order their buttons were pressed, so a single
 * global is enough to know which leg the current view belongs to. Defaults to
 * outbound so text commands before any button press behave as before.
 */
function activeDirection(): string
{
    return $GLOBALS['__flyticket_active_direction'] ?? 'o';
}

/** Set the direction for subsequent view renders (see activeDirection()). */
function setActiveDirection(string $direction): void
{
    $GLOBALS['__flyticket_active_direction'] = ($direction === 'r') ? 'r' : 'o';
}

/** The [origin, destination] pair for a direction ('o'/'r') of the active route. */
function directionPair(string $direction): array
{
    [$o, $d] = activePair();
    return ($direction === 'r') ? [$d, $o] : [$o, $d];
}

/** The opposite direction ('o' ↔ 'r'). */
function otherDirection(string $direction): string
{
    return ($direction === 'r') ? 'o' : 'r';
}

// ── Multi-route state ───────────────────────────────────────────────────
//
// A bot can offer several route pairs (its primary route plus any
// TELEGRAM_<NAME>_EXTRA_ROUTES). The direction buttons in the main menu carry
// an index into botRoutes(); views and footers carry it in their callback data
// (d:<i>:<dir>, v:<view>:<i>:<dir>) so every leg keeps its own pair. The active
// index is per-process state, like the direction — one poller process serves
// one bot, and views render in button-press order.

/** All route pairs this bot offers: [ [o,d], ... ] (primary first). */
function botRoutes(): array
{
    // Resolve the running bot from the real CLI args ($argv). In live poll-loop
    // mode and render mode, $argv[1] is the bot name. We also check
    // $GLOBALS['argv'] as a fallback because unit tests seed that global (the
    // namespaced $argv is not settable from outside the namespace in PHPUnit).
    $name = $argv[1] ?? $GLOBALS['argv'][1] ?? null;
    if ($name !== null) {
        $bot = BotRegistry::get($name);
        if ($bot !== null) {
            return $bot->allRoutes();
        }
    }

    $out = Route::outbound();
    return [[$out['origin'], $out['destination']]];
}

/** The active route index (0-based into botRoutes()). */
function activeRouteIndex(): int
{
    return (int) ($GLOBALS['__flyticket_active_route_index'] ?? 0);
}

/** Set the active route index for subsequent view renders. */
function setActiveRouteIndex(int $index): void
{
    $GLOBALS['__flyticket_active_route_index'] = max(0, min($index, count(botRoutes()) - 1));
}

/** The [origin, destination] pair currently being viewed. */
function activePair(): array
{
    $routes = botRoutes();
    return $routes[activeRouteIndex()] ?? $routes[0];
}

/**
 * Country-based label for an IATA code (e.g. "LCA" → "Cyprus (LCA)",
 * "MLA" → "Malta"). Used in menu buttons and view titles so routes are named
 * by country pair instead of city pair. When a country has multiple airports,
 * the IATA code is always shown to disambiguate.
 */
function countryLabel(string $code): string
{
    return Route::countryLabel($code);
}

/** Country-based label for a route pair (e.g. "Latvia Malta", "Latvia Cyprus (LCA)"). */
function countryPairLabel(array $pair): string
{
    return Route::countryPairLabel($pair);
}

// ── Small helpers ─────────────────────────────────────────────────────

function fmtPrice(float $price): string
{
    return '€' . number_format($price, 2);
}

/**
 * Format a date with localized day/month names for the active chat language
 * (e.g. "Thu Nov 05" → "чтв, 5 ноя" in Russian, "ceturtdiena, 5 nov." in
 * Latvian). Falls back to English names when Intl is unavailable or the
 * formatter fails, so a missing ICU locale never breaks a reply.
 */
function fmtDate(string $date): string
{
    $ts = strtotime($date);
    if ($ts === false) {
        return $date;
    }

    $localeMap = [
        'lv' => 'lv_LV',
        'ru' => 'ru_RU',
        'en' => 'en_US',
    ];
    $locale = $localeMap[Translator::get()] ?? 'en_US';

    try {
        $formatter = new \IntlDateFormatter(
            $locale,
            \IntlDateFormatter::MEDIUM,
            \IntlDateFormatter::NONE,
            null,
            \IntlDateFormatter::GREGORIAN,
            "EEE, d MMM",
        );
        $formatted = $formatter->format(new \DateTimeImmutable('@' . $ts));

        if (is_string($formatted) && $formatted !== '' && !str_contains($formatted, 'Locale data')) {
            return $formatted;
        }
    } catch (\Throwable) {
        // Fall through to the English fallback below.
    }

    return date('D M d', $ts);
}

function fmtCompany(?string $source, ?array $row = null): string
{
    if ($source === null || $source === '') {
        return 'AirBaltic';
    }
    return match ($source) {
        'ryanair'          => 'Ryanair',
        'airbaltic'        => 'AirBaltic',
        'airbaltic-cyprus' => 'AirBaltic',
        default            => ucfirst($source),
    };
}

/**
 * Compress a Ryanair deep-link down to only the params that actually matter
 * (origin, destination, date, adults). Strips ~240 chars of empty/redundant
 * query params so the link is short enough to embed inline per line.
 */
function shortBuyLink(?string $url): string
{
    if ($url === null || trim($url) === '') {
        return '';
    }

    // Ryanair select page: keep only originIata, destinationIata, dateOut, adults.
    if (str_contains($url, 'ryanair.com')) {
        $q = [];
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $q);
        $keep = array_intersect_key(
            $q,
            array_flip(['originIata', 'destinationIata', 'dateOut', 'adults']),
        );
        // Drop empty values. If nothing is left (e.g. a plain ryanair.com URL
        // with no query params), fall back to the original link so the booking
        // button isn't lost.
        $keep = array_filter($keep, fn($v) => $v !== '' && $v !== null);
        if (empty($keep)) {
            return $url;
        }
        ksort($keep);
        return 'https://www.ryanair.com/gb/en/trip/flights/select?' . http_build_query($keep);
    }

    // AirBaltic book-flight page: keep the full link. The tripType=oneway and
    // numAdt=1 params ensure the booking page pre-selects "one-way" and the
    // correct date/passenger count, so we do NOT shorten these.
    if (str_contains($url, 'airbaltic.com')) {
        return $url;
    }

    // Unknown format: return as-is.
    return $url;
}

/**
 * Render an inline clickable 🎫 for a booking link. The compressed URL keeps
 * each line short enough that all ~58 days fit in one Telegram message.
 */
function buyLinkHtml(?string $url): string
{
    $short = shortBuyLink($url);
    if ($short === '') {
        return '';
    }
    return ' <a href="' . htmlspecialchars($short, ENT_QUOTES) . '">🎫</a>';
}

// ── Bot identity ────────────────────────────────────────────────────────
//
// The running bot's own Telegram username, shown in
// the menu/help so users know which bot they are talking to. Each poller
// process serves exactly one bot, so the name is resolved once per process
// and cached in $GLOBALS (handlers are plain functions; a static would need a
// getter/setter pair). Render mode (no argv[1]) returns null — the line is
// simply omitted there.

/**
 * Resolve + cache "this bot's" username via getMe(). Returns null when it
 * cannot be resolved (render mode, unknown name, or network failure) so menu
 * rendering never blocks on the Telegram API.
 */
function bot_identity(): ?string
{
    if (!empty($GLOBALS['__flyticket_bot_username'])) {
        return $GLOBALS['__flyticket_bot_username'];
    }

    $name = $argv[1] ?? $GLOBALS['argv'][1] ?? null;
    if ($name === null) {
        return null; // render mode / no bot context
    }

    try {
        $bot = BotRegistry::get($name);
        if ($bot !== null) {
            $me       = (new TelegramClient($bot->token))->getMe();
            $username = $me['result']['username'] ?? null;
            if (is_string($username) && $username !== '') {
                $GLOBALS['__flyticket_bot_username'] = $username;
            }
        }
    } catch (\Throwable $e) {
        Logger::warn('Could not resolve bot username', ['exception' => $e->getMessage()]);
    }

    return $GLOBALS['__flyticket_bot_username'] ?? null;
}

/**
 * The "🤖 Bot: @username" line for menus, or '' when the identity is unknown.
 * Localized via the `bot_identity` translation key ({username} placeholder).
 */
function bot_identity_line(): string
{
    $username = bot_identity();
    if ($username === null) {
        return '';
    }
    return Translator::t('bot_identity', ['username' => $username]);
}

// ── Route helpers ───────────────────────────────────────────────────────
//
// Each bot monitors its own city pair(s), fully driven by .env config: a bot's
// primary route comes from TELEGRAM_<NAME>_ROUTE (falling back to the global
// Config\Route) and any extra pairs from TELEGRAM_<NAME>_EXTRA_ROUTES. The
// active bot's outbound pair comes from BotRegistry; handlers that take an
// explicit origin/destination (buildToday, buildNext, handleBuy) are
// route-agnostic — the caller passes the right pair. Adding a new route is a
// config-only change: no code edits needed.

/**
 * The active bot's PRIMARY (first, index 0) route pair as [origin, destination].
 * Used only for "which bot is this" identity — the menu banner and /help. In
 * render mode or when the bot has no per-bot override it falls back to the
 * global Config\Route::outbound(). View titles deliberately do NOT use this:
 * they use activePair() so a multi-route bot's extra legs name themselves too.
 */
function activeRoute(): array
{
    $name = $argv[1] ?? $GLOBALS['argv'][1] ?? null;
    if ($name !== null) {
        $bot = BotRegistry::get($name);
        if ($bot !== null) {
            return $bot->originDestination();
        }
    }

    $out = Route::outbound();
    return [$out['origin'], $out['destination']];
}

/**
 * Human label for the leg currently being viewed (the active route pair in the
 * active direction), using country names (e.g. "Latvia Cyprus (LCA)"). Used in
 * view titles (/today, /nextweek, ...) so a multi-route bot's extra legs name
 * themselves correctly — derived from process state (setActiveRouteIndex +
 * setActiveDirection), which is set on every button press and text command.
 */
function routeLabel(): string
{
    [$o, $d] = directionPair(activeDirection());
    return Route::countryPairLabel([$o, $d]);
}

/**
 * Label for the menu banner: the primary route when the bot offers only one
 * pair, or "Latvia Malta + Latvia Cyprus (LCA)" style when it has extra routes —
 * so the banner names everything the main menu can open.
 */
function activeRouteLabel(): string
{
    $routes = botRoutes();
    if (count($routes) === 1) {
        return countryPairLabel($routes[0]);
    }

    return implode(' + ', array_map(fn($p) => countryPairLabel($p), $routes));
}

// ── /today ─────────────────────────────────────────────────────────────

/**
 * /today — all flights departing today, sorted by departure time.
 */
function handleToday(FlightRepository $repo, string $origin, string $destination): string
{
    [$text] = buildToday($repo, $origin, $destination);
    return implode("\n", $text);
}

/**
 * Build the /today view. Returns [text, keyboardJson|null]. Flights are sorted
 * cheapest-first so the lowest available price is always at the top. Capped at
 * 8 lines to keep the message compact. Each line ends with an inline clickable
 * 🎫 (compressed booking URL) — tapping it opens that flight's booking page.
 */
function buildToday(FlightRepository $repo, string $origin, string $destination): array
{
    $date = date('Y-m-d');

    // Cheapest flight per company today (top 3 companies), cheapest-first —
    // each line is one operator's lowest fare for the day. Query the primary
    // origin together with its alternative origins (VNO, KUN) so the view can
    // also offer "depart from Estonia/Lithuania instead" when useful. Also
    // query alternative destinations (e.g. PFO alongside LCA for Cyprus) so
    // the return leg can offer "arrive at the other airport instead".
    $altOrigins = Route::alternatives($origin, $destination);
    $altDests   = Route::alternativeDestinations($origin);
    $allOrigins = array_merge([$origin], array_column($altOrigins, 0));
    $rows       = $repo->getTopPerDay($allOrigins, $destination, $date, $date, 3);

    // Destination alternatives: the alternative airport flying to the SAME
    // destination (e.g. PFO→RIX when viewing LCA→RIX).
    $altDestRows = [];
    foreach ($altDests as $altDest) {
        $altDestRows[$altDest] = $repo->getTopPerDay($altDest, $destination, $date, $date, 3);
    }

    if (empty($rows) && empty(array_filter($altDestRows))) {
        return [[Translator::t('today_title') . " — " . routeLabel() . "\n\n" . Translator::t('no_flights_today')], null];
    }

    $lines   = [];
    $lines[] = Translator::t('today_title') . " — " . routeLabel();

    // Split into primary-origin rows and alternative-origin rows (grouped per
    // origin, in ALTERNATIVE_ORIGINS order: Estonia first, then Lithuania).
    $primaryRows = array_values(array_filter($rows, fn($r) => ($r['origin'] ?? $origin) === $origin));
    $altByOrigin = [];
    foreach ($rows as $r) {
        $o = $r['origin'] ?? '';
        if ($o !== '' && $o !== $origin) {
            $altByOrigin[$o][] = $r;
        }
    }

    // Primary flights: cheapest-first, top 3.
    usort($primaryRows, fn($a, $b) => (float) $a['price'] <=> (float) $b['price']);
    foreach (array_slice($primaryRows, 0, 3) as $i => $r) {
        $dep = date('H:i', strtotime($r['depart']));
        $arr = date('H:i', strtotime($r['arrive']));
        $co  = htmlspecialchars(fmtCompany($r['source'] ?? null, $r));
        // Badge the cheapest flight so it stands out.
        $badge   = ($i === 0) ? Translator::t('lowest_badge') . ' ' : '';
        $lines[] = "{$badge}{$dep} → {$arr}  ·  {$co}  ·  <b>" . fmtPrice((float) $r['price']) . "</b>" . buyLinkHtml($r['buy_link']);
    }

    // Alternatives: one compact line per alternative origin (cheapest flight),
    // country flag + airport label so the user sees where they'd depart from.
    if (!empty($altByOrigin)) {
        $lines[] = '';
        $lines[] = Translator::t('alternatives_title');
        foreach ($altOrigins as [$altOrigin, $altDest]) {
            if (empty($altByOrigin[$altOrigin])) {
                continue; // no data for this origin today — skip silently
            }
            $r       = $altByOrigin[$altOrigin][0]; // cheapest-first within group
            $dep     = date('H:i', strtotime($r['depart']));
            $arr     = date('H:i', strtotime($r['arrive']));
            $co      = htmlspecialchars(fmtCompany($r['source'] ?? null, $r));
            $flag    = Route::flagFor($altOrigin);
            $label   = Route::airportName($altOrigin) . ' → ' . Route::airportName($destination);
            $lines[] = "  {$flag} <b>{$label}</b> · {$dep} → {$arr} · {$co} · <b>" . fmtPrice((float) $r['price']) . "</b>" . buyLinkHtml($r['buy_link']);
        }
    }

    // Destination alternatives: one compact line per alternative destination
    // (e.g. PFO→RIX when viewing LCA→RIX), flag + airport label.
    $hasAltDest = false;
    foreach ($altDests as $altDest) {
        if (empty($altDestRows[$altDest])) {
            continue;
        }
        if (!$hasAltDest) {
            if (!empty($altByOrigin)) {
                $lines[] = '';
                $lines[] = Translator::t('alternatives_title');
            }
            $hasAltDest = true;
        }
        $r       = $altDestRows[$altDest][0]; // cheapest-first within group
        $dep     = date('H:i', strtotime($r['depart']));
        $arr     = date('H:i', strtotime($r['arrive']));
        $co      = htmlspecialchars(fmtCompany($r['source'] ?? null, $r));
        $flag    = Route::flagFor($altDest);
        $label   = Route::airportName($altDest) . ' → ' . Route::airportName($destination);
        $lines[] = "  {$flag} <b>{$label}</b> · {$dep} → {$arr} · {$co} · <b>" . fmtPrice((float) $r['price']) . "</b>" . buyLinkHtml($r['buy_link']);
    }

    $count   = count($primaryRows);
    $s       = $count !== 1 ? 's' : '';
    $lines[] = Translator::t('flights_count', ['count' => $count, 's' => $s]);

    $lines[] = Translator::t('price_note');

    // Single message (today's flights always fit).
    return [[implode("\n", $lines)], null];
}

// ── /nextweek, /nextmonth, /next3months ───────────────────────────────

/**
 * /nextweek, /nextmonth, /next3months — lowest price per day.
 */
function handleNext(FlightRepository $repo, string $origin, string $destination, int $days, string $label): string
{
    [$chunks] = buildNext($repo, $origin, $destination, $days, $label);
    return implode("\n\n", $chunks);
}

/**
 * Build a range view. Returns [text, keyboardJson|null]. Each day line ends
 * with an inline clickable 🎫 (a compressed booking URL) right after the
 * company name — tapping it opens that date's booking page directly. If the
 * full range exceeds Telegram's 4096-char cap the text is split into several
 * messages, each under the cap, joined by newlines so the caller can send
 * them one at a time.
 */
function buildNext(FlightRepository $repo, string $origin, string $destination, int $days, string $label, string $direction = ''): array
{
    $dateFrom = date('Y-m-d');
    $dateTo   = date('Y-m-d', strtotime("+{$days} days"));

    // Cheapest flight per company per day (top 3 companies), cheapest-first —
    // only the cheapest option per day is rendered. Also query alternative
    // origins so they can be shown as a single "cheapest alternative" line, and
    // query alternative destinations (e.g. PFO alongside LCA for Cyprus) so
    // the return leg can offer "arrive at the other airport instead".
    $altRoutes  = Route::alternatives($origin, $destination);
    $altDests   = Route::alternativeDestinations($origin);
    $allOrigins = array_merge([$origin], array_column($altRoutes, 0));
    $rows       = $repo->getTopPerDay($allOrigins, $destination, $dateFrom, $dateTo, 3);

    // Destination alternatives: the alternative airport flying to the SAME
    // destination (e.g. PFO→RIX when viewing LCA→RIX).
    $altDestRows = [];
    foreach ($altDests as $altDest) {
        $altDestRows[$altDest] = $repo->getTopPerDay($altDest, $destination, $dateFrom, $dateTo, 3);
    }

    if (empty($rows) && empty(array_filter($altDestRows))) {
        return [[Translator::t('upcoming_title') . " — {$label} — " . routeLabel() . "\n\n" . Translator::t('no_upcoming_data')], null];
    }

    // Split into primary-origin rows and alternative-origin rows.
    $primaryRows = array_values(array_filter($rows, fn($r) => ($r['origin'] ?? $origin) === $origin));
    $altByOrigin = [];
    foreach ($rows as $r) {
        $o = $r['origin'] ?? '';
        if ($o !== '' && $o !== $origin) {
            $altByOrigin[$o][] = $r;
        }
    }

    // Build each day line with its inline 🎫 link (chronological order). Only
    // the cheapest option per day is shown — no alternatives.
    $dayLines = [];
    $byDay    = [];
    foreach ($primaryRows as $r) {
        if (!isset($byDay[$r['date']])) {
            $byDay[$r['date']] = [];
        }
        $byDay[$r['date']][] = $r;
    }
    ksort($byDay);

    foreach ($byDay as $dayRows) {
        $cheapest = $dayRows[0];
        $day      = fmtDate($cheapest['date']);
        $co       = htmlspecialchars(fmtCompany($cheapest['source'] ?? null, $cheapest));
        // Flag the cheapest available day's origin.
        $flag       = empty($altRoutes) ? '' : Route::flagFor($cheapest['origin'] ?? $origin);
        $dayLines[] = "{$flag}{$day}  <b>" . fmtPrice((float) $cheapest['price']) . "</b> · {$co}" . buyLinkHtml($cheapest['buy_link']);
    }

    // "Cheapest days" block: top 5 lowest prices in the range, sorted by price.
    // Leads the reply so the user sees the best deals first.
    $byPrice = [];
    foreach ($byDay as $dayRows) {
        $byPrice[] = $dayRows[0];
    }
    usort($byPrice, fn($a, $b) => (float) $a['price'] <=> (float) $b['price']);
    $cheapest = array_slice($byPrice, 0, 5);

    $cheapestLines   = [];
    $cheapestLines[] = Translator::t('cheapest_days');
    foreach ($cheapest as $i => $r) {
        $day   = fmtDate($r['date']);
        $co    = htmlspecialchars(fmtCompany($r['source'] ?? null, $r));
        $flag  = Route::flagFor($r['origin'] ?? $origin);
        $badge = ($i === 0) ? '🏷️ ' : '';

        $cheapestLines[] = "  {$badge}{$flag} {$day}  <b>" . fmtPrice((float) $r['price']) . "</b> · {$co}" . buyLinkHtml($r['buy_link']);
    }

    // Append alternative-origin lines after the primary day line.
    if (!empty($altByOrigin)) {
        foreach ($altRoutes as [$altOrigin, $altDest]) {
            if (empty($altByOrigin[$altOrigin])) {
                continue;
            }
            // Cheapest flight for this alternative origin in the range.
            usort($altByOrigin[$altOrigin], fn($a, $b) => (float) $a['price'] <=> (float) $b['price']);
            $r               = $altByOrigin[$altOrigin][0];
            $day             = fmtDate($r['date']);
            $co              = htmlspecialchars(fmtCompany($r['source'] ?? null, $r));
            $flag            = Route::flagFor($altOrigin);
            $label           = Route::airportName($altOrigin) . ' → ' . Route::airportName($destination);
            $cheapestLines[] = "  {$flag} <b>{$label}</b> · {$day} · {$co} · <b>" . fmtPrice((float) $r['price']) . "</b>" . buyLinkHtml($r['buy_link']);
        }
    }

    // Append destination-alternative lines (e.g. PFO→RIX when viewing LCA→RIX).
    // One line per alternative destination, cheapest flight.
    foreach ($altDests as $altDest) {
        if (empty($altDestRows[$altDest])) {
            continue;
        }
        usort($altDestRows[$altDest], fn($a, $b) => (float) $a['price'] <=> (float) $b['price']);
        $r               = $altDestRows[$altDest][0];
        $day             = fmtDate($r['date']);
        $co              = htmlspecialchars(fmtCompany($r['source'] ?? null, $r));
        $flag            = Route::flagFor($altDest);
        $label           = Route::airportName($altDest) . ' → ' . Route::airportName($destination);
        $cheapestLines[] = "  {$flag} <b>{$label}</b> · {$day} · {$co} · <b>" . fmtPrice((float) $r['price']) . "</b>" . buyLinkHtml($r['buy_link']);
    }

    // Chunk the lines into messages that each fit under Telegram's 4096 cap.
    $title = Translator::t('upcoming_title') . " — {$label} — " . routeLabel();
    $cap   = 4096;

    // The cheapest-days block always leads the first message, followed by a
    // separator and then the chronological list.
    $allLines = array_merge($cheapestLines, [''], $dayLines);

    $chunks = [];
    $cur    = [$title];
    $curLen = strlen($title) + 1;

    foreach ($allLines as $line) {
        if ($curLen + strlen($line) + 1 > $cap && count($cur) > 1) {
            $chunks[] = implode("\n", $cur);
            $cur      = [];
            $curLen   = 0;
        }
        $cur[] = $line;
        $curLen += strlen($line) + 1;
    }
    if (!empty($cur)) {
        $chunks[] = implode("\n", $cur);
    }

    // Defensive: never emit an empty/whitespace-only chunk — Telegram rejects
    // it with "message text is empty" (a 400 that drops the whole chunk). This
    // guards against a boundary case where a flush leaves only blank separator
    // lines in a trailing chunk.
    $chunks = array_values(array_filter($chunks, fn(string $c): bool => trim($c) !== ''));

    if (empty($chunks)) {
        // Nothing renderable at all (defensive; the no-data case returns earlier).
        return [[Translator::t('no_upcoming_data')], null];
    }

    // Append the price disclaimer to the last chunk so it always shows, even
    // when the range is split across several messages.
    $note = Translator::t('price_note');
    $last = count($chunks) - 1;
    $chunks[$last] .= "\n\n" . $note;

    // Return the list of messages (usually 1, more for long ranges). The
    // caller sends each as its own Telegram message.
    return [$chunks, null];
}

// ── /help ──────────────────────────────────────────────────────────────

/**
 * /help — show available commands.
 */
function handleHelp(): string
{
    // The help title names the active bot's own route (e.g. "RIX ⇄ MLA" or
    // "RIX ⇄ HEL") — {route} is substituted per bot.
    $lines = [Translator::t('help_title', ['route' => activeRouteLabel()])];

    // Show which bot this is (the bot's own Telegram username) — omitted in render mode.
    if ($username = bot_identity()) {
        $lines[] = Translator::t('bot_identity_line', ['username' => $username]);
    }

    [$o, $d] = activeRoute();
    return implode("\n", [
        ...$lines,
        "",
        Translator::t('help_outbound_label', ['origin' => countryLabel($o), 'destination' => countryLabel($d)]),
        Translator::t('help_today'),
        Translator::t('help_nextweek'),
        Translator::t('help_nextmonth'),
        Translator::t('help_next3months'),
        "",
        Translator::t('help_buy_label'),
        Translator::t('help_buy'),
        "",
        Translator::t('help_information'),
        Translator::t('help_help'),
    ]);
}

// ── Command dispatch (shared by bot loop, render mode, send-report) ────

/**
 * Map a command name to its handler reply. Shared by the bot poll loop,
 * the one-shot render mode, and send-report.php so the command set is
 * defined in exactly one place.
 */
function dispatchCommand(FlightRepository $repo, string $origin, string $destination, string $command, string $arg = ''): string
{
    [$messages] = dispatchCommandFull($repo, $origin, $destination, $command, $arg);
    return implode("\n\n", $messages);
}

/**
 * Same as dispatchCommand but also returns the matching keyboard JSON (or
 * null). Used by the bot loop so range/today views can attach their 🎫
 * per-day booking buttons. Returns [messages, keyboard|null] — the keyboard is
 * null for view commands; the caller builds the footer via viewFooterKeyboard().
 */
function dispatchCommandFull(FlightRepository $repo, string $origin, string $destination, string $command, string $arg = ''): array
{
    // /buy <date> — single booking link (keeps range views link-free so every
    // day fits in one message). Accepts "Y-m-d" or "M d" (e.g. "Nov 05").
    if ($command === 'buy') {
        [$bo, $bd] = directionPair(activeDirection());
        [$text, ]  = handleBuy($repo, $arg, $bo, $bd);
        return [[$text], null];
    }

    // Views resolve against the active bot's route pair for the CURRENT
    // direction (outbound or return) — set by the button that was pressed.
    [$o, $d] = directionPair(activeDirection());

    // buildToday / buildNext already return [messages, keyboard|null] —
    // splat them into the outer [messages, keyboard] shape directly.
    return match ($command) {
        'today'       => buildToday($repo, $o, $d),
        'nextweek'    => buildNext($repo, $o, $d, 7, Translator::t('label_next_week')),
        'nextmonth'   => buildNext($repo, $o, $d, 30, Translator::t('label_next_month')),
        'next3months' => buildNext($repo, $o, $d, 90, Translator::t('label_next_3_months')),
        // 'info'/'information' are aliases of /help — users type them expecting
        // bot info, and without the alias they fall through to "Unknown command".
        'help', 'start', 'info', 'information' => [[handleHelp()], null],
        default                                => [[Translator::t('unknown_command')], null],
    };
}

// ── /buy — one booking link for a specific date ────────────────────────

/**
 * /buy <date> — send the booking link for the cheapest flight on that date.
 * Range views (/nextweek, /next3months, ...) deliberately omit per-line links
 * so all days fit in one message; this command is how you get a tap-to-book
 * link without bloating those lists. $origin/$destination are the active bot's
 * OUTBOUND pair (defaults to it when omitted).
 *
 * Returns [text, found] where $found is true only when a booking link was
 * actually produced. Callers can check $found instead of pattern-matching on
 * localized reply text.
 */
function handleBuy(FlightRepository $repo, string $dateArg, ?string $origin = null, ?string $destination = null): array
{
    if ($origin === null || $destination === null) {
        [$origin, $destination] = activeRoute();
    }

    $route = ['origin' => $origin, 'destination' => $destination];

    // Split an optional trailing departure time (e.g. "2026-11-05 11:15").
    $time = '';
    if (preg_match('/^(.*?)\s+(\d{1,2}:\d{2})$/', trim($dateArg), $m)) {
        $dateArg = $m[1];
        $time    = $m[2];
    }

    // Accept "Y-m-d" or "M d" / "M d, Y" (e.g. "Nov 05", "Nov 05, 2026").
    $date = null;
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateArg)) {
        $date = $dateArg;
    } elseif ($parsed = date('Y-m-d', strtotime($dateArg))) {
        // Only trust it if the parsed year is in a sane range (avoids "Nov 05"
        // silently resolving to last year when no year is given).
        $y = (int) date('Y');
        if ((int) substr($parsed, 0, 4) >= $y - 1 && (int) substr($parsed, 0, 4) <= $y + 2) {
            $date = $parsed;
        }
    }

    if ($date === null) {
        return [Translator::t('buy_usage'), false];
    }

    // Country names for the reply (data still uses codes).
    $oName = countryLabel($route['origin']);
    $dName = countryLabel($route['destination']);

    $rows = $repo->getFlightsForDay($route['origin'], $route['destination'], $date);

    // When a departure time is supplied (from a /today 🎫 button), match the
    // specific flight; otherwise fall back to the cheapest flight that day.
    if (!empty($time) && preg_match('/^\d{1,2}:\d{2}/', $time, $tm)) {
        $rows = array_filter($rows, fn($r) => date('H:i', strtotime($r['depart'])) === $tm[0]);
    }

    if (empty($rows)) {
        return [Translator::t('no_flights_on_date', [
            'date'        => $date,
            'origin'      => $oName,
            'destination' => $dName,
        ]), false];
    }

    // Cheapest flight that day.
    usort($rows, fn($a, $b) => (float) $a['price'] <=> (float) $b['price']);
    $f = $rows[0];

    if (empty($f['buy_link'])) {
        return [Translator::t('no_booking_link', ['date' => $date]), false];
    }

    $dep = date('H:i', strtotime($f['depart']));
    $arr = date('H:i', strtotime($f['arrive']));
    $co  = fmtCompany($f['source'] ?? null);

    return [sprintf(
        "%s\n%s · %s → %s · <b>%s</b>\n\n<a href=\"%s\">%s</a>",
        Translator::t('buy_result_title', [
            'date'        => fmtDate($date),
            'origin'      => $oName,
            'destination' => $dName,
        ]),
        $co,
        $dep,
        $arr,
        fmtPrice((float) $f['price']),
        htmlspecialchars($f['buy_link'], ENT_QUOTES),
        Translator::t('open_booking_link'),
    ), true];
}

/**
 * Truncate a reply to Telegram's 4096-char limit without leaving an
 * unclosed HTML tag (Telegram rejects the whole message with
 * "Unclosed start tag"). Strips any dangling partial tag at the cut point
 * and closes every still-open tag on the tail.
 */
function truncateReply(string $reply): string
{
    if (strlen($reply) <= 4096) {
        return $reply;
    }

    $truncated = substr($reply, 0, 4093);

    // Drop a trailing partial/dangling open tag (e.g. "<a href="...") that
    // has no closing ">" — it would otherwise remain unclosed.
    if (preg_match('/<([a-z][a-z0-9]*)(?:\s[^>]*)?$/i', $truncated)) {
        $truncated = preg_replace('/<([a-z][a-z0-9]*)(?:\s[^>]*)?$/i', '', $truncated);
    }

    // Close any tags still open after the cut (in reverse nesting order).
    $openA = substr_count($truncated, '<a ') - substr_count($truncated, '</a>');
    $openB = substr_count($truncated, '<b>') - substr_count($truncated, '</b>');
    $openI = substr_count($truncated, '<i>') - substr_count($truncated, '</i>');

    $tail = '';
    for ($i = 0; $i < max(0, $openA); $i++) {
        $tail .= '</a>';
    }
    for ($i = 0; $i < max(0, $openB); $i++) {
        $tail .= '</b>';
    }
    for ($i = 0; $i < max(0, $openI); $i++) {
        $tail .= '</i>';
    }

    return rtrim($truncated) . '...' . $tail;
}

// ── Inline-keyboard menus (button-driven UI) ───────────────────────────
//
// The bot is fully clickable: the user picks a direction, then a view.
// Callback data is compact and self-describing so the poll loop can route it
// without extra state:
//   m            → main menu
//   d:<i>:<dir>  → open a leg's views — <i> indexes botRoutes(), dir is 'o'/'r'
//                  (legacy "d:<dir>" without an index means route 0)
//   v:<view>:<i>:<dir>  → render one view of that leg (today|week|month|3m);
//                  legacy "v:<view>:<dir>" means route 0
//   lang         → language selection menu
//   setlang:<code>  → set the active language

/**
 * The main-menu banner: title + bot identity line + "pick a view" prompt.
 * Used by every site that renders the main menu so the layout (and which bot
 * the user is talking to) stays consistent in one place.
 */
function menuBanner(): string
{
    $parts = [Translator::t('menu_title', ['route' => activeRouteLabel()])];

    if ($line = bot_identity_line()) {
        $parts[] = $line;
    }

    $parts[] = Translator::t('pick_direction');

    return implode("\n\n", $parts);
}

/**
 * Banner for a direction's view list: title + "pick a range" prompt. Shown when
 * the user opens a direction (d:<dir>) before picking a concrete range. Uses
 * country-based labels (e.g. "Latvia Cyprus (LCA)").
 */
function directionBanner(string $direction): string
{
    [$o, $d] = directionPair($direction);
    return Translator::t('menu_title', ['route' => countryLabel($o) . ' → ' . countryLabel($d)]) . "\n\n" . Translator::t('choose_what_to_show');
}

/**
 * The main-menu keyboard: one row per route pair the bot offers (primary first,
 * then extras), each with both legs, plus Help and Language. Callback data is
 * `d:<i>:<dir>` — <i> indexes into botRoutes(), <dir> is 'o'/'r'. Tapping a leg
 * opens that leg's views; the opposite leg is always one tap away from any view
 * via its footer.
 *
 * Buttons use country-based labels (e.g. "Latvia Malta") instead of city names,
 * so routes are named as country pairs. When a country has multiple airports,
 * the IATA code is shown in parentheses (e.g. "Cyprus (LCA)").
 */
function mainMenuKeyboard(): string
{
    $rows = [];
    foreach (botRoutes() as $i => [$o, $d]) {
        $rows[] = [[Translator::t('btn_route_country', ['origin' => countryLabel($o), 'destination' => countryLabel($d)]), "d:{$i}:o"]];
        $rows[] = [[Translator::t('btn_route_country', ['origin' => countryLabel($d), 'destination' => countryLabel($o)]), "d:{$i}:r"]];
    }
    return buildKeyboard([
        ...$rows,
        [[Translator::t('btn_help'), 'help']],
        [[Translator::t('btn_language'), 'lang']],
    ]);
}

/**
 * The range-picker keyboard shown when a direction is opened (d:<i>:<dir>): the
 * four views for that leg plus the opposite-direction and main-menu buttons.
 * View callbacks carry the route index: v:<view>:<i>:<dir>.
 *
 * $routeIndex is the index of the leg being opened — it MUST be passed in from
 * the pressed callback, not read from activeRouteIndex(). The keyboard is built
 * and sent in one step, but a stale/foreign route index in process state would
 * otherwise bake the WRONG leg's callbacks into every view button (e.g. opening
 * RIX⇄LCA but getting MLA's "v:today:0:o" buttons). Defaults to the active
 * index for callers that don't thread it.
 */
function directionMenuKeyboard(string $direction, int $routeIndex = null): string
{
    $i = ($routeIndex === null) ? activeRouteIndex() : $routeIndex;
    return buildKeyboard([
        [[Translator::t('btn_today'), "v:today:{$i}:{$direction}"], [Translator::t('btn_next_week'), "v:week:{$i}:{$direction}"]],
        [[Translator::t('btn_next_month'), "v:month:{$i}:{$direction}"], [Translator::t('btn_3_months'), "v:3m:{$i}:{$direction}"]],
        [oppositeDirectionButton($direction, $routeIndex), [Translator::t('btn_main_menu'), 'm']],
    ]);
}

/**
 * The navigation buttons under a rendered view. Carries the route index and
 * direction in every callback so range taps re-render the SAME leg (inheriting
 * all its flights) and the opposite-direction button flips to the other leg's
 * views of the same pair. $routeIndex is the index of the leg being viewed —
 * pass it from the rendered view's state; defaults to activeRouteIndex() for
 * callers that don't thread it (same rationale as directionMenuKeyboard()).
 */
function viewFooterKeyboard(string $direction, int $routeIndex = null): string
{
    $i = ($routeIndex === null) ? activeRouteIndex() : $routeIndex;
    return buildKeyboard([
        [[Translator::t('btn_today'), "v:today:{$i}:{$direction}"], [Translator::t('btn_next_week'), "v:week:{$i}:{$direction}"]],
        [[Translator::t('btn_next_month'), "v:month:{$i}:{$direction}"], [Translator::t('btn_3_months'), "v:3m:{$i}:{$direction}"]],
        [oppositeDirectionButton($direction, $routeIndex), [Translator::t('btn_main_menu'), 'm']],
    ]);
}

/**
 * The button that jumps to the OTHER direction's views, e.g. "Malta Latvia" while
 * viewing "Latvia Malta" (and vice versa). Its callback re-opens that leg's range
 * picker so the user lands on the opposite direction's date commands of the
 * SAME route pair (the index is preserved). $routeIndex is the index of the
 * pair being viewed; defaults to activeRouteIndex() when not threaded.
 */
function oppositeDirectionButton(string $direction, int $routeIndex = null): array
{
    $i       = ($routeIndex === null) ? activeRouteIndex() : $routeIndex;
    [$o, $d] = directionPair(otherDirection($direction));
    return [Translator::t('btn_route_country', ['origin' => countryLabel($o), 'destination' => countryLabel($d)]), 'd:' . $i . ':' . otherDirection($direction)];
}

/**
 * Resolve a view token into the matching [command, arg]. The direction is
 * carried in the callback data (v:<view>:<dir>) and handled by the caller.
 */
function viewToCommand(string $view): array
{
    $command = match ($view) {
        'today' => 'today',
        'week'  => 'nextweek',
        'month' => 'nextmonth',
        '3m'    => 'next3months',
        default => 'today',
    };

    return [$command, ''];
}

/** Build an inline keyboard from rows of [label, callback_data] pairs. */
function buildKeyboard(array $rows): string
{
    $keyboard = [];
    foreach ($rows as $row) {
        $buttons = [];
        foreach ($row as [$label, $data]) {
            $buttons[] = ['text' => $label, 'callback_data' => $data];
        }
        $keyboard[] = $buttons;
    }
    return json_encode(['inline_keyboard' => $keyboard]);
}

/**
 * Merge two inline-keyboard JSON strings (or nulls) into one. Used to attach
 * a view's 🎫 per-day buttons on top of the standard footer menu. Returns null
 * when both inputs are empty.
 */
function combineKeyboards(?string $a, ?string $b): ?string
{
    if ($a === null || $a === '') {
        return ($b === null || $b === '') ? null : $b;
    }
    if ($b === null || $b === '') {
        return $a;
    }

    $ka = json_decode($a, true)['inline_keyboard'] ?? [];
    $kb = json_decode($b, true)['inline_keyboard'] ?? [];
    return json_encode(['inline_keyboard' => array_merge($ka, $kb)]);
}

/**
 * Build the language selection keyboard. Each button sets the bot's
 * active language for this chat and returns to the main menu.
 */
function languageMenuKeyboard(): string
{
    return buildKeyboard([
        [[Translator::t('btn_lang_lv'), 'setlang:lv']],
        [[Translator::t('btn_lang_ru'), 'setlang:ru']],
        [[Translator::t('btn_lang_eng'), 'setlang:en']],
        [[Translator::t('btn_back'), 'm']],
    ]);
}
