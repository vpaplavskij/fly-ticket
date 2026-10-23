<?php

namespace FlyTicket\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Config\EnvLoader;

/**
 * Unit tests for EnvLoader.
 *
 * loadEnv() is idempotent (a static $loaded flag) and the bootstrap already
 * loads the real .env, so we can't easily re-run it against a synthetic file in
 * the same process. We therefore test the observable contract: after the
 * bootstrap has loaded the project .env, the DB_* vars that Config\Database
 * depends on are present. This catches a missing/mis-named .env key before it
 * blows up at runtime with "Missing required database env var".
 */
class EnvLoaderTest extends TestCase
{
    public function test_db_env_vars_present_after_load(): void
    {
        // The bootstrap (tests/bootstrap.php) calls loadEnv(). If the project
        // .env is missing any DB_* key this fails.
        foreach (['DB_HOST', 'DB_NAME', 'DB_USERNAME', 'DB_PASSWORD'] as $key) {
            $this->assertNotFalse(getenv($key), "env var {$key} must be set in .env");
            $this->assertNotEmpty(getenv($key), "env var {$key} must be non-empty");
        }
    }

    public function test_telegram_bots_configured(): void
    {
        // At least one bot must be configured; the registry discovers every
        // TELEGRAM_<NAME>_TOKEN var. If none are set, no bot can run.
        $this->assertNotEmpty(
            \Config\BotRegistry::all(),
            'at least one TELEGRAM_<NAME>_TOKEN var must be set in .env'
        );

        // flycheap is the only configured bot (the second "cheapway" group was removed).
        $names = \Config\BotRegistry::names();
        $this->assertContains('flycheap', $names, 'TELEGRAM_FLYCHEAP_TOKEN must be set');
    }

    public function test_loadEnv_is_idempotent_and_does_not_throw(): void
    {
        // Calling loadEnv() again after it has loaded once must not throw
        // (the static $loaded flag makes it a no-op).
        EnvLoader::loadEnv();
        $this->assertTrue(true); // reaching here means no exception was thrown
    }
}
