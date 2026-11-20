<?php

namespace FlyTicket\Tests\Unit;

use FlyTicket\Api\Telegram;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Telegram 4096-char truncation logic (telegram-bot-handlers.php:
 * truncateReply), which every send path runs through.
 *
 * The real function is called directly — it is Composer "files"-autoloaded, so
 * this suite cannot drift out of sync with the implementation the way a mirrored
 * copy could. The invariant that fixed the "Unclosed start tag" 400 error: a
 * truncated reply must never contain an unclosed <a>/<b>/<i> tag, and it must
 * stay valid UTF-8 (json_encode() returns false on a broken string, which would
 * drop the message and its keyboard).
 */
class TruncationTest extends TestCase
{
    private static function unclosedCount(string $html): array
    {
        return [
            'a' => substr_count($html, '<a ') - substr_count($html, '</a>'),
            'b' => substr_count($html, '<b>')  - substr_count($html, '</b>'),
            'i' => substr_count($html, '<i>')  - substr_count($html, '</i>'),
        ];
    }

    public function test_short_reply_is_unchanged(): void
    {
        $short = "✈️ <b>Today's flights</b>\n10:50 → 13:25 · AirBaltic · <b>€229.99</b>";
        $this->assertSame($short, Telegram\truncateReply($short));
    }

    public function test_long_reply_is_within_limit_and_balanced(): void
    {
        // Build a >4096-char reply of realistic flight lines (each line has an
        // open <a> and <b>, mirroring real /nextmonth output).
        $lines = [];
        for ($i = 0; $i < 200; $i++) {
            $lines[] = 'Wed Nov 04  <b>€37.00</b> · Ryanair <a href="https://www.ryanair.com/gb/en/trip?tp=' . str_repeat('x', 60) . '">🎫</a>';
        }
        $reply = implode("\n", $lines);
        $this->assertGreaterThan(4096, strlen($reply), 'precondition: input must exceed the limit');

        $out = Telegram\truncateReply($reply);

        // Must fit Telegram's 4096-char message limit.
        $this->assertLessThanOrEqual(4096, strlen($out));

        // The critical invariant: no unclosed tags (the bug we fixed).
        $unclosed = self::unclosedCount($out);
        $this->assertSame([ 'a' => 0, 'b' => 0, 'i' => 0 ], array_map('intval', $unclosed),
            'truncated reply has unclosed HTML tags: ' . json_encode($unclosed));

        // Trailing ellipsis is appended.
        $this->assertStringContainsString('...', $out);
    }

    public function test_cut_landing_inside_an_anchor_is_repaired(): void
    {
        // Craft a reply whose 4093rd byte lands in the middle of an <a href=...> tag,
        // which is exactly the case that produced "Unclosed start tag at byte offset 4061".
        $prefix = str_repeat('A', 4050) . ' Wed Nov 04  <b>€37.00</b> · Ryanair <a href="https://www.ryanair.com/gb/en/trip?tpStartDate=2026-11';
        $suffix = str_repeat('B', 500); // push total well past 4096
        $reply = $prefix . $suffix;

        $out = Telegram\truncateReply($reply);
        $unclosed = self::unclosedCount($out);
        $this->assertSame([ 'a' => 0, 'b' => 0, 'i' => 0 ], array_map('intval', $unclosed),
            'dangling <a href=...> fragment was not stripped: ' . json_encode($unclosed));
    }

    public function test_cut_never_splits_a_multibyte_character(): void
    {
        // Regression: substr() cut at a byte offset, landing inside "€" (3 bytes).
        // The broken UTF-8 made json_encode() return false and Telegram rejected
        // the whole message, losing the last chunk and its navigation keyboard.
        $reply = str_repeat('€ ', 3000); // 9000 bytes, well past the limit
        $this->assertGreaterThan(4096, strlen($reply), 'precondition: input must exceed the limit');

        $out = Telegram\truncateReply($reply);

        $this->assertTrue(mb_check_encoding($out, 'UTF-8'), 'truncated reply is not valid UTF-8');
        $this->assertNotFalse(json_encode($out), 'truncated reply must survive json_encode');
        $this->assertStringContainsString('...', $out);
    }
}
