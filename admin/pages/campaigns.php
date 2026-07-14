<?php
/**
 * Campaign management: filter, view, pause/resume/delete, adjust budget,
 * edit reward and description. Deleting refunds unused budget to the
 * advertiser wallet.
 *
 * @var \App\Core\Database $db
 */
declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int) ($_POST['campaign_id'] ?? 0);
    $do = (string) ($_POST['do'] ?? '');
    $campaign = $db->fetch('SELECT * FROM campaigns WHERE id = ? LIMIT 1', [$id]);

    if ($campaign !== null) {
        switch ($do) {
            case 'pause':
                $db->update('campaigns', ['status' => 'paused'], ['id' => $id]);
                flash('Campaign paused.');
                break;
            case 'resume':
                $status = (float) $campaign['remaining_budget'] >= (float) $campaign['worker_reward'] ? 'active' : 'completed';
                $db->update('campaigns', ['status' => $status], ['id' => $id]);
                flash($status === 'active' ? 'Campaign resumed.' : 'Budget exhausted — marked completed.', $status === 'active' ? 'ok' : 'err');
                break;
            case 'add_budget':
                $amount = (float) ($_POST['amount'] ?? 0);
                if ($amount > 0) {
                    $db->transaction(function ($t) use ($id, $amount, $campaign) {
                        $ok = $t->run('UPDATE advertiser_wallet SET balance = balance - ? WHERE advertiser_id = ? AND balance >= ?', [$amount, (int) $campaign['advertiser_id'], $amount])->rowCount();
                        if ($ok === 0) {
                            throw new \RuntimeException('Insufficient advertiser wallet.');
                        }
                        $t->run('UPDATE campaigns SET total_budget = total_budget + ?, remaining_budget = remaining_budget + ? WHERE id = ?', [$amount, $amount, $id]);
                        $t->insert('advertiser_transactions', ['advertiser_id' => (int) $campaign['advertiser_id'], 'type' => 'campaign_payment', 'amount' => -$amount, 'campaign_id' => $id, 'description' => 'Budget increase (admin)']);
                    });
                    flash('Budget increased.');
                }
                break;
            case 'edit':
                $desc = trim((string) ($_POST['description'] ?? ''));
                $reward = (float) ($_POST['worker_reward'] ?? $campaign['worker_reward']);
                $db->update('campaigns', ['description' => $desc, 'worker_reward' => max(0, $reward)], ['id' => $id]);
                flash('Campaign updated.');
                break;
            case 'delete':
                // Refund unused budget to the advertiser wallet, then cancel.
                $db->transaction(function ($t) use ($id, $campaign) {
                    $refund = (float) $campaign['remaining_budget'];
                    if ($refund > 0) {
                        $t->run('UPDATE advertiser_wallet SET balance = balance + ? WHERE advertiser_id = ?', [$refund, (int) $campaign['advertiser_id']]);
                        $t->insert('advertiser_transactions', ['advertiser_id' => (int) $campaign['advertiser_id'], 'type' => 'refund', 'amount' => $refund, 'campaign_id' => $id, 'description' => 'Refund on campaign delete']);
                    }
                    $t->update('campaigns', ['status' => 'cancelled', 'remaining_budget' => 0], ['id' => $id]);
                });
                flash('Campaign cancelled and unused budget refunded.');
                break;
        }
        admin_log('campaign_' . $do, "campaign=$id");
    }
    redirect(admin_url('campaigns', array_filter(['type' => $_GET['type'] ?? '', 'status' => $_GET['status'] ?? ''])));
}

$typeFilter = (string) ($_GET['type'] ?? '');
$statusFilter = (string) ($_GET['status'] ?? '');
$where = [];
$params = [];
if ($typeFilter !== '') { $where[] = 'task_type_key = ?'; $params[] = $typeFilter; }
if ($statusFilter !== '') { $where[] = 'status = ?'; $params[] = $statusFilter; }
$sql = 'SELECT * FROM campaigns';
if ($where !== []) { $sql .= ' WHERE ' . implode(' AND ', $where); }
$sql .= ' ORDER BY id DESC LIMIT 50';
$camps = $db->fetchAll($sql, $params);

$types = $db->fetchAll('SELECT type_key, name FROM campaign_task_types ORDER BY sort_order');
$statuses = ['draft', 'pending_payment', 'active', 'paused', 'completed', 'expired', 'cancelled'];
?>
<h1>Campaigns</h1>
<form class="search" method="get" action="index.php">
  <input type="hidden" name="page" value="campaigns">
  <select name="type"><option value="">All types</option>
    <?php foreach ($types as $t): ?><option value="<?= h($t['type_key']) ?>" <?= $typeFilter === $t['type_key'] ? 'selected' : '' ?>><?= h($t['name']) ?></option><?php endforeach; ?>
  </select>
  <select name="status"><option value="">All statuses</option>
    <?php foreach ($statuses as $s): ?><option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= h($s) ?></option><?php endforeach; ?>
  </select>
  <button class="btn" type="submit">Filter</button>
</form>

<?php foreach ($camps as $c): ?>
  <div class="panel">
    <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap">
      <div>
        <strong><?= h($c['title']) ?></strong> <span class="pill <?= h($c['status']) ?>"><?= h($c['status']) ?></span>
        <div class="muted"><?= h($c['task_type_key']) ?> · CPC $<?= number_format((float) $c['cpc'], 4) ?> · Worker $<?= number_format((float) $c['worker_reward'], 4) ?> · Fee <?= rtrim(rtrim(number_format((float) $c['platform_fee_percent'], 2), '0'), '.') ?>%</div>
        <div class="muted">Budget $<?= number_format((float) $c['total_budget'], 2) ?> · Remaining $<?= number_format((float) $c['remaining_budget'], 4) ?> · ✅ <?= (int) $c['completed_count'] ?> · ⏳ <?= (int) $c['pending_count'] ?> · ❌ <?= (int) $c['rejected_count'] ?></div>
      </div>
      <div class="actions">
        <?php if ($c['status'] === 'active'): ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="campaign_id" value="<?= (int) $c['id'] ?>"><button class="btn sm gray" name="do" value="pause">Pause</button></form>
        <?php elseif (in_array($c['status'], ['paused', 'completed'], true)): ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="campaign_id" value="<?= (int) $c['id'] ?>"><button class="btn sm green" name="do" value="resume">Resume</button></form>
        <?php endif; ?>
        <form method="post" onsubmit="return confirm('Cancel campaign and refund unused budget?')"><?= csrf_field() ?><input type="hidden" name="campaign_id" value="<?= (int) $c['id'] ?>"><button class="btn sm red" name="do" value="delete">Delete</button></form>
      </div>
    </div>
    <details style="margin-top:12px">
      <summary class="muted" style="cursor:pointer">Edit / add budget</summary>
      <div class="row2" style="margin-top:12px">
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="campaign_id" value="<?= (int) $c['id'] ?>">
          <label>Description</label><textarea name="description" rows="2"><?= h($c['description'] ?? '') ?></textarea>
          <label>Worker Reward (USD)</label><input name="worker_reward" type="number" step="0.000001" value="<?= h(number_format((float) $c['worker_reward'], 6, '.', '')) ?>">
          <button class="btn sm" style="margin-top:10px" name="do" value="edit">Save</button>
        </form>
        <form method="post" style="align-self:end">
          <?= csrf_field() ?><input type="hidden" name="campaign_id" value="<?= (int) $c['id'] ?>">
          <label>Add Budget (from advertiser wallet)</label><input name="amount" type="number" step="0.01" min="0">
          <button class="btn sm green" style="margin-top:10px" name="do" value="add_budget">Increase Budget</button>
        </form>
      </div>
    </details>
  </div>
<?php endforeach; ?>
<?php if ($camps === []): ?><div class="panel muted">No campaigns match.</div><?php endif; ?>
