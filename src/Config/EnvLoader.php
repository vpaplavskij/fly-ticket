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

        if (!is_file($envPath)) {
            throw new \RuntimeException(sprintf(
                'Missing .env file at %s',
                $envPath,
            ));
        }

        foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key           = trim($key);
            $value         = trim($value);
            $value         = preg_replace('/^["\'](.*)["\']$/s', '$1', $value);

            putenv(sprintf('%s=%s', $key, $value));
            $_ENV[$key]    = $value;
            $_SERVER[$key] = $value;
        }

        self::$loaded = true;
    }
}
