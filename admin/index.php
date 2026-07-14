<?php

/**
 * Super Admin Panel — authentication + dashboard.
 *
 * Session-based login against the `admins` table (hashed passwords), CSRF
 * protection on the login form, IP + audit logging, and a live dashboard of
 * platform KPIs. Management sub-pages hang off this same guarded bootstrap.
 */

declare(strict_types=1);

session_start();

use App\Core\Security;

/** @var \App\Core\App $app */
$app = require dirname(__DIR__) . '/core/bootstrap.php';

if (!$app->config()->isInstalled()) {
    header('Location: ../install/');
    exit;
}

$db = $app->db();
$security = $app->security();

// Session timeout (30 min idle).
if (isset($_SESSION['admin_id']) && (time() - ($_SESSION['admin_last'] ?? 0)) > 1800) {
    session_unset();
    session_destroy();
    header('Location: index.php?timeout=1');
    exit;
}
$_SESSION['admin_last'] = time();

$action = $_GET['action'] ?? '';

// Logout.
if ($action === 'logout') {
    session_unset();
    session_destroy();
    header('Location: index.php');
    exit;
}

// Login submit.
$loginError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'login') {
    if (!$security->verifyCsrf($_POST['csrf'] ?? null)) {
        $loginError = 'Invalid session token. Please retry.';
    } elseif (!$app->rateLimiter()->allow('adminlogin:' . ($_SERVER['REMOTE_ADDR'] ?? 'x'), 5, 300)) {
        $loginError = 'Too many attempts. Try again in a few minutes.';
    } else {
        $admin = $db->fetch('SELECT * FROM admins WHERE username = ? LIMIT 1', [trim($_POST['username'] ?? '')]);
        if ($admin !== null && Security::verifyPassword((string) ($_POST['password'] ?? ''), (string) $admin['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['admin_id'] = (int) $admin['id'];
            $_SESSION['admin_name'] = $admin['username'];
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $db->update('admins', ['last_login_at' => date('Y-m-d H:i:s'), 'last_login_ip' => $ip], ['id' => (int) $admin['id']]);
            $db->insert('admin_logs', ['admin_id' => (int) $admin['id'], 'action' => 'login', 'ip' => $ip]);
            header('Location: index.php');
            exit;
        }
        $loginError = 'Invalid username or password.';
    }
}

$loggedIn = isset($_SESSION['admin_id']);

function h(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES);
}

require __DIR__ . '/view.php';
