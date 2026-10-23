<?php

namespace FlyTicket\Api\Airbaltic;

require_once __DIR__ . '/../../bootstrap.php';

use Config\Route;
use FlyTicket\Support\Logger;
use FlyTicket\Database\Schema;
use FlyTicket\Database\FlightRepository;

Logger::registerHandlers();
Logger::info('AirBaltic fetch starting');

$airbaltic = new AirbalticClient();

$days          = 90; // fetch data for today + next N days (unified with Ryanair)
$retentionDays = 90; // keep price_history rows this many days (cleanup runs below)

// Both directions (outbound RIX → MLA, return MLA → RIX).
$routes = Route::both();

try {
    $pdo = \Database::getConnection();
    Schema::ensureTables($pdo);
    $repo  = new FlightRepository($pdo);

    $dateFrom = date('Y-m-d');
    $dateTo   = date('Y-m-d', strtotime("+$days days"));

    foreach ($routes as $route) {
        $origin      = $route['origin'];
        $destination = $route['destination'];

        printf("Fetching AirBaltic %s → %s (%s .. %s)...\n", $origin, $destination, $dateFrom, $dateTo);

        // ── Fetch from AirBaltic public API (free, no API key) ────────────
        $flights = $airbaltic->getCheapestOneWay($origin, $destination, $dateFrom, $dateTo);

        if (empty($flights)) {
            printf("AirBaltic: no flights found for %s → %s\n", $origin, $destination);
        } else {
            // ── Upsert to DB + record history (one transaction per route) ─
            $rows = [];
            foreach ($flights as $f) {
                $departDt = sprintf('%s %s:00', $f['depart_date'], $f['dep_time']);
                $arriveDt = sprintf('%s %s:00', $f['depart_date'], $f['arr_time']);

                // Include the route in the code so outbound and return legs
                // of the same flight number don't collide on the UNIQUE key.
                $code = $f['flight_number'] . '-' . $origin . '-' . $destination . '-' . $f['depart_date'];

                $rows[] = [
                    'code'          => $code,
                    'origin'        => $origin,
                    'destination'   => $destination,
                    'depart'        => date('Y-m-d H:i:s', strtotime($departDt)),
                    'arrive'        => date('Y-m-d H:i:s', strtotime($arriveDt)),
                    'price'         => $f['price'],
                    'company'       => 'AirBaltic',
                    'flight_number' => $f['flight_number'],
                    'source'        => null,
                    'buy_link'      => $f['deep_link'] ?? null,
                ];

                printf(
                    "AirBaltic: %s | %s → %s | %s EUR\n",
                    $f['flight_number'],
                    $f['dep_time'],
                    $f['arr_time'],
                    number_format($f['price'], 2)
                );
            }

            $saved = $repo->upsertFlights($rows);
            $repo->recordHistoryBatch($rows);

            printf("AirBaltic: saved %d flights (upsert + history)\n", $saved);
            Logger::info('AirBaltic fetch complete', ['origin' => $origin, 'destination' => $destination, 'flights' => $saved]);
        }
    }

    // ── Retention: drop price_history rows older than the window ───────
    // Runs at most once per day across all fetch scripts (marker file).
    $cutoff  = date('Y-m-d', strtotime("-{$retentionDays} days"));
    $deleted = \Database::prunePriceHistory($retentionDays);
    printf("AirBaltic: history retention — removed %d row(s) older than %s\n", $deleted, $cutoff);
    Logger::info('History retention cleanup', ['source' => 'airbaltic', 'removed' => $deleted]);

} catch (\Exception $e) {
    Logger::error('AirBaltic fetch failed', [
        'exception' => $e->getMessage(),
        'at'        => $e->getFile() . ':' . $e->getLine(),
    ]);
    printf("Error: %s\n", $e->getMessage());
}
