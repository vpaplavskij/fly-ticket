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
     *
     * updated_at is set explicitly — PostgreSQL has no ON UPDATE, so an
     * auto-bump would never fire and unchanged prices would look "stale".
     */
    public function upsertFlights(array $flights): int
    {
        if ($flights === []) {
            return 0;
        }

        $pdo = $this->pdo;
        $sql = 'INSERT INTO flights (code, origin, destination, depart, arrive, price, company, flight_number, source, buy_link, estimated)
                VALUES (:code, :origin, :destination, :depart, :arrive, :price, :company, :flight_number, :source, :buy_link, :estimated)
                ON CONFLICT (code) DO UPDATE SET
                    depart        = EXCLUDED.depart,
                    arrive        = EXCLUDED.arrive,
                    price         = EXCLUDED.price,
                    company       = EXCLUDED.company,
                    flight_number = EXCLUDED.flight_number,
                    buy_link      = EXCLUDED.buy_link,
                    estimated     = EXCLUDED.estimated,
                    updated_at    = now()';

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
                    ':estimated'     => !empty($f['estimated']) ? 1 : 0,
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
     * Idempotent — ON CONFLICT DO UPDATE absorbs the race where a concurrent
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
                ON CONFLICT (source, origin, destination, depart_date, fetched_at) DO UPDATE SET
                    price         = EXCLUDED.price,
                    company       = EXCLUDED.company,
                    flight_number = EXCLUDED.flight_number';

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
     * one COUNT(DISTINCT depart::date) over the idx_route_date range — used by
     * `index.php status` instead of pulling every row.
     */
    public function countFlightDays(string $origin, string $destination, string $dateFrom, string $dateTo): int
    {
        $sql = 'SELECT COUNT(DISTINCT depart::date)
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
     * Drop this route's rows that the fetch run did not see — sold-out or
     * vanished flights (e.g. a Ryanair flight number that switched between
     * runs) otherwise keep their old, often lower, price forever. Scoped by
     * source (NULL-safe, so AirBaltic's NULL-source rows stay AirBaltic's own)
     * and by the fetch window, so rows belonging to other sources or dates the
     * run never queried are never touched. An empty $codes deletes the whole
     * scoped window — a legitimate empty result (seasonal route in winter)
     * means the route really has no flights right now.
     *
     * @return int rows deleted
     */
    public function deleteUnseen(string $origin, string $destination, ?string $source, array $codes, string $dateFrom, string $dateTo): int
    {
        $sql    = 'DELETE FROM flights
                   WHERE origin = :o AND destination = :d AND source IS NOT DISTINCT FROM :s
                     AND depart >= :from AND depart <= :to';
        $params = [
            ':o'    => $origin,
            ':d'    => $destination,
            ':s'    => $source,
            ':from' => $dateFrom . ' 00:00:00',
            ':to'   => $dateTo . ' 23:59:59',
        ];

        if ($codes !== []) {
            $placeholders = [];
            foreach ($codes as $i => $code) {
                $placeholders[] = ":c{$i}";
                $params[":c{$i}"] = $code;
            }
            $sql .= ' AND code NOT IN (' . implode(', ', $placeholders) . ')';
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    /**
     * Get all flights for a specific date (today's command).
     */
    public function getFlightsForDay(string $origin, string $destination, string $date): array
    {
        $sql = 'SELECT code, origin, destination, depart, arrive, price, company, flight_number, source, buy_link, estimated
                FROM flights
                WHERE origin = :o AND destination = :d AND depart::date = :date
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
     * @param string|null $notBefore Exclude flights departing before this
     *                                  datetime — /today passes "now" so
     *                                  already-departed flights are not shown.
     * @return array<int, array{origin:string, date:string, price:float, source:?string, company:?string,
     *                           flight_number:?string, buy_link:?string, depart:?string, arrive:?string,
     *                           estimated:bool}>
     */
    public function getTopPerDay(string|array $origins, string $destination, string $dateFrom, string $dateTo, int $limit = 3, ?string $notBefore = null): array
    {
        if (is_string($origins)) {
            $origins = [$origins];
        }

        // Build "IN (:o0, :o1, ...)" placeholders per origin. Two separate
        // placeholder sets (:o* for the subquery, :O* for the outer WHERE) so
        // PDO named parameters are never reused in one statement.
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

        // Cheapest row per (day, company). PostgreSQL requires GROUP BY on the
        // expressions, not the SELECT aliases. Company is COALESCE'd to '' so a
        // NULL company never groups away (legacy rows carry NULL source/company).
        // The group key is aliased `dd` (not `depart_date`) to avoid colliding
        // with the outer SELECT's `depart_date` alias.
        $sql = 'SELECT f.origin, f.company, f.source, f.flight_number, f.buy_link, f.depart, f.arrive, f.estimated, d.dd AS depart_date, d.price
                  FROM flights f
                  JOIN (
                      SELECT depart::date AS dd, COALESCE(company, \'\') AS company, MIN(price) AS price
                      FROM flights
                      WHERE origin IN (' . implode(', ', $oIn) . ') AND destination = :d1
                        AND depart::date >= :from1 AND depart::date <= :to1'
                        . ($notBefore !== null ? ' AND depart >= :nb1' : '') . '
                      GROUP BY depart::date, COALESCE(company, \'\')
                  ) d ON d.dd = f.depart::date
                     AND COALESCE(f.company, \'\') = d.company
                     AND f.price = d.price
                  WHERE f.origin IN (' . implode(', ', $OIn) . ') AND f.destination = :d2
                    AND f.depart::date >= :from2 AND f.depart::date <= :to2'
                    . ($notBefore !== null ? ' AND f.depart >= :nb2' : '');

        if ($notBefore !== null) {
            $params[':nb1'] = $notBefore;
            $params[':nb2'] = $notBefore;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        // Collect every (day, company) row first, then sort each day by price and
        // keep the top-$limit cheapest companies. Slicing BEFORE sorting (the old
        // "keep the first $limit rows" shape) dropped the cheapest fares in
        // arbitrary DB order and let alternative-airport rows eat the slots.
        $byDate = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $date = $row['depart_date'];
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
                'estimated'     => (bool) ($row['estimated'] ?? false),
            ];
        }

        // Sort each day's rows by price, slice to the limit, and order the days
        // chronologically. Return a FLAT list of row arrays (each carrying its
        // `date`), cheapest-first within a day, days in ascending order — the
        // shape every consumer (buildToday, buildNext, buildNext) expects when it
        // accesses $row['price'], $row['depart'], etc.
        foreach ($byDate as $date => $rows) {
            usort($rows, fn($a, $b) => $a['price'] <=> $b['price']);
            $byDate[$date] = array_slice($rows, 0, $limit);
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
