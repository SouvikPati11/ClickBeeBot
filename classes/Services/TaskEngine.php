<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CampaignRepository;
use App\Models\SubmissionRepository;
use App\Models\UserRepository;
use App\Tasks\TaskRegistry;
use App\Tasks\VerificationResult;
use App\Telegram\TelegramApi;
use App\Telegram\Update;

/**
 * Orchestrates the universal worker task workflow: present -> act -> verify ->
 * reward -> next. Every task type reuses this exact pipeline; the type-specific
 * behaviour is isolated behind {@see \App\Tasks\TaskType}.
 *
 * All reward settlement is idempotent and guarded against duplicates by the
 * unique (campaign_id, user_id) submission index.
 */
final class TaskEngine
{
    public function __construct(
        private TelegramApi $telegram,
        private TaskRegistry $registry,
        private CampaignRepository $campaigns,
        private SubmissionRepository $submissions,
        private UserRepository $users,
        private Ledger $ledger,
        private NotificationService $notifications,
        private StateManager $state,
        private ReferralService $referrals,
    ) {
    }

    /**
     * Present the next available task of a type to a worker.
     *
     * @param array<string, mixed> $user
     */
    public function presentNext(array $user, string $typeKey, int $chatId): void
    {
        $type = $this->registry->get($typeKey);
        if ($type === null) {
            $this->telegram->sendMessage($chatId, '⚠️ This task type is currently disabled.');
            return;
        }

        $campaign = $this->campaigns->nextForWorker($typeKey, (int) $user['id']);
        if ($campaign === null) {
            $this->state->clear((int) $user['telegram_id']);
            $this->telegram->sendMessage($chatId, "🎉 No more tasks here right now.\nCheck back soon or try another category.");
            return;
        }

        $content = $this->campaigns->content((int) $campaign['id']) ?? [];

        // View-posts copies the advertiser's post before the task card.
        if ($typeKey === 'view_posts' && !empty($content['source_chat_id']) && !empty($content['source_message_id'])) {
            $this->telegram->copyMessage($chatId, (int) $content['source_chat_id'], (int) $content['source_message_id']);
        }

        $timerReady = $type->timerSeconds() === 0;
        $this->telegram->sendMessage($chatId, $type->presentText($campaign, $content), [
            'reply_markup' => $type->presentKeyboard($campaign, $content, $timerReady),
        ]);

        $this->state->set((int) $user['telegram_id'], 'task:' . $typeKey, [
            'campaign_id'  => (int) $campaign['id'],
            'type_key'     => $typeKey,
            'presented_at' => time(),
        ]);
    }

    /**
     * Handle the worker's "Complete/Verify" action for a campaign.
     *
     * @param array<string, mixed> $user
     */
    public function handleComplete(array $user, int $campaignId, int $chatId, Update $update): void
    {
        $campaign = $this->campaigns->findById($campaignId);
        if ($campaign === null || $campaign['status'] !== 'active') {
            $this->telegram->sendMessage($chatId, '⚠️ This task is no longer available.');
            return;
        }

        $type = $this->registry->get((string) $campaign['task_type_key']);
        if ($type === null) {
            return;
        }

        if ($this->submissions->exists($campaignId, (int) $user['id'])) {
            $this->telegram->sendMessage($chatId, '✅ You have already completed this task.');
            $this->presentNext($user, (string) $campaign['task_type_key'], $chatId);
            return;
        }

        $content = $this->campaigns->content($campaignId) ?? [];
        $stateData = $this->state->get((int) $user['telegram_id']);
        $presentedAt = (int) ($stateData['payload']['presented_at'] ?? 0);

        $context = [
            'user'    => $user,
            'update'  => $update,
            'elapsed' => $presentedAt > 0 ? time() - $presentedAt : PHP_INT_MAX,
        ];

        $result = $type->verify($campaign, $content, $context);
        $this->settle($result, $user, $campaign, $type, $chatId);
    }

    private function settle(
        VerificationResult $result,
        array $user,
        array $campaign,
        \App\Tasks\TaskType $type,
        int $chatId
    ): void {
        $campaignId = (int) $campaign['id'];
        $userId = (int) $user['id'];
        $reward = (float) $campaign['worker_reward'];
        $cpc = (float) $campaign['cpc'];
        $typeKey = (string) $campaign['task_type_key'];

        switch ($result->decision) {
            case VerificationResult::RETRY:
            case VerificationResult::REJECT:
                $this->telegram->sendMessage($chatId, $result->message);
                return;

            case VerificationResult::APPROVE_AVAILABLE:
                if (!$this->campaigns->chargeCompletion($campaignId, $cpc)) {
                    $this->telegram->sendMessage($chatId, '⚠️ This task just ran out of budget. Try another.');
                    $this->presentNext($user, $typeKey, $chatId);
                    return;
                }
                try {
                    $this->submissions->create($this->submissionRow($campaignId, $userId, $reward, 'approved'));
                } catch (\PDOException) {
                    return; // duplicate — already rewarded
                }
                $this->ledger->creditAvailable($userId, $reward, 'task_reward', 'Task: ' . $campaign['title'], 'campaign:' . $campaignId);
                $this->submissions->recordHistory($campaignId, $userId, $typeKey, $reward, 'completed');
                $this->users->incrementCounter($userId, 'completed_tasks');
                $this->referrals->creditTaskCommission($userId, $reward);
                $this->telegram->sendMessage($chatId, $result->message !== '' ? $result->message : '✅ Reward added!');
                $this->presentNext($user, $typeKey, $chatId);
                return;

            case VerificationResult::APPROVE_PENDING:
                if (!$this->campaigns->chargeCompletion($campaignId, $cpc)) {
                    $this->telegram->sendMessage($chatId, '⚠️ This task just ran out of budget. Try another.');
                    $this->presentNext($user, $typeKey, $chatId);
                    return;
                }
                try {
                    $verifyAfter = date('Y-m-d H:i:s', time() + $type->pendingHours() * 3600);
                    $this->submissions->create($this->submissionRow($campaignId, $userId, $reward, 'pending', $verifyAfter));
                } catch (\PDOException) {
                    return;
                }
                $this->ledger->creditPending($userId, $reward, 'Pending: ' . $campaign['title'], 'campaign:' . $campaignId);
                $this->users->incrementCounter($userId, 'pending_tasks');
                $this->campaigns->incrementCounter($campaignId, 'pending_count');
                $this->telegram->sendMessage($chatId, $result->message !== '' ? $result->message : '⏳ Added to pending balance.');
                $this->presentNext($user, $typeKey, $chatId);
                return;

            case VerificationResult::PENDING_REVIEW:
                try {
                    $verifyAfter = date('Y-m-d H:i:s', time() + $type->autoApprovalHours() * 3600);
                    $this->submissions->create(
                        $this->submissionRow($campaignId, $userId, $reward, 'pending_review', $verifyAfter) + [
                            'proof'      => $result->proof,
                            'proof_type' => (string) $campaign['task_type_key'],
                        ]
                    );
                } catch (\PDOException) {
                    $this->telegram->sendMessage($chatId, '✅ You already submitted proof for this task.');
                    return;
                }
                $this->users->incrementCounter($userId, 'pending_tasks');
                $this->campaigns->incrementCounter($campaignId, 'pending_count');
                $this->state->clear((int) $user['telegram_id']);
                $this->telegram->sendMessage($chatId, $result->message !== '' ? $result->message : '⏳ Proof submitted for review.');
                return;
        }
    }

    public function handleSkip(array $user, int $campaignId, int $chatId): void
    {
        $campaign = $this->campaigns->findById($campaignId);
        if ($campaign !== null) {
            $this->campaigns->incrementCounter($campaignId, 'skipped_count');
            $this->presentNext($user, (string) $campaign['task_type_key'], $chatId);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function submissionRow(int $campaignId, int $userId, float $reward, string $status, ?string $verifyAfter = null): array
    {
        return [
            'campaign_id'  => $campaignId,
            'user_id'      => $userId,
            'reward'       => $reward,
            'status'       => $status,
            'submitted_at' => date('Y-m-d H:i:s'),
            'verify_after' => $verifyAfter,
        ];
    }
}
