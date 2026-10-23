<?php

namespace Config;

/**
 * A single configured Telegram bot.
 *
 * Bots are discovered from .env by BotRegistry: every env var matching
 * TELEGRAM_<NAME>_TOKEN becomes a bot whose name is the lowercased <NAME>.
 * e.g. TELEGRAM_FLYCHEAP_TOKEN → bot "flycheap".
 */
final class Bot
{
    public function __construct(
        public readonly string $name,
        public readonly string $token,
        /**
         * Optional per-bot route override as an origin/destination pair
         * (e.g. ['origin' => 'RIX', 'destination' => 'MLA']). Defaults to the
         * global Config\Route::outbound() when unset. Lets one bot monitor a
         * different city pair than the rest of the app (see .env).
         */
        public readonly ?array $route = null,
        /**
         * Additional route pairs this bot offers alongside its primary route
         * (e.g. [['origin' => 'RIX', 'destination' => 'LCA']]). Each extra pair
         * gets its own direction buttons in the main menu and a full view set,
         * so one bot can monitor several city pairs (flycheap: RIX ⇄ MLA plus
         * RIX ⇄ LCA). The primary route stays first; extras are appended.
         */
        public readonly array $extraRoutes = [],
    ) {
    }

    /**
     * The env var that carries this bot's token.
     */
    public function tokenEnvVar(): string
    {
        return 'TELEGRAM_' . strtoupper($this->name) . '_TOKEN';
    }

    /**
     * This bot's monitored route as [origin, destination]. Falls back to the
     * global outbound route (Config\Route::outbound()) when no per-bot override
     * is configured.
     */
    public function originDestination(): array
    {
        if ($this->route !== null) {
            return [$this->route['origin'], $this->route['destination']];
        }

        $out = Route::outbound();
        return [$out['origin'], $out['destination']];
    }

    /**
     * All route pairs this bot offers: the primary route first, then any
     * extra routes (TELEGRAM_<NAME>_EXTRA_ROUTES). Each pair is
     * ['origin' => ..., 'destination' => ...]. The main menu renders one
     * direction button per leg of every pair.
     */
    public function allRoutes(): array
    {
        $routes = [$this->originDestination()];
        foreach ($this->extraRoutes as $extra) {
            $routes[] = [$extra['origin'], $extra['destination']];
        }

        return $routes;
    }
}
