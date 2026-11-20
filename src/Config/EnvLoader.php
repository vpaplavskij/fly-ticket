<?php

namespace Config;

final class EnvLoader
{
    private static bool $loaded = false;

    public static function loadEnv(?string $envPath = null): void
    {
        if (self::$loaded) {
            return;
        }

        $envPath = $envPath ?: (dirname(__DIR__, 2) . '/.env');

        // A missing .env is a valid deployment (Docker/systemd inject the real
        // environment). Nothing to load — proceed with the process env.
        if (!is_file($envPath)) {
            self::$loaded = true;
            return;
        }

        foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue; // blank, comment, or a malformed line without '='
            }

            [$key, $value] = explode('=', $line, 2);
            $key           = trim($key);
            $value         = trim($value);
            $value         = preg_replace('/^["\'](.*)["\']$/s', '$1', $value);

            // Real environment wins: a var already set by the process (shell,
            // systemd, Docker, phpunit) must not be overwritten by .env.
            if (getenv($key) !== false) {
                continue;
            }

            putenv(sprintf('%s=%s', $key, $value));
            $_ENV[$key]    = $value;
            $_SERVER[$key] = $value;
        }

        self::$loaded = true;
    }
}
