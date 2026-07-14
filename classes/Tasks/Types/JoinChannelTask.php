<?php

declare(strict_types=1);

namespace App\Tasks\Types;

use App\Helpers\Money;
use App\Tasks\TaskType;
use App\Tasks\VerificationResult;
use App\Telegram\Keyboard;

/**
 * "Join Channel" task: worker joins, bot verifies membership via
 * getChatMember. Reward goes to PENDING balance and is re-verified by the
 * cron after the configured pending hours before moving to available.
 */
final class JoinChannelTask extends TaskType
{
    public function presentText(array $campaign, array $content): string
    {
        return sprintf(
            "📢 <b>%s</b>\n\n%s\n\n💵 Reward: <b>%s</b>\n👉 Join the channel, then press Verify.\n⏳ Reward confirms after %d hour(s) if you stay joined.",
            htmlspecialchars((string) $campaign['title'], ENT_QUOTES),
            htmlspecialchars((string) ($campaign['description'] ?? ''), ENT_QUOTES),
            Money::format((float) $campaign['worker_reward']),
            $this->pendingHours()
        );
    }

    public function presentKeyboard(array $campaign, array $content, bool $timerReady): array
    {
        $username = ltrim((string) $content['channel_username'], '@');
        return Keyboard::inline()
            ->inlineRow(['📢 Join Channel', ['url' => 'https://t.me/' . $username]])
            ->inlineRow(['✅ Verify', 'task:complete:' . $campaign['id']])
            ->inlineRow($this->skipButton((int) $campaign['id']))
            ->buildInline();
    }

    public function verify(array $campaign, array $content, array $context): VerificationResult
    {
        $userTelegramId = (int) ($context['user']['telegram_id'] ?? 0);
        $chat = $content['channel_id'] ?? ('@' . ltrim((string) $content['channel_username'], '@'));

        $member = $this->telegram->getChatMember($chat, $userTelegramId);
        if ($member === null) {
            return VerificationResult::reject('⚠️ Could not verify membership. Make sure you joined and try again.');
        }

        $status = (string) ($member['status'] ?? '');
        if (in_array($status, ['member', 'administrator', 'creator', 'owner'], true)) {
            return VerificationResult::approvePending('⏳ Reward added to pending balance. It confirms after the review period.');
        }

        return VerificationResult::reject('❌ You are not a member yet. Please join the channel first.');
    }
}
