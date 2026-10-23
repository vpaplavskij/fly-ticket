<?php

namespace FlyTicket\Tests\Unit;

use PHPUnit\Framework\TestCase;
use FlyTicket\Database\UserRepository;
use FlyTicket\Tests\DatabaseTestTrait;

/**
 * Integration tests for UserRepository (per-chat language preferences).
 * Requires a working DB connection (from .env).
 */
class UserRepositoryTest extends TestCase
{
    use DatabaseTestTrait;

    private const TEST_CHAT_ID = 999_999_999; // synthetic chat id, never real

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanUserRow();
    }

    protected function tearDown(): void
    {
        $this->cleanUserRow();
        parent::tearDown();
    }

    private function cleanUserRow(): void
    {
        $pdo = $this->testPdo();
        $pdo->prepare('DELETE FROM users WHERE chat_id = ?')->execute([self::TEST_CHAT_ID]);
    }

    public function test_getLang_returns_null_when_never_set(): void
    {
        $repo = new UserRepository($this->testPdo());
        $this->assertNull($repo->getLang(self::TEST_CHAT_ID));
    }

    public function test_setLang_then_getLang_round_trip(): void
    {
        $repo = new UserRepository($this->testPdo());
        $repo->setLang(self::TEST_CHAT_ID, 'lv');
        $this->assertSame('lv', $repo->getLang(self::TEST_CHAT_ID));

        $repo->setLang(self::TEST_CHAT_ID, 'ru');
        $this->assertSame('ru', $repo->getLang(self::TEST_CHAT_ID));
    }

    public function test_setLang_upserts_not_duplicates(): void
    {
        $repo = new UserRepository($this->testPdo());
        $repo->setLang(self::TEST_CHAT_ID, 'lv');
        $repo->setLang(self::TEST_CHAT_ID, 'en');

        $pdo = $this->testPdo();
        $stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM users WHERE chat_id = ?');
        $stmt->execute([self::TEST_CHAT_ID]);
        $this->assertSame(1, (int) $stmt->fetch(\PDO::FETCH_ASSOC)['c']);

        $repo2 = new UserRepository($pdo);
        $this->assertSame('en', $repo2->getLang(self::TEST_CHAT_ID));
    }
}
