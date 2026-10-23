<?php

namespace FlyTicket\Api\Telegram;

class TelegramClient
{
    private const BASE_URL = 'https://api.telegram.org';

    private string $token;

    public function __construct(string $token)
    {
        $this->token = $token;
    }

    private function request(string $method, array $params = []): array
    {
        $ch = curl_init(self::BASE_URL . '/bot' . $this->token . '/' . $method);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($params),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 35, // slightly longer than default for long polling
        ]);

        $response = curl_exec($ch);
        $status   = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curl_err = curl_error($ch);

        if ($response === false) {
            throw new \RuntimeException("Telegram cURL error: {$curl_err}");
        }

        $decoded = json_decode($response, true);

        if ($status >= 400 || !is_array($decoded)) {
            throw new \RuntimeException(
                "Telegram API error {$status}: " . ($decoded['description'] ?? $response),
            );
        }

        return $decoded;
    }

    /**
     * Send a message to a chat.
     */
    public function sendMessage(string $chatId, string $text, ?string $replyMarkup = null): array
    {
        $params = [
            'chat_id'                 => $chatId,
            'text'                    => $text,
            'parse_mode'              => 'HTML',
            'disable_web_page_preview' => true,
        ];

        if ($replyMarkup !== null) {
            $params['reply_markup'] = json_decode($replyMarkup, true);
        }

        return $this->request('sendMessage', $params);
    }

    /**
     * Edit an existing message's text and/or inline keyboard.
     */
    public function editMessageText(string $chatId, int $messageId, string $text, ?string $replyMarkup = null): array
    {
        $params = [
            'chat_id'          => $chatId,
            'message_id'       => $messageId,
            'text'             => $text,
            'parse_mode'       => 'HTML',
            'disable_web_page_preview' => true,
        ];

        if ($replyMarkup !== null) {
            $params['reply_markup'] = json_decode($replyMarkup, true);
        }

        return $this->request('editMessageText', $params);
    }

    /**
     * Delete a message from the chat. Returns false when Telegram refuses
     * (e.g. "message to delete not found" for already-deleted or foreign
     * messages) instead of throwing, so callers can fire-and-forget.
     */
    public function deleteMessage(string $chatId, int $messageId): bool
    {
        try {
            $this->request('deleteMessage', ['chat_id' => $chatId, 'message_id' => $messageId]);
            return true;
        } catch (\RuntimeException $e) {
            // "Message to delete not found" is expected when a message was
            // already deleted or the bot has no permission — not an error.
            if (str_contains($e->getMessage(), 'not found')) {
                return false;
            }
            throw $e;
        }
    }

    /**
     * Get pending updates (long polling).
     */
    public function getUpdates(int $timeout = 30, ?int $offset = null): array
    {
        $params = [
            'timeout' => $timeout,
        ];

        if ($offset !== null) {
            $params['offset'] = $offset;
        }

        return $this->request('getUpdates', $params);
    }

    /**
     * Get bot info (useful for verifying the token works).
     */
    public function getMe(): array
    {
        return $this->request('getMe');
    }
}
