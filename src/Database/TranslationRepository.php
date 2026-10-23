<?php

namespace FlyTicket\Database;

class TranslationRepository
{
    private \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Load every translation in one query: [lang => [key => value]].
     *
     * The Translator calls this once per process and caches the result in
     * memory, so t() itself never touches the database.
     */
    public function all(): array
    {
        $rows = $this->pdo->query(
            'SELECT lang_code, `key`, value FROM ' . Schema::TRANSLATIONS_TABLE
        )->fetchAll(\PDO::FETCH_ASSOC);

        $map = [];
        foreach ($rows as $row) {
            $map[$row['lang_code']][$row['key']] = $row['value'];
        }
        return $map;
    }

    /**
     * Upsert one translation. Idempotent — safe to run on every boot.
     */
    public function upsert(string $lang, string $key, string $value): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . Schema::TRANSLATIONS_TABLE . ' (lang_code, `key`, value)
             VALUES (:lang, :key, :value)
             ON DUPLICATE KEY UPDATE value = VALUES(value)'
        );
        $stmt->execute([
            ':lang'  => $lang,
            ':key'   => $key,
            ':value' => $value,
        ]);
    }

    /**
     * Seed translations from the src/Database/seed/*.php files.
     *
     * Runs on every boot and is cheap: one SELECT of existing rows, then a
     * single transaction that upserts only the (lang, key) pairs missing from
     * the table. Existing rows are never overwritten, so values edited
     * directly in the DB persist across restarts; new keys added to a seed
     * file appear on the next boot without any migration step.
     */
    public function seedFromFiles(string $seedDir): void
    {
        $existing = $this->all();

        $toInsert = [];
        foreach (['en', 'ru', 'lv'] as $lang) {
            $file = $seedDir . '/' . $lang . '.php';
            if (!is_file($file)) {
                continue;
            }
            $entries = require $file;
            foreach ($entries as $key => $value) {
                if (!isset($existing[$lang][(string) $key])) {
                    $toInsert[] = [$lang, (string) $key, (string) $value];
                }
            }
        }

        if (empty($toInsert)) {
            return; // nothing missing — nothing to do
        }

        $this->pdo->beginTransaction();
        try {
            foreach ($toInsert as [$lang, $key, $value]) {
                $this->upsert($lang, $key, $value);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
