<?php

namespace FlyTicket\Database;

class Schema
{
    public const FLIGHTS_TABLE       = 'flights';
    public const PRICE_HISTORY_TABLE = 'price_history';
    public const SEARCHES_TABLE      = 'searches';
    public const USERS_TABLE         = 'users';
    public const TRANSLATIONS_TABLE  = 'translations';

    /**
     * Ensure all tables exist. Safe to call from every fetch script —
     * each one owns its own source rows but shares the same schema.
     *
     * PostgreSQL dialect. There is no ON UPDATE CURRENT_TIMESTAMP here, so
     * the upserts set updated_at = now() explicitly.
     */
    public static function ensureTables($pdo): void
    {
        // ── searches ───────────────────────────────────────────────────
        $pdo->exec('CREATE TABLE IF NOT EXISTS ' . self::SEARCHES_TABLE . ' (
            id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            search_id VARCHAR(64) NOT NULL UNIQUE,
            origin VARCHAR(10),
            destination VARCHAR(10),
            depart_date DATE,
            source VARCHAR(16) NULL DEFAULT NULL,
            fetched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )');

        // ── flights: current state snapshot (latest price per flight code) ──
        $pdo->exec('CREATE TABLE IF NOT EXISTS ' . self::FLIGHTS_TABLE . ' (
            id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            code VARCHAR(64) NOT NULL UNIQUE,
            search_id VARCHAR(64),
            origin VARCHAR(10),
            destination VARCHAR(10),
            depart TIMESTAMP,
            arrive TIMESTAMP,
            price DECIMAL(10,2),
            company VARCHAR(128),
            flight_number VARCHAR(16),
            source VARCHAR(16) NULL DEFAULT NULL,
            buy_link TEXT,
            estimated SMALLINT NOT NULL DEFAULT 0,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_route_date ON ' . self::FLIGHTS_TABLE . ' (origin, destination, depart)');

        // ── price_history: one row per (source, date) per fetch run ──────
        $pdo->exec('CREATE TABLE IF NOT EXISTS ' . self::PRICE_HISTORY_TABLE . ' (
            id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            source VARCHAR(16),
            origin VARCHAR(10) NOT NULL,
            destination VARCHAR(10) NOT NULL,
            depart_date DATE NOT NULL,
            price DECIMAL(10,2) NOT NULL,
            company VARCHAR(128),
            flight_number VARCHAR(16),
            fetched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (source, origin, destination, depart_date, fetched_at)
        )');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_date_range ON ' . self::PRICE_HISTORY_TABLE . ' (origin, destination, depart_date)');

        // ── users: per-chat preferences (language, etc.) ───────────────
        $pdo->exec('CREATE TABLE IF NOT EXISTS ' . self::USERS_TABLE . ' (
            chat_id BIGINT PRIMARY KEY,
            lang VARCHAR(8) NOT NULL DEFAULT \'en\',
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )');

        // ── translations: DB-backed i18n for the Telegram bot ──────────
        // One row per (lang, key). The Translator loads all rows once per
        // process and caches them in memory — no query per t() call.
        $pdo->exec('CREATE TABLE IF NOT EXISTS ' . self::TRANSLATIONS_TABLE . ' (
            lang_code VARCHAR(8) NOT NULL,
            key VARCHAR(128) NOT NULL,
            value TEXT NOT NULL,
            PRIMARY KEY (lang_code, key)
        )');
    }
}
