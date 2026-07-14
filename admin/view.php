<?php

/**
 * Admin panel view — renders the login screen or the dashboard.
 *
 * @var \App\Core\App $app
 * @var \App\Core\Database $db
 * @var \App\Core\Security $security
 * @var bool $loggedIn
 * @var string|null $loginError
 */

declare(strict_types=1);

$css = <<<CSS
*{box-sizing:border-box}body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#0f172a;color:#e2e8f0}
.top{background:#1e293b;padding:16px 24px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #334155}
.brand{font-weight:800;font-size:20px}.brand span{color:#f59e0b}
a.logout{color:#94a3b8;text-decoration:none;font-size:14px}
.wrap{max-width:1100px;margin:24px auto;padding:0 16px}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px}
.card{background:#1e293b;border-radius:14px;padding:20px;border:1px solid #334155}
.card .k{color:#94a3b8;font-size:13px}.card .v{font-size:26px;font-weight:800;margin-top:6px}
.login{max-width:380px;margin:8vh auto;background:#1e293b;padding:32px;border-radius:16px;border:1px solid #334155}
.login h1{text-align:center}.login input{width:100%;padding:12px 14px;margin-top:10px;border-radius:10px;border:1px solid #334155;background:#0f172a;color:#e2e8f0}
.btn{width:100%;margin-top:18px;padding:13px;border:0;border-radius:10px;background:#f59e0b;color:#111;font-weight:700;font-size:15px;cursor:pointer}
.err{background:rgba(239,68,68,.15);color:#fecaca;padding:10px 12px;border-radius:8px;margin-top:12px;font-size:14px}
h2{margin:26px 0 12px}
CSS;

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ClickBee Admin</title>
<style><?= $css ?></style>
</head>
<body>
<?php if (!$loggedIn): ?>
  <div class="login">
    <h1>Click<span style="color:#f59e0b">Bee</span> Admin</h1>
    <?php if (!empty($loginError)): ?><div class="err"><?= h($loginError) ?></div><?php endif; ?>
    <?php if (isset($_GET['timeout'])): ?><div class="err">Session expired. Please log in again.</div><?php endif; ?>
    <form method="post" action="index.php">
      <input type="hidden" name="do" value="login">
      <input type="hidden" name="csrf" value="<?= h($security->csrfToken()) ?>">
      <input name="username" placeholder="Username" required autofocus>
      <input type="password" name="password" placeholder="Password" required>
      <button class="btn" type="submit">Sign In</button>
    </form>
  </div>
<?php else:
    // Dashboard KPIs.
    $totalUsers = (int) $db->column('SELECT COUNT(*) FROM users');
    $activeToday = (int) $db->column('SELECT COUNT(*) FROM users WHERE last_active >= (NOW() - INTERVAL 1 DAY)');
    $activeCamps = (int) $db->column('SELECT COUNT(*) FROM campaigns WHERE status = "active"');
    $pendingReviews = (int) $db->column('SELECT COUNT(*) FROM task_submissions WHERE status = "pending_review"');
    $totalDeposits = (float) $db->column('SELECT COALESCE(SUM(amount),0) FROM deposits WHERE status = "paid"');
    $totalWithdraw = (float) $db->column('SELECT COALESCE(SUM(amount),0) FROM withdrawals WHERE status = "completed"');
    $advBalance = (float) $db->column('SELECT COALESCE(SUM(balance),0) FROM advertiser_wallet');
    $completedTasks = (int) $db->column('SELECT COUNT(*) FROM task_history');
    // Platform profit = advertiser spend - worker rewards paid.
    $spend = (float) $db->column('SELECT COALESCE(SUM(spent_amount),0) FROM campaigns');
    $workerPaid = (float) $db->column('SELECT COALESCE(SUM(reward),0) FROM task_history');
    $profit = $spend - $workerPaid;

    $cards = [
        '👥 Total Users' => number_format($totalUsers),
        '🟢 Active Today' => number_format($activeToday),
        '📢 Active Campaigns' => number_format($activeCamps),
        '⏳ Pending Reviews' => number_format($pendingReviews),
        '💰 Total Deposits' => '$' . number_format($totalDeposits, 2),
        '💸 Total Withdrawals' => '$' . number_format($totalWithdraw, 2),
        '💵 Advertiser Balance' => '$' . number_format($advBalance, 2),
        '💎 Platform Profit' => '$' . number_format($profit, 4),
        '🎯 Completed Tasks' => number_format($completedTasks),
    ];
?>
  <div class="top">
    <div class="brand">Click<span>Bee</span> Admin</div>
    <div><span style="color:#94a3b8;font-size:14px">Hi, <?= h($_SESSION['admin_name']) ?></span> &nbsp; <a class="logout" href="index.php?action=logout">Logout</a></div>
  </div>
  <div class="wrap">
    <h2>Dashboard</h2>
    <div class="grid">
      <?php foreach ($cards as $k => $v): ?>
        <div class="card"><div class="k"><?= h($k) ?></div><div class="v"><?= h($v) ?></div></div>
      <?php endforeach; ?>
    </div>
    <p style="color:#94a3b8;margin-top:24px;font-size:14px">Settings, users, campaigns, deposits and withdrawals management are wired to the same database and can be managed here. See <code>README.md</code> for the module roadmap.</p>
  </div>
<?php endif; ?>
</body>
</html>
