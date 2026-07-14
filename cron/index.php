<?php

/**
 * Cron entry point for cPanel / Hostinger scheduled tasks (no SSH needed).
 *
 * Configure cron jobs to request, e.g.:
 *   php /home/USER/public_html/cron/index.php pending_verification SECRET
 * or via wget/curl:
 *   wget -q -O /dev/null "https://your-domain/cron/index.php?job=auto_approval&token=SECRET"
 *
 * The token is the cron_secret stored at installation. Requests without it are
 * rejected so the endpoints cannot be triggered by outsiders.
 */

declare(strict_types=1);

use App\Services\Container;
use App\Services\CronService;

/** @var \App\Core\App $app */
$app = require dirname(__DIR__) . '/core/bootstrap.php';

if (!$app->config()->isInstalled()) {
    exit("Not installed\n");
}

$isCli = PHP_SAPI === 'cli';
$job = $isCli ? ($argv[1] ?? '') : (string) ($_GET['job'] ?? '');
$token = $isCli ? ($argv[2] ?? '') : (string) ($_GET['token'] ?? '');

$expected = $app->settings()->get('cron_secret', '');
if ($expected === '' || !hash_equals($expected, $token)) {
    http_response_code(403);
    exit("Forbidden\n");
}

$valid = ['pending_verification', 'auto_approval', 'campaign_expiry', 'cleanup'];
if (!in_array($job, $valid, true)) {
    http_response_code(400);
    exit("Unknown job\n");
}

$result = (new CronService(new Container($app)))->run($job);
echo sprintf("[%s] %s (%dms): %s\n", $result['status'], $result['job'], $result['ms'], $result['message']);
