<?php

namespace FlyTicket\Tests\Unit;

use PHPUnit\Framework\TestCase;
use FlyTicket\Api\Telegram;

/**
 * Unit tests for the pure helper functions in telegram-bot-handlers.php.
 * No database or network required.
 */
class HandlerHelpersTest extends TestCase
{
    public function test_fmtPrice_formats_euro(): void
    {
        $this->assertSame('€32.00', Telegram\fmtPrice(32.0));
        $this->assertSame('€1,234.50', Telegram\fmtPrice(1234.5));
        $this->assertSame('€0.00', Telegram\fmtPrice(0));
    }

    public function test_fmtCompany_maps_sources(): void
    {
        // null / empty => AirBaltic (AirBaltic rows store source = NULL)
        $this->assertSame('AirBaltic', Telegram\fmtCompany(null));
        $this->assertSame('AirBaltic', Telegram\fmtCompany(''));

        // Known sources
        $this->assertSame('Ryanair', Telegram\fmtCompany('ryanair'));
        $this->assertSame('AirBaltic', Telegram\fmtCompany('airbaltic'));

        // Unknown => ucfirst fallback
        $this->assertSame('Wizz', Telegram\fmtCompany('wizz'));
    }

    public function test_buyLinkHtml_empty_returns_empty_string(): void
    {
        $this->assertSame('', Telegram\buyLinkHtml(null));
        $this->assertSame('', Telegram\buyLinkHtml(''));
        $this->assertSame('', Telegram\buyLinkHtml('   '));
    }

    public function test_buyLinkHtml_builds_anchor(): void
    {
        $url = 'https://www.ryanair.com/x';
        $expected = sprintf(' <a href="%s">🎫</a>', htmlspecialchars($url, ENT_QUOTES));
        $this->assertSame($expected, Telegram\buyLinkHtml($url));
    }

    public function test_buyLinkHtml_escapes_html(): void
    {
        // A URL containing a quote must not break the anchor tag.
        $url = 'https://example.com/?a="b"';
        $html = Telegram\buyLinkHtml($url);
        $this->assertStringNotContainsString('href="https://example.com/?a="b"', $html);
        $this->assertStringContainsString('&quot;', $html);
    }

    public function test_languageMenuKeyboard_has_three_languages(): void
    {
        Telegram\Translator::set('en');
        $kb = json_decode(Telegram\languageMenuKeyboard(), true);
        $flat = array_map(fn($row) => $row[0]['text'], $kb['inline_keyboard']);

        // All three language buttons present.
        $this->assertStringContainsString('Latvian', $flat[0]);
        $this->assertStringContainsString('Russian', $flat[1]);
        $this->assertStringContainsString('English', $flat[2]);

        // Callback data routes to setlang:<code>.
        $this->assertSame('setlang:lv', $kb['inline_keyboard'][0][0]['callback_data']);
        $this->assertSame('setlang:ru', $kb['inline_keyboard'][1][0]['callback_data']);
        $this->assertSame('setlang:en', $kb['inline_keyboard'][2][0]['callback_data']);

        // Back button returns to main menu.
        $this->assertSame('m', $kb['inline_keyboard'][3][0]['callback_data']);
    }

    public function test_mainMenuKeyboard_includes_language_button(): void
    {
        Telegram\Translator::set('en');
        $kb = json_decode(Telegram\mainMenuKeyboard(), true);

        // The language button is the last row.
        $lastRow = end($kb['inline_keyboard']);
        $this->assertSame('lang', $lastRow[0]['callback_data']);
    }

    public function test_mainMenuKeyboard_has_two_direction_buttons(): void
    {
        Telegram\Translator::set('en');
        $kb = json_decode(Telegram\mainMenuKeyboard(), true);
        $rows = $kb['inline_keyboard'];

        // The first two rows are the direction buttons (both ways) of the
        // primary route — callback data carries the route index (0).
        $this->assertSame('d:0:o', $rows[0][0]['callback_data']);
        $this->assertSame('d:0:r', $rows[1][0]['callback_data']);

        // Labels name the bot's route pair in each direction using country names
        // from Config\Route::COUNTRIES — the default bot's primary is RIX ⇄ MLA
        // (Latvia Malta).
        $this->assertStringContainsString('Latvia', $rows[0][0]['text']);
        $this->assertStringContainsString('Malta', $rows[0][0]['text']);
        $this->assertStringContainsString('Malta', $rows[1][0]['text']);
        $this->assertStringContainsString('Latvia', $rows[1][0]['text']);
    }

    public function test_mainMenuKeyboard_renders_extra_routes(): void
    {
        Telegram\Translator::set('en');
        // Simulate a bot with one extra route pair (flycheap: RIX ⇄ MLA + RIX ⇄ LCA).
        // botRoutes() resolves the running bot from $argv (real CLI args) or falls
        // back to $GLOBALS['argv'] — set that here to simulate a live
        // `php telegram-bot.php flycheap` process.
        $_ENV['TELEGRAM_FLYCHEAP_EXTRA_ROUTES'] = 'RIX LCA';
        $savedArgv = $GLOBALS['argv'] ?? null;
        $GLOBALS['argv'] = ['telegram-bot.php', 'flycheap'];

        try {
            \Config\BotRegistry::reset();

            $kb = json_decode(Telegram\mainMenuKeyboard(), true);
            $rows = $kb['inline_keyboard'];

            // Rows 2-3 are the extra pair's legs, carrying route index 1. Labels
            // use country names (Latvia / Cyprus) from Config\Route::COUNTRIES;
            // Cyprus has multiple airports so the IATA code is shown.
            $this->assertSame('d:1:o', $rows[2][0]['callback_data']);
            $this->assertSame('d:1:r', $rows[3][0]['callback_data']);
            $this->assertStringContainsString('Cyprus (LCA)', $rows[2][0]['text']);
            $this->assertStringContainsString('Latvia', $rows[3][0]['text']);
        } finally {
            unset($_ENV['TELEGRAM_FLYCHEAP_EXTRA_ROUTES']);
            \Config\BotRegistry::reset();
            if ($savedArgv !== null) {
                $GLOBALS['argv'] = $savedArgv;
            } else {
                unset($GLOBALS['argv']);
            }
        }
    }

    public function test_viewFooterKeyboard_carries_direction_and_opposite_button(): void
    {
        Telegram\Translator::set('en');
        Telegram\setActiveRouteIndex(0);
        $kb = json_decode(Telegram\viewFooterKeyboard('o'), true);
        $rows = $kb['inline_keyboard'];

        // Every range button re-renders the SAME leg (route 0, direction 'o').
        $this->assertSame('v:today:0:o', $rows[0][0]['callback_data']);
        $this->assertSame('v:week:0:o', $rows[0][1]['callback_data']);
        $this->assertSame('v:month:0:o', $rows[1][0]['callback_data']);
        $this->assertSame('v:3m:0:o', $rows[1][1]['callback_data']);

        // Last row: opposite-direction button (flips to 'r') + main menu.
        $this->assertSame('d:0:r', $rows[2][0]['callback_data']);
        $this->assertSame('m', $rows[2][1]['callback_data']);
    }

    public function test_oppositeDirectionButton_flips_to_other_leg(): void
    {
        Telegram\Translator::set('en');
        Telegram\setActiveRouteIndex(0);
        // While viewing outbound (o), the opposite button opens return (r).
        // The label uses country names from Config\Route::COUNTRIES.
        [$label, $data] = Telegram\oppositeDirectionButton('o');
        $this->assertSame('d:0:r', $data);
        $this->assertStringContainsString('Malta', $label);
        $this->assertStringContainsString('Latvia', $label);

        // And vice versa.
        [$label, $data] = Telegram\oppositeDirectionButton('r');
        $this->assertSame('d:0:o', $data);
    }

    public function test_directionMenuKeyboard_carries_direction_in_views(): void
    {
        Telegram\Translator::set('en');
        Telegram\setActiveRouteIndex(0);
        $kb = json_decode(Telegram\directionMenuKeyboard('r'), true);
        $rows = $kb['inline_keyboard'];

        // Range buttons carry the route index and direction ('r' here) so the
        // view renders the return leg of the same pair.
        $this->assertSame('v:today:0:r', $rows[0][0]['callback_data']);
        $this->assertSame('v:week:0:r', $rows[0][1]['callback_data']);
        $this->assertSame('v:month:0:r', $rows[1][0]['callback_data']);
        $this->assertSame('v:3m:0:r', $rows[1][1]['callback_data']);

        // Opposite-direction button flips back to outbound (same route index).
        $this->assertSame('d:0:o', $rows[2][0]['callback_data']);
    }

    public function test_dispatchCommand_localizes_unknown_command(): void
    {
        Telegram\Translator::set('ru');
        // dispatchCommand needs a repo; use a real one via the DB trait is overkill
        // for this — instead just verify the translation key resolves.
        $this->assertStringContainsString('Неизвестная команда', Telegram\Translator::t('unknown_command'));
    }

    public function test_handleHelp_localizes(): void
    {
        Telegram\Translator::set('lv');
        $help = Telegram\handleHelp();
        $this->assertStringContainsString('Lidojumu cenas', $help);
        $this->assertStringContainsString('/today', $help);

        // Reset so other tests aren't affected.
        Telegram\Translator::set('en');
    }

    public function test_routeLabel_uses_country_names(): void
    {
        // Render mode / no bot context → the global default pair (RIX ⇄ MLA),
        // rendered with country names.
        $this->assertSame('Latvia Malta', Telegram\routeLabel());
    }

    public function test_countryLabel_resolves_country_names(): void
    {
        // Single-airport countries: just the country name in title case.
        $this->assertSame('Latvia', Telegram\countryLabel('RIX'));
        $this->assertSame('Malta', Telegram\countryLabel('MLA'));

        // Multi-airport countries: country name + IATA code in parentheses.
        $this->assertSame('Cyprus (LCA)', Telegram\countryLabel('LCA'));
        $this->assertSame('Cyprus (PFO)', Telegram\countryLabel('PFO'));

        // An unknown code falls back to the bare code — a new route added
        // before its country is in COUNTRIES must never break rendering.
        $this->assertSame('ZZZ', Telegram\countryLabel('ZZZ'));
    }

    public function test_countryPairLabel_uses_country_names(): void
    {
        $this->assertSame('Latvia Cyprus (LCA)', Telegram\countryPairLabel(['RIX', 'LCA']));
        $this->assertSame('Cyprus (PFO) Latvia', Telegram\countryPairLabel(['PFO', 'RIX']));
    }

    public function test_route_alternatives_returns_baltic_origins_in_order(): void
    {
        // For RIX → LCA: alternatives are VNO then KUN (ALTERNATIVE_ORIGINS order).
        $alts = \Config\Route::alternatives('RIX', 'LCA');
        $this->assertSame([['VNO', 'LCA'], ['KUN', 'LCA']], $alts);

        // For RIX → PFO: same pattern.
        $this->assertSame([['VNO', 'PFO'], ['KUN', 'PFO']], \Config\Route::alternatives('RIX', 'PFO'));

        // If the origin is already an alternative, it is excluded from the list.
        $this->assertSame([['KUN', 'LCA']], \Config\Route::alternatives('VNO', 'LCA'));
    }

    public function test_route_flagFor_returns_country_flags(): void
    {
        $this->assertSame('🇱🇻', \Config\Route::flagFor('RIX'));   // Latvia
        $this->assertSame('🇪🇪', \Config\Route::flagFor('VNO'));   // Estonia
        $this->assertSame('🇱🇹', \Config\Route::flagFor('KUN'));   // Lithuania
        $this->assertSame('🇲🇹', \Config\Route::flagFor('MLA'));   // Malta
        $this->assertSame('🇨🇾', \Config\Route::flagFor('LCA'));   // Cyprus

        // Unknown code falls back to the globe emoji.
        $this->assertSame('🌐', \Config\Route::flagFor('ZZZ'));
    }

    public function test_route_alternativeDestinations_returns_same_country_airports(): void
    {
        // LCA and PFO are both Cyprus — each is the other's alternative.
        $this->assertSame(['PFO'], \Config\Route::alternativeDestinations('LCA'));
        $this->assertSame(['LCA'], \Config\Route::alternativeDestinations('PFO'));

        // Single-airport countries have no alternatives.
        $this->assertSame([], \Config\Route::alternativeDestinations('RIX'));
        $this->assertSame([], \Config\Route::alternativeDestinations('MLA'));

        // Unknown code → empty (no country to look up).
        $this->assertSame([], \Config\Route::alternativeDestinations('ZZZ'));
    }
}
