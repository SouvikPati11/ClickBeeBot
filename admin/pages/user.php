<?php
/**
 * User profile: balances, histories and admin actions (add/deduct balance,
 * ban/unban, reset counters, soft-delete, private message).
 *
 * @var \App\Core\Database $db
 * @var \App\Services\Container $container
 */
declare(strict_types=1);

$userId = (int) ($_GET['id'] ?? 0);
$user = $db->fetch('SELECT * FROM users WHERE id = ? LIMIT 1', [$userId]);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user !== null) {
    csrf_check();
    $do = (string) ($_POST['do'] ?? '');
    $ledger = $container->ledger();

    switch ($do) {
        case 'credit':
            $amount = (float) ($_POST['amount'] ?? 0);
            if ($amount > 0) {
                $ledger->creditAvailable($userId, $amount, 'adjustment', 'Admin credit');
                admin_log('user_credit', "user=$userId amount=$amount");
                flash('Balance credited.');
            }
            break;
        case 'debit':
            $amount = (float) ($_POST['amount'] ?? 0);
            if ($amount > 0) {
                $ok = $ledger->debitAvailable($userId, $amount, 'adjustment', 'Admin deduction');
                admin_log('user_debit', "user=$userId amount=$amount ok=" . ($ok ? '1' : '0'));
                flash($ok ? 'Balance deducted.' : 'Insufficient balance.', $ok ? 'ok' : 'err');
            }
            break;
        case 'ban':
            $db->update('users', ['status' => 'banned'], ['id' => $userId]);
            admin_log('user_ban', "user=$userId");
            flash('User banned.');
            break;
        case 'unban':
            $db->update('users', ['status' => 'active'], ['id' => $userId]);
            admin_log('user_unban', "user=$userId");
            flash('User unbanned.');
            break;
        case 'reset':
            $db->update('users', [
                'completed_tasks' => 0, 'pending_tasks' => 0, 'rejected_tasks' => 0, 'auto_approved_tasks' => 0,
            ], ['id' => $userId]);
            admin_log('user_reset', "user=$userId");
            flash('Task counters reset.');
            break;
        case 'delete':
            $db->update('users', ['status' => 'deleted'], ['id' => $userId]);
            admin_log('user_delete', "user=$userId");
            flash('User soft-deleted.');
            break;
        case 'message':
            $msg = trim((string) ($_POST['message'] ?? ''));
            if ($msg !== '') {
                $container->telegram()->sendMessage((int) $user['telegram_id'], '📩 <b>Message from Admin</b>' . "\n" . h($msg));
                $container->notifications()->notify($userId, 'Admin Message', $msg, 'admin', false);
                admin_log('user_message', "user=$userId");
                flash('Message sent.');
            }
            break;
    }
    redirect(admin_url('user', ['id' => $userId]));
}

if ($user === null) {
    echo '<h1>User not found</h1><p class="muted">This user does not exist.</p>';
    return;
}

$deposits = $db->fetchAll('SELECT * FROM deposits WHERE user_id = ? ORDER BY created_at DESC LIMIT 5', [$userId]);
$withdrawals = $db->fetchAll('SELECT * FROM withdrawals WHERE user_id = ? ORDER BY created_at DESC LIMIT 5', [$userId]);
$transactions = $db->fetchAll('SELECT * FROM transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 8', [$userId]);
$refCount = (int) $db->column('SELECT COUNT(*) FROM users WHERE referred_by = ?', [$userId]);
?>
<h1><?= h($user['username'] ? '@' . $user['username'] : ($user['first_name'] ?: 'User')) ?> <span class="pill <?= h($user['status']) ?>"><?= h($user['status']) ?></span></h1>
<p class="muted">Telegram ID: <?= (int) $user['telegram_id'] ?> · Referral code: <?= h($user['referral_code']) ?> · Referrals: <?= $refCount ?></p>

<div class="grid">
  <div class="card"><div class="k">Available</div><div class="v">$<?= number_format((float) $user['available_balance'], 4) ?></div></div>
  <div class="card"><div class="k">Pending</div><div class="v">$<?= number_format((float) $user['pending_balance'], 4) ?></div></div>
  <div class="card"><div class="k">Referral</div><div class="v">$<?= number_format((float) $user['referral_balance'], 4) ?></div></div>
  <div class="card"><div class="k">Total Earned</div><div class="v">$<?= number_format((float) $user['total_earned'], 4) ?></div></div>
  <div class="card"><div class="k">Deposited</div><div class="v">$<?= number_format((float) $user['total_deposit'], 2) ?></div></div>
  <div class="card"><div class="k">Withdrawn</div><div class="v">$<?= number_format((float) $user['total_withdraw'], 2) ?></div></div>
</div>

<div class="row2" style="margin-top:20px">
  <div class="panel">
    <h2>Adjust Balance</h2>
    <form method="post" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
      <?= csrf_field() ?>
      <div style="flex:1;min-width:120px"><label>Amount (USD)</label><input name="amount" type="number" step="0.000001" min="0" required></div>
      <button class="btn green sm" name="do" value="credit" type="submit">➕ Add</button>
      <button class="btn red sm" name="do" value="debit" type="submit">➖ Deduct</button>
    </form>
  </div>
  <div class="panel">
    <h2>Account Actions</h2>
    <div class="actions">
      <?php if ($user['status'] === 'banned'): ?>
        <form method="post"><?= csrf_field() ?><button class="btn green sm" name="do" value="unban">Unban</button></form>
      <?php else: ?>
        <form method="post"><?= csrf_field() ?><button class="btn red sm" name="do" value="ban">Ban</button></form>
      <?php endif; ?>
      <form method="post" onsubmit="return confirm('Reset task counters?')"><?= csrf_field() ?><button class="btn gray sm" name="do" value="reset">Reset Tasks</button></form>
      <form method="post" onsubmit="return confirm('Soft-delete this user?')"><?= csrf_field() ?><button class="btn red sm" name="do" value="delete">Delete</button></form>
    </div>
  </div>
</div>

<div class="panel">
  <h2>Send Private Message</h2>
  <form method="post">
    <?= csrf_field() ?>
    <textarea name="message" rows="3" placeholder="Message to the user…" required></textarea>
    <button class="btn sm" style="margin-top:10px" name="do" value="message" type="submit">Send</button>
  </form>
</div>

<div class="row2">
  <div class="panel">
    <h2>Recent Transactions</h2>
    <div class="table-wrap"><table>
      <tr><th>Type</th><th>Amount</th><th>Date</th></tr>
      <?php foreach ($transactions as $t): ?>
        <tr><td><?= h($t['type']) ?></td><td>$<?= number_format((float) $t['amount'], 4) ?></td><td class="muted"><?= h($t['created_at']) ?></td></tr>
      <?php endforeach; ?>
      <?php if ($transactions === []): ?><tr><td colspan="3" class="muted">None.</td></tr><?php endif; ?>
    </table></div>
  </div>
  <div class="panel">
    <h2>Deposits & Withdrawals</h2>
    <div class="table-wrap"><table>
      <tr><th>Kind</th><th>Amount</th><th>Status</th></tr>
      <?php foreach ($deposits as $d): ?>
        <tr><td>Deposit</td><td>$<?= number_format((float) $d['amount'], 2) ?></td><td><span class="pill <?= h($d['status']) ?>"><?= h($d['status']) ?></span></td></tr>
      <?php endforeach; ?>
      <?php foreach ($withdrawals as $w): ?>
        <tr><td>Withdraw</td><td>$<?= number_format((float) $w['amount'], 2) ?></td><td><span class="pill <?= h($w['status']) ?>"><?= h($w['status']) ?></span></td></tr>
      <?php endforeach; ?>
      <?php if ($deposits === [] && $withdrawals === []): ?><tr><td colspan="3" class="muted">None.</td></tr><?php endif; ?>
    </table></div>
  </div>
</div>
