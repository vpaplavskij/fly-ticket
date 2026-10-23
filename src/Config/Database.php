<?php

namespace Config;

final class Database
{
    public static function __callStatic(string $name, array $arguments): string
    {
        \Config\EnvLoader::loadEnv();
        $env_key = strtoupper($name);
        $value   = getenv($env_key);

        if ($value === false || $value === '') {
            throw new \RuntimeException(sprintf(
                'Missing required database env var: %s (expected in .env at project root)',
                $env_key,
            ));
        }

        return $value;
    }

    public static function get_all(): array
    {
        return [
            'host' => self::DB_HOST(),
            'dbname' => self::DB_NAME(),
            'username' => self::DB_USERNAME(),
            'password' => self::DB_PASSWORD(),
            'port' => (int) (getenv('DB_PORT') ?: 3306),
            'charset' => getenv('DB_CHARSET') ?: 'utf8mb4',
            'options' => [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ],
        ];
    }

    public static function validateDatabase(): void
    {
        \Config\EnvLoader::loadEnv();
        $required = ['DB_HOST', 'DB_NAME', 'DB_USERNAME', 'DB_PASSWORD'];
        $missing  = [];

        foreach ($required as $key) {
            $value = getenv($key);
            if ($value === false || $value === '') {
                $missing[] = $key;
            }
        }

        if ($missing !== []) {
            throw new \RuntimeException(sprintf(
                'Missing required database env var(s): %s',
                implode(', ', $missing),
            ));
        }
    }
}
