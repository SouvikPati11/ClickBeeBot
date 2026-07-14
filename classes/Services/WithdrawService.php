<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Settings;
use App\Helpers\Money;

/**
 * Creates and validates withdrawal requests.
 *
 * Applies the admin-configured minimum, daily cap and fee, debits the worker's
 * available balance atomically (via Ledger) and files a pending request for
 * manual admin review. Prevents duplicate/pending stacking.
 */
final class WithdrawService
{
    public function __construct(
        private Database $db,
        private Settings $settings,
        private Ledger $ledger,
    ) {
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function request(int $userId, string $method, string $destination, float $amount): array
    {
        $min = $this->settings->float('min_withdraw', 1);
        if ($amount < $min) {
            return ['ok' => false, 'message' => sprintf('❌ Minimum withdrawal is %s.', Money::format($min))];
        }

        // Daily cap check.
        $maxDaily = $this->settings->float('max_daily_withdraw', 0);
        if ($maxDaily > 0) {
            $todayTotal = (float) $this->db->column(
                'SELECT COALESCE(SUM(amount),0) FROM withdrawals
                 WHERE user_id = ? AND status IN ("pending","approved","completed") AND DATE(created_at) = CURDATE()',
                [$userId]
            );
            if ($todayTotal + $amount > $maxDaily) {
                return ['ok' => false, 'message' => sprintf('❌ Daily withdrawal limit is %s.', Money::format($maxDaily))];
            }
        }

        // Block a second pending request.
        $pending = $this->db->column(
            'SELECT 1 FROM withdrawals WHERE user_id = ? AND status = "pending" LIMIT 1',
            [$userId]
        );
        if ($pending) {
            return ['ok' => false, 'message' => '⏳ You already have a pending withdrawal.'];
        }

        $fee = Money::applyWithdrawFee(
            $amount,
            $this->settings->bool('withdraw_fee_enabled'),
            $this->settings->get('withdraw_fee_type', 'percentage'),
            $this->settings->float('withdraw_fee_value', 0)
        );
        $net = Money::round($amount - $fee);

        // Debit atomically; false means insufficient balance.
        if (!$this->ledger->debitAvailable($userId, $amount, 'withdraw', 'Withdrawal request')) {
            return ['ok' => false, 'message' => '❌ Insufficient available balance.'];
        }

        $this->db->insert('withdrawals', [
            'user_id'        => $userId,
            'method'         => $method,
            'wallet_address' => $method === 'usdt_bep20' ? $destination : null,
            'binance_uid'    => $method === 'binance_uid' ? $destination : null,
            'amount'         => $amount,
            'fee'            => $fee,
            'net_amount'     => $net,
            'status'         => 'pending',
        ]);
        $this->db->run('UPDATE users SET total_withdraw = total_withdraw + ? WHERE id = ?', [$amount, $userId]);

        return [
            'ok'      => true,
            'message' => sprintf("✅ Withdrawal requested.\nAmount: %s\nFee: %s\nYou receive: %s\nStatus: Pending review.", Money::format($amount), Money::format($fee), Money::format($net)),
        ];
    }
}
