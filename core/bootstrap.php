<?php

/**
 * Application bootstrap.
 *
 * Included by every public entry point (webhook, cron, admin, advertiser).
 * Registers the autoloader, boots the service container and returns it.
 */

declare(strict_types=1);

use App\Core\App;
use App\Core\Autoloader;

$basePath = dirname(__DIR__);

require $basePath . '/core/Autoloader.php';

$autoloader = new Autoloader();
$autoloader->addNamespace('App\\Core', $basePath . '/core');
$autoloader->addNamespace('App\\Models', $basePath . '/models');
$autoloader->addNamespace('App\\Telegram', $basePath . '/telegram');
$autoloader->addNamespace('App\\Tasks', $basePath . '/classes/Tasks');
$autoloader->addNamespace('App\\Services', $basePath . '/classes/Services');
$autoloader->addNamespace('App\\Helpers', $basePath . '/helpers');
$autoloader->addNamespace('App\\Controllers', $basePath . '/controllers');
$autoloader->addNamespace('App\\Middleware', $basePath . '/middleware');
$autoloader->register();

// If the platform is not installed yet, redirect web visitors to the wizard.
$configFile = $basePath . '/config/config.php';
if (!is_file($configFile)) {
    if (PHP_SAPI !== 'cli' && !str_contains($_SERVER['REQUEST_URI'] ?? '', '/install')) {
        header('Location: install/');
        exit;
    }
}

return App::boot($basePath);
