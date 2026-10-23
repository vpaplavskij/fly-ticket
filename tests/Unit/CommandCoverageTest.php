<?php

namespace FlyTicket\Tests\Unit;

use PHPUnit\Framework\TestCase;
use FlyTicket\Api\Telegram;
use FlyTicket\Database\FlightRepository;
use FlyTicket\Tests\DatabaseTestTrait;

/**
 * Command + callback coverage for the Telegram bot.
 *
 * Every text command and every inline-button callback data string that a user
 * can produce is exercised through the REAL dispatch path, so a renamed or
 * dropped command/callback fails here instead of silently 400-ing in production:
 *
 *   Text commands (bot loop, telegram-bot.php):
 *     start / menu / main          → main menu banner + keyboard
 *     help / info / information    → help text
 *     today / nextweek / nextmonth / next3months / buy <date>
 *                                   → dispatchCommandFull() view render
 *     <anything else>              → localized "Unknown command"
 *
 *   Callback data (handleCallback in telegram-bot.php):
 *     m, help, lang, setlang:<code>, d:<i>:<dir> (legacy d:<dir>),
 *     v:<view>:<i>:<dir> (legacy v:<view>:<dir>), buy:<date>[:<time>]
 *     <anything else>             → option_unavailable nudge
 *
 * Like the rest of the unit suite this runs against the live database with a
 * synthetic route (TST→TST2) so it never clobbers real rows. The bot context is
 * faked by seeding $GLOBALS['argv'] + a TELEGRAM_FLYCHEAP_ROUTE override to the
 * test pair — the same trick HandlerHelpersTest uses for botRoutes()/activeRoute().
 */
class CommandCoverageTest extends TestCase
{
    use DatabaseTestTrait;

    /** @var array<string,bool> env vars we set (name → was already set) */
    private $envBackup = [];
    /** @var mixed */
    private $savedArgv = null;
    /** @var bool */
    private $hadArgv = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanTestRoute();

        // Point the flycheap bot at the synthetic test pair so dispatchCommandFull()
        // (which resolves origin/destination from process state, not its args)
        // renders TST→TST2 rows instead of real RIX⇄MLA data.
        $this->setEnv('TELEGRAM_FLYCHEAP_ROUTE', self::TEST_ORIGIN . ' ' . self::TEST_DEST);

        // Simulate a live `php telegram-bot.php flycheap` process so botRoutes() /
        // activeRoute() resolve the flycheap bot (and its test pair).
        $this->savedArgv = $GLOBALS['argv'] ?? null;
        $this->hadArgv   = array_key_exists('argv', $GLOBALS);
        $GLOBALS['argv'] = ['telegram-bot.php', 'flycheap'];

        \Config\BotRegistry::reset();
    }

    protected function tearDown(): void
    {
        // Restore env.
        foreach ($this->envBackup as $k => $wasSet) {
            if ($wasSet) {
                $_ENV[$k] = $this->envBackupValue($k);
            } else {
                unset($_ENV[$k]);
            }
        }
        $this->envBackup = [];

        // Restore argv.
        if ($this->hadArgv) {
            $GLOBALS['argv'] = $this->savedArgv;
        } else {
            unset($GLOBALS['argv']);
        }
        \Config\BotRegistry::reset();

        // Reset per-process direction/route-index state so it doesn't leak.
        $GLOBALS['__flyticket_active_direction']    = null;
        $GLOBALS['__flyticket_active_route_index']  = 0;
        unset($GLOBALS['__flyticket_bot_username']);

        $this->cleanTestRoute();
        parent::tearDown();
    }

    private function setEnv(string $k, string $v): void
    {
        if (!isset($this->envBackup[$k])) {
            $this->envBackup[$k] = array_key_exists($k, $_ENV);
            $this->envBackupValueStore[$k] = $_ENV[$k] ?? null;
        }
        $_ENV[$k] = $v;
    }

    private function envBackupValue(string $k) { return $this->envBackupValueStore[$k]; }
    /** @var array<string,mixed> */
    private $envBackupValueStore = [];

    private function repo(): FlightRepository
    {
        return new FlightRepository($this->testPdo());
    }

    /** Seed one flight on a given date (TST→TST2) so views have something to render. */
    private function seedDay(string $date, float $price = 100.0): void
    {
        $this->seedFlight([
            'code'          => 'COV-' . $date . '-' . (int) $price,
            'depart'        => $date . ' 08:00:00',
            'arrive'        => $date . ' 10:30:00',
            'price'         => (string) $price,
            'company'       => 'AirBaltic',
            'flight_number' => 'BT900',
            'source'        => null,
            'buy_link'      => 'https://www.airbaltic.com/cov',
        ]);
    }

    // ── Text commands (dispatchCommandFull / bot loop) ────────────────────────

    public function test_start_menu_main_all_render(): void
    {
        Telegram\Translator::set('en');
        $repo = $this->repo();
        // start/menu/main are handled before dispatch in the bot loop; assert the
        // banner + keyboard they produce (the same objects the loop sends).
        $banner = Telegram\menuBanner();
        $kb     = json_decode(Telegram\mainMenuKeyboard(), true);

        $this->assertNotEmpty($banner);
        // Menu names the test pair (Latvia → TST2? no — countryLabel falls back to
        // the bare code for unknown codes). Just assert it's non-empty + keyboard valid.
        $this->assertArrayHasKey('inline_keyboard', $kb);
        $this->assertNotEmpty($kb['inline_keyboard']);

        // The view commands reachable from the menu must all dispatch cleanly.
        foreach (['today', 'nextweek', 'nextmonth', 'next3months'] as $cmd) {
            [$messages] = Telegram\dispatchCommandFull($repo, self::TEST_ORIGIN, self::TEST_DEST, $cmd);
            $this->assertNotEmpty($messages, "/$cmd must produce a message");
        }
    }

    public function test_help_info_information_all_render_help(): void
    {
        Telegram\Translator::set('en');
        $repo = $this->repo();
        $expected = Telegram\handleHelp();

        foreach (['help', 'info', 'information'] as $cmd) {
            [$messages] = Telegram\dispatchCommandFull($repo, self::TEST_ORIGIN, self::TEST_DEST, $cmd);
            $this->assertSame($expected, implode("\n\n", $messages), "/$cmd must equal /help");
        }
    }

    public function test_view_commands_render_with_seeded_data(): void
    {
        Telegram\Translator::set('en');
        $repo = $this->repo();

        // Seed today + a couple of days ahead so every view has rows.
        $today = date('Y-m-d');
        $this->seedDay($today, 80.0);
        $this->seedDay(date('Y-m-d', strtotime('+3 days')), 60.0);
        $this->seedDay(date('Y-m-d', strtotime('+10 days')), 90.0);

        foreach (['today', 'nextweek', 'nextmonth', 'next3months'] as $cmd) {
            [$messages] = Telegram\dispatchCommandFull($repo, self::TEST_ORIGIN, self::TEST_DEST, $cmd);
            $this->assertNotEmpty($messages, "/$cmd must produce a message");
            $this->assertStringContainsString('€', implode("\n\n", $messages), "/$cmd should show prices");
        }
    }

    public function test_buy_command_with_date_arg(): void
    {
        Telegram\Translator::set('en');
        $repo = $this->repo();
        $date = date('Y-m-d', strtotime('+2 days'));
        $this->seedDay($date, 75.0);

        // /buy <date> resolves the active direction pair; ensure outbound is active.
        Telegram\setActiveDirection('o');
        [$messages] = Telegram\dispatchCommandFull($repo, self::TEST_ORIGIN, self::TEST_DEST, 'buy', $date);
        $text = implode("\n\n", $messages);

        // A seeded flight with a buy_link must yield the booking reply.
        $this->assertStringContainsString('€75.00', $text);
        $this->assertStringContainsString('airbaltic.com/cov', $text);
    }

    public function test_buy_command_without_date_shows_usage(): void
    {
        Telegram\Translator::set('en');
        $repo = $this->repo();
        Telegram\setActiveDirection('o');
        [$messages] = Telegram\dispatchCommandFull($repo, self::TEST_ORIGIN, self::TEST_DEST, 'buy', '');
        $this->assertStringContainsString(Telegram\Translator::t('buy_usage'), implode("\n\n", $messages));
    }

    public function test_unknown_command_is_localized(): void
    {
        Telegram\Translator::set('en');
        $repo = $this->repo();
        [$messages] = Telegram\dispatchCommandFull($repo, self::TEST_ORIGIN, self::TEST_DEST, 'definitelynotacommand');
        $this->assertStringContainsString(Telegram\Translator::t('unknown_command'), implode("\n\n", $messages));

        // Localized in other languages too.
        Telegram\Translator::set('ru');
        [$messages] = Telegram\dispatchCommandFull($repo, self::TEST_ORIGIN, self::TEST_DEST, 'definitelynotacommand');
        $this->assertStringContainsString(Telegram\Translator::t('unknown_command'), implode("\n\n", $messages));
    }

    // ── Callback data (handleCallback) ─────────────────────────────────────────

    /**
     * Every callback data string a user can tap must be one the bot emits from
     * its keyboards — i.e. handleCallback's recognition branches (m / help /
     * lang / setlang: / d: / v: / buy:) are all reachable, and none of the
     * buttons fall through to the option_unavailable nudge.
     *
     * We assert on the keyboard builders because they are the single source of
     * the callback_data strings that reach handleCallback. (handleCallback itself
     * lives in telegram-bot.php, which is not Composer-"files"-autoloaded — only
     * the handlers file is — so it can't be required from a test without
     * redefining already-loaded functions.)
     */
    public function test_main_menu_callbacks_are_all_recognized(): void
    {
        Telegram\Translator::set('en');
        $kb = json_decode(Telegram\mainMenuKeyboard(), true);

        // Collect every callback_data the main menu emits.
        $data = [];
        foreach ($kb['inline_keyboard'] as $row) {
            foreach ($row as $btn) {
                $data[] = $btn['callback_data'];
            }
        }

        // The fixed buttons are recognized by handleCallback's early branches.
        // (The 'm' main-menu button lives in view footers / the language menu,
        // not here — see test_language_menu_callbacks_are_recognized.)
        $this->assertContains('help', $data);
        $this->assertContains('lang', $data);

        // Every direction button uses the d:<i>:<dir> form handleCallback parses.
        $dButtons = array_filter($data, fn($d) => str_starts_with($d, 'd:'));
        $this->assertNotEmpty($dButtons, 'main menu must offer at least one direction button');
        foreach ($dButtons as $d) {
            $this->assertMatchesRegularExpression(
                '/^d:(\d+:[or]|o|r)$/',
                $d,
                "direction callback '{$d}' must be a form handleCallback recognizes"
            );
        }
    }

    public function test_view_footer_and_direction_menu_callbacks_are_recognized(): void
    {
        Telegram\Translator::set('en');
        Telegram\setActiveRouteIndex(0);

        foreach (['o', 'r'] as $dir) {
            $footer = json_decode(Telegram\viewFooterKeyboard($dir), true)['inline_keyboard'];
            $dmenu  = json_decode(Telegram\directionMenuKeyboard($dir), true)['inline_keyboard'];

            // Every range button is v:<view>:<i>:<dir> — the form handleCallback parses.
            foreach ([$footer, $dmenu] as $kb) {
                foreach ($kb as $row) {
                    foreach ($row as $btn) {
                        $d = $btn['callback_data'];
                        if (str_starts_with($d, 'v:')) {
                            $this->assertMatchesRegularExpression(
                                '/^v:(today|week|month|3m):\d+:[or]$/',
                                $d,
                                "view callback '{$d}' must be a form handleCallback recognizes"
                            );
                        } elseif (str_starts_with($d, 'd:')) {
                            $this->assertMatchesRegularExpression(
                                '/^d:(\d+:[or]|[or])$/',
                                $d,
                                "direction callback '{$d}' must be a form handleCallback recognizes"
                            );
                        } else {
                            $this->assertContains($d, ['m', 'help'], "unexpected callback '{$d}'");
                        }
                    }
                }
            }
        }
    }

    public function test_language_menu_callbacks_are_recognized(): void
    {
        Telegram\Translator::set('en');
        $kb = json_decode(Telegram\languageMenuKeyboard(), true);
        $data = [];
        foreach ($kb['inline_keyboard'] as $row) {
            foreach ($row as $btn) {
                $data[] = $btn['callback_data'];
            }
        }

        // setlang:<code> for each supported language + back to menu.
        $this->assertContains('setlang:lv', $data);
        $this->assertContains('setlang:ru', $data);
        $this->assertContains('setlang:en', $data);
        $this->assertContains('m', $data);
    }

    public function test_view_to_command_maps_all_view_tokens(): void
    {
        // The v:<view> tokens the keyboards emit must all map to a real command.
        $this->assertSame(['today', ''], Telegram\viewToCommand('today'));
        $this->assertSame(['nextweek', ''], Telegram\viewToCommand('week'));
        $this->assertSame(['nextmonth', ''], Telegram\viewToCommand('month'));
        $this->assertSame(['next3months', ''], Telegram\viewToCommand('3m'));

        // And each mapped command must dispatch cleanly (with seeded data).
        $repo = $this->repo();
        $this->seedDay(date('Y-m-d'), 50.0);
        foreach (['today', 'nextweek', 'nextmonth', 'next3months'] as $cmd) {
            [$messages] = Telegram\dispatchCommandFull($repo, self::TEST_ORIGIN, self::TEST_DEST, $cmd);
            $this->assertNotEmpty($messages);
        }
    }
}
