<?php
/**
 * PHPUnit bootstrap.
 *
 * Loads the composer autoloader, the global Database class, and the .env file
 * (so DB + Telegram config is available) via src/bootstrap.php — the same
 * single entry point every production script uses. telegram-bot-handlers.php
 * loads automatically through Composer "files" autoload.
 *
 * Pure unit tests do not need the network or database; loading .env here is
 * harmless and lets the integration tests run in the same pass.
 */

// bootstrap.php throws if .env is missing — swallow it so a fresh clone still
// runs the pure unit tests (endpoint/integration tests will fail loudly).
try {
    require_once __DIR__ . '/../src/bootstrap.php';
} catch (\Throwable $e) {
    // No .env (e.g. fresh clone) — endpoint/integration tests will fail loudly.
}
