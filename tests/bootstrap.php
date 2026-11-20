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

require_once __DIR__ . '/../src/bootstrap.php';
// EnvLoader no longer throws when .env is missing (env-only deployments), so a
// fresh clone still runs the pure unit tests; integration tests fail loudly.
