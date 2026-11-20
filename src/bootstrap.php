<?php

/**
 * Application bootstrap — the single line every entry script requires.
 *
 * Loads, in order:
 *   1. Composer autoloader (PSR-4 classes + files autoload, e.g. telegram-bot-handlers.php)
 *   2. The global Database class (src/Database.php — deliberately namespace-free,
 *      so it is NOT PSR-4 autoloadable and must be required explicitly)
 *   3. The .env file (idempotent — safe to call from anywhere)
 *
 * Entry scripts should be:
 *
 *     require_once __DIR__ . '/../../bootstrap.php';   // path depends on depth
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/Database.php';

\Config\EnvLoader::loadEnv();

// All dates (flight days, "today", retention cutoffs) follow one explicit zone
// instead of whatever the server happens to use. The app's routes are RIX-based,
// so Europe/Riga is the default; set APP_TZ in .env to override.
date_default_timezone_set(getenv('APP_TZ') ?: 'Europe/Riga');

// Long-running bot pollers and fetch children die silently on a fatal error
// (uncaught throw is handled elsewhere; E_ERROR/E_PARSE is not). Log the last
// error so a crash leaves a trace in the app log.
register_shutdown_function(function (): void {
    $err = error_get_last();
    if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        \FlyTicket\Support\Logger::error('Fatal error', [
            'message' => $err['message'],
            'at'      => $err['file'] . ':' . $err['line'],
        ]);
    }
});
