<?php

namespace FlyTicket\Tests\Endpoints;

use PHPUnit\Framework\TestCase;
use Config\BotRegistry;
use FlyTicket\Api\Telegram\TelegramClient;

/**
 * Endpoint contract tests for the Telegram Bot API.
 *
 * These hit the LIVE api.telegram.org using the bot tokens from .env and assert
 * that TelegramClient's plumbing (token, URL building, JSON decoding) works
 * against the real service:
 *
 *   vendor/bin/phpunit --testsuite Endpoints
 *
 * getMe() is a read-only call — it never touches the user's chat and costs one
 * of the Bot API's rate limits, so it's safe to run. We deliberately do NOT
 * test sendMessage/getUpdates here: sending would spam the user and polling
 * would race with the running bot instance (only one poller per token).
 */
class TelegramEndpointTest extends TestCase
{
    /**
     * Every configured bot must have a working token. This catches a typo'd or
     * revoked token in .env before it silently breaks a bot at runtime.
     */
    public function test_all_configured_bots_verify(): void
    {
        $bots = BotRegistry::all();
        $this->assertNotEmpty($bots, 'at least one bot must be configured in .env');

        foreach ($bots as $bot) {
            $client = new TelegramClient($bot->token);
            $me     = $client->getMe();

            $this->assertTrue($me['ok'] ?? false, "bot '{$bot->name}' token should verify via getMe");
            $this->assertArrayHasKey('id', $me['result'] ?? [], "bot '{$bot->name}' getMe must carry a bot id");
        }
    }

    public function test_flycheap_bot_identity(): void
    {
        // The original bot. Locks its username so an accidental token swap is caught.
        $client = new TelegramClient(BotRegistry::get('flycheap')->token);
        $me     = $client->getMe();

        $this->assertTrue($me['ok'] ?? false, 'Telegram API should report ok=true');
        $result = $me['result'] ?? [];
        $this->assertArrayHasKey('id', $result, 'getMe result must carry the bot id');
        $this->assertSame('FlyCheapLatviaBot', $result['username'], 'bot username must match @FlyCheapLatviaBot');
    }
}
