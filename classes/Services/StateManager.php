<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Conversation finite-state machine store.
 *
 * Multi-step bot flows (campaign creation, withdraw, deposit amount entry)
 * need to remember where a user is between messages. State is namespaced by
 * scope ('user' vs 'advertiser') so a person can act in both roles.
 */
final class StateManager
{
    public function __construct(private Database $db)
    {
    }

    /**
     * @return array{state: string|null, payload: array<string, mixed>}
     */
    public function get(int $telegramId, string $scope = 'user'): array
    {
        $row = $this->db->fetch(
            'SELECT state, payload FROM user_states WHERE telegram_id = ? AND scope = ? LIMIT 1',
            [$telegramId, $scope]
        );
        if ($row === null) {
            return ['state' => null, 'payload' => []];
        }
        /** @var array<string, mixed> $payload */
        $payload = $row['payload'] !== null ? (json_decode((string) $row['payload'], true) ?: []) : [];
        return ['state' => $row['state'], 'payload' => $payload];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function set(int $telegramId, string $state, array $payload = [], string $scope = 'user'): void
    {
        $exists = $this->db->column(
            'SELECT 1 FROM user_states WHERE telegram_id = ? AND scope = ? LIMIT 1',
            [$telegramId, $scope]
        );
        $data = [
            'state'   => $state,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ];
        if ($exists) {
            $this->db->update('user_states', $data, ['telegram_id' => $telegramId, 'scope' => $scope]);
        } else {
            $this->db->insert('user_states', $data + ['telegram_id' => $telegramId, 'scope' => $scope]);
        }
    }

    public function clear(int $telegramId, string $scope = 'user'): void
    {
        $this->db->run('DELETE FROM user_states WHERE telegram_id = ? AND scope = ?', [$telegramId, $scope]);
    }
}
