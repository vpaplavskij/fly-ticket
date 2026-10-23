<?php

namespace FlyTicket\Api\Ryanair;

require_once __DIR__ . '/../../bootstrap.php';

use Config\Route;
use FlyTicket\Support\Logger;
use FlyTicket\Database\Schema;
use FlyTicket\Database\FlightRepository;

Logger::registerHandlers();
Logger::info('Ryanair fetch starting');

$ryanair = new RyanairClient();

$days          = 90; // fetch data for today + next N days
$retentionDays = 90; // keep price_history rows this many days (cleanup runs below)

// All monitored Ryanair routes, both directions each:
//   • RIX ⇄ MLA (Malta) — the primary global route (Route constants).
//   • RIX ⇄ PFO (Paphos, Cyprus) — a seasonal summer service; if Ryanair ever
//     drops it, the fetcher simply finds no fares and skips those legs.
$routes = array_merge(
    Route::both(),
    [
        ['origin' => 'RIX', 'destination' => 'PFO'],
        ['origin' => 'PFO', 'destination' => 'RIX'],
    ],
);

try {
    $pdo = \Database::getConnection();
    Schema::ensureTables($pdo);
    $repo  = new FlightRepository($pdo);

    $dateFrom = date('Y-m-d');
    $dateTo   = date('Y-m-d', strtotime("+$days days"));

    // ── Route-existence health check (cheap backup signal) ────────────────
    // Before the (heavier) per-day fare fetch, confirm Ryanair still operates
    // out of each origin airport we monitor. This is a lightweight route-level
    // sanity check on the same MCP server: if an origin no longer lists our
    // destination, the route has been dropped/renamed and the fare calls below
    // will return empty — so we flag it explicitly instead of logging a quiet
    // "no flights". Cache per origin (each airport is probed once).
    $routeCache = [];
    foreach ($routes as $route) {
        $origin = $route['origin'];
        if (!isset($routeCache[$origin])) {
            try {
                $routeCache[$origin] = $ryanair->getRoutesFrom($origin);
            } catch (\Exception $e) {
                // Health check is best-effort — a failure here must not abort
                // the real fetch, so treat it as "unknown" and move on.
                printf("Ryanair: route health check failed for %s (%s)\n", $origin, $e->getMessage());
                $routeCache[$origin] = null;
            }
        }
    }

    foreach ($routes as $route) {
        $origin      = $route['origin'];
        $destination = $route['destination'];

        // Surface a dropped route loudly before the fare fetch.
        $health = $routeCache[$origin] ?? null;
        if (is_array($health)) {
            if (in_array($destination, $health, true)) {
                printf("Ryanair: health check OK — %s → %s is a live route\n", $origin, $destination);
            } else {
                printf(
                    "Ryanair: WARNING — %s → %s not in Ryanair's active routes from %s (route may be suspended)\n",
                    $origin,
                    $destination,
                    $origin
                );
                Logger::warn('Route health check: destination not listed', [
                    'origin'      => $origin,
                    'destination' => $destination,
                    'listed'      => implode(', ', $health),
                ]);
            }
        }

        printf("Fetching Ryanair %s → %s (%s .. %s)...\n", $origin, $destination, $dateFrom, $dateTo);

        // ── Step 1: Get per-day cheapest fares (price calendar) ────────────
        $fares = $ryanair->getCheapestPerDay($origin, $destination, $dateFrom, $dateTo);

        if (empty($fares)) {
            printf("Ryanair: no flights found for %s → %s\n", $origin, $destination);
        } else {
            // ── Step 2: Enrich with real flight details + upsert to DB ───────
            $days = [];
            foreach ($fares as $f) {
                if (!$f['unavailable'] && $f['price'] !== null) {
                    $days[] = $f['day'];
                }
            }

            // Enrich all days in parallel waves (see RyanairClient::getFlightsForDays).
            $enriched = $ryanair->getFlightsForDays($origin, $destination, $days);

            $rows    = [];
            $saved   = 0;

            foreach ($fares as $f) {
                if ($f['unavailable'] || $f['price'] === null) {
                    continue;
                }

                $day    = $f['day'];
                $flight = $enriched[$day] ?? null;

                if ($flight !== null) {
                    $depTime   = $flight['dep_time'] ?? '12:00';
                    $arrTime   = $flight['arr_time'] ?? '15:00';
                    $flNumber  = $flight['flight_number'] ?: 'FR';
                    $deepLink  = $flight['deep_link'] ?? $f['deep_link'];
                } else {
                    $depTime   = '12:00';
                    $arrTime   = '15:00';
                    $flNumber  = 'FR';
                    $deepLink  = $f['deep_link'];
                }

                $departDt = sprintf('%s %s:00', $day, $depTime);

                // The API returns real arrival times (e.g. 23:35 for a 20:50 departure).
                // Fall back to a +3h estimate only when the enrichment call failed.
                $arriveDate = ($flight !== null && !empty($flight['depart_date']) && $flight['depart_date'] !== $day)
                    ? $flight['depart_date']
                    : $day;
                $arriveDt   = date('Y-m-d H:i:s', strtotime(sprintf('%s %s:00', $arriveDate, $arrTime)));
                $code       = $flNumber . '-' . $origin . '-' . $destination . '-' . $day;

                $rows[] = [
                    'code'          => $code,
                    'origin'        => $origin,
                    'destination'   => $destination,
                    'depart'        => date('Y-m-d H:i:s', strtotime($departDt)),
                    'arrive'        => $arriveDt,
                    'price'         => $f['price'],
                    'company'       => 'Ryanair',
                    'flight_number' => $flNumber,
                    'source'        => 'ryanair',
                    'buy_link'      => $deepLink,
                ];

                printf("Ryanair: %s → %s | %s EUR%s\n", $day, $flNumber, number_format($f['price'], 2), ($flight !== null ? " ({$depTime}→{$arrTime})" : ''));
                $saved++;
            }

            // One transaction for the whole route instead of one per flight.
            if ($rows !== []) {
                $repo->upsertFlights($rows);
                $repo->recordHistoryBatch($rows);
            }

            printf("Ryanair: saved %d flights (upsert + history)\n", $saved);
            Logger::info('Ryanair fetch complete', ['origin' => $origin, 'destination' => $destination, 'flights' => $saved]);
        }
    }

    // ── Retention: drop price_history rows older than the window ───────
    // Runs at most once per day across all fetch scripts (marker file).
    $cutoff  = date('Y-m-d', strtotime("-{$retentionDays} days"));
    $deleted = \Database::prunePriceHistory($retentionDays);
    printf("Ryanair: history retention — removed %d row(s) older than %s\n", $deleted, $cutoff);
    Logger::info('History retention cleanup', ['source' => 'ryanair', 'removed' => $deleted]);

} catch (\Exception $e) {
    Logger::error('Ryanair fetch failed', [
        'exception' => $e->getMessage(),
        'at'        => $e->getFile() . ':' . $e->getLine(),
    ]);
    printf("Error: %s\n", $e->getMessage());
}
