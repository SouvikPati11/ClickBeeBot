<?php
/**
 * Advertiser management: list, wallet view, pause/ban actions.
 *
 * @var \App\Core\Database $db
 */
declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $advId = (int) ($_POST['advertiser_id'] ?? 0);
    $do = (string) ($_POST['do'] ?? '');
    $map = ['activate' => 'active', 'pause' => 'paused', 'ban' => 'banned'];
    if (isset($map[$do]) && $advId > 0) {
        $db->update('advertisers', ['status' => $map[$do]], ['id' => $advId]);
        admin_log('advertiser_' . $do, "advertiser=$advId");
        flash('Advertiser updated.');
    }
    redirect(admin_url('advertisers'));
}

$q = trim((string) ($_GET['q'] ?? ''));
$sql = 'SELECT a.*, u.username, u.telegram_id, u.first_name, w.balance, w.spent
        FROM advertisers a
        JOIN users u ON u.id = a.user_id
        LEFT JOIN advertiser_wallet w ON w.advertiser_id = a.id';
$params = [];
if ($q !== '') {
    $sql .= ' WHERE u.username LIKE ? OR u.telegram_id = ? OR a.display_name LIKE ?';
    $params = ['%' . $q . '%', ctype_digit($q) ? $q : 0, '%' . $q . '%'];
}
$sql .= ' ORDER BY a.id DESC LIMIT 50';
$rows = $db->fetchAll($sql, $params);
?>
<h1>Advertisers</h1>
<form class="search" method="get" action="index.php">
  <input type="hidden" name="page" value="advertisers">
  <input name="q" value="<?= h($q) ?>" placeholder="Username, Telegram ID or name">
  <button class="btn" type="submit">Search</button>
</form>
<div class="panel">
  <div class="table-wrap"><table>
    <tr><th>ID</th><th>Advertiser</th><th>Balance</th><th>Spent</th><th>Status</th><th>Actions</th></tr>
    <?php foreach ($rows as $a): ?>
      <tr>
        <td><?= (int) $a['id'] ?></td>
        <td><?= h($a['display_name'] ?: ($a['username'] ? '@' . $a['username'] : $a['first_name'])) ?><div class="muted"><?= (int) $a['telegram_id'] ?></div></td>
        <td>$<?= number_format((float) ($a['balance'] ?? 0), 2) ?></td>
        <td>$<?= number_format((float) ($a['spent'] ?? 0), 2) ?></td>
        <td><span class="pill <?= h($a['status']) ?>"><?= h($a['status']) ?></span></td>
        <td class="actions">
          <?php if ($a['status'] !== 'active'): ?>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="advertiser_id" value="<?= (int) $a['id'] ?>"><button class="btn sm green" name="do" value="activate">Activate</button></form>
          <?php else: ?>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="advertiser_id" value="<?= (int) $a['id'] ?>"><button class="btn sm gray" name="do" value="pause">Pause</button></form>
          <?php endif; ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="advertiser_id" value="<?= (int) $a['id'] ?>"><button class="btn sm red" name="do" value="ban">Ban</button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if ($rows === []): ?><tr><td colspan="6" class="muted">No advertisers.</td></tr><?php endif; ?>
  </table></div>
</div>
