<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Task submission data access.
 *
 * The unique (campaign_id, user_id) index is the hard guarantee against
 * duplicate rewards / duplicate completion — a second insert simply fails.
 */
final class SubmissionRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(int $campaignId, int $userId): ?array
    {
        return $this->db->fetch(
            'SELECT * FROM task_submissions WHERE campaign_id = ? AND user_id = ? LIMIT 1',
            [$campaignId, $userId]
        );
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        return $this->db->fetch('SELECT * FROM task_submissions WHERE id = ? LIMIT 1', [$id]);
    }

    public function exists(int $campaignId, int $userId): bool
    {
        return (bool) $this->db->column(
            'SELECT 1 FROM task_submissions WHERE campaign_id = ? AND user_id = ? LIMIT 1',
            [$campaignId, $userId]
        );
    }

    /**
     * Create a submission, returning its id. Guarded by the unique index; a
     * duplicate throws a PDOException the caller treats as "already done".
     *
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        return $this->db->insert('task_submissions', $data);
    }

    public function updateStatus(int $id, string $status, ?string $reason = null, bool $autoApproved = false): void
    {
        $this->db->update('task_submissions', [
            'status'        => $status,
            'reject_reason' => $reason,
            'auto_approved' => $autoApproved ? 1 : 0,
            'reviewed_at'   => date('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function pendingVerificationDue(int $limit = 200): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM task_submissions
             WHERE status = "pending" AND verify_after IS NOT NULL AND verify_after <= NOW()
             ORDER BY verify_after ASC LIMIT ?',
            [$limit]
        );
    }

    /**
     * Manual submissions past their auto-approval window.
     *
     * @return array<int, array<string, mixed>>
     */
    public function autoApprovalDue(int $limit = 200): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM task_submissions
             WHERE status = "pending_review" AND verify_after IS NOT NULL AND verify_after <= NOW()
             ORDER BY verify_after ASC LIMIT ?',
            [$limit]
        );
    }

    public function recordHistory(int $campaignId, int $userId, string $taskTypeKey, float $reward, string $status): void
    {
        $this->db->insert('task_history', [
            'campaign_id'   => $campaignId,
            'user_id'       => $userId,
            'task_type_key' => $taskTypeKey,
            'reward'        => $reward,
            'status'        => $status,
        ]);
    }
}
