<?php

/**
 * Fly-Ticket — application entry point.
 *
 * The single front door to the app. Validates configuration and the database
 * connection, prints a short status line, then dispatches a job:
 *
 *   php src/index.php                 Show help
 *   php src/index.php status          Check config + DB connectivity and exit
 *   php src/index.php fetch           Fetch latest prices (Ryanair + AirBaltic)
 *   php src/index.php report <bot> <chat> [cmd]   Send a report via one bot
 *   php src/index.php bot [name]      Run the Telegram bot poller(s) (long-running)
 *
 * `fetch`, `report` and `bot` delegate to jobs.php so all orchestration lives
 * in one place; this file just adds a validated, self-describing entry point.
 */

require_once __DIR__ . '/bootstrap.php';

use Config\EnvLoader;
use Config\Route;
use FlyTicket\Database\FlightRepository;
use FlyTicket\Database\Schema;
use FlyTicket\Support\Logger;

/** Mask the middle of a secret for display. */
function mask(string $value): string
{
    $len = strlen($value);
    if ($len <= 8) {
        return str_repeat('*', $len);
    }
    return substr($value, 0, 4) . str_repeat('*', $len - 8) . substr($value, -4);
}

/** Validate config + DB and print a short status line. Returns the PDO. */
function bootstrap(): \PDO
{
    EnvLoader::loadEnv();
    Logger::registerHandlers();

    \Config\Database::validateDatabase();
    $pdo = Database::getConnection();
    Schema::ensureTables($pdo);

    $config   = \Config\Database::get_all();
    $repo     = new FlightRepository($pdo);
    $dateFrom = '2000-01-01';
    $dateTo   = date('Y-m-d', strtotime('+365 days'));

    // Count distinct flight days across BOTH directions so the status line
    // reflects everything on file, not just one direction. A single
    // COUNT(DISTINCT DATE(depart)) per route — not a full getCheapestPerDay()
    // pull of every row — keeps `status` cheap to run.
    $dayCount = 0;
    foreach (Route::both() as $route) {
        $dayCount += $repo->countFlightDays($route['origin'], $route['destination'], $dateFrom, $dateTo);
    }

    printf(
        "Fly-Ticket  ·  %s  ·  db=%s:%d/%s  ·  %d flight day(s) on file\n",
        Route::label(),
        $config['host'],
        $config['port'],
        $config['dbname'],
        $dayCount,
    );

    return $pdo;
}

function runJob(string $job, array $argv): int
{
    if ($job === 'status') {
        bootstrap();
        echo "OK\n";
        return 0;
    }

    $args = array_slice($argv, 2);
    $cmd  = escapeshellarg('php') . ' ' . escapeshellarg(__DIR__ . '/jobs.php') . ' ' . escapeshellarg($job);
    foreach ($args as $a) {
        $cmd .= ' ' . escapeshellarg($a);
    }

    passthru($cmd, $code);
    return $code;
}

function help(): void
{
    $title = 'Fly-Ticket — flight price monitor (' . Route::label() . ')';
    echo <<<TXT
{$title}

Usage: php src/index.php <command> [args]

Commands:
  status                  Check config + DB connectivity and exit
  fetch                   Fetch latest prices from Ryanair + AirBaltic
  report <bot> <chat_id> [cmd] [arg]
                          Send a price report via one bot
                          (today | nextweek | nextmonth | next3months | return)
  report                  Send a report to every configured bot (fan-out)
  bot [name]              Run the Telegram poller(s). No name = all bots.
  help                    Show this message

Bots are configured in .env (one TELEGRAM_<NAME>_TOKEN line per bot).

TXT;
}

$job = $argv[1] ?? 'help';

try {
    switch ($job) {
        case 'status':
        case 'fetch':
        case 'report':
        case 'bot':
            exit(runJob($job, $argv));
        case 'help':
        default:
            help();
            exit(0);
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    Logger::error('index.php failed', ['exception' => $e->getMessage()]);
    exit(1);
}
