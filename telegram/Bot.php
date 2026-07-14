<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Services\Container;

/**
 * Top-level update router.
 *
 * Resolves the user, enforces maintenance mode and bans, then dispatches to
 * the worker/advertiser handlers or the task engine. Kept deliberately thin —
 * screen rendering and business logic live in the handlers and services.
 */
final class Bot
{
    private UserHandler $userHandler;
    private AdvertiserHandler $advertiserHandler;

    public function __construct(private Container $c)
    {
        $this->userHandler = new UserHandler($c);
        $this->advertiserHandler = new AdvertiserHandler($c);
    }

    public function handle(Update $update): void
    {
        $from = $update->from();
        if (($from['id'] ?? 0) === 0 || !empty($from['is_bot'])) {
            return;
        }

        $chatId = $update->chatId();
        if ($chatId === null) {
            return;
        }

        // Anti-flood: throttle per user.
        if (!$this->c->app()->rateLimiter()->allow('tg:' . $from['id'], 25, 10)) {
            return;
        }

        $referredBy = $this->extractReferrer($update);
        $user = $this->c->users()->findOrCreate($from, $referredBy);
        $this->c->users()->touchActive((int) $user['id']);

        if ($user['status'] === 'banned') {
            $this->c->telegram()->answerCallbackQuery($update->callbackId(), '🚫 Your account is banned.', true);
            return;
        }

        if ($referredBy !== null) {
            $this->c->referrals()->link($referredBy, (int) $user['id']);
        }

        // Maintenance mode blocks non-admins.
        $adminId = $this->c->app()->settings()->int('admin_telegram_id', 0);
        if ($this->c->app()->settings()->bool('maintenance_mode') && (int) $user['telegram_id'] !== $adminId) {
            $this->c->telegram()->sendMessage($chatId, '🛠 The bot is under maintenance. Please try again later.');
            return;
        }

        if ($update->isCallback()) {
            $this->routeCallback($update, $user, $chatId);
            return;
        }

        $this->routeMessage($update, $user, $chatId);
    }

    private function routeCallback(Update $update, array $user, int $chatId): void
    {
        $data = $update->callbackData();
        $this->c->telegram()->answerCallbackQuery($update->callbackId());
        $engine = $this->c->taskEngine();

        if (preg_match('/^task:(complete|skip|wait|proof):(\d+)$/', $data, $m)) {
            $campaignId = (int) $m[2];
            match ($m[1]) {
                'complete' => $engine->handleComplete($user, $campaignId, $chatId, $update),
                'skip'     => $engine->handleSkip($user, $campaignId, $chatId),
                'wait'     => $this->c->telegram()->answerCallbackQuery($update->callbackId(), '⏳ Please wait for the timer to finish.', true),
                'proof'    => $this->promptProof($user, $campaignId, $chatId),
                default    => null,
            };
            return;
        }

        if (str_starts_with($data, 'more:')) {
            $engine->presentNext($user, substr($data, 5), $chatId);
            return;
        }

        if (str_starts_with($data, 'bal:')) {
            $this->routeBalance(substr($data, 4), $user, $chatId);
            return;
        }

        if (str_starts_with($data, 'adv:')) {
            $this->advertiserHandler->routeCallback(substr($data, 4), $user, $chatId);
            return;
        }
    }

    private function routeMessage(Update $update, array $user, int $chatId): void
    {
        $text = $update->text();

        if (str_starts_with($text, '/start')) {
            $this->c->state()->clear((int) $user['telegram_id']);
            $this->userHandler->showMain($user, $chatId);
            return;
        }

        // State-driven flows (proof submission, join-bot forward, advertiser wizard).
        $state = $this->c->state()->get((int) $user['telegram_id']);
        if ($this->handleState($update, $user, $chatId, $state)) {
            return;
        }

        // Main menu.
        $typeKey = Menu::typeForLabel($text);
        if ($typeKey !== null) {
            $this->c->taskEngine()->presentNext($user, $typeKey, $chatId);
            return;
        }

        switch ($text) {
            case Menu::MORE:      $this->userHandler->showMore($user, $chatId); return;
            case Menu::BALANCE:   $this->userHandler->showBalance($user, $chatId); return;
            case Menu::REFERRALS: $this->userHandler->showReferrals($user, $chatId); return;
            case Menu::INFO:      $this->userHandler->showInfo($chatId); return;
            case Menu::ADVERTISE: $this->advertiserHandler->showMain($user, $chatId); return;
            default:
                $this->userHandler->showMain($user, $chatId);
        }
    }

    /**
     * @param array{state: string|null, payload: array<string, mixed>} $state
     */
    private function handleState(Update $update, array $user, int $chatId, array $state): bool
    {
        $current = $state['state'] ?? null;
        if ($current === null) {
            return false;
        }

        // Awaiting manual proof.
        if ($current === 'task:awaitproof') {
            $campaignId = (int) ($state['payload']['campaign_id'] ?? 0);
            $this->c->taskEngine()->handleComplete($user, $campaignId, $chatId, $update);
            return true;
        }

        // Join-bot: user forwards a message from the advertiser bot.
        if (str_starts_with($current, 'task:join_bot') && $update->isForward()) {
            $campaignId = (int) ($state['payload']['campaign_id'] ?? 0);
            $this->c->taskEngine()->handleComplete($user, $campaignId, $chatId, $update);
            return true;
        }

        // Advertiser wizard / withdraw flows.
        if (str_starts_with($current, 'adv:')) {
            return $this->advertiserHandler->handleState($update, $user, $chatId, $state);
        }

        return false;
    }

    private function promptProof(array $user, int $campaignId, int $chatId): void
    {
        $this->c->state()->set((int) $user['telegram_id'], 'task:awaitproof', ['campaign_id' => $campaignId]);
        $this->c->telegram()->sendMessage($chatId, '📤 Send your proof now (text or screenshot).');
    }

    private function routeBalance(string $action, array $user, int $chatId): void
    {
        match ($action) {
            'refresh', 'refinc' => $this->userHandler->showBalance($user, $chatId),
            'history'           => $this->userHandler->showHistory($user, $chatId),
            'tx'                => $this->userHandler->showTransactions($user, $chatId),
            'notif'             => $this->userHandler->showNotifications($user, $chatId),
            'deposit'           => $this->advertiserHandler->startDeposit($user, $chatId, 'user'),
            'withdraw'          => $this->advertiserHandler->startWithdraw($user, $chatId),
            default             => null,
        };
    }

    private function extractReferrer(Update $update): ?int
    {
        $text = $update->text();
        if (!preg_match('/^\/start\s+(\S+)/', $text, $m)) {
            return null;
        }
        $code = $m[1];
        $referrer = $this->c->users()->findByReferralCode($code);
        return $referrer !== null ? (int) $referrer['id'] : null;
    }
}
