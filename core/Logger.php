<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Channel-based file logger.
 *
 * Each channel (telegram, payment, cron, admin, database, app) writes to its
 * own dated file under /logs so problems can be traced per subsystem. In
 * production only warning and above are written for the noisy channels.
 */
final class Logger
{
    public const DEBUG   = 'debug';
    public const INFO    = 'info';
    public const WARNING = 'warning';
    public const ERROR   = 'error';

    private string $dir;
    private bool $debug;

    public function __construct(string $dir, bool $debug = false)
    {
        $this->dir = rtrim($dir, '/');
        $this->debug = $debug;

        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0755, true);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    public function log(string $channel, string $level, string $message, array $context = []): void
    {
        if ($level === self::DEBUG && !$this->debug) {
            return;
        }

        $line = sprintf(
            "[%s] %s.%s: %s %s\n",
            date('Y-m-d H:i:s'),
            $channel,
            strtoupper($level),
            $message,
            $context === [] ? '' : json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        $file = sprintf('%s/%s-%s.log', $this->dir, $channel, date('Y-m-d'));
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    /** @param array<string, mixed> $context */
    public function error(string $channel, string $message, array $context = []): void
    {
        $this->log($channel, self::ERROR, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function warning(string $channel, string $message, array $context = []): void
    {
        $this->log($channel, self::WARNING, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function info(string $channel, string $message, array $context = []): void
    {
        $this->log($channel, self::INFO, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function debug(string $channel, string $message, array $context = []): void
    {
        $this->log($channel, self::DEBUG, $message, $context);
    }
}
