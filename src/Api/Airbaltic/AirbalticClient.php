<?php

namespace FlyTicket\Api\Airbaltic;

class AirbalticClient
{
    private const BASE_URL = 'https://api.airbaltic.com';

    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';

    /**
     * Get scheduled flights for a route over a date range.
     * Prices are the exact per-date one-way fares (matching airbaltic.com),
     * with a fallback to the month's lowest fare when a date has no live price.
     * Returns array of:
     *   [
     *     'code'          => string  (e.g. "BT739-2026-10-17")
     *     'flight_number' => string  (e.g. "BT739")
     *     'depart_date'   => string  (e.g. "Y-m-d")
     *     'dep_time'      => string  (e.g. "10:50")
     *     'arr_time'      => string  (e.g. "13:25")
     *     'price'         => float   (exact per-date fare, EUR)
     *     'deep_link'     => ?string (booking URL for the route)
     *   ]
     */
    public function getCheapestOneWay(
        string $origin,
        string $destination,
        string $dateFrom,
        string $dateTo,
    ): array {
        // 1. Scheduled flights (flight numbers, dates, times)
        $timetable = $this->postJson('/schedule/timetable/simple', [
            'orig'        => $origin,
            'dest'        => $destination,
            'periodStart' => $dateFrom,
            'periodEnd'   => $dateTo,
        ]);

        // 2. Exact per-date one-way fares (the live endpoint airbaltic.com uses)
        $datePrices = $this->getDateFares($origin, $destination, $dateFrom, $dateTo);

        // 3. Fallback: month's lowest fare, used only when a date has no live price
        $offers      = $this->getJson('/2e/offers/' . urlencode($origin) . '/' . urlencode($destination));
        $monthPrices = $this->mapMonthPrices($offers);

        $flights = [];
        foreach ($timetable as $entry) {
            $flightNumber = $entry['flightNumber'] ?? '';
            $depTime      = $this->normalizeTime($entry['depTime'] ?? '00:00');
            $arrTime      = $this->normalizeTime($entry['arrTime'] ?? '00:00');

            foreach (($entry['dates'] ?? []) as $date) {
                // Prefer the exact live per-date fare; fall back to month's lowest.
                // If neither has a price the fare is genuinely unknown — skip the
                // date rather than storing a 0.0 (which would surface as a €0.00
                // "cheapest" ticket in the bot, since getTopPerDay sorts by price).
                $price = $datePrices[$date] ?? $monthPrices[substr($date, 0, 7)] ?? null;
                if ($price === null || $price <= 0) {
                    continue;
                }

                $flights[] = [
                    'code'          => $flightNumber . '-' . $date,
                    'flight_number' => $flightNumber,
                    'depart_date'   => $date,
                    'dep_time'      => $depTime,
                    'arr_time'      => $arrTime,
                    'price'         => $price,
                    'deep_link'     => $this->buildDeepLink($origin, $destination, $date),
                ];
            }
        }

        return $flights;
    }

    /**
     * Exact per-date one-way fares for a route over a date range.
     * This is the same live endpoint airbaltic.com's booking form uses, so the
     * prices match what a user sees on the website. Returns "Y-m-d" => EUR.
     */
    public function getDateFares(
        string $origin,
        string $destination,
        string $dateFrom,
        string $dateTo,
    ): array {
        return $this->getFaresByMode($origin, $destination, $dateFrom, $dateTo, 'oneway');
    }

    /**
     * Same as getCheapestOneWay() but runs the three HTTP calls (timetable,
     * live per-date fares, monthly offers) concurrently via curl_multi. The
     * Cyprus fetcher needs this: sequential calls would take minutes; in
     * parallel each route is one wave of ~1s.
     */
    public function getCheapestOneWayParallel(
        string $origin,
        string $destination,
        string $dateFrom,
        string $dateTo,
    ): array {
        $multi = curl_multi_init();

        // 1. Timetable (POST /schedule/timetable/simple)
        $chTimetable = curl_init(self::BASE_URL . '/schedule/timetable/simple');
        curl_setopt_array($chTimetable, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode([
                'orig'        => $origin,
                'dest'        => $destination,
                'periodStart' => $dateFrom,
                'periodEnd'   => $dateTo,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'User-Agent: ' . self::USER_AGENT,
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 30,
        ]);

        // 2. Live per-date fares (GET https://www.airbaltic.com/api/fsf/outbound)
        $fareUrl = 'https://www.airbaltic.com/api/fsf/outbound?' . http_build_query([
            'flightMode' => 'oneway',
            'origin'     => $origin,
            'destin'     => $destination,
            'startDate'  => $dateFrom,
            'endDate'    => $dateTo,
        ]);
        $chFares = curl_init($fareUrl);
        curl_setopt_array($chFares, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'User-Agent: ' . self::USER_AGENT,
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 30,
        ]);

        // 3. Monthly offers fallback (GET /2e/offers/{origin}/{destination})
        $chOffers = curl_init(self::BASE_URL . '/2e/offers/' . urlencode($origin) . '/' . urlencode($destination));
        curl_setopt_array($chOffers, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'User-Agent: ' . self::USER_AGENT,
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 30,
        ]);

        foreach ([$chTimetable, $chFares, $chOffers] as $ch) {
            curl_multi_add_handle($multi, $ch);
        }

        // NOTE: the CURLM_* constants are not all reliably defined on this PHP build,
        // so compare against the numeric values (CURLM_OK=0, CURLM_ERROR=-1).
        do {
            $status = curl_multi_exec($multi, $active);
            if ($status === -1) {
                break;
            }
            // Wait until at least one handle is ready.
            //
            // curl_multi_select() returns 0 when a handle is ready and -1 when
            // none are — on libcurl 8.x (PHP 8.5) a -1 return while handles are
            // still running means "nothing to wait on right now, re-exec", NOT
            // "all done". The old bounded-timeout loop treated the -1 case as
            // "keep looping until 30s elapse", which burned ~30s per wave and
            // then read empty bodies (http_code 0) — every route reported "no
            // service" even though the same request succeeded sequentially.
            // Break on -1 so the outer loop re-execs immediately; a genuinely
            // stalled handle is still bounded by CURLOPT_TIMEOUT above.
            while ($active > 0) {
                $r = @curl_multi_select($multi);
                if ($r === false) {
                    usleep(50_000);
                } else {
                    break;
                }
            }
        } while ($active > 0 || $status !== 0);

        $timetable = self::readMultiJson($multi, $chTimetable, 'AirBaltic timetable');
        $datePrices = [];
        try {
            $fareRows = self::readMultiJson($multi, $chFares, 'AirBaltic fares');
            foreach (($fareRows['data'] ?? []) as $row) {
                $price = (float) ($row['price'] ?? 0);
                if (!empty($row['date']) && $price > 0) {
                    $datePrices[$row['date']] = $price;
                }
            }
        } catch (\RuntimeException) {
            // Live endpoint unavailable — fall back to monthly fares, same as the sequential path.
        }

        $offers = [];
        try {
            $offers = self::readMultiJson($multi, $chOffers, 'AirBaltic offers');
        } catch (\RuntimeException) {
            // No monthly fallback available — dates without a live price get 0.
        }

        curl_multi_remove_handle($multi, $chTimetable);
        curl_multi_remove_handle($multi, $chFares);
        curl_multi_remove_handle($multi, $chOffers);
        curl_multi_close($multi);

        // Merge timetable + fares (same logic as the sequential path).
        $monthPrices = $this->mapMonthPrices($offers);
        $flights     = [];

        foreach ($timetable as $entry) {
            $flightNumber = $entry['flightNumber'] ?? '';
            $depTime      = $this->normalizeTime($entry['depTime'] ?? '00:00');
            $arrTime      = $this->normalizeTime($entry['arrTime'] ?? '00:00');

            foreach (($entry['dates'] ?? []) as $date) {
                // Same unknown-price guard as the sequential path: drop dates
                // with no live or monthly fare instead of storing 0.0.
                $price = $datePrices[$date] ?? $monthPrices[substr($date, 0, 7)] ?? null;
                if ($price === null || $price <= 0) {
                    continue;
                }

                $flights[] = [
                    'code'          => $flightNumber . '-' . $date,
                    'flight_number' => $flightNumber,
                    'depart_date'   => $date,
                    'dep_time'      => $depTime,
                    'arr_time'      => $arrTime,
                    'price'         => $price,
                    'deep_link'     => $this->buildDeepLink($origin, $destination, $date),
                ];
            }
        }

        return $flights;
    }

    /**
     * Read a completed curl_multi handle and decode its JSON body.
     */
    private static function readMultiJson(\CurlMultiHandle $multi, \CurlHandle $ch, string $label): array
    {
        $response = curl_multi_getcontent($ch);
        $curl_err = curl_error($ch);

        if ($response === false || $response === null) {
            throw new \RuntimeException("{$label} cURL error: {$curl_err}");
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("{$label}: unexpected response: " . substr((string) $response, 0, 200));
        }

        return $decoded;
    }

    /**
     * Shared fare lookup for the live /api/fsf/outbound endpoint.
     * Returns "Y-m-d" => EUR (only priced dates).
     */
    private function getFaresByMode(
        string $origin,
        string $destination,
        string $dateFrom,
        string $dateTo,
        string $mode,
    ): array {
        $map = [];

        try {
            $rows = $this->getJson('/api/fsf/outbound', [
                'flightMode' => $mode,
                'origin'     => $origin,
                'destin'     => $destination,
                'startDate'  => $dateFrom,
                'endDate'    => $dateTo,
            ], 'https://www.airbaltic.com');
        } catch (\RuntimeException) {
            // Live endpoint unavailable — caller falls back to monthly fares.
            return $map;
        }

        foreach (($rows['data'] ?? []) as $row) {
            $price = (float) ($row['price'] ?? 0);
            if (!empty($row['date']) && $price > 0) {
                $map[$row['date']] = $price;
            }
        }

        return $map;
    }

    // ── Internal HTTP helpers ────────────────────────────────────────

    private function postJson(string $path, array $body): array
    {
        $ch = curl_init(self::BASE_URL . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'User-Agent: ' . self::USER_AGENT,
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 30,
        ]);

        $response = curl_exec($ch);
        $curl_err = curl_error($ch);

        if ($response === false) {
            throw new \RuntimeException("AirBaltic cURL error: {$curl_err}");
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('AirBaltic: unexpected response: ' . substr((string) $response, 0, 200));
        }

        return $decoded;
    }

    private function getJson(string $path, array $query = [], ?string $host = null): array
    {
        $base = ($host ?? self::BASE_URL);
        $url  = $base . $path;
        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'User-Agent: ' . self::USER_AGENT,
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 30,
        ]);

        $response = curl_exec($ch);
        $curl_err = curl_error($ch);

        if ($response === false) {
            throw new \RuntimeException("AirBaltic cURL error: {$curl_err}");
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('AirBaltic: unexpected response: ' . substr((string) $response, 0, 200));
        }

        return $decoded;
    }

    /**
     * Map the 12 monthly offer entries (id = months from now) to a
     * date => price lookup keyed by "Y-m" so flights can pick their month.
     */
    private function mapMonthPrices(array $offers): array
    {
        $now   = new \DateTimeImmutable();
        $map   = [];

        foreach ($offers as $offer) {
            $id    = (int) ($offer['id'] ?? 0);
            $price = (float) ($offer['price'] ?? 0);
            if ($id < 1 || $price <= 0) {
                continue;
            }

            // id=1 is the current month, id=2 next month, ...
            $month = $now->modify('first day of this month')->modify("+" . ($id - 1) . " months");
            $map[$month->format('Y-m')] = $price;
        }

        return $map;
    }

    /**
     * Build a booking deep link that pre-fills the AirBaltic SPA form.
     * The /en/book-flight page reads these query params on load:
     *   originCode, destinCode, tripType (oneway|return), departure, return, numAdt
     */
    private function buildDeepLink(string $origin, string $destination, string $date): string
    {
        return 'https://www.airbaltic.com/en/book-flight?' . http_build_query([
            'originCode' => $origin,
            'destinCode' => $destination,
            'tripType'   => 'oneway',
            'departure'  => $date,
            'numAdt'     => 1,
        ]);
    }

    /**
     * Strip timezone offset from "10:50+03:00" → "10:50".
     */
    private function normalizeTime(string $time): string
    {
        if (preg_match('/^(\d{2}:\d{2})/', $time, $m)) {
            return $m[1];
        }
        return $time;
    }
}
