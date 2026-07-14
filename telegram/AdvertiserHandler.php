<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Helpers\Money;
use App\Helpers\Validator;
use App\Services\Container;

/**
 * Advertiser panel handler (Telegram side).
 *
 * Drives the advertiser main menu, wallet, campaign list/statistics and the
 * guided campaign-creation wizard. The wizard is task-type agnostic: it asks
 * for the type's primary asset, then the shared fields (title, description,
 * CPC, budget) and hands off to CampaignService for pricing + payment.
 *
 * Also hosts the deposit and withdraw flows shared with the worker panel.
 */
final class AdvertiserHandler
{
    private const MENU_CREATE = '➕ Create New Ad';
    private const MENU_CAMPS  = '📋 My Campaigns';
    private const MENU_WALLET = '💰 Wallet';
    private const MENU_STATS  = '📊 Statistics';
    private const MENU_REVIEW = '⏳ Pending Reviews';
    private const MENU_BACK   = '🔙 Back';
    private const BTN_CONFIRM = '✅ Confirm & Pay';
    private const BTN_CANCEL  = '❌ Cancel';

    public function __construct(private Container $c)
    {
    }

    // ---- Entry / menu -----------------------------------------------------

    public function showMain(array $user, int $chatId): void
    {
        $this->ensureAdvertiser($user);
        $this->c->state()->set((int) $user['telegram_id'], 'adv:home', []);

        $this->c->telegram()->sendMessage($chatId, "📊 <b>Advertiser Panel</b>\nManage your campaigns and wallet.", ['reply_markup' => $this->advMenuMarkup()]);
    }

    /**
     * The advertiser main-menu reply keyboard, reused across screens so
     * navigation stays consistent during the wizard.
     *
     * @return array<string, mixed>
     */
    private function advMenuMarkup(): array
    {
        return Keyboard::reply()
            ->row(self::MENU_CREATE)
            ->row(self::MENU_CAMPS, self::MENU_WALLET)
            ->row(self::MENU_STATS, self::MENU_REVIEW)
            ->row(self::MENU_BACK)
            ->buildReply();
    }

    /**
     * @param array{state: string|null, payload: array<string, mixed>} $state
     */
    public function handleState(Update $update, array $user, int $chatId, array $state): bool
    {
        $current = (string) $state['state'];
        $text = $update->text();

        // Menu buttons available on any advertiser screen.
        switch ($text) {
            case self::MENU_CREATE: $this->startCreate($user, $chatId); return true;
            case self::MENU_CAMPS:  $this->showCampaigns($user, $chatId); return true;
            case self::MENU_WALLET: $this->showWallet($user, $chatId); return true;
            case self::MENU_STATS:  $this->showStatistics($user, $chatId); return true;
            case self::MENU_REVIEW: $this->showPendingReviews($user, $chatId); return true;
            case self::MENU_BACK:
                $this->c->state()->clear((int) $user['telegram_id']);
                (new UserHandler($this->c))->showMain($user, $chatId);
                return true;
        }

        // Wizard / flow steps.
        return match (true) {
            $current === 'adv:new:type'    => $this->pickTypeByLabel($user, $chatId, $text),
            $current === 'adv:new:asset'   => $this->collectAsset($update, $user, $chatId, $state['payload']),
            $current === 'adv:new:title'   => $this->collectField($user, $chatId, $state['payload'], 'title', $text, 'adv:new:desc', '📝 Send a short description:'),
            $current === 'adv:new:desc'    => $this->collectField($user, $chatId, $state['payload'], 'description', $text, 'adv:new:cpc', '💵 Send the reward per action (CPC), e.g. 0.02:'),
            $current === 'adv:new:cpc'     => $this->collectCpc($user, $chatId, $state['payload'], $text),
            $current === 'adv:new:budget'  => $this->collectBudget($user, $chatId, $state['payload'], $text),
            $current === 'adv:new:confirm' => $this->confirmStep($user, $chatId, $text),
            $current === 'adv:deposit'     => $this->collectDeposit($user, $chatId, $state['payload'], $text),
            $current === 'adv:wd:address'  => $this->collectWithdrawAddress($user, $chatId, $state['payload'], $text),
            $current === 'adv:wd:amount'   => $this->collectWithdrawAmount($user, $chatId, $state['payload'], $text),
            default => false,
        };
    }

    public function routeCallback(string $action, array $user, int $chatId): void
    {
        if (str_starts_with($action, 'wd:')) {
            $this->pickWithdrawMethod($user, $chatId, substr($action, 3));
            return;
        }
        if (preg_match('/^camp:(pause|resume):(\d+)$/', $action, $m)) {
            $this->toggleCampaign($user, $chatId, (int) $m[2], $m[1]);
            return;
        }
        if (preg_match('/^rev:(approve|reject):(\d+)$/', $action, $m)) {
            $this->reviewSubmission($user, $chatId, (int) $m[2], $m[1]);
        }
    }

    private function reviewSubmission(array $user, int $chatId, int $submissionId, string $decision): void
    {
        $advertiser = $this->ensureAdvertiser($user);
        $submission = $this->c->submissions()->findById($submissionId);
        if ($submission === null || $submission['status'] !== 'pending_review') {
            $this->c->telegram()->sendMessage($chatId, '⚠️ This submission was already handled.');
            return;
        }
        $campaign = $this->c->campaigns()->findById((int) $submission['campaign_id']);
        if ($campaign === null || (int) $campaign['advertiser_id'] !== (int) $advertiser['id']) {
            return;
        }

        if ($decision === 'approve') {
            if ($this->c->campaigns()->chargeCompletion((int) $campaign['id'], (float) $campaign['cpc'])) {
                $reward = (float) $submission['reward'];
                $this->c->submissions()->updateStatus($submissionId, 'approved');
                $this->c->ledger()->creditAvailable((int) $submission['user_id'], $reward, 'task_reward', 'Task approved: ' . $campaign['title'], 'campaign:' . $campaign['id']);
                $this->c->submissions()->recordHistory((int) $campaign['id'], (int) $submission['user_id'], (string) $campaign['task_type_key'], $reward, 'completed');
                $this->c->users()->incrementCounter((int) $submission['user_id'], 'completed_tasks');
                $this->c->referrals()->creditTaskCommission((int) $submission['user_id'], $reward);
                $this->c->notifications()->notify((int) $submission['user_id'], 'Task Approved', 'Your submission was approved and rewarded.', 'task');
                $this->c->telegram()->sendMessage($chatId, '✅ Approved and rewarded.');
            } else {
                $this->c->telegram()->sendMessage($chatId, '⚠️ Campaign budget is exhausted.');
            }
        } else {
            $this->c->submissions()->updateStatus($submissionId, 'rejected', 'Rejected by advertiser');
            $this->c->campaigns()->incrementCounter((int) $campaign['id'], 'rejected_count');
            $this->c->users()->incrementCounter((int) $submission['user_id'], 'rejected_tasks');
            $this->c->notifications()->notify((int) $submission['user_id'], 'Task Rejected', 'Your submission was rejected. You may request an admin review.', 'task');
            $this->c->telegram()->sendMessage($chatId, '❌ Rejected.');
        }
    }

    // ---- Campaign creation wizard ----------------------------------------

    private function startCreate(array $user, int $chatId): void
    {
        $labels = [];
        foreach ($this->c->registry()->enabled() as $type) {
            $labels[] = $type['icon'] . ' ' . $type['name'];
        }

        // Two buttons per row for a cleaner, more organised layout.
        $kb = Keyboard::reply();
        foreach (array_chunk($labels, 2) as $pair) {
            $kb->row(...$pair);
        }
        $kb->row(self::MENU_BACK);

        $this->c->state()->set((int) $user['telegram_id'], 'adv:new:type', []);
        $this->c->telegram()->sendMessage(
            $chatId,
            "➕ <b>Create New Ad</b>\nChoose the type of task you want to promote:",
            ['reply_markup' => $kb->buildReply()]
        );
    }

    /**
     * Resolve the tapped reply-keyboard label to a task type and start the
     * asset step. Reply keyboards are more reliable than inline callbacks for
     * this multi-step flow.
     */
    private function pickTypeByLabel(array $user, int $chatId, string $label): bool
    {
        foreach ($this->c->registry()->enabled() as $type) {
            if (($type['icon'] . ' ' . $type['name']) === $label) {
                $this->pickType($user, $chatId, (string) $type['type_key']);
                return true;
            }
        }
        $this->c->telegram()->sendMessage($chatId, '❌ Please choose a task type using the buttons below.');
        return true;
    }

    private function pickType(array $user, int $chatId, string $typeKey): void
    {
        $config = $this->c->registry()->configFor($typeKey);
        if ($config === null) {
            $this->c->telegram()->sendMessage($chatId, '⚠️ Task type unavailable.');
            return;
        }
        $payload = ['type_key' => $typeKey, 'content' => []];
        $this->c->state()->set((int) $user['telegram_id'], 'adv:new:asset', $payload);
        // Restore the advertiser menu keyboard for the remaining wizard steps.
        $this->c->telegram()->sendMessage($chatId, $this->assetPrompt($typeKey), ['reply_markup' => $this->advMenuMarkup()]);
    }

    private function assetPrompt(string $typeKey): string
    {
        return match ($typeKey) {
            'visit_website' => '🌐 Send the website URL to promote:',
            'join_channel'  => "📢 Send your channel @username or link.\n⚠️ Add this bot as an <b>admin</b> of the channel first.",
            'join_bot'      => '🤖 Forward any message from the bot you want to promote.',
            'view_posts'    => '📄 Forward the Telegram post you want workers to view.',
            default         => '🔗 Send the task URL (Play Store / review / social link):',
        };
    }

    private function collectAsset(Update $update, array $user, int $chatId, array $payload): bool
    {
        $typeKey = (string) $payload['type_key'];
        $content = [];
        $text = $update->text();

        switch ($typeKey) {
            case 'visit_website':
                if (!Validator::url($text)) {
                    $this->c->telegram()->sendMessage($chatId, '❌ Invalid URL. Send a valid http(s) link.');
                    return true;
                }
                $content['website_url'] = $text;
                break;

            case 'join_channel':
                $username = Validator::normalizeUsername($text);
                if (!Validator::username($username)) {
                    $this->c->telegram()->sendMessage($chatId, '❌ Invalid channel username.');
                    return true;
                }
                if (!$this->verifyBotIsAdmin('@' . $username, $chatId)) {
                    return true;
                }
                $content['channel_username'] = $username;
                $chat = $this->c->telegram()->getChat('@' . $username);
                $content['channel_id'] = $chat['id'] ?? null;
                break;

            case 'join_bot':
                $origin = $update->isForward() ? $update->forwardOrigin() : null;
                if ($origin === null || $origin['type'] !== 'bot') {
                    $this->c->telegram()->sendMessage($chatId, '❌ Please forward a message from the bot (protected content disabled).');
                    return true;
                }
                $content['bot_id'] = $origin['id'];
                $content['bot_username'] = $origin['username'];
                break;

            case 'view_posts':
                if (!$update->isForward()) {
                    $this->c->telegram()->sendMessage($chatId, '❌ Please forward the post you want to promote.');
                    return true;
                }
                $origin = $update->forwardOrigin();
                $content['source_chat_id'] = $origin['id'] ?? null;
                $content['source_message_id'] = $update->raw()['message']['forward_from_message_id'] ?? ($update->raw()['message']['message_id'] ?? null);
                break;

            default:
                if (!Validator::url($text)) {
                    $this->c->telegram()->sendMessage($chatId, '❌ Invalid URL.');
                    return true;
                }
                $content['task_url'] = $text;
                $content['proof_type'] = 'screenshot';
        }

        $payload['content'] = $content;
        $this->c->state()->set((int) $user['telegram_id'], 'adv:new:title', $payload);
        $this->c->telegram()->sendMessage($chatId, '🏷 Send a campaign title:');
        return true;
    }

    private function collectField(array $user, int $chatId, array $payload, string $field, string $value, string $nextState, string $nextPrompt): bool
    {
        if (!Validator::notEmpty($value, 1, 300)) {
            $this->c->telegram()->sendMessage($chatId, '❌ Please send valid text.');
            return true;
        }
        $payload[$field] = $value;
        $this->c->state()->set((int) $user['telegram_id'], $nextState, $payload);
        $this->c->telegram()->sendMessage($chatId, $nextPrompt);
        return true;
    }

    private function collectCpc(array $user, int $chatId, array $payload, string $text): bool
    {
        if (!Validator::positiveAmount($text)) {
            $this->c->telegram()->sendMessage($chatId, '❌ Enter a valid amount, e.g. 0.02');
            return true;
        }
        $cpc = (float) $text;
        $validation = $this->c->campaignService()->validate((string) $payload['type_key'], $cpc, PHP_FLOAT_MAX);
        if (!$validation['ok'] && str_contains($validation['message'], 'CPC')) {
            $this->c->telegram()->sendMessage($chatId, $validation['message']);
            return true;
        }
        $payload['cpc'] = $cpc;
        $this->c->state()->set((int) $user['telegram_id'], 'adv:new:budget', $payload);
        $this->c->telegram()->sendMessage($chatId, '💰 Send the total budget for this campaign, e.g. 5:');
        return true;
    }

    private function collectBudget(array $user, int $chatId, array $payload, string $text): bool
    {
        if (!Validator::positiveAmount($text)) {
            $this->c->telegram()->sendMessage($chatId, '❌ Enter a valid budget amount.');
            return true;
        }
        $payload['total_budget'] = (float) $text;
        $this->c->state()->set((int) $user['telegram_id'], 'adv:new:confirm', $payload);

        $summary = sprintf(
            "📋 <b>Confirm Campaign</b>\n\n🏷 %s\n💵 Reward (CPC): %s\n💰 Budget: %s",
            htmlspecialchars((string) $payload['title'], ENT_QUOTES),
            Money::format((float) $payload['cpc']),
            Money::format((float) $payload['total_budget'])
        );
        $kb = Keyboard::reply()->row(self::BTN_CONFIRM)->row(self::BTN_CANCEL);
        $this->c->telegram()->sendMessage($chatId, $summary . "\n\nConfirm below to pay and activate.", ['reply_markup' => $kb->buildReply()]);
        return true;
    }

    /**
     * Handle the reply-keyboard confirmation step of the create wizard.
     */
    private function confirmStep(array $user, int $chatId, string $text): bool
    {
        if ($text === self::BTN_CONFIRM) {
            $this->finishCreate($user, $chatId);
            return true;
        }
        if ($text === self::BTN_CANCEL) {
            $this->c->state()->set((int) $user['telegram_id'], 'adv:home', []);
            $this->c->telegram()->sendMessage($chatId, '❌ Cancelled.', ['reply_markup' => $this->advMenuMarkup()]);
            return true;
        }
        $this->c->telegram()->sendMessage($chatId, 'Please tap ✅ Confirm & Pay or ❌ Cancel.');
        return true;
    }

    private function finishCreate(array $user, int $chatId): void
    {
        $state = $this->c->state()->get((int) $user['telegram_id']);
        $payload = $state['payload'];
        if (($state['state'] ?? '') !== 'adv:new:confirm') {
            return;
        }

        $advertiser = $this->ensureAdvertiser($user);
        $result = $this->c->campaignService()->create(
            (int) $advertiser['id'],
            (string) $payload['type_key'],
            [
                'title'        => $payload['title'] ?? 'Campaign',
                'description'  => $payload['description'] ?? '',
                'cpc'          => (float) $payload['cpc'],
                'total_budget' => (float) $payload['total_budget'],
            ],
            (array) ($payload['content'] ?? [])
        );

        $this->c->state()->set((int) $user['telegram_id'], 'adv:home', []);
        $this->c->telegram()->sendMessage($chatId, $result['message'], ['reply_markup' => $this->advMenuMarkup()]);
    }

    private function verifyBotIsAdmin(string $channel, int $chatId): bool
    {
        $me = $this->c->telegram()->getMe();
        $admins = $this->c->telegram()->getChatAdministrators($channel);
        if ($me === null || $admins === null) {
            $this->c->telegram()->sendMessage($chatId, '❌ Could not access that channel. Add the bot as an admin and try again.');
            return false;
        }
        foreach ($admins as $admin) {
            if ((int) ($admin['user']['id'] ?? 0) === (int) ($me['id'] ?? -1)) {
                return true;
            }
        }
        $this->c->telegram()->sendMessage($chatId, '❌ The bot is not an admin of that channel. Please add it as admin.');
        return false;
    }

    // ---- Wallet / campaigns / stats --------------------------------------

    public function showWallet(array $user, int $chatId): void
    {
        $advertiser = $this->ensureAdvertiser($user);
        $db = $this->c->app()->db();
        $wallet = $db->fetch('SELECT * FROM advertiser_wallet WHERE advertiser_id = ?', [(int) $advertiser['id']]) ?? [];
        // Campaigns are funded from the account balance (same as the Balance
        // screen), so the wallet shows that single balance.
        $balance = (float) $db->column('SELECT available_balance FROM users WHERE id = ?', [(int) $user['id']]);
        $text = sprintf(
            "💰 <b>Advertiser Wallet</b>\n\n💵 Balance: <b>%s</b>\n📤 Spent on ads: %s",
            Money::format($balance),
            Money::format((float) ($wallet['spent'] ?? 0))
        );
        $kb = Keyboard::inline()->inlineRow(['➕ Deposit', 'bal:deposit']);
        $this->c->telegram()->sendMessage($chatId, $text, ['reply_markup' => $kb->buildInline()]);
    }

    public function showCampaigns(array $user, int $chatId): void
    {
        $advertiser = $this->ensureAdvertiser($user);
        $rows = $this->c->app()->db()->fetchAll(
            'SELECT * FROM campaigns WHERE advertiser_id = ? ORDER BY created_at DESC LIMIT 10',
            [(int) $advertiser['id']]
        );
        if ($rows === []) {
            $this->c->telegram()->sendMessage($chatId, '📋 You have no campaigns yet. Tap “Create New Ad”.');
            return;
        }
        foreach ($rows as $c) {
            $text = sprintf(
                "🏷 <b>%s</b>\n📦 %s | Status: <b>%s</b>\n💰 Budget: %s | Remaining: %s\n✅ Completed: %d | ⏳ Pending: %d",
                htmlspecialchars((string) $c['title'], ENT_QUOTES),
                $c['task_type_key'],
                $c['status'],
                Money::format((float) $c['total_budget']),
                Money::format((float) $c['remaining_budget']),
                (int) $c['completed_count'],
                (int) $c['pending_count']
            );
            $kb = Keyboard::inline();
            if ($c['status'] === 'active') {
                $kb->inlineRow(['⏸ Pause', 'adv:camp:pause:' . $c['id']]);
            } elseif ($c['status'] === 'paused') {
                $kb->inlineRow(['▶ Resume', 'adv:camp:resume:' . $c['id']]);
            }
            $this->c->telegram()->sendMessage($chatId, $text, ['reply_markup' => $kb->buildInline()]);
        }
    }

    private function toggleCampaign(array $user, int $chatId, int $campaignId, string $action): void
    {
        $advertiser = $this->ensureAdvertiser($user);
        $campaign = $this->c->campaigns()->findById($campaignId);
        if ($campaign === null || (int) $campaign['advertiser_id'] !== (int) $advertiser['id']) {
            return;
        }
        $new = $action === 'pause' ? 'paused' : 'active';
        $this->c->campaigns()->setStatus($campaignId, $new);
        $this->c->telegram()->sendMessage($chatId, $action === 'pause' ? '⏸ Campaign paused.' : '▶ Campaign resumed.');
    }

    public function showStatistics(array $user, int $chatId): void
    {
        $advertiser = $this->ensureAdvertiser($user);
        $db = $this->c->app()->db();
        $id = (int) $advertiser['id'];
        $total = (int) $db->column('SELECT COUNT(*) FROM campaigns WHERE advertiser_id = ?', [$id]);
        $active = (int) $db->column('SELECT COUNT(*) FROM campaigns WHERE advertiser_id = ? AND status = "active"', [$id]);
        $spent = (float) $db->column('SELECT COALESCE(SUM(spent_amount),0) FROM campaigns WHERE advertiser_id = ?', [$id]);
        $completed = (int) $db->column('SELECT COALESCE(SUM(completed_count),0) FROM campaigns WHERE advertiser_id = ?', [$id]);
        $this->c->telegram()->sendMessage($chatId, sprintf(
            "📊 <b>Statistics</b>\n\n📦 Campaigns: %d\n🟢 Active: %d\n✅ Completions: %d\n📤 Total spent: %s",
            $total,
            $active,
            $completed,
            Money::format($spent)
        ));
    }

    public function showPendingReviews(array $user, int $chatId): void
    {
        $advertiser = $this->ensureAdvertiser($user);
        $rows = $this->c->app()->db()->fetchAll(
            'SELECT s.*, c.title FROM task_submissions s
             JOIN campaigns c ON c.id = s.campaign_id
             WHERE c.advertiser_id = ? AND s.status = "pending_review"
             ORDER BY s.created_at ASC LIMIT 10',
            [(int) $advertiser['id']]
        );
        if ($rows === []) {
            $this->c->telegram()->sendMessage($chatId, '⏳ No pending reviews.');
            return;
        }
        foreach ($rows as $s) {
            $kb = Keyboard::inline()->inlineRow(
                ['✅ Approve', 'adv:rev:approve:' . $s['id']],
                ['❌ Reject', 'adv:rev:reject:' . $s['id']]
            );
            $proof = (string) ($s['proof'] ?? '');
            $this->c->telegram()->sendMessage(
                $chatId,
                sprintf("🏷 %s\nProof: %s", htmlspecialchars((string) $s['title'], ENT_QUOTES), htmlspecialchars($proof, ENT_QUOTES)),
                ['reply_markup' => $kb->buildInline()]
            );
        }
    }

    // ---- Deposit / withdraw ----------------------------------------------

    public function startDeposit(array $user, int $chatId, string $accountType = 'user'): void
    {
        $this->c->state()->set((int) $user['telegram_id'], 'adv:deposit', ['account_type' => $accountType]);
        $this->c->telegram()->sendMessage($chatId, '➕ Enter the amount to deposit in USD (e.g. 5):');
    }

    private function collectDeposit(array $user, int $chatId, array $payload, string $text): bool
    {
        if (!Validator::positiveAmount($text)) {
            $this->c->telegram()->sendMessage($chatId, '❌ Enter a valid amount.');
            return true;
        }
        $result = $this->c->depositService()->initiate((int) $user['id'], (float) $text, (string) ($payload['account_type'] ?? 'user'));
        $this->c->state()->clear((int) $user['telegram_id']);

        if ($result['ok'] && !empty($result['pay_link'])) {
            $kb = Keyboard::inline()->inlineRow(['💳 Pay Now', ['url' => $result['pay_link']]]);
            $this->c->telegram()->sendMessage($chatId, $result['message'], ['reply_markup' => $kb->buildInline()]);
        } else {
            $this->c->telegram()->sendMessage($chatId, $result['message']);
        }
        return true;
    }

    public function startWithdraw(array $user, int $chatId): void
    {
        $kb = Keyboard::inline()
            ->inlineRow(['USDT (BEP20)', 'adv:wd:usdt_bep20'])
            ->inlineRow(['Binance UID', 'adv:wd:binance_uid']);
        $this->c->telegram()->sendMessage($chatId, '💸 Choose a withdrawal method:', ['reply_markup' => $kb->buildInline()]);
    }

    private function pickWithdrawMethod(array $user, int $chatId, string $method): void
    {
        $this->c->state()->set((int) $user['telegram_id'], 'adv:wd:address', ['method' => $method]);
        $prompt = $method === 'usdt_bep20' ? '📥 Send your USDT BEP20 wallet address:' : '📥 Send your Binance UID:';
        $this->c->telegram()->sendMessage($chatId, $prompt);
    }

    private function collectWithdrawAddress(array $user, int $chatId, array $payload, string $text): bool
    {
        $method = (string) $payload['method'];
        $valid = $method === 'usdt_bep20' ? Validator::bep20Address($text) : Validator::binanceUid($text);
        if (!$valid) {
            $this->c->telegram()->sendMessage($chatId, '❌ Invalid destination. Please check and resend.');
            return true;
        }
        $payload['destination'] = trim($text);
        $this->c->state()->set((int) $user['telegram_id'], 'adv:wd:amount', $payload);
        $this->c->telegram()->sendMessage($chatId, '💵 Enter the amount to withdraw:');
        return true;
    }

    private function collectWithdrawAmount(array $user, int $chatId, array $payload, string $text): bool
    {
        if (!Validator::positiveAmount($text)) {
            $this->c->telegram()->sendMessage($chatId, '❌ Enter a valid amount.');
            return true;
        }
        $result = $this->c->withdrawService()->request(
            (int) $user['id'],
            (string) $payload['method'],
            (string) $payload['destination'],
            (float) $text
        );
        $this->c->state()->clear((int) $user['telegram_id']);
        $this->c->telegram()->sendMessage($chatId, $result['message']);
        return true;
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function ensureAdvertiser(array $user): array
    {
        $db = $this->c->app()->db();
        $advertiser = $db->fetch('SELECT * FROM advertisers WHERE user_id = ? LIMIT 1', [(int) $user['id']]);
        if ($advertiser !== null) {
            return $advertiser;
        }
        $advertiserId = $db->insert('advertisers', [
            'user_id'      => (int) $user['id'],
            'display_name' => $user['first_name'] ?? ('User' . $user['id']),
        ]);
        $db->insert('advertiser_wallet', ['advertiser_id' => $advertiserId]);
        $db->update('users', ['is_advertiser' => 1], ['id' => (int) $user['id']]);
        /** @var array<string, mixed> $created */
        $created = $db->fetch('SELECT * FROM advertisers WHERE id = ?', [$advertiserId]);
        return $created;
    }
}
