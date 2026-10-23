<?php

namespace FlyTicket\Tests;

use FlyTicket\Database\Schema;

/**
 * Shared helpers for tests that touch the database.
 *
 * Tests use a synthetic route (TST → TST) so they never collide with the real
 * RIX → MLA production rows, and clean up after themselves.
 */
trait DatabaseTestTrait
{
    protected const TEST_ORIGIN = 'TST';
    protected const TEST_DEST   = 'TST2';

    /** @var \PDO|null */
    protected ?\PDO $testPdo = null;

    protected function testPdo(): \PDO
    {
        if ($this->testPdo === null) {
            $this->testPdo = \Database::getConnection();
            Schema::ensureTables($this->testPdo);
        }
        return $this->testPdo;
    }

    /** Wipe any test-route rows so each test starts clean. */
    protected function cleanTestRoute(): void
    {
        $pdo = $this->testPdo();
        // Wipe by destination (TST2) so rows seeded under an alternative
        // origin (e.g. 'ALT') are cleaned too — the UNIQUE code key is global,
        // so a leftover row from any test origin would collide with a later
        // test that reuses the same flight code.
        $pdo->prepare('DELETE FROM flights WHERE destination = ? OR origin IN (?, ?)')
            ->execute([self::TEST_DEST, self::TEST_ORIGIN, 'ALT']);
        $pdo->prepare('DELETE FROM price_history WHERE destination = ? OR origin IN (?, ?)')
            ->execute([self::TEST_DEST, self::TEST_ORIGIN, 'ALT']);
    }

    /** Insert a flight row directly (bypasses the fetch pipeline). */
    protected function seedFlight(array $f): void
    {
        $pdo = $this->testPdo();
        $stmt = $pdo->prepare(
            'INSERT INTO flights (code, origin, destination, depart, arrive, price, company, flight_number, source, buy_link)
             VALUES (:code, :origin, :destination, :depart, :arrive, :price, :company, :flight_number, :source, :buy_link)'
        );
        $stmt->execute([
            ':code'          => $f['code'],
            ':origin'        => $f['origin'] ?? self::TEST_ORIGIN,
            ':destination'   => $f['destination'] ?? self::TEST_DEST,
            ':depart'        => $f['depart'],
            ':arrive'        => $f['arrive'],
            ':price'         => (string) $f['price'],
            ':company'       => $f['company'],
            ':flight_number' => $f['flight_number'] ?? null,
            ':source'        => $f['source'] ?? null,
            ':buy_link'      => $f['buy_link'] ?? null,
        ]);
    }
}
