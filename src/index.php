<?php

/**
 * Fly-Ticket — application entry point.
 *
 * The single front door to the app. Validates configuration and the database
 * connection, prints a short status line, then dispatches a job:
 *
 *   php src/index.php                 Show help
 *   php src/index.php migrate         Create/upgrade the DB schema (once after install)
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

/** Validate config + DB and print a short status line. Returns the PDO. */
function bootstrap(): \PDO
{
    EnvLoader::loadEnv();
    Logger::registerHandlers();

    \Config\Database::validateDatabase();
    $pdo = Database::getConnection();

    $config   = \Config\Database::get_all();
    $repo     = new FlightRepository($pdo);
    $dateFrom = '2000-01-01';
    $dateTo   = date('Y-m-d', strtotime('+365 days'));

    // Count distinct flight days across BOTH directions so the status line
    // reflects everything on file, not just one direction. A single
    // COUNT(DISTINCT depart::date) per route — not a full getCheapestPerDay()
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
    $cmd  = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/jobs.php') . ' ' . escapeshellarg($job);
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
  migrate               Create/upgrade the DB schema (run once after install)
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
        case 'migrate':
            EnvLoader::loadEnv();
            Logger::registerHandlers();
            \Config\Database::validateDatabase();
            $pdo = Database::getConnection();
            Schema::ensureTables($pdo);
            // Legacy MySQL-era leftovers that nothing ever read; not in the DDL anymore.
            $pdo->exec('DROP TABLE IF EXISTS searches');
            $pdo->exec('ALTER TABLE flights DROP COLUMN IF EXISTS search_id');
            echo "Schema ready\n";
            exit(0);
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
