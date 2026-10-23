<?php

namespace Config;

/**
 * Registry of all configured Telegram bots.
 *
 * Bots are discovered from .env: every env var matching the pattern
 * TELEGRAM_<NAME>_TOKEN (where <NAME> is uppercase letters/digits, not "BOT")
 * becomes a bot named after the lowercased <NAME>.
 *
 *   TELEGRAM_FLYCHEAP_TOKEN  → bot "flycheap"
 *
 * To add a new bot: create it in @BotFather, add its token line to .env, and
 * (re)start the bot process. No code changes needed — the registry picks it up
 * automatically. Bots are returned sorted by name for deterministic ordering.
 *
 * Optional per-bot route override: TELEGRAM_<NAME>_ROUTE="RIX MLA" makes that
 * bot monitor RIX ⇄ MLA instead of the global Config\Route. The pair is origin
 * destination, space-separated IATA codes.
 *
 * Optional per-bot extra routes: TELEGRAM_<NAME>_EXTRA_ROUTES="RIX LCA" adds one
 * or more route pairs ("RIX LCA RIX PFO") offered alongside the primary route —
 * each gets its own direction buttons in the main menu (flycheap: RIX ⇄ MLA plus
 * RIX ⇄ LCA and RIX ⇄ PFO).
 */
final class BotRegistry
{
    /** Pattern: TELEGRAM_<NAME>_TOKEN where <NAME> is uppercase alnum, not "BOT". */
    private const TOKEN_VAR_RE = '/^TELEGRAM_([A-Z0-9]+)_TOKEN$/';

    /** @var list<Bot>|null Discovered bots, or null if not yet loaded. */
    private static ?array $bots = null;

    /**
     * All configured bots, sorted by name. Empty if no TELEGRAM_*_TOKEN vars are set.
     * The discovery result is cached for the lifetime of the process — env vars
     * are loaded once at startup, so re-scanning $_ENV on every call was wasted work.
     */
    public static function all(): array
    {
        if (self::$bots === null) {
            \Config\EnvLoader::loadEnv();

            $bots = [];
            foreach ($_ENV as $key => $value) {
                if (!is_string($key) || !preg_match(self::TOKEN_VAR_RE, $key, $m)) {
                    continue;
                }
                if ($value === '' || $value === null) {
                    continue;
                }
                $name = strtolower($m[1]);
                // Skip a hypothetical TELEGRAM_BOT_TOKEN (the old single-bot name).
                if ($name === 'bot') {
                    continue;
                }

                // Optional per-bot route override: TELEGRAM_<NAME>_ROUTE="RIX HEL"
                // (origin destination, space-separated IATA codes). Lets one bot
                // monitor a different city pair than the global Config\Route.
                $route = null;
                $routeVar = 'TELEGRAM_' . strtoupper($m[1]) . '_ROUTE';
                if (!empty($_ENV[$routeVar])) {
                    [$ro, $rd] = array_pad(explode(' ', trim((string) $_ENV[$routeVar])), 2, '');
                    if ($ro !== '' && $rd !== '') {
                        $route = ['origin' => strtoupper($ro), 'destination' => strtoupper($rd)];
                    }
                }

                // Optional per-bot extra routes: TELEGRAM_<NAME>_EXTRA_ROUTES="RIX LCA"
                // (or "RIX LCA RIX HEL" for several). Each space-separated pair is
                // offered alongside the bot's primary route — the main menu gets a
                // direction button per leg, and each leg has its own full view set.
                $extraRoutes = [];
                $extraVar = 'TELEGRAM_' . strtoupper($m[1]) . '_EXTRA_ROUTES';
                if (!empty($_ENV[$extraVar])) {
                    $codes = preg_split('/\s+/', trim((string) $_ENV[$extraVar]));
                    for ($i = 0; $i + 1 < count($codes); $i += 2) {
                        if ($codes[$i] !== '' && $codes[$i + 1] !== '') {
                            $extraRoutes[] = ['origin' => strtoupper($codes[$i]), 'destination' => strtoupper($codes[$i + 1])];
                        }
                    }
                }

                $bots[$name] = new Bot($name, (string) $value, $route, $extraRoutes);
            }

            ksort($bots);
            self::$bots = array_values($bots);
        }

        return self::$bots;
    }

    /**
     * Clear the cached discovery result so the next call to all() re-scans
     * $_ENV. Useful in tests that mutate env vars after the first lookup.
     */
    public static function reset(): void
    {
        self::$bots = null;
    }

    /**
     * Look up a bot by name (case-insensitive). Returns null if not configured.
     */
    public static function get(string $name): ?Bot
    {
        foreach (self::all() as $bot) {
            if (strcasecmp($bot->name, $name) === 0) {
                return $bot;
            }
        }
        return null;
    }

    /**
     * Names of all configured bots (sorted). Useful for CLI help / diagnostics.
     */
    public static function names(): array
    {
        return array_map(fn(Bot $b) => $b->name, self::all());
    }
}
