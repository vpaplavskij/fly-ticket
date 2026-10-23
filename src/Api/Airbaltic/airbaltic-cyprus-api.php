<?php

namespace FlyTicket\Api\Airbaltic;

require_once __DIR__ . '/../../bootstrap.php';

use Config\Route;
use FlyTicket\Support\Logger;
use FlyTicket\Database\Schema;
use FlyTicket\Database\FlightRepository;

Logger::registerHandlers();
Logger::info('AirBaltic Cyprus fetch starting');

$airbaltic = new AirbalticClient();

$days          = 90; // fetch data for today + next N days (unified with the other fetchers)
$retentionDays = 90; // keep price_history rows this many days (cleanup runs below)

/**
 * Baltic ⇄ Cyprus (Larnaca). AirBaltic operates the primary RIX ⇄ LCA service
 * plus alternative-origin legs from Tallinn (VNO) and Kaunas (KUN) — the
 * bot UI offers these as "depart from Estonia/Lithuania instead" alternatives.
 * Each direction is probed independently; routes with no timetable in the
 * window are skipped quietly (seasonal/suspended). LCA = Larnaca, the main
 * international airport serving Cyprus.
 */
$routes = [
    // Primary: Riga ⇄ Larnaca (both directions).
    ['origin' => Route::OUTBOUND_ORIGIN, 'destination' => 'LCA', 'label' => "RIX → LCA (" . Route::airportName(Route::OUTBOUND_ORIGIN) . ' → ' . Route::airportName('LCA') . ')'],
    ['origin' => 'LCA', 'destination' => Route::RETURN_DESTINATION, 'label' => "LCA → RIX (" . Route::airportName('LCA') . ' → ' . Route::airportName(Route::RETURN_DESTINATION) . ')'],
];

// Alternative origins: Estonia (VNO) and Lithuania (KUN) → Cyprus. The bot UI
// shows these as alternative departure airports. Routes with no AirBaltic
// service are skipped quietly by the fetch loop below (e.g. KUN has no
// Cyprus timetable — that is expected, not an error).
foreach (Route::ALTERNATIVE_ORIGINS as $altOrigin) {
    $routes[] = [
        'origin'      => $altOrigin,
        'destination' => 'LCA',
        'label'       => "{$altOrigin} → LCA (" . Route::airportName($altOrigin) . ' → ' . Route::airportName('LCA') . ')',
    ];
}

// Return-leg alternatives: PFO → RIX (the other Cyprus airport back to Latvia),
// plus LCA → VNO / LCA → KUN so the bot can offer "arrive in Tallinn/Kaunas
// instead of Riga" when the user picks the return leg. Routes with no AirBaltic
// service are skipped quietly by the fetch loop below.
$routes[] = [
    'origin'      => 'PFO',
    'destination' => Route::RETURN_DESTINATION,
    'label'       => "PFO → RIX (" . Route::airportName('PFO') . ' → ' . Route::airportName(Route::RETURN_DESTINATION) . ')',
];
foreach (Route::ALTERNATIVE_ORIGINS as $altOrigin) {
    $routes[] = [
        'origin'      => 'LCA',
        'destination' => $altOrigin,
        'label'       => "LCA → {$altOrigin} (" . Route::airportName('LCA') . ' → ' . Route::airportName($altOrigin) . ')',
    ];
}

try {
    $pdo = \Database::getConnection();
    Schema::ensureTables($pdo);
    $repo  = new FlightRepository($pdo);

    $dateFrom = date('Y-m-d');
    $dateTo   = date('Y-m-d', strtotime("+$days days"));

    $totalSaved = 0;

    foreach ($routes as $route) {
        $origin      = $route['origin'];
        $destination = $route['destination'];

        printf("Fetching AirBaltic %s (%s .. %s)...\n", $route['label'], $dateFrom, $dateTo);

        // ── Fetch from AirBaltic public API (free, no API key) ────────────
        // The three HTTP calls per route (timetable, live fares, monthly
        // offers) run concurrently — see getCheapestOneWayParallel().
        try {
            $flights = $airbaltic->getCheapestOneWayParallel($origin, $destination, $dateFrom, $dateTo);
        } catch (\RuntimeException $e) {
            // No service on this route (seasonal/suspended) — skip quietly.
            printf("AirBaltic: no service for %s → %s (%s)\n", $origin, $destination, $e->getMessage());
            continue;
        }

        if (empty($flights)) {
            printf("AirBaltic: no flights found for %s → %s\n", $origin, $destination);
            continue;
        }

        // ── Upsert every flight to the `flights` snapshot table ─────────
        // The timetable can list several flights on one date, so keep them all
        // here. One transaction per route instead of one round-trip per flight.
        $rows = [];
        foreach ($flights as $f) {
            $departDt = sprintf('%s %s:00', $f['depart_date'], $f['dep_time']);
            $arriveDt = sprintf('%s %s:00', $f['depart_date'], $f['arr_time']);

            // Include the route in the code so outbound and return legs of the
            // same flight number don't collide on the UNIQUE key.
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
                'source'        => 'airbaltic-cyprus',
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

        // ── History: one observation per date (the day's cheapest flight) ─
        // price_history has a UNIQUE key on (source, origin, destination,
        // depart_date, fetched_at), so it holds exactly one row per date —
        // record the cheapest flight of that date.
        $cheapestByDate = [];
        foreach ($flights as $f) {
            $d = $f['depart_date'];
            if (!isset($cheapestByDate[$d]) || $f['price'] < $cheapestByDate[$d]['price']) {
                $cheapestByDate[$d] = $f;
            }
        }

        $historyRows = [];
        foreach ($cheapestByDate as $f) {
            $departDt = sprintf('%s %s:00', $f['depart_date'], $f['dep_time']);
            $arriveDt = sprintf('%s %s:00', $f['depart_date'], $f['arr_time']);

            $historyRows[] = [
                'source'        => 'airbaltic-cyprus',
                'origin'        => $origin,
                'destination'   => $destination,
                'depart'        => date('Y-m-d H:i:s', strtotime($departDt)),
                'arrive'        => date('Y-m-d H:i:s', strtotime($arriveDt)),
                'price'         => $f['price'],
                'company'       => 'AirBaltic',
                'flight_number' => $f['flight_number'],
            ];
        }

        $saved = $repo->upsertFlights($rows);
        $repo->recordHistoryBatch($historyRows);

        printf("AirBaltic: saved %d flights (%d dates) for %s\n", $saved, count($cheapestByDate), $route['label']);
        $totalSaved += $saved;
        Logger::info('AirBaltic Cyprus fetch complete', ['route' => $route['label'], 'flights' => $saved]);
    }

    printf("AirBaltic Cyprus: total saved %d flights across %d routes\n", $totalSaved, count($routes));

    // ── Retention: drop price_history rows older than the window ───────
    // Runs at most once per day across all fetch scripts (marker file).
    $cutoff  = date('Y-m-d', strtotime("-{$retentionDays} days"));
    $deleted = \Database::prunePriceHistory($retentionDays);
    printf("AirBaltic: history retention — removed %d row(s) older than %s\n", $deleted, $cutoff);
    Logger::info('History retention cleanup', ['source' => 'airbaltic-cyprus', 'removed' => $deleted]);

} catch (\Exception $e) {
    Logger::error('AirBaltic Cyprus fetch failed', [
        'exception' => $e->getMessage(),
        'at'        => $e->getFile() . ':' . $e->getLine(),
    ]);
    printf("Error: %s\n", $e->getMessage());
}
