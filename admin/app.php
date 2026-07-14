<?php

/**
 * Admin panel bootstrap, authentication guard and shared helpers.
 *
 * Every admin page includes this first. It boots the kernel, enforces the
 * session (login, timeout, regeneration), and exposes small helpers used by
 * the pages: CSRF, flash messages, audit logging and safe output.
 *
 * If the visitor is not authenticated it renders the login screen and exits,
 * so no page needs to repeat the guard.
 *
 * @var \App\Core\App $app
 * @var \App\Core\Database $db
 * @var \App\Core\Security $security
 */

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

use App\Core\Security;
use App\Services\Container;

/** @var \App\Core\App $app */
$app = require dirname(__DIR__) . '/core/bootstrap.php';

if (!$app->config()->isInstalled()) {
    header('Location: ../install/');
    exit;
}

$db = $app->db();
$security = $app->security();
$container = new Container($app);

// ---- helpers -------------------------------------------------------------

function h(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function redirect(string $to): never
{
    header('Location: ' . $to);
    exit;
}

/** Store a one-shot flash message shown on the next page render. */
function flash(string $message, string $type = 'ok'): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/** @return array<int, array{type:string,message:string}> */
function take_flash(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function csrf_field(): string
{
    global $security;
    return '<input type="hidden" name="csrf" value="' . h($security->csrfToken()) . '">';
}

/** Verify a POST CSRF token; abort the request on mismatch. */
function csrf_check(): void
{
    global $security;
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    if (!$security->verifyCsrf($_POST['csrf'] ?? null)) {
        http_response_code(419);
        exit('Invalid session token. Go back and retry.');
    }
}

function admin_log(string $action, string $details = ''): void
{
    global $db;
    $db->insert('admin_logs', [
        'admin_id' => (int) ($_SESSION['admin_id'] ?? 0),
        'action'   => $action,
        'details'  => $details !== '' ? $details : null,
        'ip'       => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
}

function admin_url(string $page, array $params = []): string
{
    return 'index.php?' . http_build_query(array_merge(['page' => $page], $params));
}

// ---- session lifecycle ---------------------------------------------------

// Idle timeout (30 min).
if (isset($_SESSION['admin_id']) && (time() - ($_SESSION['admin_last'] ?? 0)) > 1800) {
    session_unset();
    session_destroy();
    session_start();
    $_SESSION['flash'][] = ['type' => 'err', 'message' => 'Session expired. Please log in again.'];
    redirect('index.php');
}
$_SESSION['admin_last'] = time();

// Logout.
if (($_GET['action'] ?? '') === 'logout') {
    session_unset();
    session_destroy();
    redirect('index.php');
}

// Login submit.
$loginError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'login') {
    if (!$security->verifyCsrf($_POST['csrf'] ?? null)) {
        $loginError = 'Invalid session token. Please retry.';
    } elseif (!$app->rateLimiter()->allow('adminlogin:' . ($_SERVER['REMOTE_ADDR'] ?? 'x'), 5, 300)) {
        $loginError = 'Too many attempts. Try again in a few minutes.';
    } else {
        $admin = $db->fetch('SELECT * FROM admins WHERE username = ? LIMIT 1', [trim((string) ($_POST['username'] ?? ''))]);
        if ($admin !== null && Security::verifyPassword((string) ($_POST['password'] ?? ''), (string) $admin['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['admin_id'] = (int) $admin['id'];
            $_SESSION['admin_name'] = $admin['username'];
            $_SESSION['admin_role'] = $admin['role'];
            $_SESSION['admin_last'] = time();
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $db->update('admins', ['last_login_at' => date('Y-m-d H:i:s'), 'last_login_ip' => $ip], ['id' => (int) $admin['id']]);
            $db->insert('admin_logs', ['admin_id' => (int) $admin['id'], 'action' => 'login', 'ip' => $ip]);
            redirect('index.php');
        }
        $loginError = 'Invalid username or password.';
    }
}

// Guard: not logged in -> show login and stop.
if (!isset($_SESSION['admin_id'])) {
    include __DIR__ . '/login.php';
    exit;
}
