<?php

namespace FlyTicket\Support;

/**
 * Minimal file logger — no dependencies.
 *
 * Writes timestamped, level-tagged lines to a log file (default: storage/logs/app.log).
 * Safe for concurrent use: each line is a single fwrite() call, which is atomic
 * enough for this workload on Linux.
 *
 * Usage:
 *   Logger::info('Bot started');
 *   Logger::error('Fetch failed', ['source' => 'ryanair', 'exception' => $e->getMessage()]);
 */
class Logger
{
    public const DEBUG = 'DEBUG';
    public const INFO  = 'INFO';
    public const WARN  = 'WARN';
    public const ERROR = 'ERROR';

    private static ?string $dir = null;

    private static function dir(): string
    {
        if (self::$dir === null) {
            self::$dir = getenv('LOG_DIR') ?: dirname(__DIR__, 2) . '/storage/logs';
            if (!is_dir(self::$dir)) {
                mkdir(self::$dir, 0755, true);
            }
        }
        return self::$dir;
    }

    public static function log(string $level, string $message, array $context = []): void
    {
        $line = sprintf(
            "[%s] %s: %s%s\n",
            date('Y-m-d H:i:s'),
            $level,
            $message,
            $context !== [] ? ' ' . self::formatContext($context) : '',
        );

        // Daily rotation: one file per day, plus a "current" symlink-free name.
        $file = self::dir() . '/app-' . date('Y-m-d') . '.log';
        file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    public static function debug(string $message, array $context = []): void
    {
        self::log(self::DEBUG, $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::log(self::INFO, $message, $context);
    }

    public static function warn(string $message, array $context = []): void
    {
        self::log(self::WARN, $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::log(self::ERROR, $message, $context);
    }

    public static function registerHandlers(): void
    {
        set_exception_handler(function (\Throwable $e): void {
            self::error('Uncaught exception: ' . $e->getMessage(), [
                'class' => get_class($e),
                'at'    => $e->getFile() . ':' . $e->getLine(),
                'trace' => implode("\n", array_slice(explode("\n", $e->getTraceAsString()), 0, 5)),
            ]);
            // Rethrow so callers (e.g. the bot loop) can decide whether to continue.
            throw $e;
        });

        set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
            // Respect error_reporting: don't log suppressed errors (@ operator).
            if (!(error_reporting() & $severity)) {
                return true;
            }
            self::warn('PHP ' . self::severityName($severity) . ': ' . $message, ['at' => "{$file}:{$line}"]);
            return true;
        });
    }

    private static function formatContext(array $context): string
    {
        $parts = [];
        foreach ($context as $k => $v) {
            if (is_scalar($v)) {
                $parts[] = "{$k}=" . $v;
            } else {
                $parts[] = "{$k}=" . json_encode($v);
            }
        }
        return implode(' ', $parts);
    }

    private static function severityName(int $severity): string
    {
        return match ($severity) {
            E_DEPRECATED, E_USER_DEPRECATED                               => 'DEPRECATED',
            E_NOTICE, E_USER_NOTICE                                       => 'NOTICE',
            E_WARNING, E_USER_WARNING                                     => 'WARNING',
            E_ERROR, E_USER_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR => 'FATAL',
            default                                                       => 'ERROR',
        };
    }
}
