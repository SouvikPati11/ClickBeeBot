<?php

/**
 * Super Admin Panel — front controller.
 *
 * Boots the guarded admin context, then dispatches to a page under /pages.
 * Each page processes its own POST actions and echoes its body; this file
 * captures that body and wraps it in the shared layout (sidebar + topbar).
 */

declare(strict_types=1);

require __DIR__ . '/app.php';

// Whitelisted pages -> [file, title]. Adding a feature = adding a page here.
$pages = [
    'dashboard'   => ['dashboard.php',   'Dashboard'],
    'users'       => ['users.php',       'Users'],
    'user'        => ['user.php',        'User Profile'],
    'advertisers' => ['advertisers.php', 'Advertisers'],
    'campaigns'   => ['campaigns.php',   'Campaigns'],
    'deposits'    => ['deposits.php',    'Deposits'],
    'withdrawals' => ['withdrawals.php',  'Withdrawals'],
    'tasktypes'   => ['tasktypes.php',   'Task & Profit Settings'],
    'payment'     => ['payment.php',     'Payment Settings'],
    'settings'    => ['settings.php',    'Bot & Platform Settings'],
    'broadcast'   => ['broadcast.php',   'Broadcast'],
    'logs'        => ['logs.php',        'Logs'],
];

$page = (string) ($_GET['page'] ?? 'dashboard');
if (!isset($pages[$page])) {
    $page = 'dashboard';
}

[$pageFile, $pageTitle] = $pages[$page];
$activePage = $page;

ob_start();
require __DIR__ . '/pages/' . $pageFile;
$content = ob_get_clean();

require __DIR__ . '/layout.php';
