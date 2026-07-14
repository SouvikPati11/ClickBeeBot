<?php
/**
 * Dashboard: live platform KPIs and profit breakdown.
 *
 * @var \App\Core\Database $db
 */
declare(strict_types=1);

$totalUsers     = (int) $db->column('SELECT COUNT(*) FROM users');
$activeToday    = (int) $db->column('SELECT COUNT(*) FROM users WHERE last_active >= (NOW() - INTERVAL 1 DAY)');
$activeCamps    = (int) $db->column('SELECT COUNT(*) FROM campaigns WHERE status = "active"');
$pendingReviews = (int) $db->column('SELECT COUNT(*) FROM task_submissions WHERE status = "pending_review"');
$totalDeposits  = (float) $db->column('SELECT COALESCE(SUM(amount),0) FROM deposits WHERE status = "paid"');
$totalWithdraw  = (float) $db->column('SELECT COALESCE(SUM(amount),0) FROM withdrawals WHERE status = "completed"');
$advBalance     = (float) $db->column('SELECT COALESCE(SUM(balance),0) FROM advertiser_wallet');
$completedTasks = (int) $db->column('SELECT COUNT(*) FROM task_history');
$pendingWd      = (int) $db->column('SELECT COUNT(*) FROM withdrawals WHERE status = "pending"');

$spend      = (float) $db->column('SELECT COALESCE(SUM(spent_amount),0) FROM campaigns');
$workerPaid = (float) $db->column('SELECT COALESCE(SUM(reward),0) FROM task_history');
$campaignProfit = $spend - $workerPaid;
$wdFeeProfit    = (float) $db->column('SELECT COALESCE(SUM(fee),0) FROM withdrawals WHERE status = "completed"');
$profit = $campaignProfit + $wdFeeProfit;

$cards = [
    '👥 Total Users'        => number_format($totalUsers),
    '🟢 Active Today'       => number_format($activeToday),
    '📢 Active Campaigns'   => number_format($activeCamps),
    '⏳ Pending Reviews'    => number_format($pendingReviews),
    '💸 Pending Withdrawals'=> number_format($pendingWd),
    '💰 Total Deposits'     => '$' . number_format($totalDeposits, 2),
    '📤 Total Withdrawals'  => '$' . number_format($totalWithdraw, 2),
    '💵 Advertiser Balance' => '$' . number_format($advBalance, 2),
    '🎯 Completed Tasks'    => number_format($completedTasks),
];
?>
<h1>Dashboard</h1>
<div class="grid">
  <?php foreach ($cards as $k => $v): ?>
    <div class="card"><div class="k"><?= h($k) ?></div><div class="v"><?= h($v) ?></div></div>
  <?php endforeach; ?>
</div>

<div class="panel" style="margin-top:20px">
  <h2>💎 Platform Profit</h2>
  <div class="grid">
    <div class="card"><div class="k">Campaign Profit</div><div class="v">$<?= number_format($campaignProfit, 4) ?></div></div>
    <div class="card"><div class="k">Withdraw Fee Profit</div><div class="v">$<?= number_format($wdFeeProfit, 4) ?></div></div>
    <div class="card"><div class="k">Lifetime Profit</div><div class="v">$<?= number_format($profit, 4) ?></div></div>
  </div>
</div>

<?php
$topWorkers = $db->fetchAll('SELECT username, first_name, completed_tasks, total_earned FROM users ORDER BY total_earned DESC LIMIT 5');
$recentCamps = $db->fetchAll('SELECT title, task_type_key, status, completed_count FROM campaigns ORDER BY created_at DESC LIMIT 5');
?>
<div class="row2">
  <div class="panel">
    <h2>🏆 Top Workers</h2>
    <div class="table-wrap"><table>
      <tr><th>User</th><th>Tasks</th><th>Earned</th></tr>
      <?php foreach ($topWorkers as $w): ?>
        <tr><td><?= h($w['username'] ? '@' . $w['username'] : $w['first_name']) ?></td><td><?= (int) $w['completed_tasks'] ?></td><td>$<?= number_format((float) $w['total_earned'], 4) ?></td></tr>
      <?php endforeach; ?>
      <?php if ($topWorkers === []): ?><tr><td colspan="3" class="muted">No data yet.</td></tr><?php endif; ?>
    </table></div>
  </div>
  <div class="panel">
    <h2>🕒 Recent Campaigns</h2>
    <div class="table-wrap"><table>
      <tr><th>Title</th><th>Type</th><th>Status</th></tr>
      <?php foreach ($recentCamps as $c): ?>
        <tr><td><?= h($c['title']) ?></td><td><?= h($c['task_type_key']) ?></td><td><span class="pill <?= h($c['status']) ?>"><?= h($c['status']) ?></span></td></tr>
      <?php endforeach; ?>
      <?php if ($recentCamps === []): ?><tr><td colspan="3" class="muted">No campaigns yet.</td></tr><?php endif; ?>
    </table></div>
  </div>
</div>
