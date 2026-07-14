<?php
/**
 * Deposit management: view by status and manually credit a stuck deposit.
 *
 * Manual credit routes through DepositService so balances, referral commission
 * and transactions are handled exactly like a verified webhook — and never
 * double-credited.
 *
 * @var \App\Core\Database $db
 * @var \App\Services\Container $container
 */
declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int) ($_POST['deposit_id'] ?? 0);
    $deposit = $db->fetch('SELECT * FROM deposits WHERE id = ? LIMIT 1', [$id]);
    if ($deposit !== null && $deposit['status'] !== 'paid') {
        // Ensure an invoice id exists so confirmByTrackId can match.
        $trackId = (string) ($deposit['invoice_id'] ?? '');
        if ($trackId === '') {
            $trackId = 'manual-' . $id;
            $db->update('deposits', ['invoice_id' => $trackId], ['id' => $id]);
        }
        $ok = $container->depositService()->confirmByTrackId($trackId, 'manual-credit');
        admin_log('deposit_manual_credit', "deposit=$id ok=" . ($ok ? '1' : '0'));
        flash($ok ? 'Deposit credited.' : 'Could not credit (already paid?).', $ok ? 'ok' : 'err');
    }
    redirect(admin_url('deposits', array_filter(['status' => $_GET['status'] ?? ''])));
}

$statusFilter = (string) ($_GET['status'] ?? '');
$where = '';
$params = [];
if (in_array($statusFilter, ['pending', 'paid', 'expired', 'failed'], true)) {
    $where = 'WHERE d.status = ?';
    $params[] = $statusFilter;
}
$rows = $db->fetchAll(
    "SELECT d.*, u.username, u.telegram_id FROM deposits d JOIN users u ON u.id = d.user_id $where ORDER BY d.created_at DESC LIMIT 50",
    $params
);
?>
<h1>Deposits</h1>
<form class="search" method="get" action="index.php">
  <input type="hidden" name="page" value="deposits">
  <select name="status">
    <?php foreach (['all', 'pending', 'paid', 'expired', 'failed'] as $s): ?>
      <option value="<?= $s === 'all' ? '' : $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
    <?php endforeach; ?>
  </select>
  <button class="btn" type="submit">Filter</button>
</form>
<div class="panel">
  <div class="table-wrap"><table>
    <tr><th>ID</th><th>User</th><th>Amount</th><th>Type</th><th>Invoice</th><th>Status</th><th></th></tr>
    <?php foreach ($rows as $d): ?>
      <tr>
        <td><?= (int) $d['id'] ?></td>
        <td><?= h($d['username'] ? '@' . $d['username'] : $d['telegram_id']) ?></td>
        <td>$<?= number_format((float) $d['amount'], 2) ?></td>
        <td><?= h($d['account_type']) ?></td>
        <td class="muted"><?= h($d['invoice_id'] ?: '—') ?></td>
        <td><span class="pill <?= h($d['status']) ?>"><?= h($d['status']) ?></span></td>
        <td>
          <?php if ($d['status'] !== 'paid'): ?>
            <form method="post" onsubmit="return confirm('Manually credit this deposit?')"><?= csrf_field() ?><input type="hidden" name="deposit_id" value="<?= (int) $d['id'] ?>"><button class="btn sm green">Credit</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if ($rows === []): ?><tr><td colspan="7" class="muted">No deposits.</td></tr><?php endif; ?>
  </table></div>
</div>
