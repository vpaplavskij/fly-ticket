<?php

/**
 * Cron entrypoint — one file to point the crontab at.
 *
 * Usage:
 *   php src/jobs.php fetch                          Run all fetches (Ryanair, AirBaltic Malta, AirBaltic Cyprus)
 *   php src/jobs.php bot                            Start a poller for every configured bot (long-running)
 *   php src/jobs.php bot <bot_name>                Start the poller for ONE bot (long-running)
 *   php src/jobs.php report <bot_name> <chat_id> [cmd] [arg]
 *                                                 Send a price report via one specific bot
 *   php src/jobs.php report                         Report to every configured bot (each gets its own chat)
 *   php src/jobs.php help                           Show available jobs
 *
 * Crontab (server):
 *   0 6,11,16,20 * * *  cd /path/to/fly-ticket && php src/jobs.php fetch >> storage/logs/cron.log 2>&1
 *   * * * * *           cd /path/to/fly-ticket && php src/jobs.php bot        (watchdog: runs all bots)
 */

namespace FlyTicket;

require_once __DIR__ . '/bootstrap.php';

use Config\BotRegistry;
use Config\EnvLoader;
use FlyTicket\Support\Logger;

EnvLoader::loadEnv();
Logger::registerHandlers();

$job       = $argv[1] ?? 'help';
$startedAt = microtime(true);

switch ($job) {
    case 'fetch':
        runFetchJob();
        break;

    case 'report':
        runReportJob($argv);
        break;

    case 'bot':
        runBotJob($argv);
        break;

    case 'help':
    default:
        echo "Usage: php src/jobs.php <job>\n\n";
        echo "Jobs:\n";
        echo "  fetch                          Fetch latest prices (Ryanair, AirBaltic Malta, AirBaltic Cyprus)\n";
        echo "  bot [bot_name]                Run the Telegram poller(s). With no name, starts one process per\n";
        echo "                                configured bot (used by the watchdog). With a name, just that bot.\n";
        echo "  report <bot> <chat_id> [cmd]  Send a price report via ONE bot (today|nextweek|nextmonth|next3months)\n";
        echo "  report                         Send a report to EVERY configured bot (each gets its own chat)\n";
        echo "  help                           Show this message\n";
        echo "\nConfigured bots: " . (implode(', ', BotRegistry::names()) ?: '(none)') . "\n";
        $exitCode = 0;
}

Logger::info('Job complete', ['job' => $job, 'elapsed_s' => round(microtime(true) - $startedAt, 1)]);
exit($exitCode ?? 0);

// ── Job implementations ────────────────────────────────────────────────

/**
 * `fetch` job. Runs all source fetchers CONCURRENTLY.
 *
 * Each script is an independent sub-process that connects to the DB, runs
 * Schema::ensureTables(), and writes only its own route rows — so they never
 * collide. Running them in parallel (instead of one after another) cuts the
 * cron cycle from "sum of all sources" to "the slowest single source". Each
 * script's stdout is captured to a temp file and echoed once it finishes, in
 * completion order.
 */
function runFetchJob(): void
{
    Logger::info('Job: fetch starting (concurrent)');

    $sources = [
        'Ryanair'       => __DIR__ . '/Api/Ryanair/ryanair-api.php',
        'AirBaltic'     => __DIR__ . '/Api/Airbaltic/airbaltic-api.php',
        'AirBalticCyprus' => __DIR__ . '/Api/Airbaltic/airbaltic-cyprus-api.php',
    ];

    // Spawn every source, redirecting its output to a per-source temp file.
    $procs = [];
    foreach ($sources as $name => $script) {
        $outFile = tempnam(sys_get_temp_dir(), 'flyticket-fetch-');
        $cmd     = escapeshellarg('php') . ' ' . escapeshellarg($script);
        $spec    = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $outFile, 'w'],
            2 => ['file', $outFile, 'a'],
        ];
        $proc = proc_open($cmd, $spec, $pipes);
        if (!is_resource($proc)) {
            Logger::error('Failed to spawn fetch script', ['source' => $name]);
            $procs[$name] = ['proc' => null, 'out' => $outFile, 'code' => 1];
            continue;
        }
        $procs[$name] = ['proc' => $proc, 'out' => $outFile, 'code' => null];
    }

    // Wait for all of them (in any order) and report as each finishes.
    $exitCode = 0;
    foreach ($procs as $name => $p) {
        if ($p['proc'] === null) {
            continue;
        }
        $code = proc_close($p['proc']); // blocks until that child exits
        $p['code'] = $code;

        if (is_file($p['out'])) {
            foreach (file($p['out'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                echo "  [{$name}] {$line}\n";
            }
            @unlink($p['out']);
        }

        if ($code !== 0) {
            Logger::warn('Source fetch exited non-zero', ['source' => $name, 'exit_code' => $code]);
            $exitCode = $code;
        } else {
            Logger::info('Source fetch OK', ['source' => $name]);
        }
    }

    exit($exitCode);
}

/**
 * `report` job.
 *
 *   report <bot_name> <chat_id> [cmd] [arg]  → send via one specific bot
 *   report                                    → fan out to every configured bot
 */
function runReportJob(array $argv): void
{
    $bots = BotRegistry::all();
    if (empty($bots)) {
        fwrite(STDERR, "No bots configured. Add TELEGRAM_<NAME>_TOKEN lines to .env\n");
        exit(1);
    }

    // Form 1: explicit bot + chat.
    if (isset($argv[2]) && $argv[2] !== '') {
        $bot = BotRegistry::get($argv[2]);
        if ($bot === null) {
            fwrite(STDERR, "Unknown bot '{$argv[2]}'. Configured bots: " . implode(', ', BotRegistry::names()) . "\n");
            exit(1);
        }
        $chatId = $argv[3] ?? null;
        if ($chatId === null || $chatId === '') {
            fwrite(STDERR, "Usage: php src/jobs.php report <bot_name> <chat_id> [today|nextweek|nextmonth|next3months|return] [arg]\n");
            exit(1);
        }
        $command = $argv[4] ?? 'today';
        $arg     = $argv[5] ?? '';
        Logger::info('Job: report (single bot) starting', ['bot' => $bot->name, 'chat_id' => $chatId, 'command' => $command, 'arg' => $arg]);
        passthru(
            escapeshellarg('php') . ' ' . escapeshellarg(__DIR__ . '/Api/Telegram/send-report.php')
            . ' ' . escapeshellarg($bot->name) . ' ' . escapeshellarg($chatId) . ' ' . escapeshellarg($command) . ' ' . escapeshellarg($arg),
            $code
        );
        exit($code);
    }

    // Form 2: fan out to every bot. Each bot's chat ID comes from its own
    // TELEGRAM_<NAME>_CHAT_ID env var; if that is unset the bot falls back to
    // the shared test chat (see below).
    Logger::info('Job: report (all bots) starting', ['bots' => BotRegistry::names()]);
    $exitCode = 0;
    foreach ($bots as $bot) {
        $chatVar = 'TELEGRAM_' . strtoupper($bot->name) . '_CHAT_ID';
        $chatId  = $_ENV[$chatVar] ?? null;
        if ($chatId === null || $chatId === '') {
            $chatId = '833934506'; // fallback: shared test group (set TELEGRAM_<NAME>_CHAT_ID in .env to override)
            Logger::warn('No chat ID for bot, using shared test chat', ['bot' => $bot->name, 'var' => $chatVar]);
        }
        $command = 'nextweek';
        $arg     = '';
        Logger::info('Report fan-out', ['bot' => $bot->name, 'chat_id' => $chatId]);
        passthru(
            escapeshellarg('php') . ' ' . escapeshellarg(__DIR__ . '/Api/Telegram/send-report.php')
            . ' ' . escapeshellarg($bot->name) . ' ' . escapeshellarg($chatId) . ' ' . escapeshellarg($command) . ' ' . escapeshellarg($arg),
            $code
        );
        if ($code !== 0) {
            Logger::warn('Report fan-out failed', ['bot' => $bot->name, 'exit_code' => $code]);
            $exitCode = $code;
        }
    }
    exit($exitCode);
}

/**
 * `bot` job. Starts a long-running poller for one or every configured bot.
 *
 *   bot <bot_name>  → run just that bot's loop in this process
 *   bot             → spawn a child process per bot, then wait on all of them
 */
function runBotJob(array $argv): void
{
    $bots = BotRegistry::all();
    if (empty($bots)) {
        fwrite(STDERR, "No bots configured. Add TELEGRAM_<NAME>_TOKEN lines to .env\n");
        exit(1);
    }

    // Single bot: run in-process.
    $name = isset($argv[2]) ? strtolower($argv[2]) : null;
    if ($name !== null) {
        Logger::info('Job: bot starting (single)', ['bot' => $name]);
        passthru(
            escapeshellarg('php') . ' ' . escapeshellarg(__DIR__ . '/Api/Telegram/telegram-bot.php') . ' ' . escapeshellarg($name),
            $code
        );
        exit($code);
    }

    // All bots: spawn one child process per bot, then wait for all of them so
    // the watchdog's `nohup php src/jobs.php bot &` keeps every bot alive and
    // killing the jobs.php process tears down all children.
    Logger::info('Job: bot starting (all)', ['bots' => BotRegistry::names()]);

    $specs = [];
    foreach ($bots as $bot) {
        $cmd = escapeshellarg('php') . ' ' . escapeshellarg(__DIR__ . '/Api/Telegram/telegram-bot.php') . ' ' . escapeshellarg($bot->name);
        Logger::info('Spawning bot poller', ['bot' => $bot->name]);
        // stdout/stderr inherit the parent's (cron redirects them to bot.log).
        $specs[$bot->name] = proc_open($cmd, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    }

    // Wait for every child. Each poller runs forever, so this blocks until the
    // whole group is killed (Ctrl-C / watchdog restart).
    foreach ($specs as $botName => $proc) {
        if (!is_resource($proc)) {
            Logger::error('Failed to spawn bot poller', ['bot' => $botName]);
            continue;
        }
        proc_close($proc); // waits for the child to exit, then releases the resource
    }
}
