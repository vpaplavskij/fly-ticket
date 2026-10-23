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
     */
    public static function ensureTables($pdo): void
    {
        // Round-trip fares were removed from the app; drop any legacy table.
        $pdo->exec('DROP TABLE IF EXISTS round_trip_fares');

        $pdo->exec('CREATE TABLE IF NOT EXISTS ' . self::SEARCHES_TABLE . ' (
            id INT AUTO_INCREMENT PRIMARY KEY,
            search_id VARCHAR(64) NOT NULL UNIQUE,
            origin VARCHAR(10),
            destination VARCHAR(10),
            depart_date DATE,
            source VARCHAR(16) NULL DEFAULT NULL,
            fetched_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        // ── flights: current state snapshot (latest price per flight code) ──
        $pdo->exec('CREATE TABLE IF NOT EXISTS ' . self::FLIGHTS_TABLE . ' (
            id INT AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(64) NOT NULL UNIQUE,
            search_id VARCHAR(64),
            origin VARCHAR(10),
            destination VARCHAR(10),
            depart DATETIME,
            arrive DATETIME,
            price VARCHAR(32),
            company VARCHAR(128),
            flight_number VARCHAR(16),
            source VARCHAR(16) NULL DEFAULT NULL,
            buy_link TEXT,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_route_date (origin, destination, depart)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        // ── price_history: one row per (source, date) per fetch run ──────
        $pdo->exec('CREATE TABLE IF NOT EXISTS ' . self::PRICE_HISTORY_TABLE . ' (
            id INT AUTO_INCREMENT PRIMARY KEY,
            source VARCHAR(16),
            origin VARCHAR(10) NOT NULL,
            destination VARCHAR(10) NOT NULL,
            depart_date DATE NOT NULL,
            price DECIMAL(10,2) NOT NULL,
            company VARCHAR(128),
            flight_number VARCHAR(16),
            fetched_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_source_date (source, origin, destination, depart_date, fetched_at),
            INDEX idx_date_range (origin, destination, depart_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        // ── users: per-chat preferences (language, etc.) ───────────────
        $pdo->exec('CREATE TABLE IF NOT EXISTS ' . self::USERS_TABLE . ' (
            chat_id BIGINT PRIMARY KEY,
            lang VARCHAR(8) NOT NULL DEFAULT "en",
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        // ── translations: DB-backed i18n for the Telegram bot ──────────
        // One row per (lang, key). The Translator loads all rows once per
        // process and caches them in memory — no query per t() call.
        $pdo->exec('CREATE TABLE IF NOT EXISTS ' . self::TRANSLATIONS_TABLE . ' (
            lang_code VARCHAR(8) NOT NULL,
            `key` VARCHAR(128) NOT NULL,
            value TEXT NOT NULL,
            PRIMARY KEY (lang_code, `key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }
}
