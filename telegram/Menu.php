<?php

declare(strict_types=1);

namespace App\Telegram;

/**
 * Central definition of the worker main-menu reply keyboard and the mapping
 * from button labels to task-type keys / actions. Keeping this in one place
 * means the menu is consistent everywhere and easy to extend.
 */
final class Menu
{
    public const VISIT     = '💻 Visit Sites';
    public const CHANNELS  = '📢 Join Channels';
    public const BOTS      = '🤖 Join Bots';
    public const MORE      = '🤩 More';
    public const BALANCE   = '💰 Balance';
    public const REFERRALS = '🙌 Referrals';
    public const INFO      = 'ℹ️ Info';
    public const ADVERTISE = '📊 Advertise';

    /** Reply-keyboard label -> task type key for the primary categories. */
    private const LABEL_TO_TYPE = [
        self::VISIT    => 'visit_website',
        self::CHANNELS => 'join_channel',
        self::BOTS     => 'join_bot',
    ];

    public static function typeForLabel(string $label): ?string
    {
        return self::LABEL_TO_TYPE[$label] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function main(): array
    {
        return Keyboard::reply()
            ->row(self::VISIT, self::CHANNELS)
            ->row(self::BOTS, self::MORE)
            ->row(self::BALANCE, self::REFERRALS)
            ->row(self::INFO, self::ADVERTISE)
            ->buildReply(true, false, 'Choose an option…');
    }
}
