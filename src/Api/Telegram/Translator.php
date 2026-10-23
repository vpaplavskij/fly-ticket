<?php

namespace FlyTicket\Api\Telegram;

use FlyTicket\Database\Schema;
use FlyTicket\Database\TranslationRepository;

/**
 * Lightweight translator for the Telegram bot.
 *
 * Translations live in the `translations` table (one row per lang/key) — the
 * single source of truth at runtime. The first t() call in a process seeds
 * any missing keys from src/Database/seed/*.php, then loads every row once
 * via TranslationRepository and caches them in memory — subsequent t() calls
 * are pure array lookups, no query per message. There is no file fallback: if
 * the database is unreachable the bot cannot serve localized text.
 *
 * The active language is per-chat, persisted in the `users` table so it
 * survives bot restarts.
 */
class Translator
{
    /** Supported language codes (file name → code). */
    public const LANGS = ['lv' => 'LV', 'ru' => 'RU', 'en' => 'ENG'];

    private static array $caches = [];
    private static ?string $activeLang = null;
    private static bool $loaded = false;

    /**
     * Set the active language for subsequent t() calls.
     */
    public static function set(string $lang): void
    {
        self::$activeLang = self::normalize($lang);
    }

    /**
     * Get the currently active language code, or 'en' if unset.
     */
    public static function get(): string
    {
        return self::$activeLang ?? 'en';
    }

    /**
     * Translate a key. Falls back to English, then to the key itself.
     * Supports {placeholder} substitution via $params.
     */
    public static function t(string $key, array $params = []): string
    {
        self::ensureLoaded();

        $lang = self::get();
        $str  = self::$caches[$lang][$key] ?? null;

        // Fallback to English if the key is missing in the active language.
        if ($str === null && $lang !== 'en') {
            $str = self::$caches['en'][$key] ?? null;
        }

        if ($str === null) {
            return $key;
        }

        foreach ($params as $k => $v) {
            $str = str_replace('{' . $k . '}', (string) $v, $str);
        }

        return $str;
    }

    /**
     * Load translations once per process. Seeds any missing keys from
     * src/Database/seed/*.php, then loads every row from the `translations`
     * table. There is no file fallback — a DB failure propagates so callers
     * see it rather than silently serving raw keys.
     */
    private static function ensureLoaded(): void
    {
        if (self::$loaded) {
            return;
        }

        $pdo  = \Database::getConnection();
        Schema::ensureTables($pdo);
        $repo = new TranslationRepository($pdo);
        $repo->seedFromFiles(__DIR__ . '/../../Database/seed');
        self::$caches = $repo->all();

        self::$loaded = true;
    }

    /**
     * Normalize a language code to its file name. Accepts 'LV', 'lv', 'Latvian', etc.
     */
    public static function normalize(string $lang): string
    {
        $lower = strtolower(trim($lang));
        return match ($lower) {
            'lv', 'latvian', 'latvia'  => 'lv',
            'ru', 'russian', 'russia'  => 'ru',
            'en', 'eng', 'english'     => 'en',
            default                    => 'en',
        };
    }

    /**
     * Human-readable language name for display.
     */
    public static function displayName(string $lang): string
    {
        return match (self::normalize($lang)) {
            'lv' => 'Latvian (LV)',
            'ru' => 'Russian (RU)',
            default => 'English (ENG)',
        };
    }
}
