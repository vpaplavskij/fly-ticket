<?php

namespace FlyTicket\Api\Telegram;

require_once __DIR__ . '/../../bootstrap.php';

use Config\BotRegistry;
use FlyTicket\Database\FlightRepository;
use FlyTicket\Database\Schema;

/**
 * Send a flight-price report to a Telegram chat via a specific bot.
 *
 * Usage: php src/Api/Telegram/send-report.php <bot_name> <chat_id> [command] [arg]
 *
 * Commands:
 *   today         — cheapest flights for today (default)
 *   nextweek      — cheapest flights over the next 7 days
 *   nextmonth     — cheapest flights over the next 30 days
 *   next3months   — cheapest flights over the next 90 days
 */

$botName = strtolower($argv[1] ?? '');
$chatId  = $argv[2] ?? null;
$command = strtolower($argv[3] ?? 'today');
$arg     = strtolower($argv[4] ?? '');

if ($botName === '' || $chatId === null || $chatId === '') {
    fwrite(STDERR, "Usage: php send-report.php <bot_name> <chat_id> [today|nextweek|nextmonth|next3months]\n");
    fwrite(STDERR, 'Configured bots: ' . (implode(', ', BotRegistry::names()) ?: '(none)') . "\n");
    exit(1);
}

$bot = BotRegistry::get($botName);
if ($bot === null) {
    fwrite(STDERR, "Unknown bot '{$botName}'. Configured bots: " . (implode(', ', BotRegistry::names()) ?: '(none)') . "\n");
    exit(1);
}

// ── Setup ──────────────────────────────────────────────────────────
$pdo = \Database::getConnection();
Schema::ensureTables($pdo);
$repo = new FlightRepository($pdo);

// This bot's primary monitored route (its TELEGRAM_<NAME>_ROUTE override when
// configured, otherwise the global Config\Route default).
[$origin, $destination] = $bot->originDestination();

// The shared command handlers + dispatcher (telegram-bot-handlers.php) are
// loaded automatically via Composer "files" autoload (see composer.json).

// Use the chat's stored language if available, otherwise default to English.
$users = new \FlyTicket\Database\UserRepository($pdo);
$lang  = $users->getLang($chatId) ?? 'en';
Translator::set(Translator::normalize($lang));

$reply = truncateReply(dispatchCommand($repo, $origin, $destination, $command, $arg));

$telegram = new TelegramClient($bot->token);
$result   = $telegram->sendMessage($chatId, $reply);

if (!($result['ok'] ?? false)) {
    fwrite(STDERR, "Telegram send failed: " . json_encode($result) . "\n");
    exit(1);
}

printf("[%s] Sent '%s' report to chat %s\n", $bot->name, $command, $chatId);
