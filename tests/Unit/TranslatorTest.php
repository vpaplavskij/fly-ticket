<?php

namespace FlyTicket\Tests\Unit;

use PHPUnit\Framework\TestCase;
use FlyTicket\Api\Telegram\Translator;

/**
 * Unit tests for the Translator class. No database or network required.
 */
class TranslatorTest extends TestCase
{
    protected function tearDown(): void
    {
        // Reset to English so tests don't leak language state.
        Translator::set('en');
        parent::tearDown();
    }

    public function test_default_language_is_english(): void
    {
        $this->assertSame('en', Translator::get());
    }

    public function test_set_and_get_language(): void
    {
        Translator::set('lv');
        $this->assertSame('lv', Translator::get());

        Translator::set('ru');
        $this->assertSame('ru', Translator::get());
    }

    public function test_normalize_accepts_various_forms(): void
    {
        $this->assertSame('lv', Translator::normalize('LV'));
        $this->assertSame('lv', Translator::normalize('lv'));
        $this->assertSame('lv', Translator::normalize('Latvian'));
        $this->assertSame('ru', Translator::normalize('RU'));
        $this->assertSame('ru', Translator::normalize('russian'));
        $this->assertSame('en', Translator::normalize('ENG'));
        $this->assertSame('en', Translator::normalize('english'));
        // Unknown → English fallback.
        $this->assertSame('en', Translator::normalize('fr'));
    }

    public function test_t_returns_english_string_by_default(): void
    {
        $this->assertStringContainsString('Flight Price Monitor', Translator::t('menu_title'));
    }

    public function test_t_returns_latvian_when_set(): void
    {
        Translator::set('lv');
        $this->assertStringContainsString('Lidojumu cenas', Translator::t('menu_title'));
    }

    public function test_t_returns_russian_when_set(): void
    {
        Translator::set('ru');
        $this->assertStringContainsString('Монитор цен на авиабилеты', Translator::t('menu_title'));
    }

    public function test_t_returns_localized_string_for_known_key(): void
    {
        // Each language has its own btn_today string.
        Translator::set('lv');
        $this->assertStringContainsString('Šodienas lidojumi', Translator::t('btn_today'));

        Translator::set('ru');
        $this->assertStringContainsString('Рейсы сегодня', Translator::t('btn_today'));

        Translator::set('en');
        $this->assertStringContainsString("Today's flights", Translator::t('btn_today'));
    }

    public function test_t_substitutes_placeholders(): void
    {
        Translator::set('en');
        $result = Translator::t('lang_set', ['name' => 'Latvian (LV)']);
        $this->assertStringContainsString('Latvian (LV)', $result);
        $this->assertStringNotContainsString('{name}', $result);
    }

    public function test_t_substitutes_flights_count(): void
    {
        Translator::set('en');
        $result = Translator::t('flights_count', ['count' => 5, 's' => '']);
        $this->assertStringContainsString('5 flight', $result);
    }

    public function test_display_name(): void
    {
        $this->assertSame('Latvian (LV)', Translator::displayName('lv'));
        $this->assertSame('Russian (RU)', Translator::displayName('ru'));
        $this->assertSame('English (ENG)', Translator::displayName('en'));
    }

    public function test_langs_constant_lists_all_supported(): void
    {
        $this->assertArrayHasKey('lv', Translator::LANGS);
        $this->assertArrayHasKey('ru', Translator::LANGS);
        $this->assertArrayHasKey('en', Translator::LANGS);
    }
}
