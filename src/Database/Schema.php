<?php

namespace FlyTicket\Database;

class Schema
{
    public const FLIGHTS_TABLE       = 'flights';
    public const PRICE_HISTORY_TABLE = 'price_history';
    public const USERS_TABLE         = 'users';
    public const TRANSLATIONS_TABLE  = 'translations';

    /**
     * Ensure all tables exist. Called ONCE by `php src/index.php migrate`
     * (and by the test bootstrap); fetch scripts and bots no longer run DDL
     * on every start. Idempotent, so re-running migrate is safe.
     *
     * PostgreSQL dialect. There is no ON UPDATE CURRENT_TIMESTAMP here, so
     * the upserts set updated_at = now() explicitly.
     */
    public static function ensureTables($pdo): void
    {
        // ── flights: current state snapshot (latest price per flight code) ──
        $pdo->exec('CREATE TABLE IF NOT EXISTS ' . self::FLIGHTS_TABLE . ' (
            id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            code VARCHAR(64) NOT NULL UNIQUE,
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
        // The retention DELETE filters on fetched_at; without this index it
        // sequential-scans the whole table (which blocks the concurrent writers).
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_history_fetched ON ' . self::PRICE_HISTORY_TABLE . ' (fetched_at)');

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
