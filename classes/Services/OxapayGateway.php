<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;
use App\Core\Settings;

/**
 * Oxapay crypto payment gateway client.
 *
 * Creates merchant invoices for deposits and verifies incoming webhooks. All
 * keys come from the settings table (admin editable) — never hardcoded. The
 * class is intentionally small so future gateways can implement the same
 * create/verify contract.
 */
final class OxapayGateway
{
    private string $merchantKey;
    private string $webhookSecret;

    public function __construct(
        private Settings $settings,
        private Logger $logger,
        private string $baseUrl,
    ) {
        $this->merchantKey = $settings->get('oxapay_merchant_key', '');
        $this->webhookSecret = $settings->get('oxapay_webhook_secret', '');
    }

    public function isEnabled(): bool
    {
        return $this->settings->bool('oxapay_enabled') && $this->merchantKey !== '';
    }

    /**
     * Create an invoice for a USD amount. Returns [payLink, trackId] or null.
     *
     * @return array{pay_link: string, track_id: string}|null
     */
    public function createInvoice(float $amountUsd, int $orderId): ?array
    {
        $payload = [
            'merchant'    => $this->merchantKey,
            'amount'      => $amountUsd,
            'currency'    => 'USD',
            'lifeTime'    => 60,
            'feePaidByPayer' => 1,
            'orderId'     => (string) $orderId,
            'callbackUrl' => $this->baseUrl . '/webhook/oxapay.php',
            'description' => 'Balance deposit #' . $orderId,
        ];

        $response = $this->post('https://api.oxapay.com/merchants/request', $payload);
        if ($response === null || (int) ($response['result'] ?? 0) !== 100) {
            $this->logger->error('payment', 'Oxapay invoice failed', ['response' => $response]);
            return null;
        }

        return [
            'pay_link' => (string) ($response['payLink'] ?? ''),
            'track_id' => (string) ($response['trackId'] ?? ''),
        ];
    }

    /**
     * Verify an incoming webhook against the stored secret (HMAC of raw body).
     */
    public function verifyWebhook(string $rawBody, string $signature): bool
    {
        if ($this->webhookSecret === '') {
            return false;
        }
        return hash_equals(hash_hmac('sha512', $rawBody, $this->webhookSecret), $signature);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private function post(string $url, array $payload): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 25,
        ]);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            $this->logger->error('payment', 'Oxapay cURL error', ['error' => $error]);
            return null;
        }
        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode((string) $response, true);
        return is_array($decoded) ? $decoded : null;
    }
}
