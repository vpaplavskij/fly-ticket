<?php

namespace FlyTicket\Api\Telegram;

require_once __DIR__ . '/../../bootstrap.php';

use Config\BotRegistry;

// ── Setup ──────────────────────────────────────────────────────────
define('RENDER_COMMANDS_ONLY', true);
define('RENDER_COMMANDS', ['today', 'nextweek', 'nextmonth']);

// Render mode uses the named bot's configured route(s) — its primary route
// plus any TELEGRAM_<NAME>_EXTRA_ROUTES — resolved from BotRegistry. The bot
// name is also required by telegram-bot.php's standalone entry point, so we
// set it here. (Default "flycheap" when no name is given.)
$botName = $argv[1] ?? 'flycheap';
if (!in_array($botName, BotRegistry::names(), true)) {
    fwrite(STDERR, "Unknown bot '{$botName}'. Configured: " . implode(', ', BotRegistry::names()) . "\n");
    exit(1);
}
// Make activeRoute() / routeLabel() resolve the right pair in render mode.
$GLOBALS['argv'] = array_merge($GLOBALS['argv'], [$botName]);

$pdo = \Database::getConnection();

// Pull in runBotLoop() from telegram-bot.php. Its render-mode branch calls
// renderCommandsForBot() (Composer "files" autoloaded, see composer.json) and
// exits before returning — so this require never enters the poll loop and
// never needs a bot token. The shared handler functions (handleToday, ...)
// are already loaded via Composer "files" autoload.
require __DIR__ . '/telegram-bot.php';
runBotLoop($botName);
