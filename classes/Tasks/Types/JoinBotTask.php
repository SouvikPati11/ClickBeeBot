<?php

declare(strict_types=1);

namespace App\Tasks\Types;

use App\Helpers\Money;
use App\Tasks\TaskType;
use App\Tasks\VerificationResult;
use App\Telegram\Keyboard;
use App\Telegram\Update;

/**
 * "Join Bot" task: worker starts the advertiser's bot and forwards any message
 * from it back. Verification checks the forward origin is a bot whose id
 * matches the campaign's stored bot id — preventing fake verification.
 */
final class JoinBotTask extends TaskType
{
    public function presentText(array $campaign, array $content): string
    {
        return sprintf(
            "🤖 <b>%s</b>\n\n%s\n\n💵 Reward: <b>%s</b>\n👉 Open the bot and press Start, then forward any message from it here.",
            htmlspecialchars((string) $campaign['title'], ENT_QUOTES),
            htmlspecialchars((string) ($campaign['description'] ?? ''), ENT_QUOTES),
            Money::format((float) $campaign['worker_reward'])
        );
    }

    public function presentKeyboard(array $campaign, array $content, bool $timerReady): array
    {
        $username = ltrim((string) $content['bot_username'], '@');
        return Keyboard::inline()
            ->inlineRow(['🤖 Open Bot', ['url' => 'https://t.me/' . $username]])
            ->inlineRow(['✅ I Forwarded — Verify', 'task:complete:' . $campaign['id']])
            ->inlineRow($this->skipButton((int) $campaign['id']))
            ->buildInline();
    }

    public function verify(array $campaign, array $content, array $context): VerificationResult
    {
        /** @var Update|null $update */
        $update = $context['update'] ?? null;

        if (!$update instanceof Update || !$update->isForward()) {
            return VerificationResult::reject('📩 Please forward a message <b>from the bot</b> to verify.');
        }

        $origin = $update->forwardOrigin();
        if ($origin === null) {
            return VerificationResult::reject('⚠️ This forward hides its origin. Forward a normal (non-protected) message from the bot.');
        }

        if ($origin['type'] !== 'bot') {
            return VerificationResult::reject('❌ That message is not from a bot. Forward a message from the advertiser bot.');
        }

        $expectedId = (int) ($content['bot_id'] ?? 0);
        $expectedUser = strtolower(ltrim((string) $content['bot_username'], '@'));
        $originId = (int) ($origin['id'] ?? 0);
        $originUser = strtolower((string) ($origin['username'] ?? ''));

        $matches = ($expectedId > 0 && $originId === $expectedId)
            || ($expectedUser !== '' && $originUser === $expectedUser);

        if (!$matches) {
            return VerificationResult::reject('❌ The forwarded message is from a different bot.');
        }

        return VerificationResult::approveAvailable('✅ Verified! Reward added to your available balance.');
    }
}
