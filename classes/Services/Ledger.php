<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Helpers\Money;

/**
 * The single authority for balance mutations.
 *
 * Every credit/debit updates the authoritative balance column AND writes a
 * transaction row inside one DB transaction, so the ledger and balances can
 * never drift. Balances are never derived from transaction history.
 *
 * Balance buckets on `users`:
 *   available_balance  - spendable (withdraw, task rewards, referral payouts)
 *   pending_balance    - rewards awaiting verification (e.g. channel joins)
 *   referral_balance   - lifetime referral earnings (reporting bucket)
 */
final class Ledger
{
    public function __construct(private Database $db)
    {
    }

    /**
     * Credit the user's available balance and record a transaction.
     */
    public function creditAvailable(int $userId, float $amount, string $type, string $description = '', ?string $reference = null): void
    {
        $amount = Money::round($amount);
        $this->db->transaction(function (Database $db) use ($userId, $amount, $type, $description, $reference): void {
            $db->run(
                'UPDATE users SET available_balance = available_balance + ?, total_earned = total_earned + ? WHERE id = ?',
                [$amount, $this->isEarning($type) ? $amount : 0, $userId]
            );
            $this->writeTransaction($db, $userId, $type, $amount, $description, $reference);
        });
    }

    /**
     * Move a reward into pending balance (awaiting verification).
     */
    public function creditPending(int $userId, float $amount, string $description = '', ?string $reference = null): void
    {
        $amount = Money::round($amount);
        $this->db->transaction(function (Database $db) use ($userId, $amount, $description, $reference): void {
            $db->run('UPDATE users SET pending_balance = pending_balance + ? WHERE id = ?', [$amount, $userId]);
            $this->writeTransaction($db, $userId, 'pending_reward', $amount, $description, $reference);
        });
    }

    /**
     * Confirm a pending reward: pending -> available. Used by the cron after
     * re-verifying (e.g. still a channel member).
     */
    public function confirmPending(int $userId, float $amount, string $description = '', ?string $reference = null): void
    {
        $amount = Money::round($amount);
        $this->db->transaction(function (Database $db) use ($userId, $amount, $description, $reference): void {
            $db->run(
                'UPDATE users SET pending_balance = pending_balance - ?, available_balance = available_balance + ?, total_earned = total_earned + ? WHERE id = ?',
                [$amount, $amount, $amount, $userId]
            );
            $this->writeTransaction($db, $userId, 'task_reward', $amount, $description, $reference);
        });
    }

    /**
     * Cancel a pending reward (e.g. user left the channel). Removes from
     * pending balance and records a reversal.
     */
    public function cancelPending(int $userId, float $amount, string $description = '', ?string $reference = null): void
    {
        $amount = Money::round($amount);
        $this->db->transaction(function (Database $db) use ($userId, $amount, $description, $reference): void {
            $db->run('UPDATE users SET pending_balance = GREATEST(pending_balance - ?, 0) WHERE id = ?', [$amount, $userId]);
            $this->writeTransaction($db, $userId, 'balance_reversal', -$amount, $description, $reference);
        });
    }

    /**
     * Debit available balance (withdraw request, adjustment). Returns false if
     * the balance is insufficient (checked atomically).
     */
    public function debitAvailable(int $userId, float $amount, string $type, string $description = '', ?string $reference = null): bool
    {
        $amount = Money::round($amount);
        return (bool) $this->db->transaction(function (Database $db) use ($userId, $amount, $type, $description, $reference): bool {
            $affected = $db->run(
                'UPDATE users SET available_balance = available_balance - ? WHERE id = ? AND available_balance >= ?',
                [$amount, $userId, $amount]
            )->rowCount();

            if ($affected === 0) {
                return false;
            }
            $this->writeTransaction($db, $userId, $type, -$amount, $description, $reference);
            return true;
        });
    }

    public function addReferralEarning(int $userId, float $amount): void
    {
        $this->db->run(
            'UPDATE users SET referral_balance = referral_balance + ? WHERE id = ?',
            [Money::round($amount), $userId]
        );
    }

    private function writeTransaction(Database $db, int $userId, string $type, float $amount, string $description, ?string $reference): void
    {
        $balanceAfter = $db->column('SELECT available_balance FROM users WHERE id = ?', [$userId]);
        $db->insert('transactions', [
            'user_id'       => $userId,
            'type'          => $type,
            'amount'        => $amount,
            'balance_after' => $balanceAfter !== false ? $balanceAfter : null,
            'reference'     => $reference,
            'description'   => $description !== '' ? $description : null,
        ]);
    }

    private function isEarning(string $type): bool
    {
        return in_array($type, ['task_reward', 'referral', 'platform_bonus'], true);
    }
}
