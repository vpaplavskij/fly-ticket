<?php

namespace FlyTicket\Tests\Unit;

use PHPUnit\Framework\TestCase;
use FlyTicket\Database\FlightRepository;
use FlyTicket\Tests\DatabaseTestTrait;

/**
 * Integration tests for the Telegram command handlers (handleToday, handleNext,
 * handleHelp).
 *
 * These seed a synthetic route (TST → TST2) into the real database so they never
 * collide with production RIX → MLA rows, then assert on the rendered HTML. They
 * require a working DB connection (from .env); if the DB is unreachable the tests
 * fail — that's intentional, since these are integration tests.
 */
class HandlerIntegrationTest extends TestCase
{
    use DatabaseTestTrait;

    private function repo(): FlightRepository
    {
        return new FlightRepository($this->testPdo());
    }

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

    public function test_handleToday_no_flights_returns_empty_message(): void
    {
        $reply = \FlyTicket\Api\Telegram\handleToday($this->repo(), self::TEST_ORIGIN, self::TEST_DEST);
        $this->assertStringContainsString("No flights found for today", $reply);
    }

    public function test_handleToday_renders_seeded_flights(): void
    {
        $today = date('Y-m-d');

        // Two flights today: one AirBaltic (source NULL), one Ryanair.
        $this->seedFlight([
            'code'          => 'BT100-' . $today,
            'depart'        => $today . ' 08:00:00',
            'arrive'        => $today . ' 10:30:00',
            'price'         => '250.00',
            'company'       => 'AirBaltic',
            'flight_number' => 'BT100',
            'source'        => null,
            'buy_link'      => 'https://www.airbaltic.com/bt100',
        ]);
        $this->seedFlight([
            'code'          => 'FR200-' . $today,
            'depart'        => $today . ' 09:15:00',
            'arrive'        => $today . ' 11:45:00',
            'price'         => '77.00',
            'company'       => 'Ryanair',
            'flight_number' => 'FR200',
            'source'        => 'ryanair',
            'buy_link'      => 'https://www.ryanair.com/fr200',
        ]);

        $reply = \FlyTicket\Api\Telegram\handleToday($this->repo(), self::TEST_ORIGIN, self::TEST_DEST);

        // Header
        $this->assertStringContainsString("✈️ <b>Today's flights</b>", $reply);

        // Both flights present with correct times, company, and price.
        $this->assertStringContainsString('08:00 → 10:30', $reply);
        $this->assertStringContainsString('AirBaltic', $reply);
        $this->assertStringContainsString('€250.00', $reply);

        $this->assertStringContainsString('09:15 → 11:45', $reply);
        $this->assertStringContainsString('Ryanair', $reply);
        $this->assertStringContainsString('€77.00', $reply);

        // Buy links rendered as anchors.
        $this->assertStringContainsString('<a href="https://www.airbaltic.com/bt100">', $reply);
        $this->assertStringContainsString('<a href="https://www.ryanair.com/fr200">', $reply);

        // Footer count (2 flights).
        $this->assertStringContainsString('<i>2 flights today</i>', $reply);
    }

    public function test_handleToday_sorted_cheapest_first(): void
    {
        $today = date('Y-m-d');

        // Seed out of price order; handler must sort cheapest-first so the
        // lowest price leads the list.
        $this->seedFlight([
            'code' => 'FR200-' . $today, 'depart' => $today . ' 14:00:00', 'arrive' => $today . ' 16:30:00',
            'price' => '50.00', 'company' => 'Ryanair', 'flight_number' => 'FR200', 'source' => 'ryanair',
        ]);
        $this->seedFlight([
            'code' => 'BT100-' . $today, 'depart' => $today . ' 06:00:00', 'arrive' => $today . ' 08:30:00',
            'price' => '250.00', 'company' => 'AirBaltic', 'flight_number' => 'BT100', 'source' => null,
        ]);

        $reply = \FlyTicket\Api\Telegram\handleToday($this->repo(), self::TEST_ORIGIN, self::TEST_DEST);

        // The cheaper flight (€50.00) must lead the list — /today is sorted
        // cheapest-first so the lowest price is always at the top.
        $posCheap  = strpos($reply, '14:00 → 16:30');
        $posEarly  = strpos($reply, '06:00 → 08:30');
        $posLate   = $posEarly;
        $this->assertNotFalse($posCheap);
        $this->assertNotFalse($posEarly);
        $this->assertLessThan($posEarly, $posCheap, 'cheapest flight must lead the list');
    }

    public function test_handleNext_no_data_returns_empty_message(): void
    {
        $reply = \FlyTicket\Api\Telegram\handleNext($this->repo(), self::TEST_ORIGIN, self::TEST_DEST, 7, 'next week');
        $this->assertStringContainsString('No upcoming data yet', $reply);
    }

    public function test_handleNext_renders_lowest_price_per_day(): void
    {
        // Seed two days, each with a cheap and an expensive flight. The handler
        // must show only the cheapest per day.
        $d1 = date('Y-m-d', strtotime('+1 day'));
        $d2 = date('Y-m-d', strtotime('+2 days'));

        $this->seedFlight(['code' => 'FR1-' . $d1, 'depart' => $d1 . ' 07:00:00', 'arrive' => $d1 . ' 09:30:00', 'price' => '90.00', 'company' => 'Ryanair', 'flight_number' => 'FR1', 'source' => 'ryanair']);
        $this->seedFlight(['code' => 'FR2-' . $d1, 'depart' => $d1 . ' 13:00:00', 'arrive' => $d1 . ' 15:30:00', 'price' => '45.00', 'company' => 'Ryanair', 'flight_number' => 'FR2', 'source' => 'ryanair']);
        $this->seedFlight(['code' => 'BT1-' . $d2, 'depart' => $d2 . ' 08:00:00', 'arrive' => $d2 . ' 10:30:00', 'price' => '300.00', 'company' => 'AirBaltic', 'flight_number' => 'BT1', 'source' => null]);

        $reply = \FlyTicket\Api\Telegram\handleNext($this->repo(), self::TEST_ORIGIN, self::TEST_DEST, 7, 'next week');

        // Header
        $this->assertStringContainsString('📅 <b>Upcoming Prices</b>', $reply);

        // Day 1: cheapest (45.00) shown, not the 90.00 flight.
        $this->assertStringContainsString('€45.00', $reply);
        $this->assertStringNotContainsString('€90.00', $reply);

        // Day 2: only flight (300.00) shown.
        $this->assertStringContainsString('€300.00', $reply);
    }

    public function test_handleNext_picks_cheapest_across_sources(): void
    {
        $d1 = date('Y-m-d', strtotime('+1 day'));

        // Same day, two sources: Ryanair cheaper than AirBaltic.
        $this->seedFlight(['code' => 'BT1-' . $d1, 'depart' => $d1 . ' 08:00:00', 'arrive' => $d1 . ' 10:30:00', 'price' => '250.00', 'company' => 'AirBaltic', 'flight_number' => 'BT1', 'source' => null]);
        $this->seedFlight(['code' => 'FR1-' . $d1, 'depart' => $d1 . ' 09:00:00', 'arrive' => $d1 . ' 11:30:00', 'price' => '60.00', 'company' => 'Ryanair', 'flight_number' => 'FR1', 'source' => 'ryanair']);

        $reply = \FlyTicket\Api\Telegram\handleNext($this->repo(), self::TEST_ORIGIN, self::TEST_DEST, 7, 'next week');

        // The cheapest (Ryanair €60.00) must LEAD the day line — it is the
        // primary option, not an alternative.
        $this->assertStringContainsString('€60.00', $reply);

        // Alternatives were removed: no "alt:" anywhere, and the pricier
        // AirBaltic flight (€250.00) is not rendered at all — only the
        // cheapest option per day shows.
        $this->assertStringNotContainsString('alt:', $reply);
        $this->assertStringNotContainsString('€250.00', $reply);
    }

    public function test_handleHelp_lists_all_commands(): void
    {
        $help = \FlyTicket\Api\Telegram\handleHelp();
        foreach (['/today', '/nextweek', '/nextmonth', '/next3months', '/information', '/help'] as $cmd) {
            $this->assertStringContainsString($cmd, $help);
        }
    }

    /**
     * Regression: /information used to fall through the dispatcher to
     * "Unknown command" — it must behave exactly like /help.
     */
    public function test_information_is_alias_of_help(): void
    {
        \FlyTicket\Api\Telegram\Translator::set('en');

        $info = \FlyTicket\Api\Telegram\dispatchCommand($this->repo(), self::TEST_ORIGIN, self::TEST_DEST, 'information');
        $help = \FlyTicket\Api\Telegram\handleHelp();

        $this->assertSame($help, $info);
        $this->assertStringNotContainsString('Unknown command', $info);

        // The alias must be documented in the help text itself.
        $this->assertStringContainsString('/information', $help);
    }
}
