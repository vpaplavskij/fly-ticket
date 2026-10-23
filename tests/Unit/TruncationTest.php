<?php

namespace FlyTicket\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the Telegram 4096-char truncation logic.
 *
 * The truncation lives inline in telegram-bot.php (the long-polling loop), so it
 * cannot be called directly without running the bot. This test replicates that
 * exact algorithm and asserts the invariant that fixed the "Unclosed start tag"
 * 400 error: a truncated reply must never contain an unclosed <a>/<b>/<i> tag.
 *
 * Keep this in sync with the block in src/Api/Telegram/telegram-bot.php.
 */
class TruncationTest extends TestCase
{
    /** Mirror of the truncation block in telegram-bot.php. */
    private static function truncate(string $reply): string
    {
        if (strlen($reply) > 4096) {
            $truncated = substr($reply, 0, 4093);

            // Drop a trailing partial/dangling open tag with no closing ">".
            if (preg_match('/<([a-z][a-z0-9]*)(?:\s[^>]*)?$/i', $truncated)) {
                $truncated = preg_replace('/<([a-z][a-z0-9]*)(?:\s[^>]*)?$/i', '', $truncated);
            }

            $openA = substr_count($truncated, '<a ') - substr_count($truncated, '</a>');
            $openB = substr_count($truncated, '<b>')  - substr_count($truncated, '</b>');
            $openI = substr_count($truncated, '<i>')  - substr_count($truncated, '</i>');

            $tail = '';
            for ($i = 0; $i < max(0, $openA); $i++) { $tail .= '</a>'; }
            for ($i = 0; $i < max(0, $openB); $i++) { $tail .= '</b>'; }
            for ($i = 0; $i < max(0, $openI); $i++) { $tail .= '</i>'; }

            $reply = rtrim($truncated) . '...' . $tail;
        }
        return $reply;
    }

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
        $this->assertSame($short, self::truncate($short));
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

        $out = self::truncate($reply);

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

        $out = self::truncate($reply);
        $unclosed = self::unclosedCount($out);
        $this->assertSame([ 'a' => 0, 'b' => 0, 'i' => 0 ], array_map('intval', $unclosed),
            'dangling <a href=...> fragment was not stripped: ' . json_encode($unclosed));
    }
}
