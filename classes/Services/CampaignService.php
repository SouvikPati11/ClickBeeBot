<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Helpers\Money;
use App\Tasks\TaskRegistry;

/**
 * Advertiser campaign lifecycle: pricing, creation, payment and refunds.
 *
 * Worker reward and platform fee are derived from the advertiser CPC using the
 * per-task-type platform fee (admin configurable). The campaign budget is
 * deducted from the advertiser wallet on activation; unused budget is refunded
 * on deletion. Enforces per-type minimum CPC / daily budget.
 */
final class CampaignService
{
    public function __construct(
        private Database $db,
        private TaskRegistry $registry,
    ) {
    }

    /**
     * Validate advertiser input against the task-type minimums.
     *
     * @return array{ok: bool, message: string}
     */
    public function validate(string $typeKey, float $cpc, float $totalBudget): array
    {
        $config = $this->registry->configFor($typeKey);
        if ($config === null || empty($config['enabled'])) {
            return ['ok' => false, 'message' => '❌ This task type is unavailable.'];
        }
        if ($cpc < (float) $config['min_cpc']) {
            return ['ok' => false, 'message' => sprintf('❌ Minimum CPC is %s.', Money::format((float) $config['min_cpc']))];
        }
        if ($totalBudget < (float) $config['min_daily_budget']) {
            return ['ok' => false, 'message' => sprintf('❌ Minimum budget is %s.', Money::format((float) $config['min_daily_budget']))];
        }
        return ['ok' => true, 'message' => ''];
    }

    /**
     * Pricing preview: worker reward + platform fee for a CPC.
     *
     * @return array{worker: float, fee: float, fee_percent: float}
     */
    public function pricing(string $typeKey, float $cpc): array
    {
        $config = $this->registry->configFor($typeKey);
        $feePercent = (float) ($config['platform_fee_percent'] ?? 10);
        $split = Money::splitCpc($cpc, $feePercent);
        return ['worker' => $split['worker'], 'fee' => $split['fee'], 'fee_percent' => $feePercent];
    }

    /**
     * Create and activate a campaign, deducting its budget from the advertiser
     * wallet in one transaction.
     *
     * @param array<string, mixed> $data    title, description, cpc, total_budget, daily_budget
     * @param array<string, mixed> $content campaign_contents fields
     * @return array{ok: bool, message: string, campaign_id?: int}
     */
    public function create(int $advertiserId, string $typeKey, array $data, array $content): array
    {
        $config = $this->registry->configFor($typeKey);
        if ($config === null) {
            return ['ok' => false, 'message' => '❌ Invalid task type.'];
        }

        $cpc = (float) $data['cpc'];
        $total = (float) $data['total_budget'];
        $pricing = $this->pricing($typeKey, $cpc);
        $target = $pricing['worker'] > 0 ? (int) floor($total / $cpc) : 0;

        try {
            return $this->db->transaction(function (Database $db) use ($advertiserId, $typeKey, $config, $data, $content, $cpc, $total, $pricing, $target): array {
                $affected = $db->run(
                    'UPDATE advertiser_wallet SET balance = balance - ?, spent = spent + ? WHERE advertiser_id = ? AND balance >= ?',
                    [$total, 0, $advertiserId, $total]
                )->rowCount();

                if ($affected === 0) {
                    return ['ok' => false, 'message' => '❌ Insufficient wallet balance. Please deposit first.'];
                }

                $campaignId = $db->insert('campaigns', [
                    'advertiser_id'        => $advertiserId,
                    'task_type_id'         => (int) $config['id'],
                    'task_type_key'        => $typeKey,
                    'title'                => $data['title'],
                    'description'          => $data['description'] ?? null,
                    'cpc'                  => $cpc,
                    'platform_fee_percent' => $pricing['fee_percent'],
                    'worker_reward'        => $pricing['worker'],
                    'daily_budget'         => (float) ($data['daily_budget'] ?? $total),
                    'total_budget'         => $total,
                    'remaining_budget'     => $total,
                    'target_count'         => $target,
                    'status'               => 'active',
                    'start_date'           => date('Y-m-d H:i:s'),
                ]);

                $db->insert('campaign_contents', array_merge([
                    'campaign_id'       => $campaignId,
                    'verification_type' => (string) $config['verification_type'],
                    'timer_seconds'     => (int) $config['timer_seconds'],
                ], $content));

                $db->insert('advertiser_transactions', [
                    'advertiser_id' => $advertiserId,
                    'type'          => 'campaign_payment',
                    'amount'        => -$total,
                    'campaign_id'   => $campaignId,
                    'description'   => 'Campaign funding: ' . $data['title'],
                ]);

                return ['ok' => true, 'message' => '✅ Campaign is now <b>active</b>!', 'campaign_id' => $campaignId];
            });
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => '❌ Could not create campaign. Please try again.'];
        }
    }
}
