<?php

namespace FlyTicket\Api\Ryanair;

class RyanairClient
{
    private const MCP_ENDPOINT = 'https://services-api.ryanair.com/mcp-server/mcp';

    private ?string $sessionId = null;

    /**
     * Get cheapest one-way flights per day for a route.
     * Returns array of:
     *   [
     *     'day'         => string  (e.g. "2026-10-18")
     *     'price'       => float|null
     *     'unavailable' => bool
     *     'sold_out'    => bool
     *     'is_cheapest' => bool
     *     'deep_link'   => ?string  (booking link for that specific day)
     *   ]
     */
    public function getCheapestPerDay(string $origin, string $destination, string $dateFrom, string $dateTo): array
    {
        $result = $this->callTool('pricing-get-cheapest-flight-per-day', [
            'departureIataCode'         => $origin,
            'arrivalIataCode'           => $destination,
            'outboundDepartureDateFrom' => $dateFrom,
            'outboundDepartureDateTo'   => $dateTo,
            'currency'                  => 'EUR',
        ]);

        $fares      = [];
        $structured = $result['structuredContent'] ?? null;

        if ($structured && isset($structured['fares']) && is_array($structured['fares'])) {
            foreach ($structured['fares'] as $fare) {
                $price = null;
                if (!empty($fare['priceText'])) {
                    $price = (float) preg_replace('/[^0-9.]/', '', $fare['priceText']);
                }

                $fares[] = [
                    'day'         => $fare['day'] ?? null,
                    'price'       => $price,
                    'unavailable' => $fare['unavailable'] ?? true,
                    'sold_out'    => $fare['soldOut'] ?? false,
                    'is_cheapest' => $fare['isCheapest'] ?? false,
                    'deep_link'   => $fare['deepLink'] ?? null,
                ];
            }
        }

        return $fares;
    }

    /**
     * Enrich several days in parallel. Each day is still a 1-day-range call
     * (the one-way tool returns only the single cheapest flight for a multi-day
     * range), but the calls run concurrently via curl_multi instead of one
     * after another — ~90 sequential round-trips become a handful of waves.
     *
     * Returns [day => flight-array|null] in input order; null means the call
     * failed or the day has no flight.
     */
    public function getFlightsForDays(string $origin, string $destination, array $days, int $concurrency = 5): array
    {
        if ($days === []) {
            return [];
        }

        // The session ID is shared across calls, so initialize once up front.
        if ($this->sessionId === null) {
            $this->initializeSession();
        }

        $results = [];
        $pending = array_values($days);

        while ($pending !== []) {
            $chunk     = array_splice($pending, 0, max(1, $concurrency));
            $multi     = curl_multi_init();
            $handles   = [];
            $failed    = [];

            foreach ($chunk as $day) {
                $body = json_encode([
                    'jsonrpc' => '2.0',
                    'id'      => mt_rand(),
                    'method'  => 'tools/call',
                    'params'  => [
                        'name'      => 'pricing-get-cheapest-one-way-flight',
                        'arguments' => [
                            'outboundDepartureDateFrom' => $day,
                            'outboundDepartureDateTo'   => $day,
                            'departureIataCodes'        => [$origin],
                            'arrivalIataCodes'          => [$destination],
                            'currency'                  => 'EUR',
                        ],
                    ],
                ]);

                $ch = curl_init(self::MCP_ENDPOINT);
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $body,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER     => [
                        'Content-Type: application/json',
                        'Accept: application/json, text/event-stream',
                        'Mcp-Session-Id: ' . $this->sessionId,
                    ],
                    CURLOPT_TIMEOUT => 30,
                ]);

                curl_multi_add_handle($multi, $ch);
                $handles[$day] = $ch;
            }

            // Wait for the whole wave.
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
                // then read empty bodies (http_code 0). Break on -1 so the outer
                // loop re-execs immediately; a genuinely stalled handle is still
                // bounded by CURLOPT_TIMEOUT above.
                while ($active > 0) {
                    $r = @curl_multi_select($multi);
                    if ($r === false) {
                        usleep(50_000);
                    } else {
                        break;
                    }
                }
            } while ($active > 0 || $status !== 0);

            // Collect.
            foreach ($handles as $day => $ch) {
                curl_multi_remove_handle($multi, $ch);
                $response = curl_multi_getcontent($ch);
                $curl_err = curl_error($ch);

                if ($response === false || $response === null) {
                    $failed[$day] = "cURL error: {$curl_err}";
                    continue;
                }

                try {
                    $result  = self::parseMcpResponse($response);
                    $results[$day] = self::parseFlightForDay($result, $origin, $destination);
                } catch (\Throwable $e) {
                    $failed[$day] = $e->getMessage();
                    $results[$day] = null;
                }
            }

            curl_multi_close($multi);

            // If every call in the wave failed, the session is likely dead —
            // re-initialize once so the next wave can recover.
            if (count($failed) === count($chunk)) {
                $this->sessionId = null;
                $this->initializeSession();
            } elseif ($failed !== []) {
                // Keep going; individual failures are reported per day.
                foreach ($failed as $day => $err) {
                    fwrite(STDERR, "Ryanair MCP: enrichment failed for {$day}: {$err}\n");
                }
            }
        }

        return $results;
    }

    /**
     * List the destination IATA codes Ryanair serves from an origin airport.
     * Used as a cheap route-existence health check: it confirms the airline
     * still operates out of that airport (and which pairs) without pulling any
     * fares, so it is far lighter than a full fare fetch and surfaces a
     * dropped/renamed route before the per-day fare calls return empty.
     *
     * Returns an array of destination IATA codes (empty when the call fails or
     * the airport has no routes).
     */
    public function getRoutesFrom(string $origin): array
    {
        $result = $this->callTool('search-routes', ['origin' => $origin]);

        // The tool returns a human-readable text block, e.g.
        //   "Found 21 routes from RIX:\nRIX → PFO (Paphos, Cyprus)\n..."
        $text = '';
        foreach (($result['content'] ?? []) as $item) {
            if (($item['type'] ?? '') === 'text' && !empty($item['text'])) {
                $text .= $item['text'];
            }
        }

        $destinations = [];
        // Each route line is "XXX → YYY (City, Country)" — capture the dest code.
        if (preg_match_all('/→\s*([A-Z]{3})\b/', $text, $m)) {
            foreach ($m[1] as $code) {
                $destinations[$code] = true;
            }
        }

        return array_keys($destinations);
    }

    // ── Internal MCP helpers ───────────────────────────────────────

    private function callTool(string $name, array $arguments): array
    {
        if ($this->sessionId === null) {
            $this->initializeSession();
        }

        $body = json_encode([
            'jsonrpc' => '2.0',
            'id'      => mt_rand(),
            'method'  => 'tools/call',
            'params'  => [
                'name'      => $name,
                'arguments' => $arguments,
            ],
        ]);

        $ch = curl_init(self::MCP_ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json, text/event-stream',
                'Mcp-Session-Id: ' . $this->sessionId,
            ],
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $curl_err = curl_error($ch);

        if ($response === false) {
            throw new \RuntimeException("Ryanair MCP cURL error: {$curl_err}");
        }

        return self::parseMcpResponse($response);
    }

    /**
     * Decode an MCP response body (SSE or plain JSON) into the `result` array.
     */
    private static function parseMcpResponse(string $response): array
    {
        // Response may be SSE or JSON
        $decoded = json_decode($response, true);

        if (!is_array($decoded)) {
            if (preg_match('/data:\s*(\{.+})/', $response, $matches)) {
                $decoded = json_decode($matches[1], true);
            } else {
                throw new \RuntimeException("Ryanair MCP: unexpected response: " . substr($response, 0, 200));
            }
        }

        if (isset($decoded['error'])) {
            throw new \RuntimeException("Ryanair MCP error: " . ($decoded['error']['message'] ?? 'unknown'));
        }

        return $decoded['result'] ?? [];
    }

    /**
     * Extract the cheapest flight for a day from a one-way tool `result`.
     * Returns null when the response has no flights.
     */
    private static function parseFlightForDay(array $result, string $origin, string $destination): ?array
    {
        $structured = $result['structuredContent'] ?? null;
        if (!($structured && isset($structured['flights']) && is_array($structured['flights']))) {
            return null;
        }

        // Return the first (cheapest) flight for that day, or null
        $f = $structured['flights'][0];
        $flightNumber = $f['flightNumber'] ?? '';
        $date         = $f['date'] ?? '';
        $priceText    = $f['priceText'] ?? '';
        $price        = (float) preg_replace('/[^0-9.]/', '', $priceText);

        return [
            'code'             => $flightNumber . '-' . $date,
            'flight_number'    => $flightNumber,
            'depart_date'      => $date,
            'dep_time'         => $f['depTime'] ?? '00:00',
            'arr_time'         => $f['arrTime'] ?? '00:00',
            'duration'         => $f['duration'] ?? null,
            'dep_airport'      => $f['depAirport'] ?? $origin,
            'arr_airport'      => $f['arrAirport'] ?? $destination,
            'dep_airport_name' => $f['depAirportName'] ?? null,
            'arr_airport_name' => $f['arrAirportName'] ?? null,
            'price'            => $price,
            'deep_link'        => $f['deepLink'] ?? null,
        ];
    }

    private function initializeSession(): void
    {
        $body = json_encode([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'initialize',
            'params'  => [
                'protocolVersion' => '2025-03-26',
                'capabilities'    => [],
                'clientInfo'      => ['name' => 'fly-ticket', 'version' => '1.0'],
            ],
        ]);

        $ch = curl_init(self::MCP_ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json, text/event-stream',
            ],
            CURLOPT_TIMEOUT => 30,
        ]);

        $fullResponse = curl_exec($ch);

        if ($fullResponse === false) {
            throw new \RuntimeException("Ryanair MCP: failed to initialize session");
        }

        // Extract Mcp-Session-Id from response headers
        $headerEnd = strpos($fullResponse, "\r\n\r\n");
        if ($headerEnd === false) {
            throw new \RuntimeException("Ryanair MCP: no session ID in response");
        }
        $headers = substr($fullResponse, 0, $headerEnd);

        if (preg_match('/Mcp-Session-Id:\s*(\S+)/i', $headers, $matches)) {
            $this->sessionId = $matches[1];
        } else {
            throw new \RuntimeException("Ryanair MCP: no session ID in response");
        }
    }
}
