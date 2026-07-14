<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Settings;

/**
 * Creates deposit records and Oxapay invoices, and credits balances when a
 * verified webhook confirms payment.
 *
 * Crediting is idempotent: a deposit already marked paid is never credited
 * twice, guarding against replayed callbacks.
 */
final class DepositService
{
    public function __construct(
        private Database $db,
        private Settings $settings,
        private OxapayGateway $gateway,
        private Ledger $ledger,
        private ReferralService $referrals,
    ) {
    }

    /**
     * @return array{ok: bool, message: string, pay_link?: string}
     */
    public function initiate(int $userId, float $amount, string $accountType): array
    {
        $min = $this->settings->float('min_deposit', 1);
        if ($amount < $min) {
            return ['ok' => false, 'message' => sprintf('❌ Minimum deposit is $%s.', number_format($min, 2))];
        }
        if (!$this->gateway->isEnabled()) {
            return ['ok' => false, 'message' => '⚠️ Deposits are currently unavailable. Please contact support.'];
        }

        $depositId = $this->db->insert('deposits', [
            'user_id'      => $userId,
            'account_type' => $accountType,
            'amount'       => $amount,
            'currency'     => 'USDT',
            'gateway'      => 'oxapay',
            'status'       => 'pending',
        ]);

        $invoice = $this->gateway->createInvoice($amount, $depositId);
        if ($invoice === null) {
            $this->db->update('deposits', ['status' => 'failed'], ['id' => $depositId]);
            return ['ok' => false, 'message' => '❌ Could not create the payment invoice. Try again later.'];
        }

        $this->db->update('deposits', ['invoice_id' => $invoice['track_id']], ['id' => $depositId]);

        return [
            'ok'       => true,
            'message'  => "💳 Your invoice is ready. Tap the link below to pay.\nBalance is credited automatically after confirmation.",
            'pay_link' => $invoice['pay_link'],
        ];
    }

    /**
     * Mark a deposit paid and credit the correct balance. Idempotent.
     */
    public function confirmByTrackId(string $trackId, string $transactionId = ''): bool
    {
        $deposit = $this->db->fetch('SELECT * FROM deposits WHERE invoice_id = ? LIMIT 1', [$trackId]);
        if ($deposit === null || $deposit['status'] === 'paid') {
            return false;
        }

        return (bool) $this->db->transaction(function (Database $db) use ($deposit, $transactionId): bool {
            $db->update('deposits', [
                'status'         => 'paid',
                'transaction_id' => $transactionId,
                'completed_at'   => date('Y-m-d H:i:s'),
            ], ['id' => (int) $deposit['id']]);

            $amount = (float) $deposit['amount'];
            $userId = (int) $deposit['user_id'];

            if ($deposit['account_type'] === 'advertiser') {
                $advertiserId = (int) $db->column('SELECT id FROM advertisers WHERE user_id = ? LIMIT 1', [$userId]);
                if ($advertiserId > 0) {
                    $db->run('UPDATE advertiser_wallet SET balance = balance + ? WHERE advertiser_id = ?', [$amount, $advertiserId]);
                    $db->insert('advertiser_transactions', [
                        'advertiser_id' => $advertiserId,
                        'type'          => 'deposit',
                        'amount'        => $amount,
                        'description'   => 'Wallet deposit',
                    ]);
                }
            } else {
                $db->run('UPDATE users SET total_deposit = total_deposit + ? WHERE id = ?', [$amount, $userId]);
                $this->ledger->creditAvailable($userId, $amount, 'deposit', 'Deposit', 'deposit:' . $deposit['id']);
                $this->referrals->creditDepositCommission($userId, $amount);
            }
            return true;
        });
    }
}
