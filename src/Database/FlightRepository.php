<?php

namespace FlyTicket\Database;

class FlightRepository
{
    private \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Batch upsert: all flights in a single transaction, one prepared
     * statement for every row. ~60× fewer round-trips than calling
     * upsertFlight() per flight (the fetch scripts write ~500 rows/run).
     */
    public function upsertFlights(array $flights): int
    {
        if ($flights === []) {
            return 0;
        }

        $pdo = $this->pdo;
        $sql = 'INSERT INTO flights (code, search_id, origin, destination, depart, arrive, price, company, flight_number, source, buy_link)
                VALUES (:code, NULL, :origin, :destination, :depart, :arrive, :price, :company, :flight_number, :source, :buy_link)
                ON DUPLICATE KEY UPDATE
                    depart        = VALUES(depart),
                    arrive        = VALUES(arrive),
                    price         = VALUES(price),
                    company       = VALUES(company),
                    flight_number = VALUES(flight_number),
                    buy_link      = VALUES(buy_link)';

        $stmt  = $pdo->prepare($sql);
        $count = 0;

        try {
            $pdo->beginTransaction();
            foreach ($flights as $f) {
                $stmt->execute([
                    ':code'          => $f['code'],
                    ':origin'        => $f['origin'],
                    ':destination'   => $f['destination'],
                    ':depart'        => $f['depart'],
                    ':arrive'        => $f['arrive'],
                    ':price'         => (string) $f['price'],
                    ':company'       => $f['company'],
                    ':flight_number' => $f['flight_number'],
                    ':source'        => $f['source'] ?? null,
                    ':buy_link'      => $f['buy_link'] ?? null,
                ]);
                $count++;
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $count;
    }

    /**
     * Batch history insert: one prepared statement, single transaction.
     * Idempotent — ON DUPLICATE KEY UPDATE absorbs the race where a concurrent
     * cron run inserts the same (source, origin, destination, depart_date,
     * fetched_at) within the same second.
     */
    public function recordHistoryBatch(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $pdo = $this->pdo;
        $sql = 'INSERT INTO price_history (source, origin, destination, depart_date, price, company, flight_number)
                VALUES (:source, :origin, :destination, :depart_date, :price, :company, :flight_number)
                ON DUPLICATE KEY UPDATE
                    price         = VALUES(price),
                    company       = VALUES(company),
                    flight_number = VALUES(flight_number)';

        $stmt  = $pdo->prepare($sql);
        $count = 0;

        try {
            $pdo->beginTransaction();
            foreach ($rows as $f) {
                $stmt->execute([
                    ':source'        => $f['source'] ?? '',
                    ':origin'        => $f['origin'],
                    ':destination'   => $f['destination'],
                    ':depart_date'   => substr($f['depart'], 0, 10),
                    ':price'         => (string) $f['price'],
                    ':company'       => $f['company'],
                    ':flight_number' => $f['flight_number'] ?? null,
                ]);
                $count++;
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $count;
    }

    /**
     * Count the distinct flight days on file for a route (any price). Cheap —
     * one COUNT(DISTINCT DATE(depart)) over the idx_route_date range — used by
     * `index.php status` instead of pulling every row.
     */
    public function countFlightDays(string $origin, string $destination, string $dateFrom, string $dateTo): int
    {
        $sql = 'SELECT COUNT(DISTINCT DATE(depart))
                 FROM flights
                 WHERE origin = :o AND destination = :d
                   AND depart >= :from AND depart < :to';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':o'    => $origin,
            ':d'    => $destination,
            ':from' => $dateFrom . ' 00:00:00',
            ':to'   => date('Y-m-d H:i:s', strtotime($dateTo . ' +1 day')),
        ]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Get all flights for a specific date (today's command).
     */
    public function getFlightsForDay(string $origin, string $destination, string $date): array
    {
        $sql = 'SELECT code, origin, destination, depart, arrive, price, company, flight_number, source, buy_link
                FROM flights
                WHERE origin = :o AND destination = :d AND DATE(depart) = :date
                ORDER BY price ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':o' => $origin, ':d' => $destination, ':date' => $date]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Cheapest $limit distinct companies per day for a date range, across one
     * or more origin airports.
     *
     * Returns one entry per (day, company) — the cheapest row for that company
     * on that day — so callers can list each operator's lowest fare side by
     * side. Rows are grouped by date (ascending), then sorted cheapest-first
     * within each day. Each row carries its `origin` so callers can tell which
     * airport a flight departs from (needed when alternative origins like VNO
     * or KUN are mixed in).
     *
     * @param string|string[] $origins One origin, or several (e.g. the primary
     *                                  route's origin plus its alternatives).
     * @return array<int, array{origin:string, date:string, price:float, source:?string, company:?string,
     *                           flight_number:?string, buy_link:?string, depart:?string, arrive:?string}>
     */
    public function getTopPerDay(string|array $origins, string $destination, string $dateFrom, string $dateTo, int $limit = 3): array
    {
        if (is_string($origins)) {
            $origins = [$origins];
        }

        // Build "IN (:o0, :o1, ...)" placeholders per origin. Two separate
        // placeholder sets (:o* for the subquery, :O* for the outer WHERE) so
        // PDO named parameters are never reused — MySQL/PDO forbid reusing a
        // named parameter in one statement.
        $oIn  = [];
        $OIn  = [];
        $params = [
            ':d1'    => $destination,
            ':from1' => $dateFrom,
            ':to1'   => $dateTo,
            ':d2'    => $destination,
            ':from2' => $dateFrom,
            ':to2'   => $dateTo,
        ];
        foreach ($origins as $i => $origin) {
            $oIn[]  = ":o{$i}";
            $OIn[]  = ":O{$i}";
            $params[":o{$i}"] = $origin;
            $params[":O{$i}"] = $origin;
        }

        // Cheapest row per (day, company) — the subquery keeps this valid under
        // MySQL's ONLY_FULL_GROUP_BY mode. Company is COALESCE'd to '' so a NULL
        // company never groups away (legacy rows carry NULL source/company). The
        // group key is aliased `dd` (not `depart_date`) to avoid colliding with
        // the outer alias in the SELECT list — MySQL would otherwise resolve
        // `d.depart_date` to the raw column and return NULL.
        $sql = 'SELECT f.origin, f.company, f.source, f.flight_number, f.buy_link, f.depart, f.arrive, d.dd AS depart_date, d.price
                  FROM flights f
                  JOIN (
                      SELECT DATE(depart) AS dd, COALESCE(company, \'\') AS company, MIN(price) AS price
                      FROM flights
                      WHERE origin IN (' . implode(', ', $oIn) . ') AND destination = :d1
                        AND DATE(depart) >= :from1 AND DATE(depart) <= :to1
                      GROUP BY dd, company
                  ) d ON d.dd = DATE(f.depart)
                     AND COALESCE(f.company, \'\') = d.company
                     AND f.price = d.price
                  WHERE f.origin IN (' . implode(', ', $OIn) . ') AND f.destination = :d2
                    AND DATE(f.depart) >= :from2 AND DATE(f.depart) <= :to2';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        // Keep the top-$limit cheapest company per day (a day with fewer
        // distinct companies simply yields fewer rows).
        $byDate = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $date = $row['depart_date'];
            if (!isset($byDate[$date]) || count($byDate[$date]) < $limit) {
                $byDate[$date][] = [
                    'origin'        => $row['origin'],
                    'date'          => $date,
                    'price'         => (float) $row['price'],
                    'source'        => $row['source'],
                    'company'       => $row['company'] !== '' ? $row['company'] : null,
                    'flight_number' => $row['flight_number'],
                    'buy_link'      => $row['buy_link'],
                    'depart'        => $row['depart'],
                    'arrive'        => $row['arrive'],
                ];
            }
        }

        // The subquery is not ordered, so sort each day's rows by price and the
        // days themselves chronologically. Return a FLAT list of row arrays (each
        // carrying its `date`), cheapest-first within a day, days in ascending
        // order — the shape every consumer (buildToday, buildNext,
        // buildNext) expects when it accesses $row['price'], $row['depart'], etc.
        foreach ($byDate as $date => $rows) {
            usort($rows, fn($a, $b) => $a['price'] <=> $b['price']);
            $byDate[$date] = $rows;
        }
        ksort($byDate);

        $flat = [];
        foreach ($byDate as $rows) {
            foreach ($rows as $row) {
                $flat[] = $row;
            }
        }
        return $flat;
    }
}
