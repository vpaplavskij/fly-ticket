<?php

namespace FlyTicket\Api\Airbaltic;

require_once __DIR__ . '/../../bootstrap.php';

use Config\Route;
use FlyTicket\Support\Logger;
use FlyTicket\Database\FlightRepository;

Logger::registerHandlers();
Logger::info('AirBaltic fetch starting');

$airbaltic = new AirbalticClient();

$days = 90; // fetch data for today + next N days (unified with Ryanair)

// Both directions (outbound RIX → MLA, return MLA → RIX).
$routes = Route::both();

try {
    $pdo = \Database::getConnection();
    $repo  = new FlightRepository($pdo);

    $dateFrom = date('Y-m-d');
    $dateTo   = date('Y-m-d', strtotime("+$days days"));

    foreach ($routes as $route) {
        $origin      = $route['origin'];
        $destination = $route['destination'];

        printf("Fetching AirBaltic %s → %s (%s .. %s)...\n", $origin, $destination, $dateFrom, $dateTo);

        // ── Fetch from AirBaltic public API (free, no API key) ────────────
        $flights = $airbaltic->getCheapestOneWay($origin, $destination, $dateFrom, $dateTo);

        $rows = [];
        if (empty($flights)) {
            printf("AirBaltic: no flights found for %s → %s\n", $origin, $destination);
        } else {
            // ── Upsert to DB + record history (one transaction per route) ─
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
                    'estimated'     => $f['estimated'] ?? false,
                ];

                printf(
                    "AirBaltic: %s | %s → %s | %s EUR%s\n",
                    $f['flight_number'],
                    $f['dep_time'],
                    $f['arr_time'],
                    number_format($f['price'], 2),
                    (($f['estimated'] ?? false) ? ' (estimated)' : '')
                );
            }

            $saved = $repo->upsertFlights($rows);
            $repo->recordHistoryBatch($rows);

            printf("AirBaltic: saved %d flights (upsert + history)\n", $saved);
            Logger::info('AirBaltic fetch complete', ['origin' => $origin, 'destination' => $destination, 'flights' => $saved]);
        }

        // Drop rows this run did not see (sold-out/vanished flights). Scoped to
        // this route + NULL source (AirBaltic's MLA rows), so Ryanair's rows on
        // the same pair are untouched.
        $stale = $repo->deleteUnseen($origin, $destination, null, array_column($rows, 'code'), $dateFrom, $dateTo);
        if ($stale > 0) {
            printf("AirBaltic: removed %d stale row(s) for %s → %s\n", $stale, $origin, $destination);
        }
    }

    // History retention is done once per cycle by jobs.php fetch (it prunes
    // price_history after all children finish), so no DELETE happens here.

} catch (\Exception $e) {
    Logger::error('AirBaltic fetch failed', [
        'exception' => $e->getMessage(),
        'at'        => $e->getFile() . ':' . $e->getLine(),
    ]);
    printf("Error: %s\n", $e->getMessage());
}
