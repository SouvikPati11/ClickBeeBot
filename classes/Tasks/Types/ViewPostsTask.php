<?php

declare(strict_types=1);

namespace App\Tasks\Types;

use App\Helpers\Money;
use App\Tasks\TaskType;
use App\Tasks\VerificationResult;
use App\Telegram\Keyboard;

/**
 * "View Posts" task: the bot copies the advertiser's post to the worker, who
 * views it for the timer duration and then completes. Same timer verification
 * as Visit Website but the content is a forwarded/copied Telegram post.
 */
final class ViewPostsTask extends TaskType
{
    public function presentText(array $campaign, array $content): string
    {
        return sprintf(
            "📄 <b>%s</b>\n\n%s\n\n💵 Reward: <b>%s</b>\n⏱ View the post above for <b>%d seconds</b>, then press Complete.",
            htmlspecialchars((string) $campaign['title'], ENT_QUOTES),
            htmlspecialchars((string) ($campaign['description'] ?? ''), ENT_QUOTES),
            Money::format((float) $campaign['worker_reward']),
            $this->timerSeconds()
        );
    }

    public function presentKeyboard(array $campaign, array $content, bool $timerReady): array
    {
        // Complete is always shown; verify() enforces the timer server-side.
        return Keyboard::inline()
            ->inlineRow($this->completeButton((int) $campaign['id']))
            ->inlineRow($this->skipButton((int) $campaign['id']))
            ->buildInline();
    }

    public function verify(array $campaign, array $content, array $context): VerificationResult
    {
        $elapsed = (int) ($context['elapsed'] ?? 0);
        if ($elapsed < $this->timerSeconds()) {
            return VerificationResult::retry(sprintf('⏳ Please wait %d more second(s).', $this->timerSeconds() - $elapsed));
        }
        return VerificationResult::approveAvailable('✅ Reward added to your available balance.');
    }
}
