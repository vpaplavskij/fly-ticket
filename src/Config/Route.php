<?php

namespace Config;

/**
 * The default (global) monitored route, in both directions, plus the human
 * names for every airport code the app knows.
 *
 * This is the fallback pair used by any bot that does not set its own
 * TELEGRAM_<NAME>_ROUTE override (currently RIX ⇄ MLA). Bots can and do
 * monitor other city pairs — and even several at once via
 * TELEGRAM_<NAME>_EXTRA_ROUTES — so treat this as a default, not the only
 * route. Keeping the pair here as one unit rather than separate origin/
 * destination constants keeps every consumer aware that the route is
 * bidirectional.
 *
 * The AIRPORTS map is the single source of truth for human city names: the
 * Telegram UI (routeLabel), the fetch scripts' log labels, and any future
 * display all resolve IATA codes through it here. Adding a new airport
 * anywhere in the app = add one line to this array. Unknown codes fall back
 * to the bare code, so nothing ever breaks when a new route appears before its
 * name is added.
 */
final class Route
{
    /** Outbound leg: Riga → Malta. */
    public const OUTBOUND_ORIGIN      = 'RIX';
    public const OUTBOUND_DESTINATION = 'MLA';

    /** Return leg: Malta → Riga. */
    public const RETURN_ORIGIN        = 'MLA';
    public const RETURN_DESTINATION   = 'RIX';

    /**
     * Human names for every airport code the app uses, keyed by IATA code.
     * The UI shows "Riga ⇄ Larnaca (Cyprus)" (names) while buttons and data
     * stay on codes ("RIX → LCA"). Add a new route's airports here so its
     * labels render with city names too.
     */
    public const AIRPORTS = [
        'RIX' => 'Riga',
        'VNO' => 'Tallinn',
        'KUN' => 'Kaunas',
        'MLA' => 'Malta',
        'LCA' => 'Larnaca (Cyprus)',
        'PFO' => 'Paphos (Cyprus)',
    ];

    /**
     * Country names for every airport code the app uses, keyed by IATA code.
     * Used by the Telegram UI to display routes as country pairs (e.g.
     * "Latvia Cyprus (LCA)") instead of city pairs. When a country has
     * multiple airports, the IATA code is always shown in parentheses to
     * distinguish them (e.g. "Cyprus (LCA)" vs "Cyprus (PFO)").
     */
    public const COUNTRIES = [
        'RIX' => 'Latvia',
        'VNO' => 'Estonia',
        'KUN' => 'Lithuania',
        'MLA' => 'Malta',
        'LCA' => 'Cyprus',
        'PFO' => 'Cyprus',
    ];

    /**
     * Baltic origin airports that can substitute for RIX when offering
     * "alternative" flights to a destination (see Route::alternatives()).
     * Order defines the display order: Estonia first, then Lithuania.
     */
    public const ALTERNATIVE_ORIGINS = ['VNO', 'KUN'];

    /**
     * Country flag emoji for an IATA code's country, resolved via COUNTRIES.
     * Unknown countries fall back to the generic globe — a new route added
     * before its flag is known must never break rendering.
     */
    public static function flagFor(string $code): string
    {
        return self::FLAGS[self::countryName($code)] ?? '🌐';
    }

    /** Country name → flag emoji (Baltic states + the destinations we serve). */
    private const FLAGS = [
        'Latvia'    => '🇱🇻',
        'Estonia'   => '🇪🇪',
        'Lithuania' => '🇱🇹',
        'Malta'     => '🇲🇹',
        'Cyprus'    => '🇨🇾',
    ];

    /** Human name for an IATA code, or the bare code when unknown. */
    public static function airportName(string $code): string
    {
        return self::AIRPORTS[$code] ?? $code;
    }

    /** Country name for an IATA code, or the bare code when unknown. */
    public static function countryName(string $code): string
    {
        return self::COUNTRIES[$code] ?? $code;
    }

    /**
     * Label for one end of a route using country names. If the country has
     * multiple airports in the COUNTRIES map, the IATA code is appended in
     * parentheses (e.g. "Cyprus (LCA)"). Otherwise just the country name
     * (e.g. "Malta"). Country names are title-cased ("Latvia", not "LATVIA").
     */
    public static function countryLabel(string $code): string
    {
        $country = self::countryName($code);
        // Count how many airports map to this country.
        $count = count(array_keys(self::COUNTRIES, $country));
        if ($count > 1) {
            return $country . ' (' . $code . ')';
        }
        return $country;
    }

    /** Country-based label for a pair, e.g. "LATVIA MALTA". */
    public static function countryPairLabel(array $pair): string
    {
        return self::countryLabel($pair[0]) . ' ' . self::countryLabel($pair[1]);
    }

    /**
     * Alternative route pairs to a destination: every ALTERNATIVE_ORIGINS
     * airport that differs from the given origin, paired with it (same order as
     * ALTERNATIVE_ORIGINS). E.g. for ('RIX', 'PFO') → [['VNO','PFO'], ['KUN','PFO']].
     * Used by the bot UI to offer "depart from Estonia/Lithuania instead" when
     * the primary route has no flights on a date.
     */
    public static function alternatives(string $origin, string $destination): array
    {
        return array_values(array_map(
            fn($alt) => [$alt, $destination],
            array_filter(self::ALTERNATIVE_ORIGINS, fn($alt) => $alt !== $origin),
        ));
    }

    /**
     * Alternative destination airports for a given origin: every airport in the
     * same country as $origin that differs from it (same order as COUNTRIES).
     * E.g. for ('LCA') → ['PFO'] (both are Cyprus), for ('RIX') → [] (Latvia
     * has only RIX). Used by the bot UI to offer "arrive at the other Cyprus
     * airport instead" when the user picks a multi-airport country's leg.
     */
    public static function alternativeDestinations(string $origin): array
    {
        $country = self::COUNTRIES[$origin] ?? null;
        if ($country === null) {
            return [];
        }
        $siblings = array_keys(self::COUNTRIES, $country, true);
        return array_values(array_diff($siblings, [$origin]));
    }

    /** Outbound leg as an origin/destination pair. */
    public static function outbound(): array
    {
        return ['origin' => self::OUTBOUND_ORIGIN, 'destination' => self::OUTBOUND_DESTINATION];
    }

    /** Return leg as an origin/destination pair. */
    public static function return_(): array
    {
        return ['origin' => self::RETURN_ORIGIN, 'destination' => self::RETURN_DESTINATION];
    }

    /** Both directions, in travel order (outbound first). */
    public static function both(): array
    {
        return [self::outbound(), self::return_()];
    }

    /** Human label for the pair, e.g. "RIX ⇄ MLA". */
    public static function label(): string
    {
        return self::OUTBOUND_ORIGIN . ' ⇄ ' . self::OUTBOUND_DESTINATION;
    }
}
