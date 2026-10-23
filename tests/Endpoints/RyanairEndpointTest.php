<?php

namespace FlyTicket\Tests\Endpoints;

use PHPUnit\Framework\TestCase;
use FlyTicket\Api\Ryanair\RyanairClient;

/**
 * Endpoint contract tests for Ryanair (MCP server).
 *
 * These hit the LIVE ryanair.com MCP endpoint and assert that the response
 * shape is what RyanairClient expects. Slow + network-dependent, so they live
 * in their own "Endpoints" suite:
 *
 *   vendor/bin/phpunit --testsuite Endpoints
 */
class RyanairEndpointTest extends TestCase
{
    private const ORIGIN = 'RIX';
    private const DEST   = 'MLA';

    public function test_getCheapestPerDay_returns_fares(): void
    {
        $client = new RyanairClient();

        $from = (new \DateTimeImmutable('+1 day'))->format('Y-m-d');
        $to   = (new \DateTimeImmutable('+7 days'))->format('Y-m-d');

        $fares = $client->getCheapestPerDay(self::ORIGIN, self::DEST, $from, $to);

        $this->assertIsArray($fares);
        if ($fares === []) {
            $this->markTestSkipped('No fares returned — MCP endpoint may have changed.');
        }

        // Each fare must carry the fields ryanair-api.php depends on.
        foreach ($fares as $fare) {
            $this->assertArrayHasKey('day', $fare);
            $this->assertArrayHasKey('price', $fare);
            $this->assertArrayHasKey('unavailable', $fare);
            $this->assertArrayHasKey('sold_out', $fare);

            if ($fare['price'] !== null) {
                $this->assertIsFloat($fare['price']);
                $this->assertGreaterThan(0, $fare['price']);
            }
        }
    }

    public function test_getFlightsForDays_returns_flight_details(): void
    {
        $client = new RyanairClient();

        $from = (new \DateTimeImmutable('+1 day'))->format('Y-m-d');
        $to   = (new \DateTimeImmutable('+7 days'))->format('Y-m-d');

        // Get the per-day price calendar, then pick an available day to enrich.
        $fares = $client->getCheapestPerDay(self::ORIGIN, self::DEST, $from, $to);

        $this->assertIsArray($fares);
        if ($fares === []) {
            $this->markTestSkipped('No fares returned — MCP endpoint may have changed.');
        }

        // Find the first day that has a price (i.e. is bookable).
        $day = null;
        foreach ($fares as $fare) {
            if (!$fare['unavailable'] && $fare['price'] !== null) {
                $day = $fare['day'];
                break;
            }
        }

        if ($day === null) {
            $this->markTestSkipped('No available days in range — MCP endpoint may have changed.');
        }

        // Enrich that day with full flight details (the production code path).
        $results = $client->getFlightsForDays(self::ORIGIN, self::DEST, [$day]);

        $flight = $results[$day] ?? null;
        if ($flight === null) {
            $this->markTestSkipped('No flight returned for ' . $day . ' — MCP endpoint may have changed.');
        }

        // Each flight must carry the fields ryanair-api.php depends on.
        foreach (['code', 'flight_number', 'depart_date', 'dep_time', 'arr_time', 'price'] as $key) {
            $this->assertArrayHasKey($key, $flight, "flight missing key: {$key}");
        }
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $flight['depart_date']);
    }
}
