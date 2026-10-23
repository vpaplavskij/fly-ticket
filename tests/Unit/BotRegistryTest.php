<?php

namespace FlyTicket\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Config\BotRegistry;
use Config\Bot;

/**
 * Unit tests for BotRegistry — the discovery of TELEGRAM_<NAME>_TOKEN env vars.
 * No network or database required (only .env, loaded by the bootstrap).
 */
class BotRegistryTest extends TestCase
{
    public function test_discovers_configured_bots(): void
    {
        $bots = BotRegistry::all();
        $this->assertNotEmpty($bots, 'at least one bot must be configured');

        // Sorted by name for deterministic ordering.
        $names = array_map(fn(Bot $b) => $b->name, $bots);
        $sorted = $names;
        sort($sorted);
        $this->assertSame($sorted, $names, 'bots must be sorted by name');
    }

    public function test_known_bots_present(): void
    {
        $names = BotRegistry::names();
        $this->assertContains('flycheap', $names, 'TELEGRAM_FLYCHEAP_TOKEN must be discovered');
    }

    public function test_get_returns_matching_bot(): void
    {
        $bot = BotRegistry::get('flycheap');
        $this->assertNotNull($bot);
        $this->assertSame('flycheap', $bot->name);
        $this->assertNotEmpty($bot->token);
        $this->assertSame('TELEGRAM_FLYCHEAP_TOKEN', $bot->tokenEnvVar());
    }

    public function test_get_is_case_insensitive(): void
    {
        $this->assertNotNull(BotRegistry::get('FLYCHEAP'));
        $this->assertNotNull(BotRegistry::get('FlyCheap'));
    }

    public function test_get_returns_null_for_unknown_bot(): void
    {
        $this->assertNull(BotRegistry::get('does-not-exist'));
    }

    public function test_names_matches_all(): void
    {
        $fromAll = array_map(fn(Bot $b) => $b->name, BotRegistry::all());
        $this->assertSame($fromAll, BotRegistry::names());
    }
}
