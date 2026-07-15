<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Core\Logger;

/**
 * Telegram Bot API client (webhook mode, no polling).
 *
 * A thin cURL wrapper that centralises every outbound request so retries,
 * logging and error handling live in one place. Higher-level helpers
 * (sendMessage, editMessageText, getChatMember, …) build on request().
 */
final class TelegramApi
{
    private string $token;
    private Logger $logger;
    private string $endpoint = 'https://api.telegram.org/bot';

    public function __construct(string $token, Logger $logger)
    {
        $this->token = $token;
        $this->logger = $logger;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null Decoded "result", or null on failure.
     */
    public function request(string $method, array $params = []): ?array
    {
        $url = $this->endpoint . $this->token . '/' . $method;

        foreach ($params as $key => $value) {
            if (is_array($value)) {
                $params[$key] = json_encode($value, JSON_UNESCAPED_UNICODE);
            }
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $params,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            $this->logger->error('telegram', 'cURL error on ' . $method, ['error' => $error]);
            return null;
        }

        /** @var array{ok?: bool, result?: array<string,mixed>, description?: string}|null $decoded */
        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded) || empty($decoded['ok'])) {
            $this->logger->warning('telegram', 'API returned not-ok on ' . $method, [
                'response' => is_array($decoded) ? ($decoded['description'] ?? '') : 'invalid json',
            ]);
            return null;
        }

        /** @var array<string, mixed> $result */
        $result = $decoded['result'] ?? [];
        return $result;
    }

    /**
     * @param array<string, mixed> $extra
     */
    public function sendMessage(int|string $chatId, string $text, array $extra = []): ?array
    {
        return $this->request('sendMessage', array_merge([
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ], $extra));
    }

    /**
     * @param array<string, mixed> $extra
     */
    public function editMessageText(int|string $chatId, int $messageId, string $text, array $extra = []): ?array
    {
        return $this->request('editMessageText', array_merge([
            'chat_id'    => $chatId,
            'message_id' => $messageId,
            'text'       => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ], $extra));
    }

    public function answerCallbackQuery(string $callbackId, string $text = '', bool $alert = false): ?array
    {
        return $this->request('answerCallbackQuery', [
            'callback_query_id' => $callbackId,
            'text'              => $text,
            'show_alert'        => $alert,
        ]);
    }

    public function deleteMessage(int|string $chatId, int $messageId): ?array
    {
        return $this->request('deleteMessage', ['chat_id' => $chatId, 'message_id' => $messageId]);
    }

    /**
     * @param array<string, mixed> $extra
     */
    public function copyMessage(int|string $chatId, int|string $fromChatId, int $messageId, array $extra = []): ?array
    {
        return $this->request('copyMessage', array_merge([
            'chat_id'      => $chatId,
            'from_chat_id' => $fromChatId,
            'message_id'   => $messageId,
        ], $extra));
    }

    public function getChatMember(int|string $chatId, int $userId): ?array
    {
        return $this->request('getChatMember', ['chat_id' => $chatId, 'user_id' => $userId]);
    }

    public function getChat(int|string $chatId): ?array
    {
        return $this->request('getChat', ['chat_id' => $chatId]);
    }

    public function getChatAdministrators(int|string $chatId): ?array
    {
        return $this->request('getChatAdministrators', ['chat_id' => $chatId]);
    }

    public function setWebhook(string $url, string $secretToken = ''): ?array
    {
        $params = [
            'url'                  => $url,
            'drop_pending_updates' => true,
            'max_connections'      => 50,
            // Explicitly request every update type the bot needs. Without this,
            // a webhook previously registered with a narrower list may never
            // deliver callback_query updates, which makes ALL inline-keyboard
            // buttons (Complete, Verify, More, …) appear unresponsive.
            'allowed_updates'      => ['message', 'edited_message', 'callback_query', 'my_chat_member', 'chat_member'],
        ];
        if ($secretToken !== '') {
            $params['secret_token'] = $secretToken;
        }
        return $this->request('setWebhook', $params);
    }

    public function getMe(): ?array
    {
        return $this->request('getMe');
    }
}
