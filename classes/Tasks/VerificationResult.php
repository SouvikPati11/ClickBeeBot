<?php

declare(strict_types=1);

namespace App\Tasks;

/**
 * Outcome of a task verification attempt.
 *
 * Decouples each task type's verification logic from how the engine settles
 * the reward. The decision drives the Ledger:
 *   APPROVE_AVAILABLE  -> reward credited to available balance immediately
 *   APPROVE_PENDING    -> reward held in pending balance (re-checked by cron)
 *   PENDING_REVIEW     -> manual submission queued for advertiser/admin
 *   REJECT             -> no reward; show the message to the worker
 *   RETRY              -> not ready yet (e.g. timer still running); no state change
 */
final class VerificationResult
{
    public const APPROVE_AVAILABLE = 'approve_available';
    public const APPROVE_PENDING   = 'approve_pending';
    public const PENDING_REVIEW    = 'pending_review';
    public const REJECT            = 'reject';
    public const RETRY             = 'retry';

    private function __construct(
        public readonly string $decision,
        public readonly string $message = '',
        public readonly ?string $proof = null,
    ) {
    }

    public static function approveAvailable(string $message = ''): self
    {
        return new self(self::APPROVE_AVAILABLE, $message);
    }

    public static function approvePending(string $message = ''): self
    {
        return new self(self::APPROVE_PENDING, $message);
    }

    public static function pendingReview(string $message = '', ?string $proof = null): self
    {
        return new self(self::PENDING_REVIEW, $message, $proof);
    }

    public static function reject(string $message): self
    {
        return new self(self::REJECT, $message);
    }

    public static function retry(string $message): self
    {
        return new self(self::RETRY, $message);
    }
}
