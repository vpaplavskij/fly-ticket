<?php

namespace FlyTicket\Api\Telegram;

use FlyTicket\Database\FlightRepository;

/**
 * One-shot render mode for the Telegram bot.
 *
 * Prints command output to stdout and exits — used for local testing without
 * a bot token or the long-polling loop. Loaded automatically via Composer
 * "files" autoload (see composer.json), so no script needs to require it.
 *
 * The entry point is renderCommandsForBot(), called from runBotLoop() in
 * telegram-bot.php when RENDER_COMMANDS_ONLY is defined. It renders every
 * route pair the bot offers (primary first, then extras) so a multi-route
 * bot's output covers all its legs. The active route index is per-process
 * state used by text-command dispatch — set before each pair so /help-style
 * commands resolve the right leg.
 */
function renderCommandsForBot(\Config\Bot $bot): void
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = \Database::getConnection();
    }
    $repo     = new FlightRepository($pdo);
    $commands = defined('RENDER_COMMANDS') ? array_map('strtolower', RENDER_COMMANDS) : ['today', 'nextweek'];

    $routes = $bot->allRoutes();
    foreach (array_keys($routes) as $routeIndex) {
        [$ro, $rd] = $routes[$routeIndex];
        setActiveRouteIndex((int) $routeIndex);
        echo "── {$ro} ⇄ {$rd} ──\n";
        foreach ($commands as $command) {
            echo truncateReply(dispatchCommand($repo, $ro, $rd, $command)) . "\n\n";
        }
    }
}
