<?php

/**
 * Oxapay payment webhook.
 *
 * Verifies the signature, then confirms the matching deposit exactly once.
 * Never trusts the callback blindly — the deposit is credited only on a
 * verified "Paid" status and only if it has not already been credited.
 */

declare(strict_types=1);

use App\Services\Container;

/** @var \App\Core\App $app */
$app = require dirname(__DIR__) . '/core/bootstrap.php';

if (!$app->config()->isInstalled()) {
    http_response_code(404);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$signature = $_SERVER['HTTP_HMAC'] ?? ($_SERVER['HTTP_X_SIGNATURE'] ?? '');

$container = new Container($app);
$gateway = $container->oxapay();

if (!$gateway->verifyWebhook($raw, (string) $signature)) {
    $app->logger()->warning('payment', 'Oxapay webhook signature rejected');
    http_response_code(403);
    exit;
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    exit;
}

$status = strtolower((string) ($data['status'] ?? ''));
$trackId = (string) ($data['trackId'] ?? ($data['track_id'] ?? ''));
$txId = (string) ($data['txID'] ?? ($data['txid'] ?? ''));

if (in_array($status, ['paid', 'confirmed', 'completed'], true) && $trackId !== '') {
    $container->depositService()->confirmByTrackId($trackId, $txId);
}

http_response_code(200);
echo 'OK';
