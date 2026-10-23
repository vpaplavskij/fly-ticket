<?php

namespace FlyTicket\Database;

class UserRepository
{
    private \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Get the stored language for a chat, or null if never set.
     */
    public function getLang(int|string $chatId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT lang FROM users WHERE chat_id = :id');
        $stmt->execute([':id' => $chatId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row['lang'] ?? null;
    }

    /**
     * Set (or update) the language for a chat.
     */
    public function setLang(int|string $chatId, string $lang): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (chat_id, lang) VALUES (:id, :lang)
             ON DUPLICATE KEY UPDATE lang = :lang2',
        );
        $stmt->execute([
            ':id'    => $chatId,
            ':lang'  => $lang,
            ':lang2' => $lang,
        ]);
    }
}
