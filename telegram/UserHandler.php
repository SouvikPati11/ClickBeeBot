<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Core\App;
use App\Helpers\Money;
use App\Services\Container;

/**
 * Worker (user) panel handler.
 *
 * Renders the reply-keyboard main menu and every worker screen (balance,
 * referrals, info, notifications, history, transactions) and routes task
 * actions to the TaskEngine. Screens are short and inline-keyboard driven,
 * per the platform UX rules.
 */
final class UserHandler
{
    public function __construct(private Container $c)
    {
    }

    /**
     * @param array<string, mixed> $user
     */
    public function showMain(array $user, int $chatId): void
    {
        $name = $this->c->app()->settings()->get('bot_name', 'ClickBee');
        $this->c->telegram()->sendMessage(
            $chatId,
            "👋 Welcome to <b>" . htmlspecialchars($name, ENT_QUOTES) . "</b>!\nComplete tasks and earn rewards.",
            ['reply_markup' => Menu::main()]
        );
    }

    /**
     * @param array<string, mixed> $user
     */
    public function showMore(array $user, int $chatId): void
    {
        $kb = Keyboard::inline();
        foreach ($this->c->registry()->enabled() as $type) {
            // The three primary categories already have their own menu buttons.
            if (in_array($type['type_key'], ['visit_website', 'join_channel', 'join_bot'], true)) {
                continue;
            }
            $available = $this->c->campaigns()->countAvailable((string) $type['type_key'], (int) $user['id']);
            $kb->inlineRow([
                sprintf('%s %s (%d)', $type['icon'], $type['name'], $available),
                'more:' . $type['type_key'],
            ]);
        }
        $this->c->telegram()->sendMessage($chatId, '🤩 <b>More task categories</b>', ['reply_markup' => $kb->buildInline()]);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function showBalance(array $user, int $chatId): void
    {
        $u = $this->c->users()->findById((int) $user['id']) ?? $user;
        $text = sprintf(
            "💰 <b>Your Balance</b>\n\n"
            . "💵 Available: <b>%s</b>\n⏳ Pending: <b>%s</b>\n🎁 Referral: <b>%s</b>\n\n"
            . "📥 Total Deposit: %s\n📤 Total Withdraw: %s\n🏆 Total Earned: %s",
            Money::format((float) $u['available_balance']),
            Money::format((float) $u['pending_balance']),
            Money::format((float) $u['referral_balance']),
            Money::format((float) $u['total_deposit']),
            Money::format((float) $u['total_withdraw']),
            Money::format((float) $u['total_earned'])
        );

        $kb = Keyboard::inline()
            ->inlineRow(['➕ Deposit', 'bal:deposit'], ['💸 Withdraw', 'bal:withdraw'])
            ->inlineRow(['📜 Task History', 'bal:history'], ['💳 Transactions', 'bal:tx'])
            ->inlineRow(['🎁 Referral Income', 'bal:refinc'], ['🔔 Notifications', 'bal:notif'])
            ->inlineRow(['🔄 Refresh', 'bal:refresh']);

        $this->c->telegram()->sendMessage($chatId, $text, ['reply_markup' => $kb->buildInline()]);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function showReferrals(array $user, int $chatId): void
    {
        $db = $this->c->app()->db();
        $userId = (int) $user['id'];
        $botUsername = $this->c->app()->settings()->get('bot_username', '');
        $link = $botUsername !== ''
            ? sprintf('https://t.me/%s?start=%s', ltrim($botUsername, '@'), $user['referral_code'])
            : 'Referral link unavailable (bot username not set).';

        $total = (int) $db->column('SELECT COUNT(*) FROM users WHERE referred_by = ?', [$userId]);
        $active = (int) $db->column(
            'SELECT COUNT(*) FROM users WHERE referred_by = ? AND last_active >= (NOW() - INTERVAL 7 DAY)',
            [$userId]
        );
        $depComm = (float) $db->column(
            'SELECT COALESCE(SUM(amount),0) FROM referral_history WHERE referrer_id = ? AND commission_type = "deposit"',
            [$userId]
        );
        $taskComm = (float) $db->column(
            'SELECT COALESCE(SUM(amount),0) FROM referral_history WHERE referrer_id = ? AND commission_type = "task"',
            [$userId]
        );

        $text = sprintf(
            "🙌 <b>Referrals</b>\n\n🔗 %s\n\n👥 Total: <b>%d</b>\n🟢 Active (7d): <b>%d</b>\n\n"
            . "💵 Deposit commission: <b>%s</b>\n🏆 Task commission: <b>%s</b>\n💰 Total: <b>%s</b>",
            htmlspecialchars($link, ENT_QUOTES),
            $total,
            $active,
            Money::format($depComm),
            Money::format($taskComm),
            Money::format($depComm + $taskComm)
        );
        $this->c->telegram()->sendMessage($chatId, $text);
    }

    public function showInfo(int $chatId): void
    {
        $s = $this->c->app()->settings();
        $text = "ℹ️ <b>Information</b>\n\n"
            . "<b>About</b>\n" . htmlspecialchars($s->get('info_about'), ENT_QUOTES) . "\n\n"
            . "<b>Rules</b>\n" . htmlspecialchars($s->get('info_rules'), ENT_QUOTES);

        $kb = Keyboard::inline();
        if ($s->get('support_username') !== '') {
            $kb->inlineRow(['💬 Support', ['url' => 'https://t.me/' . ltrim($s->get('support_username'), '@')]]);
        }
        if ($s->get('official_channel') !== '') {
            $kb->inlineRow(['📢 Channel', ['url' => 'https://t.me/' . ltrim($s->get('official_channel'), '@')]]);
        }
        $this->c->telegram()->sendMessage($chatId, $text, ['reply_markup' => $kb->buildInline()]);
    }

    public function showNotifications(array $user, int $chatId): void
    {
        $db = $this->c->app()->db();
        $rows = $db->fetchAll(
            'SELECT title, message, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10',
            [(int) $user['id']]
        );
        $this->c->notifications()->markAllRead((int) $user['id']);

        if ($rows === []) {
            $this->c->telegram()->sendMessage($chatId, '🔔 No notifications yet.');
            return;
        }
        $lines = ['🔔 <b>Recent notifications</b>', ''];
        foreach ($rows as $r) {
            $lines[] = sprintf('• <b>%s</b> — %s', htmlspecialchars((string) $r['title'], ENT_QUOTES), htmlspecialchars((string) $r['message'], ENT_QUOTES));
        }
        $this->c->telegram()->sendMessage($chatId, implode("\n", $lines));
    }

    public function showHistory(array $user, int $chatId): void
    {
        $rows = $this->c->app()->db()->fetchAll(
            'SELECT task_type_key, reward, status, completed_at FROM task_history WHERE user_id = ? ORDER BY completed_at DESC LIMIT 10',
            [(int) $user['id']]
        );
        if ($rows === []) {
            $this->c->telegram()->sendMessage($chatId, '📜 No completed tasks yet.');
            return;
        }
        $lines = ['📜 <b>Task History</b>', ''];
        foreach ($rows as $r) {
            $lines[] = sprintf('• %s — %s (%s)', $r['task_type_key'], Money::format((float) $r['reward']), $r['status']);
        }
        $this->c->telegram()->sendMessage($chatId, implode("\n", $lines));
    }

    public function showTransactions(array $user, int $chatId): void
    {
        $rows = $this->c->app()->db()->fetchAll(
            'SELECT type, amount, created_at FROM transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 10',
            [(int) $user['id']]
        );
        if ($rows === []) {
            $this->c->telegram()->sendMessage($chatId, '💳 No transactions yet.');
            return;
        }
        $lines = ['💳 <b>Transactions</b>', ''];
        foreach ($rows as $r) {
            $sign = (float) $r['amount'] >= 0 ? '➕' : '➖';
            $lines[] = sprintf('%s %s — %s', $sign, Money::format(abs((float) $r['amount'])), $r['type']);
        }
        $this->c->telegram()->sendMessage($chatId, implode("\n", $lines));
    }
}
