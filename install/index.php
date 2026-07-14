<?php

/**
 * ClickBee Install Wizard.
 *
 * Non-technical, browser-only installation: no SSH, Composer or terminal.
 * Walks through system checks, database, bot, payment and admin setup, then
 * creates the schema, writes config, registers the Telegram webhook and locks
 * itself so it cannot be run twice.
 */

declare(strict_types=1);

session_start();

use App\Core\Autoloader;
use App\Core\Database;
use App\Core\Migrator;
use App\Core\Security;

$basePath = dirname(__DIR__);
require $basePath . '/core/Autoloader.php';
$autoloader = new Autoloader();
$autoloader->addNamespace('App\\Core', $basePath . '/core');
$autoloader->register();

$configFile = $basePath . '/config/config.php';
$lockFile = $basePath . '/config/install.lock';

// Refuse to run again once installed / locked.
if (is_file($lockFile) || (is_file($configFile) && (require $configFile)['installed'] === true)) {
    render('Already Installed', 6, 6,
        '<p class="muted">The platform is already installed. For security, delete the <code>/install</code> folder.</p>'
        . '<a class="btn" href="../admin/">Go to Admin Panel</a>');
    exit;
}

$totalSteps = 5;
$step = (int) ($_GET['step'] ?? 1);
$error = null;
$notice = null;

/**
 * Render a page using the shared layout.
 */
function render(string $title, int $step, int $totalSteps, string $content, ?string $error = null, ?string $notice = null): void
{
    include __DIR__ . '/layout.php';
}

function e(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES);
}

// -------------------------------------------------------------------------
// Step 2 submit — validate + store DB credentials.
// -------------------------------------------------------------------------
if ($step === 2 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $db = [
        'host' => trim($_POST['host'] ?? 'localhost'),
        'name' => trim($_POST['name'] ?? ''),
        'user' => trim($_POST['user'] ?? ''),
        'pass' => (string) ($_POST['pass'] ?? ''),
        'port' => (int) ($_POST['port'] ?? 3306),
        'charset' => 'utf8mb4',
    ];
    try {
        new Database($db);
        $_SESSION['install_db'] = $db;
        header('Location: ?step=3');
        exit;
    } catch (\Throwable $ex) {
        $error = 'Connection failed: ' . e($ex->getMessage());
        $step = 2;
    }
}

// -------------------------------------------------------------------------
// Step 3 submit — bot config (validate token via getMe).
// -------------------------------------------------------------------------
if ($step === 3 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = trim($_POST['bot_token'] ?? '');
    $adminId = trim($_POST['admin_telegram_id'] ?? '');
    $baseUrl = rtrim(trim($_POST['base_url'] ?? ''), '/');

    $me = null;
    if ($token !== '') {
        $ch = curl_init('https://api.telegram.org/bot' . $token . '/getMe');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
        $resp = curl_exec($ch);
        curl_close($ch);
        $me = json_decode((string) $resp, true);
    }

    if (!is_array($me) || empty($me['ok'])) {
        $error = 'Invalid bot token — Telegram rejected it.';
        $step = 3;
    } elseif (!ctype_digit($adminId)) {
        $error = 'Admin Telegram ID must be numeric.';
        $step = 3;
    } elseif (!filter_var($baseUrl, FILTER_VALIDATE_URL)) {
        $error = 'Website URL is invalid.';
        $step = 3;
    } else {
        $_SESSION['install_bot'] = [
            'bot_token' => $token,
            'bot_username' => $me['result']['username'] ?? '',
            'admin_telegram_id' => $adminId,
            'base_url' => $baseUrl,
        ];
        header('Location: ?step=4');
        exit;
    }
}

// -------------------------------------------------------------------------
// Step 4 submit — Oxapay (optional).
// -------------------------------------------------------------------------
if ($step === 4 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $_SESSION['install_pay'] = [
        'oxapay_enabled' => isset($_POST['oxapay_enabled']) ? '1' : '0',
        'oxapay_merchant_key' => trim($_POST['merchant_key'] ?? ''),
        'oxapay_payout_key' => trim($_POST['payout_key'] ?? ''),
        'oxapay_webhook_secret' => trim($_POST['webhook_secret'] ?? ''),
        'oxapay_environment' => $_POST['environment'] ?? 'production',
    ];
    header('Location: ?step=5');
    exit;
}

// -------------------------------------------------------------------------
// Step 5 submit — create admin + finish installation.
// -------------------------------------------------------------------------
if ($step === 5 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $adminUser = trim($_POST['admin_user'] ?? '');
    $adminPass = (string) ($_POST['admin_pass'] ?? '');

    if (empty($_SESSION['install_db']) || empty($_SESSION['install_bot'])) {
        $error = 'Session expired. Please restart the installer.';
        $step = 1;
    } elseif (strlen($adminUser) < 3 || strlen($adminPass) < 8) {
        $error = 'Admin username must be 3+ chars and password 8+ chars.';
        $step = 5;
    } else {
        try {
            $dbConfig = $_SESSION['install_db'];
            $bot = $_SESSION['install_bot'];
            $pay = $_SESSION['install_pay'] ?? [];

            $database = new Database($dbConfig);
            $migrator = new Migrator($database, $basePath);
            $migrator->installSchema();
            $migrator->seed();

            $appKey = Security::randomKey(32);
            $webhookSecret = Security::randomKey(16);
            $cronSecret = Security::randomKey(16);

            // Apply admin-editable settings.
            $settings = array_merge([
                'bot_token' => $bot['bot_token'],
                'bot_username' => $bot['bot_username'],
                'admin_telegram_id' => $bot['admin_telegram_id'],
                'website_url' => $bot['base_url'],
                'webhook_secret' => $webhookSecret,
                'cron_secret' => $cronSecret,
            ], $pay);
            foreach ($settings as $k => $v) {
                $exists = $database->column('SELECT 1 FROM settings WHERE setting_key = ? LIMIT 1', [$k]);
                if ($exists) {
                    $database->update('settings', ['setting_value' => (string) $v], ['setting_key' => $k]);
                } else {
                    $database->insert('settings', ['setting_key' => $k, 'setting_value' => (string) $v]);
                }
            }

            // Create the super admin account.
            $database->insert('admins', [
                'username' => $adminUser,
                'password_hash' => Security::hashPassword($adminPass),
                'telegram_id' => (int) $bot['admin_telegram_id'],
                'role' => 'super',
            ]);

            // Write config file.
            $config = [
                'installed' => true,
                'db' => $dbConfig,
                'app_key' => $appKey,
                'environment' => 'production',
                'base_url' => $bot['base_url'],
            ];
            $php = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n";
            if (@file_put_contents($configFile, $php) === false) {
                throw new \RuntimeException('Cannot write config/config.php — make the /config folder writable (0755).');
            }

            // Register the Telegram webhook.
            $webhookUrl = $bot['base_url'] . '/webhook/index.php';
            $ch = curl_init('https://api.telegram.org/bot' . $bot['bot_token'] . '/setWebhook');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => ['url' => $webhookUrl, 'secret_token' => $webhookSecret, 'drop_pending_updates' => 'true'],
                CURLOPT_TIMEOUT => 15,
            ]);
            curl_exec($ch);
            curl_close($ch);

            // Lock the installer.
            @file_put_contents($lockFile, date('c'));
            unset($_SESSION['install_db'], $_SESSION['install_bot'], $_SESSION['install_pay']);

            $base = $bot['base_url'];
            $success = '<div class="alert alert-ok">🎉 Installation complete!</div>'
                . '<p class="muted">Webhook registered at <code>' . e($webhookUrl) . '</code></p>'
                . '<h2>Cron jobs (add in cPanel)</h2>'
                . '<p class="muted">Run each on its own schedule (e.g. every 5–15 min):</p>'
                . '<div class="check"><span>Pending verify</span></div><code>' . e($base) . '/cron/index.php?job=pending_verification&token=' . e($cronSecret) . '</code><br><br>'
                . '<div class="check"><span>Auto approval</span></div><code>' . e($base) . '/cron/index.php?job=auto_approval&token=' . e($cronSecret) . '</code><br><br>'
                . '<div class="check"><span>Campaign expiry</span></div><code>' . e($base) . '/cron/index.php?job=campaign_expiry&token=' . e($cronSecret) . '</code><br><br>'
                . '<div class="check"><span>Cleanup</span></div><code>' . e($base) . '/cron/index.php?job=cleanup&token=' . e($cronSecret) . '</code>'
                . '<a class="btn" href="../admin/">Open Admin Panel</a>'
                . '<p class="muted" style="margin-top:16px">⚠️ Now delete the <code>/install</code> folder from your hosting.</p>';
            render('Finished', 5, 5, $success);
            exit;
        } catch (\Throwable $ex) {
            $error = 'Installation failed: ' . e($ex->getMessage());
            $step = 5;
        }
    }
}

// -------------------------------------------------------------------------
// Render the requested step.
// -------------------------------------------------------------------------
switch ($step) {
    case 1:
        $checks = [
            'PHP 8.2+' => version_compare(PHP_VERSION, '8.2.0', '>='),
            'PDO MySQL' => extension_loaded('pdo_mysql'),
            'cURL' => extension_loaded('curl'),
            'mbstring' => extension_loaded('mbstring'),
            'JSON' => extension_loaded('json'),
            'config/ writable' => is_writable($basePath . '/config'),
            'logs/ writable' => is_writable($basePath . '/logs'),
            'storage/ writable' => is_writable($basePath . '/storage'),
        ];
        $allOk = !in_array(false, $checks, true);
        $rows = '';
        foreach ($checks as $name => $ok) {
            $rows .= '<div class="check"><span>' . e($name) . '</span><span class="' . ($ok ? 'badge-ok">✓ OK' : 'badge-err">✗ Fail') . '</span></div>';
        }
        $content = '<h2>System Requirements</h2>' . $rows
            . ($allOk
                ? '<a class="btn" href="?step=2">Continue →</a>'
                : '<p class="muted" style="margin-top:16px">Fix the failing items, then reload this page.</p><a class="btn" href="?step=1">Re-check</a>');
        render('System Check', 1, $totalSteps, $content, $error);
        break;

    case 2:
        $content = '<h2>Database Configuration</h2><form method="post" action="?step=2">'
            . '<label>Database Host</label><input name="host" value="localhost" required>'
            . '<label>Database Name</label><input name="name" required>'
            . '<label>Database User</label><input name="user" required>'
            . '<label>Database Password</label><input type="password" name="pass">'
            . '<label>Port</label><input name="port" value="3306" required>'
            . '<button class="btn" type="submit">Test & Continue →</button></form>';
        render('Database', 2, $totalSteps, $content, $error);
        break;

    case 3:
        $guessBase = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') ;
        $guessBase = rtrim(preg_replace('~/install/?$~', '', $guessBase . ($_SERVER['REQUEST_URI'] ?? '')), '/');
        $guessBase = preg_replace('~/install.*$~', '', $guessBase);
        $content = '<h2>Bot Configuration</h2><form method="post" action="?step=3">'
            . '<label>Bot Token (from @BotFather)</label><input name="bot_token" required>'
            . '<label>Admin Telegram ID (from @userinfobot)</label><input name="admin_telegram_id" required>'
            . '<label>Website URL (public, https)</label><input name="base_url" value="' . e($guessBase) . '" required>'
            . '<button class="btn" type="submit">Validate & Continue →</button></form>';
        render('Bot Setup', 3, $totalSteps, $content, $error);
        break;

    case 4:
        $content = '<h2>Payment (Oxapay)</h2><p class="muted">Optional — you can configure this later in the Admin Panel.</p><form method="post" action="?step=4">'
            . '<label><input type="checkbox" name="oxapay_enabled" style="width:auto"> Enable Oxapay deposits</label>'
            . '<label>Merchant API Key</label><input name="merchant_key">'
            . '<label>Payout Key</label><input name="payout_key">'
            . '<label>Webhook Secret</label><input name="webhook_secret">'
            . '<label>Environment</label><select name="environment"><option value="production">Production</option><option value="sandbox">Sandbox</option></select>'
            . '<button class="btn" type="submit">Continue →</button></form>';
        render('Payment', 4, $totalSteps, $content, $error);
        break;

    case 5:
        $content = '<h2>Create Super Admin</h2><form method="post" action="?step=5">'
            . '<label>Admin Username</label><input name="admin_user" required minlength="3">'
            . '<label>Admin Password (8+ chars)</label><input type="password" name="admin_pass" required minlength="8">'
            . '<button class="btn" type="submit">Finish Installation ✓</button></form>';
        render('Admin Account', 5, $totalSteps, $content, $error);
        break;

    default:
        header('Location: ?step=1');
        exit;
}
