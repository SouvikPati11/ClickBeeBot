<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Security primitives shared across the panels and webhook.
 *
 * Provides CSRF tokens (for the admin/advertiser web panels), output escaping,
 * password hashing, and constant-time signature helpers used to verify the
 * Telegram webhook secret and payment callbacks.
 */
final class Security
{
    private string $appKey;

    public function __construct(string $appKey)
    {
        $this->appKey = $appKey;
    }

    public static function randomKey(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }

    /** Escape a string for safe HTML output (XSS prevention). */
    public static function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    public function csrfToken(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return '';
        }
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = self::randomKey(16);
        }
        return (string) $_SESSION['_csrf'];
    }

    public function verifyCsrf(?string $token): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['_csrf']) || $token === null) {
            return false;
        }
        return hash_equals((string) $_SESSION['_csrf'], $token);
    }

    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    public static function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /** HMAC signature used for webhook secret paths and internal tokens. */
    public function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->appKey);
    }

    public function verifySignature(string $payload, string $signature): bool
    {
        return hash_equals($this->sign($payload), $signature);
    }

    public function verifyHmac(string $payload, string $signature, string $secret, string $algo = 'sha256'): bool
    {
        return hash_equals(hash_hmac($algo, $payload, $secret), $signature);
    }
}
