<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Settings;
use App\Helpers\Money;
use App\Models\UserRepository;

/**
 * Referral commissions on task rewards and deposits.
 *
 * Commission percentages are admin-configurable (settings table). Paying a
 * commission credits the referrer's available balance, records referral
 * history, and keeps the per-pair totals on the `referrals` row up to date.
 */
final class ReferralService
{
    public function __construct(
        private Database $db,
        private Settings $settings,
        private UserRepository $users,
        private Ledger $ledger,
    ) {
    }

    public function creditTaskCommission(int $earnerUserId, float $rewardAmount): void
    {
        $this->credit($earnerUserId, $rewardAmount, 'task', 'task_commission_pct', 'Task referral commission');
    }

    public function creditDepositCommission(int $depositorUserId, float $depositAmount): void
    {
        $this->credit($depositorUserId, $depositAmount, 'deposit', 'deposit_commission_pct', 'Deposit referral commission');
    }

    private function credit(int $earnerUserId, float $baseAmount, string $commissionType, string $pctKey, string $description): void
    {
        if (!$this->settings->bool('referral_enabled', true)) {
            return;
        }

        $earner = $this->users->findById($earnerUserId);
        if ($earner === null || empty($earner['referred_by'])) {
            return;
        }

        $percent = $this->settings->float($pctKey, 0);
        if ($percent <= 0) {
            return;
        }

        $referrerId = (int) $earner['referred_by'];
        $commission = Money::round($baseAmount * ($percent / 100));
        if ($commission <= 0) {
            return;
        }

        $this->ledger->creditAvailable($referrerId, $commission, 'referral', $description, 'ref:' . $earnerUserId);
        $this->ledger->addReferralEarning($referrerId, $commission);

        $this->db->insert('referral_history', [
            'referrer_id'     => $referrerId,
            'referred_id'     => $earnerUserId,
            'amount'          => $commission,
            'source'          => $description,
            'commission_type' => $commissionType,
        ]);

        $column = $commissionType === 'deposit' ? 'deposit_commission' : 'task_commission';
        $this->db->run(
            sprintf(
                'UPDATE referrals SET `%s` = `%s` + ? WHERE referrer_id = ? AND referred_id = ?',
                $column,
                $column
            ),
            [$commission, $referrerId, $earnerUserId]
        );
    }

    /**
     * Register a referral pair once (called on first /start with a ref code).
     */
    public function link(int $referrerId, int $referredId): void
    {
        if ($referrerId === $referredId) {
            return;
        }
        $exists = $this->db->column(
            'SELECT 1 FROM referrals WHERE referrer_id = ? AND referred_id = ? LIMIT 1',
            [$referrerId, $referredId]
        );
        if (!$exists) {
            $this->db->insert('referrals', ['referrer_id' => $referrerId, 'referred_id' => $referredId]);
        }
    }
}
