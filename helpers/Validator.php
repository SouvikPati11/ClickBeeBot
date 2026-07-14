<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Reusable input validation used by both the bot flows and the web panels.
 *
 * Never trust user input: every advertiser URL, wallet address, reward and
 * budget passes through here before touching the database.
 */
final class Validator
{
    public static function telegramId(mixed $value): bool
    {
        return is_numeric($value) && (int) $value > 0;
    }

    public static function url(string $value): bool
    {
        if (!filter_var($value, FILTER_VALIDATE_URL)) {
            return false;
        }
        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true);
    }

    public static function amount(mixed $value, float $min = 0.0): bool
    {
        return is_numeric($value) && (float) $value >= $min;
    }

    public static function positiveAmount(mixed $value): bool
    {
        return is_numeric($value) && (float) $value > 0;
    }

    /** USDT BEP20 addresses are 0x-prefixed 40 hex chars. */
    public static function bep20Address(string $value): bool
    {
        return (bool) preg_match('/^0x[a-fA-F0-9]{40}$/', trim($value));
    }

    public static function binanceUid(string $value): bool
    {
        return (bool) preg_match('/^[0-9]{6,15}$/', trim($value));
    }

    /** Telegram channel/bot usernames: 5-32 chars, letters/digits/underscore. */
    public static function username(string $value): bool
    {
        $value = ltrim(trim($value), '@');
        return (bool) preg_match('/^[a-zA-Z][a-zA-Z0-9_]{4,31}$/', $value);
    }

    public static function normalizeUsername(string $value): string
    {
        $value = trim($value);
        // Accept full t.me links as well as @username.
        if (preg_match('~t\.me/([a-zA-Z0-9_]+)~', $value, $m)) {
            return $m[1];
        }
        return ltrim($value, '@');
    }

    public static function notEmpty(string $value, int $min = 1, int $max = 4096): bool
    {
        $len = mb_strlen(trim($value));
        return $len >= $min && $len <= $max;
    }
}
