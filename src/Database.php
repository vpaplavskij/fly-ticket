<?php

class Database
{
    private static ?\PDO $instance = null;

    private function __construct() {}

    private function __clone() {}

    /**
     * Drop price_history rows older than $days days. Called ONCE per fetch
     * cycle from jobs.php (after the fetch children finish), so it never races
     * with the writers the way the old per-script call did.
     */
    public static function prunePriceHistory(int $days = 90): int
    {
        $pdo     = self::getConnection();
        $cutoff  = date('Y-m-d', strtotime("-{$days} days"));
        $deleted = (int) $pdo->exec(
            'DELETE FROM price_history WHERE fetched_at < ' . $pdo->quote($cutoff)
        );

        return $deleted;
    }

    /**
     * Drop the cached PDO connection. The long-running bot loop calls this when
     * PostgreSQL drops the connection (server restart, backend killed, SQLSTATE
     * 08000/57014); the next getConnection() opens a fresh one.
     */
    public static function resetConnection(): void
    {
        self::$instance = null;
    }

    public static function getConnection(): \PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        \Config\Database::validateDatabase();

        $config = \Config\Database::get_all();

        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            $config['host'],
            $config['port'],
            $config['dbname'],
        );

        try {
            self::$instance = new \PDO(
                $dsn,
                $config['username'],
                $config['password'],
                $config['options'] ?? [],
            );
        } catch (\PDOException $e) {
            throw new \RuntimeException(sprintf(
                'PostgreSQL connection failed (%s): %s',
                $e->getCode(),
                $e->getMessage(),
            ), 0, $e);
        }

        return self::$instance;
    }
}
