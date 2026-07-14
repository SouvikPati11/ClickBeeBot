<?php
/**
 * Withdrawal management: approve / reject / complete manual payouts.
 *
 * Rejecting refunds the amount to the worker's available balance (the request
 * debited it at submission time). Completing records the transaction id.
 *
 * @var \App\Core\Database $db
 * @var \App\Services\Container $container
 */
declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int) ($_POST['withdrawal_id'] ?? 0);
    $do = (string) ($_POST['do'] ?? '');
    $note = trim((string) ($_POST['admin_note'] ?? ''));
    $txId = trim((string) ($_POST['transaction_id'] ?? ''));
    $wd = $db->fetch('SELECT * FROM withdrawals WHERE id = ? LIMIT 1', [$id]);

    if ($wd !== null && in_array($wd['status'], ['pending', 'approved'], true)) {
        if ($do === 'approve') {
            $db->update('withdrawals', ['status' => 'approved', 'admin_note' => $note ?: null], ['id' => $id]);
            $container->notifications()->notify((int) $wd['user_id'], 'Withdrawal Approved', 'Your withdrawal was approved and is being processed.', 'withdraw');
            flash('Withdrawal approved.');
        } elseif ($do === 'complete') {
            $db->update('withdrawals', ['status' => 'completed', 'transaction_id' => $txId ?: null, 'admin_note' => $note ?: null, 'completed_at' => date('Y-m-d H:i:s')], ['id' => $id]);
            $container->notifications()->notify((int) $wd['user_id'], 'Withdrawal Completed', 'Your withdrawal has been paid. TX: ' . ($txId ?: 'n/a'), 'withdraw');
            flash('Withdrawal marked completed.');
        } elseif ($do === 'reject') {
            // Refund the debited amount to the worker.
            $db->transaction(function ($t) use ($wd, $id, $note) {
                $t->run('UPDATE users SET available_balance = available_balance + ?, total_withdraw = GREATEST(total_withdraw - ?,0) WHERE id = ?', [(float) $wd['amount'], (float) $wd['amount'], (int) $wd['user_id']]);
                $t->insert('transactions', ['user_id' => (int) $wd['user_id'], 'type' => 'balance_reversal', 'amount' => (float) $wd['amount'], 'description' => 'Withdrawal rejected — refund']);
                $t->update('withdrawals', ['status' => 'rejected', 'admin_note' => $note ?: null], ['id' => $id]);
            });
            $container->notifications()->notify((int) $wd['user_id'], 'Withdrawal Rejected', 'Your withdrawal was rejected and the amount refunded.' . ($note ? ' Reason: ' . $note : ''), 'withdraw');
            flash('Withdrawal rejected and refunded.');
        }
        admin_log('withdraw_' . $do, "withdrawal=$id");
    }
    redirect(admin_url('withdrawals', array_filter(['status' => $_GET['status'] ?? ''])));
}

$statusFilter = (string) ($_GET['status'] ?? 'pending');
$where = '';
$params = [];
if (in_array($statusFilter, ['pending', 'approved', 'rejected', 'completed'], true)) {
    $where = 'WHERE w.status = ?';
    $params[] = $statusFilter;
}
$rows = $db->fetchAll(
    "SELECT w.*, u.username, u.telegram_id FROM withdrawals w JOIN users u ON u.id = w.user_id $where ORDER BY w.created_at DESC LIMIT 50",
    $params
);
?>
<h1>Withdrawals</h1>
<form class="search" method="get" action="index.php">
  <input type="hidden" name="page" value="withdrawals">
  <select name="status">
    <?php foreach (['pending', 'approved', 'completed', 'rejected', 'all'] as $s): ?>
      <option value="<?= $s === 'all' ? '' : $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
    <?php endforeach; ?>
  </select>
  <button class="btn" type="submit">Filter</button>
</form>

<?php foreach ($rows as $w): ?>
  <div class="panel">
    <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap">
      <div>
        <strong>$<?= number_format((float) $w['amount'], 4) ?></strong>
        <span class="pill <?= h($w['status']) ?>"><?= h($w['status']) ?></span>
        <div class="muted">Net $<?= number_format((float) $w['net_amount'], 4) ?> · Fee $<?= number_format((float) $w['fee'], 4) ?></div>
        <div class="muted"><?= h($w['method']) ?> → <?= h($w['wallet_address'] ?: $w['binance_uid']) ?></div>
        <div class="muted">User: <?= h($w['username'] ? '@' . $w['username'] : $w['telegram_id']) ?> · <?= h($w['created_at']) ?></div>
        <?php if ($w['admin_note']): ?><div class="muted">Note: <?= h($w['admin_note']) ?></div><?php endif; ?>
      </div>
    </div>
    <?php if (in_array($w['status'], ['pending', 'approved'], true)): ?>
      <form method="post" style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
        <?= csrf_field() ?><input type="hidden" name="withdrawal_id" value="<?= (int) $w['id'] ?>">
        <div style="flex:1;min-width:150px"><label>Transaction ID</label><input name="transaction_id" placeholder="TX hash / ref"></div>
        <div style="flex:1;min-width:150px"><label>Admin note</label><input name="admin_note" placeholder="Optional"></div>
        <?php if ($w['status'] === 'pending'): ?><button class="btn sm gray" name="do" value="approve">Approve</button><?php endif; ?>
        <button class="btn sm green" name="do" value="complete">Mark Paid</button>
        <button class="btn sm red" name="do" value="reject" onclick="return confirm('Reject and refund?')">Reject</button>
      </form>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
<?php if ($rows === []): ?><div class="panel muted">No withdrawals.</div><?php endif; ?>
