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
