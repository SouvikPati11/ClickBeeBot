<?php
/**
 * User management: search and list workers with quick status.
 * Individual actions live on the "user" profile page.
 *
 * @var \App\Core\Database $db
 */
declare(strict_types=1);

$q = trim((string) ($_GET['q'] ?? ''));
$statusFilter = (string) ($_GET['status'] ?? '');

$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(telegram_id = ? OR username LIKE ? OR first_name LIKE ?)';
    $params[] = ctype_digit($q) ? $q : 0;
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}
if (in_array($statusFilter, ['active', 'banned', 'deleted'], true)) {
    $where[] = 'status = ?';
    $params[] = $statusFilter;
}
$sql = 'SELECT * FROM users';
if ($where !== []) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY id DESC LIMIT 50';
$users = $db->fetchAll($sql, $params);
?>
<h1>Users</h1>
<form class="search" method="get" action="index.php">
  <input type="hidden" name="page" value="users">
  <input name="q" value="<?= h($q) ?>" placeholder="Telegram ID, username or name">
  <select name="status">
    <option value="">All statuses</option>
    <?php foreach (['active', 'banned', 'deleted'] as $s): ?>
      <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
    <?php endforeach; ?>
  </select>
  <button class="btn" type="submit">Search</button>
</form>

<div class="panel">
  <div class="table-wrap"><table>
    <tr><th>ID</th><th>User</th><th>Telegram ID</th><th>Available</th><th>Pending</th><th>Tasks</th><th>Status</th><th></th></tr>
    <?php foreach ($users as $u): ?>
      <tr>
        <td><?= (int) $u['id'] ?></td>
        <td><?= h($u['username'] ? '@' . $u['username'] : ($u['first_name'] ?: '—')) ?></td>
        <td><?= (int) $u['telegram_id'] ?></td>
        <td>$<?= number_format((float) $u['available_balance'], 4) ?></td>
        <td>$<?= number_format((float) $u['pending_balance'], 4) ?></td>
        <td><?= (int) $u['completed_tasks'] ?></td>
        <td><span class="pill <?= h($u['status']) ?>"><?= h($u['status']) ?></span></td>
        <td><a class="btn sm gray" href="<?= h(admin_url('user', ['id' => (int) $u['id']])) ?>">Manage</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($users === []): ?><tr><td colspan="8" class="muted">No users found.</td></tr><?php endif; ?>
  </table></div>
</div>
