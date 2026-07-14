<?php

/**
 * Telegram webhook entry point.
 *
 * Telegram POSTs updates here. The secret token (set with the webhook) is
 * verified before any processing to reject forged requests. Always returns
 * 200 quickly so Telegram does not retry storms.
 */

declare(strict_types=1);

use App\Services\Container;
use App\Telegram\Bot;
use App\Telegram\Update;

/** @var \App\Core\App $app */
$app = require dirname(__DIR__) . '/core/bootstrap.php';

if (!$app->config()->isInstalled()) {
    http_response_code(404);
    exit;
}

// Verify Telegram's secret token header against the stored secret.
$expected = $app->settings()->get('webhook_secret', '');
$received = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
if ($expected !== '' && !hash_equals($expected, (string) $received)) {
    http_response_code(403);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$payload = json_decode($raw, true);

// Acknowledge immediately; process after closing the connection when possible.
http_response_code(200);
header('Content-Type: application/json');
echo '{"ok":true}';
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

if (!is_array($payload)) {
    exit;
}

try {
    $container = new Container($app);
    (new Bot($container))->handle(new Update($payload));
} catch (\Throwable $e) {
    $app->logger()->error('telegram', 'Webhook processing error', [
        'message' => $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
    ]);
}
