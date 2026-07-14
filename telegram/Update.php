<?php

declare(strict_types=1);

namespace App\Telegram;

/**
 * Immutable value object over a raw Telegram update payload.
 *
 * Normalises access to the fields the bot cares about (message text, callback
 * data, forwarded origin, chat id) so handlers stay clean.
 */
final class Update
{
    /** @param array<string, mixed> $raw */
    public function __construct(private array $raw)
    {
    }

    /** @return array<string, mixed> */
    public function raw(): array
    {
        return $this->raw;
    }

    public function isCallback(): bool
    {
        return isset($this->raw['callback_query']);
    }

    public function isMessage(): bool
    {
        return isset($this->raw['message']);
    }

    /** @return array<string, mixed> */
    public function message(): array
    {
        if ($this->isCallback()) {
            return $this->raw['callback_query']['message'] ?? [];
        }
        return $this->raw['message'] ?? [];
    }

    /** @return array<string, mixed> */
    public function from(): array
    {
        if ($this->isCallback()) {
            return $this->raw['callback_query']['from'] ?? [];
        }
        return $this->raw['message']['from'] ?? [];
    }

    public function userId(): ?int
    {
        $id = $this->from()['id'] ?? null;
        return $id === null ? null : (int) $id;
    }

    public function chatId(): ?int
    {
        $id = $this->message()['chat']['id'] ?? null;
        return $id === null ? null : (int) $id;
    }

    public function messageId(): ?int
    {
        $id = $this->message()['message_id'] ?? null;
        return $id === null ? null : (int) $id;
    }

    public function text(): string
    {
        return trim((string) ($this->raw['message']['text'] ?? ''));
    }

    public function callbackData(): string
    {
        return (string) ($this->raw['callback_query']['data'] ?? '');
    }

    public function callbackId(): string
    {
        return (string) ($this->raw['callback_query']['id'] ?? '');
    }

    /** True if the incoming message is a forwarded one. */
    public function isForward(): bool
    {
        $m = $this->raw['message'] ?? [];
        return isset($m['forward_origin']) || isset($m['forward_from']) || isset($m['forward_from_chat']);
    }

    /**
     * Resolve the origin (bot/user/chat) of a forwarded message.
     *
     * @return array{type: string, id: int|null, username: string|null, name: string|null}|null
     */
    public function forwardOrigin(): ?array
    {
        $m = $this->raw['message'] ?? [];

        if (isset($m['forward_from'])) {
            $u = $m['forward_from'];
            return [
                'type'     => ($u['is_bot'] ?? false) ? 'bot' : 'user',
                'id'       => isset($u['id']) ? (int) $u['id'] : null,
                'username' => $u['username'] ?? null,
                'name'     => $u['first_name'] ?? null,
            ];
        }

        if (isset($m['forward_from_chat'])) {
            $c = $m['forward_from_chat'];
            return [
                'type'     => (string) ($c['type'] ?? 'channel'),
                'id'       => isset($c['id']) ? (int) $c['id'] : null,
                'username' => $c['username'] ?? null,
                'name'     => $c['title'] ?? null,
            ];
        }

        // forward_origin (Bot API 7+) hides sender if privacy enabled.
        if (isset($m['forward_origin'])) {
            $o = $m['forward_origin'];
            $type = (string) ($o['type'] ?? '');
            if ($type === 'chat' && isset($o['sender_chat'])) {
                return [
                    'type'     => (string) ($o['sender_chat']['type'] ?? 'channel'),
                    'id'       => (int) ($o['sender_chat']['id'] ?? 0),
                    'username' => $o['sender_chat']['username'] ?? null,
                    'name'     => $o['sender_chat']['title'] ?? null,
                ];
            }
            if ($type === 'user' && isset($o['sender_user'])) {
                return [
                    'type'     => ($o['sender_user']['is_bot'] ?? false) ? 'bot' : 'user',
                    'id'       => (int) ($o['sender_user']['id'] ?? 0),
                    'username' => $o['sender_user']['username'] ?? null,
                    'name'     => $o['sender_user']['first_name'] ?? null,
                ];
            }
        }

        return null;
    }
}
