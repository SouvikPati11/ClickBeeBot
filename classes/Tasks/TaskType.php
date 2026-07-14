<?php

declare(strict_types=1);

namespace App\Tasks;

use App\Telegram\Keyboard;
use App\Telegram\TelegramApi;

/**
 * Base class for every task type — the heart of the modular task engine.
 *
 * A new task type is added by extending this class and registering it in the
 * `campaign_task_types` table (handler_class). No existing code changes. The
 * shared worker workflow (present -> act -> verify -> reward) lives in the
 * engine; concrete types only describe how their task is presented, how the
 * advertiser configures it, and how a completion is verified.
 */
abstract class TaskType
{
    /**
     * @param array<string, mixed> $config The campaign_task_types row.
     */
    public function __construct(
        protected array $config,
        protected TelegramApi $telegram,
    ) {
    }

    final public function key(): string
    {
        return (string) $this->config['type_key'];
    }

    final public function name(): string
    {
        return (string) $this->config['name'];
    }

    final public function icon(): string
    {
        return (string) $this->config['icon'];
    }

    final public function verificationType(): string
    {
        return (string) $this->config['verification_type'];
    }

    final public function pendingHours(): int
    {
        return (int) $this->config['pending_hours'];
    }

    final public function autoApprovalHours(): int
    {
        return (int) $this->config['auto_approval_hours'];
    }

    final public function timerSeconds(): int
    {
        return (int) $this->config['timer_seconds'];
    }

    /** Whether this type requires the worker to submit manual proof. */
    public function isManual(): bool
    {
        return $this->verificationType() === 'manual';
    }

    /**
     * Presentation text (HTML) shown to the worker for a campaign.
     *
     * @param array<string, mixed> $campaign
     * @param array<string, mixed> $content
     */
    abstract public function presentText(array $campaign, array $content): string;

    /**
     * Inline keyboard shown with the task. $timerReady indicates whether the
     * timer (if any) has elapsed so the Complete button may be shown.
     *
     * @param array<string, mixed> $campaign
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    abstract public function presentKeyboard(array $campaign, array $content, bool $timerReady): array;

    /**
     * Verify a completion attempt.
     *
     * @param array<string, mixed> $campaign
     * @param array<string, mixed> $content
     * @param array<string, mixed> $context Engine-supplied: user row, update, opened_at, etc.
     */
    abstract public function verify(array $campaign, array $content, array $context): VerificationResult;

    /**
     * Standard action-button row used by most task types.
     *
     * @return array{0: string, 1: string}
     */
    protected function completeButton(int $campaignId): array
    {
        return ['✅ Complete', 'task:complete:' . $campaignId];
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function skipButton(int $campaignId): array
    {
        return ['⏭ Skip', 'task:skip:' . $campaignId];
    }
}
