<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Data access for workers/users.
 *
 * Balances are stored as authoritative columns and only ever mutated through
 * {@see \App\Services\Ledger} inside transactions — never recomputed from
 * history, per the platform's balance rules.
 */
final class UserRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function findByTelegramId(int $telegramId): ?array
    {
        return $this->db->fetch('SELECT * FROM users WHERE telegram_id = ? LIMIT 1', [$telegramId]);
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        return $this->db->fetch('SELECT * FROM users WHERE id = ? LIMIT 1', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function findByReferralCode(string $code): ?array
    {
        return $this->db->fetch('SELECT * FROM users WHERE referral_code = ? LIMIT 1', [$code]);
    }

    /**
     * Fetch an existing user or create one from a Telegram "from" object.
     *
     * @param array<string, mixed> $from
     * @return array<string, mixed>
     */
    public function findOrCreate(array $from, ?int $referredBy = null): array
    {
        $telegramId = (int) ($from['id'] ?? 0);
        $existing = $this->findByTelegramId($telegramId);
        if ($existing !== null) {
            return $existing;
        }

        $this->db->insert('users', [
            'telegram_id'   => $telegramId,
            'username'      => $from['username'] ?? null,
            'first_name'    => $from['first_name'] ?? null,
            'last_name'     => $from['last_name'] ?? null,
            'language'      => substr((string) ($from['language_code'] ?? 'en'), 0, 8),
            'referral_code' => $this->generateReferralCode(),
            'referred_by'   => $referredBy,
            'last_active'   => date('Y-m-d H:i:s'),
        ]);

        /** @var array<string, mixed> $user */
        $user = $this->findByTelegramId($telegramId);
        return $user;
    }

    public function touchActive(int $userId): void
    {
        $this->db->update('users', ['last_active' => date('Y-m-d H:i:s')], ['id' => $userId]);
    }

    public function setStatus(int $userId, string $status): void
    {
        $this->db->update('users', ['status' => $status], ['id' => $userId]);
    }

    public function incrementCounter(int $userId, string $column, int $by = 1): void
    {
        $allowed = ['completed_tasks', 'pending_tasks', 'rejected_tasks', 'auto_approved_tasks', 'notification_count'];
        if (!in_array($column, $allowed, true)) {
            throw new \InvalidArgumentException('Illegal counter column.');
        }
        $this->db->run(
            sprintf('UPDATE users SET `%s` = `%s` + ? WHERE id = ?', $column, $column),
            [$by, $userId]
        );
    }

    private function generateReferralCode(): string
    {
        do {
            $code = strtoupper(substr(bin2hex(random_bytes(5)), 0, 8));
        } while ($this->findByReferralCode($code) !== null);
        return $code;
    }
}
