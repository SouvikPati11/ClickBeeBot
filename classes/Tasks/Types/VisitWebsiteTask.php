<?php

declare(strict_types=1);

namespace App\Tasks\Types;

use App\Helpers\Money;
use App\Tasks\TaskType;
use App\Tasks\VerificationResult;
use App\Telegram\Keyboard;

/**
 * "Visit Website" task: open a URL, wait out a timer, then complete.
 *
 * Verification is a server-side timer measured from when the task was
 * presented — the Complete button does nothing until it elapses.
 */
final class VisitWebsiteTask extends TaskType
{
    public function presentText(array $campaign, array $content): string
    {
        return sprintf(
            "💻 <b>%s</b>\n\n%s\n\n💵 Reward: <b>%s</b>\n⏱ Stay for <b>%d seconds</b>, then press Complete.",
            htmlspecialchars((string) $campaign['title'], ENT_QUOTES),
            htmlspecialchars((string) ($campaign['description'] ?? ''), ENT_QUOTES),
            Money::format((float) $campaign['worker_reward']),
            $this->timerSeconds()
        );
    }

    public function presentKeyboard(array $campaign, array $content, bool $timerReady): array
    {
        $kb = Keyboard::inline()->inlineRow(
            ['🌐 Open Website', ['url' => (string) $content['website_url']]]
        );

        if ($timerReady) {
            $kb->inlineRow($this->completeButton((int) $campaign['id']));
        } else {
            $kb->inlineRow(['⏳ Please wait…', 'task:wait:' . $campaign['id']]);
        }
        $kb->inlineRow($this->skipButton((int) $campaign['id']));

        return $kb->buildInline();
    }

    public function verify(array $campaign, array $content, array $context): VerificationResult
    {
        $elapsed = (int) ($context['elapsed'] ?? 0);
        if ($elapsed < $this->timerSeconds()) {
            $wait = $this->timerSeconds() - $elapsed;
            return VerificationResult::retry(sprintf('⏳ Please wait %d more second(s).', $wait));
        }
        return VerificationResult::approveAvailable('✅ Reward added to your available balance.');
    }
}
