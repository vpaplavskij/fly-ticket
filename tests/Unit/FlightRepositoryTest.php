<?php

namespace FlyTicket\Tests\Unit;

use PHPUnit\Framework\TestCase;
use FlyTicket\Database\FlightRepository;
use FlyTicket\Tests\DatabaseTestTrait;

/**
 * Integration tests for FlightRepository (upsert + read round-trips).
 *
 * Seeds a synthetic route (TST → TST2) so it never collides with production
 * RIX → MLA rows. Requires a working DB connection from .env.
 */
class FlightRepositoryTest extends TestCase
{
    use DatabaseTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanTestRoute();
    }

    protected function tearDown(): void
    {
        $this->cleanTestRoute();
        parent::tearDown();
    }

    private function repo(): FlightRepository
    {
        return new FlightRepository($this->testPdo());
    }

    public function test_upsertFlights_inserts_then_updates_on_same_code(): void
    {
        $repo = $this->repo();
        $day  = date('Y-m-d', strtotime('+1 day'));

        // First insert.
        $repo->upsertFlights([[
            'code' => 'FR9-' . $day, 'origin' => self::TEST_ORIGIN, 'destination' => self::TEST_DEST,
            'depart' => $day . ' 08:00:00', 'arrive' => $day . ' 10:30:00', 'price' => '100.00',
            'company' => 'Ryanair', 'flight_number' => 'FR9', 'source' => 'ryanair',
        ]]);

        // Upsert same code with a new price — must UPDATE, not duplicate.
        $repo->upsertFlights([[
            'code' => 'FR9-' . $day, 'origin' => self::TEST_ORIGIN, 'destination' => self::TEST_DEST,
            'depart' => $day . ' 08:00:00', 'arrive' => $day . ' 10:30:00', 'price' => '55.00',
            'company' => 'Ryanair', 'flight_number' => 'FR9', 'source' => 'ryanair',
        ]]);

        $rows = $repo->getFlightsForDay(self::TEST_ORIGIN, self::TEST_DEST, $day);
        $this->assertCount(1, $rows, 'upsert must not create duplicate rows for the same code');
        $this->assertEquals('55.00', $rows[0]['price'], 'second upsert must update the price');
    }

    public function test_getFlightsForDay_returns_seeded_rows(): void
    {
        $repo = $this->repo();
        $day  = date('Y-m-d', strtotime('+1 day'));

        $this->seedFlight(['code' => 'BT1-' . $day, 'depart' => $day . ' 08:00:00', 'arrive' => $day . ' 10:30:00', 'price' => '250.00', 'company' => 'AirBaltic', 'flight_number' => 'BT1', 'source' => null]);

        $rows = $repo->getFlightsForDay(self::TEST_ORIGIN, self::TEST_DEST, $day);
        $this->assertCount(1, $rows);
        $this->assertSame('AirBaltic', $rows[0]['company']);
        $this->assertEquals(250.0, (float) $rows[0]['price']);
    }

    public function test_getTopPerDay_picks_cheapest_per_day(): void
    {
        $repo = $this->repo();
        $day  = date('Y-m-d', strtotime('+1 day'));

        // Two flights same day, different prices.
        $this->seedFlight(['code' => 'FR1-' . $day, 'depart' => $day . ' 07:00:00', 'arrive' => $day . ' 09:30:00', 'price' => '90.00', 'company' => 'Ryanair', 'flight_number' => 'FR1', 'source' => 'ryanair']);
        $this->seedFlight(['code' => 'FR2-' . $day, 'depart' => $day . ' 13:00:00', 'arrive' => $day . ' 15:30:00', 'price' => '45.00', 'company' => 'Ryanair', 'flight_number' => 'FR2', 'source' => 'ryanair']);

        $rows = $repo->getTopPerDay(self::TEST_ORIGIN, self::TEST_DEST, $day, $day);
        // getTopPerDay returns the cheapest row per (day, company) — both rows
        // are Ryanair so only the cheaper one survives. Cheapest-first within
        // the day, so 45.00 leads.
        $this->assertCount(1, $rows);
        $this->assertEquals(45.0, (float) $rows[0]['price'], 'must return the cheapest flight for the day');
    }

    /**
     * Regression: getTopPerDay() must return a FLAT list of row arrays (each
     * carrying its own `date`), not a nested `[date => [rows]]` map. The three
     * consumers (buildToday, buildNext) index it as a flat
     * list; the old nested shape produced malformed Telegram output that was
     * rejected with HTTP 400 "message text is empty".
     */
    public function test_getTopPerDay_returns_flat_list_of_rows(): void
    {
        $repo = $this->repo();
        $day1 = date('Y-m-d', strtotime('+1 day'));
        $day2 = date('Y-m-d', strtotime('+2 days'));

        // Two days × two companies, cheapest-first expected within each day.
        $this->seedFlight(['code' => 'A-' . $day1, 'depart' => $day1 . ' 07:00:00', 'arrive' => $day1 . ' 09:30:00', 'price' => '45.00', 'company' => 'Ryanair', 'flight_number' => 'FR1', 'source' => 'ryanair']);
        $this->seedFlight(['code' => 'B-' . $day1, 'depart' => $day1 . ' 13:00:00', 'arrive' => $day1 . ' 15:30:00', 'price' => '90.00', 'company' => 'AirBaltic', 'flight_number' => 'BT1', 'source' => null]);
        $this->seedFlight(['code' => 'C-' . $day2, 'depart' => $day2 . ' 07:00:00', 'arrive' => $day2 . ' 09:30:00', 'price' => '60.00', 'company' => 'Ryanair', 'flight_number' => 'FR2', 'source' => 'ryanair']);

        $rows = $repo->getTopPerDay(self::TEST_ORIGIN, self::TEST_DEST, $day1, $day2);
        $this->assertCount(3, $rows);

        // Flat: every element is a row array with its own `date` key.
        foreach ($rows as $row) {
            $this->assertIsArray($row);
            $this->assertArrayHasKey('date', $row);
            $this->assertArrayHasKey('price', $row);
            $this->assertArrayHasKey('depart', $row);
            // No nested `[0]`-keyed sub-array (the old broken shape).
            $this->assertArrayNotHasKey(0, $row);
        }

        // Days in ascending order, cheapest-first within each day.
        $this->assertSame($day1, $rows[0]['date']);
        $this->assertEquals(45.0, (float) $rows[0]['price']);
        $this->assertEquals(90.0, (float) $rows[1]['price']);
        $this->assertSame($day2, $rows[2]['date']);
    }

    /**
     * Multi-origin getTopPerDay: pass an array of origins and verify rows are
     * returned with the correct `origin` field for each.
     */
    public function test_getTopPerDay_multi_origin_returns_correct_origin_field(): void
    {
        $repo = $this->repo();
        $day  = date('Y-m-d', strtotime('+1 day'));

        // Seed flights from two different origins to the same destination.
        $this->seedFlight(['code' => 'MOA-' . $day, 'origin' => self::TEST_ORIGIN, 'depart' => $day . ' 07:00:00', 'arrive' => $day . ' 09:30:00', 'price' => '45.00', 'company' => 'Ryanair', 'flight_number' => 'FR1', 'source' => 'ryanair']);
        $this->seedFlight(['code' => 'MOB-' . $day, 'origin' => 'ALT', 'destination' => self::TEST_DEST, 'depart' => $day . ' 08:00:00', 'arrive' => $day . ' 10:30:00', 'price' => '55.00', 'company' => 'AirBaltic', 'flight_number' => 'BT1', 'source' => null]);

        // Query both origins together.
        $rows = $repo->getTopPerDay([self::TEST_ORIGIN, 'ALT'], self::TEST_DEST, $day, $day);
        $this->assertCount(2, $rows);

        // Each row must carry its own origin.
        $origins = array_map(fn($r) => $r['origin'], $rows);
        sort($origins);
        $this->assertSame(['ALT', self::TEST_ORIGIN], $origins);
    }

    /**
     * Multi-origin getTopPerDay with a single-element array must behave the
     * same as passing a plain string (backward compatibility).
     */
    public function test_getTopPerDay_single_origin_array_matches_string(): void
    {
        $repo = $this->repo();
        $day  = date('Y-m-d', strtotime('+1 day'));

        $this->seedFlight(['code' => 'SAA-' . $day, 'depart' => $day . ' 07:00:00', 'arrive' => $day . ' 09:30:00', 'price' => '45.00', 'company' => 'Ryanair', 'flight_number' => 'FR1', 'source' => 'ryanair']);

        $rowsStr = $repo->getTopPerDay(self::TEST_ORIGIN, self::TEST_DEST, $day, $day);
        $rowsArr = $repo->getTopPerDay([self::TEST_ORIGIN], self::TEST_DEST, $day, $day);

        $this->assertCount(1, $rowsStr);
        $this->assertCount(1, $rowsArr);
        $this->assertEquals((float) $rowsStr[0]['price'], (float) $rowsArr[0]['price']);
    }

    public function test_upsertFlights_and_recordHistoryBatch_write_both_tables(): void
    {
        $repo = $this->repo();
        $day  = date('Y-m-d');

        // The production write path: upsertFlights() writes the `flights`
        // snapshot, recordHistoryBatch() appends the price_history row.
        $flight = [
            'code' => 'FR7-' . $day, 'origin' => self::TEST_ORIGIN, 'destination' => self::TEST_DEST,
            'depart' => $day . ' 08:00:00', 'arrive' => $day . ' 10:30:00', 'price' => '77.00',
            'company' => 'Ryanair', 'flight_number' => 'FR7', 'source' => 'ryanair',
        ];
        $repo->upsertFlights([$flight]);
        $repo->recordHistoryBatch([$flight]);

        // flights snapshot written.
        $rows = $repo->getFlightsForDay(self::TEST_ORIGIN, self::TEST_DEST, $day);
        $this->assertCount(1, $rows, 'upsertFlights must write a flights row');

        // price_history row written.
        $stmt = $this->testPdo()
            ->prepare('SELECT price FROM price_history WHERE origin = ? AND destination = ? AND depart_date = ?');
        $stmt->execute([self::TEST_ORIGIN, self::TEST_DEST, $day]);
        $this->assertEquals(77.0, (float) $stmt->fetchColumn(), 'recordHistoryBatch must record a price_history row');
    }
}
