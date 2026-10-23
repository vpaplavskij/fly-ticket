

<?php

class Database
{
    private static ?\PDO $instance = null;

    private function __construct() {}

    private function __clone() {}

    /**
     * Drop price_history rows older than $days days, at most once per day.
     * All four fetch scripts call this every 15 minutes (96×/day); the marker
     * file makes the DELETE run only once a day instead of 384 times.
     */
    public static function prunePriceHistory(int $days = 90): int
    {
        $marker = sys_get_temp_dir() . '/fly-ticket-history-cleanup';

        if (is_file($marker) && filemtime($marker) > time() - 86400) {
            return 0; // already cleaned up today
        }

        $pdo    = self::getConnection();
        $cutoff = date('Y-m-d', strtotime("-{$days} days"));
        $deleted = (int) $pdo->exec(
            'DELETE FROM price_history WHERE fetched_at < ' . $pdo->quote($cutoff)
        );

        @file_put_contents($marker, date('c')); // best-effort; a failed write just means the DELETE re-runs next time

        return $deleted;
    }

    public static function getConnection(): \PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        \Config\Database::validateDatabase();

        $config = \Config\Database::get_all();

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'],
            $config['port'],
            $config['dbname'],
            $config['charset'],
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
                'MySQL connection failed (%s): %s',
                $e->getCode(),
                $e->getMessage(),
            ), 0, $e);
        }

        return self::$instance;
    }
}
