<?php
/**
 * Payment gateway settings (Oxapay). Stored in the settings table; nothing is
 * hardcoded. The webhook URL is shown for reference.
 *
 * @var \App\Core\App $app
 * @var \App\Core\Database $db
 */
declare(strict_types=1);

$settings = $app->settings();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $settings->setMany([
        'oxapay_enabled'        => isset($_POST['oxapay_enabled']) ? '1' : '0',
        'oxapay_merchant_key'   => trim((string) ($_POST['oxapay_merchant_key'] ?? '')),
        'oxapay_payout_key'     => trim((string) ($_POST['oxapay_payout_key'] ?? '')),
        'oxapay_webhook_secret' => trim((string) ($_POST['oxapay_webhook_secret'] ?? '')),
        'oxapay_environment'    => ($_POST['oxapay_environment'] ?? 'production') === 'sandbox' ? 'sandbox' : 'production',
    ]);
    admin_log('payment_update');
    flash('Payment settings saved.');
    redirect(admin_url('payment'));
}

$webhookUrl = rtrim($settings->get('website_url', $app->config()->baseUrl()), '/') . '/webhook/oxapay.php';
?>
<h1>Payment Settings</h1>
<div class="panel">
  <h2>🏦 Oxapay</h2>
  <form method="post">
    <?= csrf_field() ?>
    <label style="display:flex;align-items:center;gap:8px;color:var(--text)">
      <input type="checkbox" name="oxapay_enabled" style="width:auto" <?= $settings->bool('oxapay_enabled') ? 'checked' : '' ?>> Enable Oxapay deposits
    </label>
    <label>Merchant API Key</label><input name="oxapay_merchant_key" value="<?= h($settings->get('oxapay_merchant_key')) ?>">
    <label>Payout Key</label><input name="oxapay_payout_key" value="<?= h($settings->get('oxapay_payout_key')) ?>">
    <label>Webhook Secret</label><input name="oxapay_webhook_secret" value="<?= h($settings->get('oxapay_webhook_secret')) ?>">
    <label>Environment</label>
    <select name="oxapay_environment">
      <option value="production" <?= $settings->get('oxapay_environment') === 'production' ? 'selected' : '' ?>>Production</option>
      <option value="sandbox" <?= $settings->get('oxapay_environment') === 'sandbox' ? 'selected' : '' ?>>Sandbox</option>
    </select>
    <p class="muted" style="margin-top:14px">Callback URL (set this in your Oxapay dashboard):<br><code><?= h($webhookUrl) ?></code></p>
    <button class="btn" type="submit" style="margin-top:8px">Save Payment Settings</button>
  </form>
</div>
