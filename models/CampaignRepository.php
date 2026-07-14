<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Campaign data access and fair task distribution.
 *
 * Distribution rules: only active campaigns with remaining budget are shown,
 * a user never sees a campaign they already have a submission for, and results
 * are ordered to spread load fairly rather than always returning the same one.
 */
final class CampaignRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        return $this->db->fetch('SELECT * FROM campaigns WHERE id = ? LIMIT 1', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function content(int $campaignId): ?array
    {
        return $this->db->fetch('SELECT * FROM campaign_contents WHERE campaign_id = ? LIMIT 1', [$campaignId]);
    }

    /**
     * Pick the next available campaign of a given task type for a worker.
     *
     * @return array<string, mixed>|null
     */
    public function nextForWorker(string $taskTypeKey, int $userId): ?array
    {
        return $this->db->fetch(
            'SELECT c.* FROM campaigns c
             WHERE c.task_type_key = ?
               AND c.status = "active"
               AND c.remaining_budget >= c.worker_reward
               AND NOT EXISTS (
                   SELECT 1 FROM task_submissions s
                   WHERE s.campaign_id = c.id AND s.user_id = ?
               )
             ORDER BY c.remaining_budget DESC, c.completed_count ASC, RAND()
             LIMIT 1',
            [$taskTypeKey, $userId]
        );
    }

    public function countAvailable(string $taskTypeKey, int $userId): int
    {
        return (int) $this->db->column(
            'SELECT COUNT(*) FROM campaigns c
             WHERE c.task_type_key = ? AND c.status = "active"
               AND c.remaining_budget >= c.worker_reward
               AND NOT EXISTS (SELECT 1 FROM task_submissions s WHERE s.campaign_id = c.id AND s.user_id = ?)',
            [$taskTypeKey, $userId]
        );
    }

    /**
     * Atomically charge a campaign for one completion: reduce remaining budget
     * and record spend. Returns false if the budget can no longer cover it.
     */
    public function chargeCompletion(int $campaignId, float $cpc): bool
    {
        return (bool) $this->db->transaction(function (Database $db) use ($campaignId, $cpc): bool {
            $affected = $db->run(
                'UPDATE campaigns
                 SET remaining_budget = remaining_budget - ?, spent_amount = spent_amount + ?, completed_count = completed_count + 1
                 WHERE id = ? AND remaining_budget >= ? AND status = "active"',
                [$cpc, $cpc, $campaignId, $cpc]
            )->rowCount();

            if ($affected === 0) {
                return false;
            }

            // Auto-complete the campaign when the budget is exhausted.
            $db->run(
                'UPDATE campaigns SET status = "completed"
                 WHERE id = ? AND remaining_budget < worker_reward AND status = "active"',
                [$campaignId]
            );
            return true;
        });
    }

    public function incrementCounter(int $campaignId, string $column, int $by = 1): void
    {
        $allowed = ['pending_count', 'rejected_count', 'skipped_count', 'completed_count'];
        if (!in_array($column, $allowed, true)) {
            throw new \InvalidArgumentException('Illegal campaign counter.');
        }
        $this->db->run(
            sprintf('UPDATE campaigns SET `%s` = `%s` + ? WHERE id = ?', $column, $column),
            [$by, $campaignId]
        );
    }

    public function setStatus(int $campaignId, string $status): void
    {
        $this->db->update('campaigns', ['status' => $status], ['id' => $campaignId]);
    }
}
