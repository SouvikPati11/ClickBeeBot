<?php
/**
 * Bot & Platform settings.
 *
 * All values are stored in the `settings` table and cached; saving flushes the
 * cache so the bot picks up changes immediately. Grouped into General, Info,
 * Referral and Deposit/Withdraw. Sensitive keys (tokens, secrets) are handled
 * carefully — the bot token is optional and, when changed, re-registers the
 * webhook.
 *
 * @var \App\Core\App $app
 * @var \App\Core\Database $db
 * @var \App\Services\Container $container
 */
declare(strict_types=1);

$settings = $app->settings();

// key => ['type' => text|number|textarea|checkbox|select, 'options' => [...]]
$fields = [
    // General.
    'bot_name'         => ['type' => 'text'],
    'bot_username'     => ['type' => 'text'],
    'support_username' => ['type' => 'text'],
    'official_channel' => ['type' => 'text'],
    'website_url'      => ['type' => 'text'],
    'admin_telegram_id'=> ['type' => 'text'],
    'currency_symbol'  => ['type' => 'text'],
    'timezone'         => ['type' => 'text'],
    'maintenance_mode' => ['type' => 'checkbox'],
    // Info pages.
    'info_about'       => ['type' => 'textarea'],
    'info_rules'       => ['type' => 'textarea'],
    'info_terms'       => ['type' => 'textarea'],
    // Referral.
    'referral_enabled'       => ['type' => 'checkbox'],
    'deposit_commission_pct' => ['type' => 'number'],
    'task_commission_pct'    => ['type' => 'number'],
    'min_referral_payout'    => ['type' => 'number'],
    // Deposit / withdraw.
    'min_deposit'          => ['type' => 'number'],
    'min_withdraw'         => ['type' => 'number'],
    'max_daily_withdraw'   => ['type' => 'number'],
    'withdraw_fee_enabled' => ['type' => 'checkbox'],
    'withdraw_fee_type'    => ['type' => 'select', 'options' => ['percentage', 'fixed']],
    'withdraw_fee_value'   => ['type' => 'number'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $pairs = [];
    foreach ($fields as $key => $def) {
        if ($def['type'] === 'checkbox') {
            $pairs[$key] = isset($_POST[$key]) ? '1' : '0';
        } elseif (array_key_exists($key, $_POST)) {
            $pairs[$key] = trim((string) $_POST[$key]);
        }
    }

    // Persist a new bot token if one was entered.
    $newToken = trim((string) ($_POST['bot_token'] ?? ''));
    if ($newToken !== '') {
        $settings->set('bot_token', $newToken);
    }

    $settings->setMany($pairs);

    // Re-register the webhook only when requested (a new bot token was entered,
    // or the "Re-register webhook" box was ticked). Best-effort: it is wrapped
    // so a network/API problem can never turn the settings save into a 500.
    $wantWebhook = $newToken !== '' || isset($_POST['reregister_webhook']);
    $webhookOk = null;
    if ($wantWebhook) {
        try {
            $token = $settings->get('bot_token', '');
            $base = rtrim($settings->get('website_url', $app->config()->baseUrl()), '/');
            if ($token !== '' && $base !== '') {
                $tg = new App\Telegram\TelegramApi($token, $app->logger());
                $webhookOk = $tg->setWebhook($base . '/webhook/index.php', $settings->get('webhook_secret', '')) !== null;
            }
        } catch (\Throwable $e) {
            $app->logger()->error('admin', 'Webhook re-register failed', ['error' => $e->getMessage()]);
            $webhookOk = false;
        }
    }

    admin_log('settings_update', implode(',', array_keys($pairs)));
    flash($webhookOk === false
        ? 'Settings saved, but the webhook could not be re-registered — check the bot token and website URL.'
        : 'Settings saved.' . ($webhookOk ? ' Webhook re-registered.' : ''), $webhookOk === false ? 'err' : 'ok');
    redirect(admin_url('settings'));
}

/** Render one field control. */
function field(string $key, array $def, App\Core\Settings $s): string
{
    $label = ucwords(str_replace('_', ' ', $key));
    $val = $s->get($key, '');
    $out = '<label>' . h($label) . '</label>';
    switch ($def['type']) {
        case 'checkbox':
            $out = '<label style="display:flex;align-items:center;gap:8px;color:var(--text)"><input type="checkbox" name="' . h($key) . '" style="width:auto" ' . ($s->bool($key) ? 'checked' : '') . '> ' . h($label) . '</label>';
            break;
        case 'textarea':
            $out .= '<textarea name="' . h($key) . '" rows="3">' . h($val) . '</textarea>';
            break;
        case 'select':
            $out .= '<select name="' . h($key) . '">';
            foreach ($def['options'] as $opt) {
                $out .= '<option value="' . h($opt) . '" ' . ($val === $opt ? 'selected' : '') . '>' . h($opt) . '</option>';
            }
            $out .= '</select>';
            break;
        case 'number':
            $out .= '<input name="' . h($key) . '" type="number" step="0.000001" value="' . h($val) . '">';
            break;
        default:
            $out .= '<input name="' . h($key) . '" value="' . h($val) . '">';
    }
    return $out;
}

$groups = [
    'General' => ['bot_name', 'bot_username', 'support_username', 'official_channel', 'website_url', 'admin_telegram_id', 'currency_symbol', 'timezone', 'maintenance_mode'],
    'Info Pages' => ['info_about', 'info_rules', 'info_terms'],
    'Referral' => ['referral_enabled', 'deposit_commission_pct', 'task_commission_pct', 'min_referral_payout'],
    'Deposit & Withdraw' => ['min_deposit', 'min_withdraw', 'max_daily_withdraw', 'withdraw_fee_enabled', 'withdraw_fee_type', 'withdraw_fee_value'],
];
?>
<h1>Bot &amp; Platform Settings</h1>
<form method="post">
  <?= csrf_field() ?>
  <?php foreach ($groups as $groupName => $keys): ?>
    <div class="panel">
      <h2><?= h($groupName) ?></h2>
      <?php foreach ($keys as $key): ?>
        <?= field($key, $fields[$key], $settings) ?>
      <?php endforeach; ?>
      <?php if ($groupName === 'General'): ?>
        <label>Bot Token <span class="muted">(leave blank to keep current; entering a new one re-registers the webhook)</span></label>
        <input name="bot_token" placeholder="••••••••••••">
        <label style="display:flex;align-items:center;gap:8px;color:var(--text);margin-top:12px">
          <input type="checkbox" name="reregister_webhook" style="width:auto"> Re-register Telegram webhook on save
          <span class="muted">(tick this if inline buttons like Complete / Verify don't respond)</span>
        </label>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  <button class="btn" type="submit">Save All Settings</button>
</form>
