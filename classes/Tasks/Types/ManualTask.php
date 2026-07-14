<?php

declare(strict_types=1);

namespace App\Tasks\Types;

use App\Helpers\Money;
use App\Tasks\TaskType;
use App\Tasks\VerificationResult;
use App\Telegram\Keyboard;
use App\Telegram\Update;

/**
 * Manual-proof task (App Download, App Review, Social Media, …).
 *
 * The worker submits proof (screenshot / text / username / email) which is
 * queued for advertiser review. Auto-approval runs via cron if the advertiser
 * does not act within the configured window.
 */
final class ManualTask extends TaskType
{
    public function presentText(array $campaign, array $content): string
    {
        $target = $content['task_url'] ?? $content['play_store_url'] ?? '';
        $proof = (string) ($content['proof_type'] ?? 'text');

        return sprintf(
            "%s <b>%s</b>\n\n%s\n\n💵 Reward: <b>%s</b>\n📎 Required proof: <b>%s</b>\n%s\nSend your proof as a reply to submit for review.",
            $this->icon(),
            htmlspecialchars((string) $campaign['title'], ENT_QUOTES),
            htmlspecialchars((string) ($campaign['description'] ?? ''), ENT_QUOTES),
            Money::format((float) $campaign['worker_reward']),
            htmlspecialchars($proof, ENT_QUOTES),
            $target !== '' ? '🔗 ' . htmlspecialchars((string) $target, ENT_QUOTES) : ''
        );
    }

    public function presentKeyboard(array $campaign, array $content, bool $timerReady): array
    {
        $kb = Keyboard::inline();
        $target = $content['task_url'] ?? $content['play_store_url'] ?? '';
        if ($target !== '') {
            $kb->inlineRow(['🔗 Open Task', ['url' => (string) $target]]);
        }
        $kb->inlineRow(['📤 Submit Proof', 'task:proof:' . $campaign['id']]);
        $kb->inlineRow($this->skipButton((int) $campaign['id']));
        return $kb->buildInline();
    }

    public function verify(array $campaign, array $content, array $context): VerificationResult
    {
        /** @var Update|null $update */
        $update = $context['update'] ?? null;
        $proof = '';

        if ($update instanceof Update) {
            $proof = $update->text();
            $photo = $update->raw()['message']['photo'] ?? null;
            if ($photo !== null) {
                $largest = end($photo);
                $proof = 'photo:' . ($largest['file_id'] ?? '');
            }
        }

        if ($proof === '') {
            return VerificationResult::reject('📎 Please send valid proof (text or screenshot) to submit.');
        }

        return VerificationResult::pendingReview('⏳ Proof submitted. The advertiser will review it shortly.', $proof);
    }
}
