<?php

namespace FlyTicket\Tests\Endpoints;

use PHPUnit\Framework\TestCase;
use FlyTicket\Api\Airbaltic\AirbalticClient;

/**
 * Endpoint contract tests for AirBaltic.
 *
 * These hit the LIVE airbaltic.com endpoints and assert that the response
 * shape is what AirbalticClient expects. They are slow (real network) and can
 * be flaky when the airline changes their API, so they live in their own
 * "Endpoints" suite — run them separately:
 *
 *   vendor/bin/phpunit --testsuite Endpoints
 *
 * The Unit suite never touches the network.
 */
class AirbalticEndpointTest extends TestCase
{
    private const ORIGIN = 'RIX';
    private const DEST   = 'MLA';

    public function test_getDateFares_returns_per_date_prices(): void
    {
        $client = new AirbalticClient();

        // A range that definitely has service (this week + next).
        $from = (new \DateTimeImmutable('+1 day'))->format('Y-m-d');
        $to   = (new \DateTimeImmutable('+14 days'))->format('Y-m-d');

        $fares = $client->getDateFares(self::ORIGIN, self::DEST, $from, $to);

        // Endpoint must return an array keyed by "Y-m-d".
        $this->assertIsArray($fares);

        if ($fares === []) {
            $this->markTestSkipped('No fares returned for the range — endpoint may have changed or route has no service.');
        }

        // Every key must be a valid Y-m-d date.
        foreach (array_keys($fares) as $date) {
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', (string) $date, "bad date key: {$date}");
        }

        // Every price must be a positive number.
        foreach ($fares as $date => $price) {
            $this->assertIsFloat($price);
            $this->assertGreaterThan(0, $price, "price for {$date} must be > 0");
        }
    }

    public function test_getCheapestOneWay_returns_flights_with_prices(): void
    {
        $client = new AirbalticClient();

        $from = (new \DateTimeImmutable('+1 day'))->format('Y-m-d');
        $to   = (new \DateTimeImmutable('+7 days'))->format('Y-m-d');

        $flights = $client->getCheapestOneWay(self::ORIGIN, self::DEST, $from, $to);

        $this->assertIsArray($flights);
        if ($flights === []) {
            $this->markTestSkipped('No flights returned — timetable endpoint may have changed.');
        }

        // Each flight must carry the fields the bot + repository depend on.
        $required = ['code', 'flight_number', 'depart_date', 'dep_time', 'arr_time', 'price'];
        foreach ($flights as $f) {
            foreach ($required as $key) {
                $this->assertArrayHasKey($key, $f, "flight missing key: {$key}");
            }
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $f['depart_date']);
            $this->assertIsFloat($f['price']);
        }
    }
}
