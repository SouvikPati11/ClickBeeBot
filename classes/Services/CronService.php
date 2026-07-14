<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Cron job implementations.
 *
 * Each job is independent and logs its own execution so one failure never
 * blocks the others. Designed for cPanel cron hitting a URL — no CLI required.
 */
final class CronService
{
    public function __construct(private Container $c)
    {
    }

    /**
     * Run a named job with logging and timing.
     *
     * @return array{job: string, status: string, message: string, ms: int}
     */
    public function run(string $job): array
    {
        $start = microtime(true);
        try {
            $message = match ($job) {
                'pending_verification' => $this->pendingVerification(),
                'auto_approval'        => $this->autoApproval(),
                'campaign_expiry'      => $this->campaignExpiry(),
                'cleanup'              => $this->cleanup(),
                default                => throw new \InvalidArgumentException('Unknown job: ' . $job),
            };
            $status = 'success';
        } catch (\Throwable $e) {
            $status = 'failed';
            $message = $e->getMessage();
            $this->c->app()->logger()->error('cron', 'Job failed: ' . $job, ['error' => $message]);
        }

        $ms = (int) round((microtime(true) - $start) * 1000);
        $this->c->app()->db()->insert('cron_logs', [
            'job'         => $job,
            'status'      => $status,
            'message'     => substr($message, 0, 250),
            'duration_ms' => $ms,
        ]);

        return ['job' => $job, 'status' => $status, 'message' => $message, 'ms' => $ms];
    }

    /**
     * Re-verify pending rewards (e.g. channel joins). Confirm if still valid,
     * cancel otherwise, moving balance out of pending.
     */
    private function pendingVerification(): string
    {
        $submissions = $this->c->submissions()->pendingVerificationDue();
        $confirmed = 0;
        $cancelled = 0;

        foreach ($submissions as $s) {
            $campaign = $this->c->campaigns()->findById((int) $s['campaign_id']);
            if ($campaign === null) {
                continue;
            }
            $type = $this->c->registry()->get((string) $campaign['task_type_key']);
            $user = $this->c->users()->findById((int) $s['user_id']);
            if ($type === null || $user === null) {
                continue;
            }

            $content = $this->c->campaigns()->content((int) $campaign['id']) ?? [];
            $result = $type->verify($campaign, $content, ['user' => $user, 'update' => null, 'elapsed' => PHP_INT_MAX]);

            if ($result->decision === \App\Tasks\VerificationResult::APPROVE_PENDING
                || $result->decision === \App\Tasks\VerificationResult::APPROVE_AVAILABLE) {
                $this->c->ledger()->confirmPending((int) $user['id'], (float) $s['reward'], 'Confirmed: ' . $campaign['title'], 'campaign:' . $campaign['id']);
                $this->c->submissions()->updateStatus((int) $s['id'], 'approved');
                $this->c->submissions()->recordHistory((int) $campaign['id'], (int) $user['id'], (string) $campaign['task_type_key'], (float) $s['reward'], 'completed');
                $this->c->users()->incrementCounter((int) $user['id'], 'completed_tasks');
                $this->c->referrals()->creditTaskCommission((int) $user['id'], (float) $s['reward']);
                $this->c->notifications()->notify((int) $user['id'], 'Reward Confirmed', 'Your pending reward was confirmed.', 'task');
                $confirmed++;
            } else {
                $this->c->ledger()->cancelPending((int) $user['id'], (float) $s['reward'], 'Cancelled: ' . $campaign['title'], 'campaign:' . $campaign['id']);
                $this->c->submissions()->updateStatus((int) $s['id'], 'cancelled');
                $this->c->notifications()->notify((int) $user['id'], 'Reward Cancelled', 'You left the required channel, so the reward was cancelled.', 'task');
                $cancelled++;
            }
        }

        return sprintf('Confirmed %d, cancelled %d pending rewards.', $confirmed, $cancelled);
    }

    /**
     * Auto-approve manual submissions the advertiser did not review in time.
     */
    private function autoApproval(): string
    {
        $submissions = $this->c->submissions()->autoApprovalDue();
        $approved = 0;

        foreach ($submissions as $s) {
            $campaign = $this->c->campaigns()->findById((int) $s['campaign_id']);
            if ($campaign === null) {
                continue;
            }
            if (!$this->c->campaigns()->chargeCompletion((int) $campaign['id'], (float) $campaign['cpc'])) {
                $this->c->submissions()->updateStatus((int) $s['id'], 'cancelled', 'Budget exhausted');
                continue;
            }
            $reward = (float) $s['reward'];
            $this->c->submissions()->updateStatus((int) $s['id'], 'approved', null, true);
            $this->c->ledger()->creditAvailable((int) $s['user_id'], $reward, 'task_reward', 'Auto-approved: ' . $campaign['title'], 'campaign:' . $campaign['id']);
            $this->c->submissions()->recordHistory((int) $campaign['id'], (int) $s['user_id'], (string) $campaign['task_type_key'], $reward, 'auto_approved');
            $this->c->users()->incrementCounter((int) $s['user_id'], 'auto_approved_tasks');
            $this->c->referrals()->creditTaskCommission((int) $s['user_id'], $reward);
            $this->c->notifications()->notify((int) $s['user_id'], 'Task Auto-Approved', 'Your submission was auto-approved and rewarded.', 'task');
            $approved++;
        }

        return sprintf('Auto-approved %d submissions.', $approved);
    }

    /**
     * Expire ended campaigns and pause any whose remaining budget can no
     * longer fund a single completion.
     */
    private function campaignExpiry(): string
    {
        $db = $this->c->app()->db();
        $expired = $db->run(
            'UPDATE campaigns SET status = "expired" WHERE status IN ("active","paused") AND end_date IS NOT NULL AND end_date < NOW()'
        )->rowCount();
        $completed = $db->run(
            'UPDATE campaigns SET status = "completed" WHERE status = "active" AND remaining_budget < worker_reward'
        )->rowCount();

        return sprintf('Expired %d, completed %d campaigns.', $expired, $completed);
    }

    /**
     * Housekeeping: drop stale conversation states and old rate-limit files.
     */
    private function cleanup(): string
    {
        $rows = $this->c->app()->db()->run(
            'DELETE FROM user_states WHERE updated_at < (NOW() - INTERVAL 2 DAY)'
        )->rowCount();
        return sprintf('Cleared %d stale states.', $rows);
    }
}
