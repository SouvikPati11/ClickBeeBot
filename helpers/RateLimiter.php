<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Lightweight file-based rate limiter for anti-flood / anti-spam.
 *
 * Shared hosting rarely has Redis, so limits are tracked in small per-bucket
 * cache files. Used to throttle bot commands, task completions and callback
 * spam per Telegram user.
 */
final class RateLimiter
{
    private string $dir;

    public function __construct(string $cacheDir)
    {
        $this->dir = rtrim($cacheDir, '/') . '/ratelimit';
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0755, true);
        }
    }

    /**
     * Returns true if the action is allowed (and records the hit).
     */
    public function allow(string $key, int $maxHits, int $windowSeconds): bool
    {
        $file = $this->dir . '/' . sha1($key) . '.json';
        $now = time();

        $data = ['count' => 0, 'reset' => $now + $windowSeconds];
        if (is_file($file)) {
            /** @var array{count:int,reset:int}|null $decoded */
            $decoded = json_decode((string) file_get_contents($file), true);
            if (is_array($decoded) && $decoded['reset'] > $now) {
                $data = $decoded;
            }
        }

        if ($data['count'] >= $maxHits) {
            return false;
        }

        $data['count']++;
        @file_put_contents($file, json_encode($data), LOCK_EX);
        return true;
    }
}
