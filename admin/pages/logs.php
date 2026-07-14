<?php
/**
 * Logs: admin audit trail and cron execution history.
 *
 * @var \App\Core\Database $db
 */
declare(strict_types=1);

$tab = (string) ($_GET['tab'] ?? 'admin');
$adminLogs = $db->fetchAll('SELECT l.*, a.username FROM admin_logs l LEFT JOIN admins a ON a.id = l.admin_id ORDER BY l.created_at DESC LIMIT 60');
$cronLogs = $db->fetchAll('SELECT * FROM cron_logs ORDER BY created_at DESC LIMIT 60');
?>
<h1>Logs</h1>
<div class="search">
  <a class="btn <?= $tab === 'admin' ? '' : 'gray' ?>" href="<?= h(admin_url('logs', ['tab' => 'admin'])) ?>">Admin Audit</a>
  <a class="btn <?= $tab === 'cron' ? '' : 'gray' ?>" href="<?= h(admin_url('logs', ['tab' => 'cron'])) ?>">Cron</a>
</div>

<?php if ($tab === 'cron'): ?>
  <div class="panel"><div class="table-wrap"><table>
    <tr><th>Job</th><th>Status</th><th>Duration</th><th>Message</th><th>When</th></tr>
    <?php foreach ($cronLogs as $l): ?>
      <tr>
        <td><?= h($l['job']) ?></td>
        <td><span class="pill <?= $l['status'] === 'success' ? 'completed' : 'rejected' ?>"><?= h($l['status']) ?></span></td>
        <td><?= (int) $l['duration_ms'] ?> ms</td>
        <td class="muted"><?= h($l['message']) ?></td>
        <td class="muted"><?= h($l['created_at']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($cronLogs === []): ?><tr><td colspan="5" class="muted">No cron runs yet.</td></tr><?php endif; ?>
  </table></div></div>
<?php else: ?>
  <div class="panel"><div class="table-wrap"><table>
    <tr><th>Admin</th><th>Action</th><th>Details</th><th>IP</th><th>When</th></tr>
    <?php foreach ($adminLogs as $l): ?>
      <tr>
        <td><?= h($l['username'] ?: '—') ?></td>
        <td><?= h($l['action']) ?></td>
        <td class="muted"><?= h($l['details'] ?: '') ?></td>
        <td class="muted"><?= h($l['ip'] ?: '') ?></td>
        <td class="muted"><?= h($l['created_at']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($adminLogs === []): ?><tr><td colspan="5" class="muted">No admin actions yet.</td></tr><?php endif; ?>
  </table></div></div>
<?php endif; ?>
