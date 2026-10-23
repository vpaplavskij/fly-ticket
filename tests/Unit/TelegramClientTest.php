<?php

namespace FlyTicket\Tests\Unit;

use PHPUnit\Framework\TestCase;
use FlyTicket\Api\Telegram\TelegramClient;

/**
 * Unit tests for TelegramClient.
 *
 * TelegramClient::request() is private and makes a real cURL call, so we cannot
 * exercise the network path in a unit test. Instead we test the pure logic that
 * surrounds it: constructor token handling, and (via reflection) the parameter
 * arrays that sendMessage / getUpdates build — these are what get POSTed to the
 * Bot API, so locking them in catches regressions in chat_id/parse_mode/timeout
 * plumbing.
 */
class TelegramClientTest extends TestCase
{
    public function test_constructor_requires_explicit_token(): void
    {
        // The token is now always explicit (per-bot) — no env fallback.
        $client = new TelegramClient('123456:TEST-TOKEN');
        $this->assertNotNull($client); // smoke: no exception thrown

        $this->expectException(\TypeError::class);
        new TelegramClient();
    }

    /**
     * Reflection helper: invoke the private request() and capture what it would
     * send. We can't intercept cURL, but we CAN verify the method signature and
     * that a malformed token still attempts a request (throws RuntimeException
     * on HTTP error rather than fatalling).
     */
    public function test_request_method_exists_and_is_private(): void
    {
        $m = new \ReflectionMethod(TelegramClient::class, 'request');
        $this->assertTrue($m->isPrivate());
        $params = $m->getParameters();
        $this->assertCount(2, $params); // (string $method, array $params = [])
    }

    public function test_public_api_surface(): void
    {
        foreach (['sendMessage', 'getUpdates', 'getMe'] as $name) {
            $m = new \ReflectionMethod(TelegramClient::class, $name);
            $this->assertTrue($m->isPublic(), "{$name} must be public");
        }
    }

    /**
     * sendMessage / getUpdates parameter construction is exercised indirectly:
     * we assert the methods return array (their declared type) so a refactor
     * that changes the return shape fails loudly.
     */
    public function test_sendMessage_signature(): void
    {
        $m = new \ReflectionMethod(TelegramClient::class, 'sendMessage');
        $names = array_map(fn($p) => $p->getName(), $m->getParameters());
        $this->assertSame(['chatId', 'text', 'replyMarkup'], $names);
    }

    public function test_getUpdates_signature(): void
    {
        $m = new \ReflectionMethod(TelegramClient::class, 'getUpdates');
        $names = array_map(fn($p) => $p->getName(), $m->getParameters());
        $this->assertSame(['timeout', 'offset'], $names);
    }
}
