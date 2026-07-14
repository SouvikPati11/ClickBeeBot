<?php
/**
 * Broadcast a message to users via Telegram.
 *
 * Targets all users, workers, or advertisers (banned users always excluded).
 * Sends in a bounded batch to stay within shared-hosting execution limits;
 * for very large bases run it a few times or wire it to the notification cron.
 *
 * @var \App\Core\Database $db
 * @var \App\Services\Container $container
 */
declare(strict_types=1);

$sent = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $message = trim((string) ($_POST['message'] ?? ''));
    $target = (string) ($_POST['target'] ?? 'all');
    $limit = min(500, max(1, (int) ($_POST['limit'] ?? 200)));

    if ($message === '') {
        flash('Message cannot be empty.', 'err');
        redirect(admin_url('broadcast'));
    }

    $where = 'status = "active"';
    if ($target === 'advertisers') {
        $where .= ' AND is_advertiser = 1';
    } elseif ($target === 'workers') {
        $where .= ' AND is_advertiser = 0';
    }
    $users = $db->fetchAll("SELECT telegram_id FROM users WHERE $where ORDER BY last_active DESC LIMIT $limit");

    $tg = $container->telegram();
    $ok = 0;
    foreach ($users as $u) {
        $res = $tg->sendMessage((int) $u['telegram_id'], $message);
        if ($res !== null) {
            $ok++;
        }
        usleep(40000); // ~25 msgs/sec, within Telegram limits
    }
    admin_log('broadcast', "target=$target sent=$ok");
    $sent = $ok;
    flash("Broadcast sent to $ok user(s).");
    redirect(admin_url('broadcast'));
}
?>
<h1>Broadcast</h1>
<div class="panel">
  <form method="post">
    <?= csrf_field() ?>
    <label>Target</label>
    <select name="target">
      <option value="all">All users</option>
      <option value="workers">Workers only</option>
      <option value="advertisers">Advertisers only</option>
    </select>
    <label>Batch limit (per run)</label>
    <input name="limit" type="number" min="1" max="500" value="200">
    <label>Message (HTML allowed)</label>
    <textarea name="message" rows="5" placeholder="Your announcement…" required></textarea>
    <button class="btn" type="submit" style="margin-top:12px" onclick="return confirm('Send broadcast now?')">Send Broadcast</button>
  </form>
  <p class="muted" style="margin-top:12px">Banned users are always excluded. Large audiences: run the broadcast a few times — each run sends the next batch by recent activity.</p>
</div>
