<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\UserRepository;
use App\Telegram\TelegramApi;

/**
 * Persists in-app notifications and optionally pushes them to Telegram.
 *
 * Every important platform action (task approved/rejected, deposit, withdraw,
 * referral commission, reward cancelled) flows through here so the unread
 * counter and history stay consistent.
 */
final class NotificationService
{
    public function __construct(
        private Database $db,
        private UserRepository $users,
        private TelegramApi $telegram,
    ) {
    }

    public function notify(int $userId, string $title, string $message, string $type = 'system', bool $push = true): void
    {
        $this->db->insert('notifications', [
            'user_id' => $userId,
            'title'   => $title,
            'message' => $message,
            'type'    => $type,
        ]);
        $this->users->incrementCounter($userId, 'notification_count');

        if ($push) {
            $user = $this->users->findById($userId);
            if ($user !== null && $user['status'] === 'active') {
                $this->telegram->sendMessage(
                    (int) $user['telegram_id'],
                    '<b>' . htmlspecialchars($title, ENT_QUOTES) . '</b>' . "\n" . htmlspecialchars($message, ENT_QUOTES)
                );
            }
        }
    }

    public function unreadCount(int $userId): int
    {
        return (int) $this->db->column(
            'SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0',
            [$userId]
        );
    }

    public function markAllRead(int $userId): void
    {
        $this->db->run('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0', [$userId]);
    }
}
