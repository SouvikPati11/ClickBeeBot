<?php

declare(strict_types=1);

namespace App\Core;

use App\Helpers\RateLimiter;

/**
 * Minimal service container / application kernel.
 *
 * Wires the core services together and exposes them via lazy accessors so the
 * webhook, cron jobs and web panels all share one bootstrap path. This is the
 * single composition root — nothing else constructs core services directly.
 */
final class App
{
    private static ?App $instance = null;

    public string $basePath;
    private Config $config;
    private ?Database $db = null;
    private ?Logger $logger = null;
    private ?Settings $settings = null;
    private ?Security $security = null;
    private ?RateLimiter $rateLimiter = null;

    private function __construct(string $basePath, Config $config)
    {
        $this->basePath = rtrim($basePath, '/');
        $this->config = $config;
    }

    public static function boot(string $basePath): self
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $configPath = $basePath . '/config/config.php';
        $config = Config::fromFile($configPath);

        $app = new self($basePath, $config);
        $app->registerErrorHandling();

        self::$instance = $app;
        return $app;
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            throw new \RuntimeException('Application not booted.');
        }
        return self::$instance;
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function db(): Database
    {
        return $this->db ??= new Database((array) $this->config->get('db', []));
    }

    public function logger(): Logger
    {
        return $this->logger ??= new Logger($this->basePath . '/logs', $this->config->isDebug());
    }

    public function settings(): Settings
    {
        return $this->settings ??= new Settings($this->db(), $this->basePath . '/storage/cache');
    }

    public function security(): Security
    {
        return $this->security ??= new Security((string) $this->config->get('app_key', ''));
    }

    public function rateLimiter(): RateLimiter
    {
        return $this->rateLimiter ??= new RateLimiter($this->basePath . '/storage/cache');
    }

    private function registerErrorHandling(): void
    {
        $debug = $this->config->isDebug();
        error_reporting(E_ALL);
        ini_set('display_errors', $debug ? '1' : '0');

        set_exception_handler(function (\Throwable $e): void {
            $this->logger()->error('app', $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            if (!$this->config->isDebug()) {
                http_response_code(500);
            }
        });
    }
}
