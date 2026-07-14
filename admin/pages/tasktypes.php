<?php
/**
 * Task & Profit settings.
 *
 * Edits the dynamic `campaign_task_types` table: enable/disable, minimum CPC,
 * minimum daily budget, platform fee %, verification type, pending hours,
 * auto-approval hours and timer. Changes apply to NEW campaigns immediately;
 * existing campaigns keep the values captured at creation.
 *
 * @var \App\Core\Database $db
 */
declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int) ($_POST['type_id'] ?? 0);
    if ($id > 0) {
        $db->update('campaign_task_types', [
            'enabled'              => isset($_POST['enabled']) ? 1 : 0,
            'min_cpc'              => max(0, (float) ($_POST['min_cpc'] ?? 0)),
            'min_daily_budget'     => max(0, (float) ($_POST['min_daily_budget'] ?? 0)),
            'platform_fee_percent' => min(100, max(0, (float) ($_POST['platform_fee_percent'] ?? 0))),
            'verification_type'    => (string) ($_POST['verification_type'] ?? 'timer'),
            'pending_hours'        => max(0, (int) ($_POST['pending_hours'] ?? 0)),
            'auto_approval_hours'  => max(0, (int) ($_POST['auto_approval_hours'] ?? 0)),
            'timer_seconds'        => max(0, (int) ($_POST['timer_seconds'] ?? 0)),
        ], ['id' => $id]);
        admin_log('tasktype_update', 'type=' . $id);
        flash('Task type updated.');
    }
    redirect(admin_url('tasktypes'));
}

$types = $db->fetchAll('SELECT * FROM campaign_task_types ORDER BY sort_order');
$verifications = ['timer', 'membership', 'forward', 'manual', 'post_view'];
?>
<h1>Task &amp; Profit Settings</h1>
<p class="muted">Each task type is configured independently. New campaigns use these values; existing ones keep their original settings.</p>

<?php foreach ($types as $t): ?>
  <div class="panel">
    <h2><?= h($t['icon']) ?> <?= h($t['name']) ?> <span class="muted">(<?= h($t['type_key']) ?>)</span></h2>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="type_id" value="<?= (int) $t['id'] ?>">
      <label style="display:flex;align-items:center;gap:8px;color:var(--text)">
        <input type="checkbox" name="enabled" style="width:auto" <?= $t['enabled'] ? 'checked' : '' ?>> Enabled
      </label>
      <div class="row2">
        <div><label>Minimum CPC (USD)</label><input name="min_cpc" type="number" step="0.000001" value="<?= h(number_format((float) $t['min_cpc'], 6, '.', '')) ?>"></div>
        <div><label>Minimum Daily Budget (USD)</label><input name="min_daily_budget" type="number" step="0.01" value="<?= h(number_format((float) $t['min_daily_budget'], 6, '.', '')) ?>"></div>
      </div>
      <div class="row2">
        <div><label>Platform Fee (%)</label><input name="platform_fee_percent" type="number" step="0.01" min="0" max="100" value="<?= h(number_format((float) $t['platform_fee_percent'], 2, '.', '')) ?>"></div>
        <div>
          <label>Verification Type</label>
          <select name="verification_type">
            <?php foreach ($verifications as $v): ?>
              <option value="<?= $v ?>" <?= $t['verification_type'] === $v ? 'selected' : '' ?>><?= $v ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="row2">
        <div><label>Pending Hours (before confirm)</label><input name="pending_hours" type="number" min="0" value="<?= (int) $t['pending_hours'] ?>"></div>
        <div><label>Auto-Approval Hours (manual tasks)</label><input name="auto_approval_hours" type="number" min="0" value="<?= (int) $t['auto_approval_hours'] ?>"></div>
      </div>
      <label>Timer Seconds (timer / post-view tasks)</label>
      <input name="timer_seconds" type="number" min="0" value="<?= (int) $t['timer_seconds'] ?>">
      <button class="btn sm" style="margin-top:14px" type="submit">Save <?= h($t['name']) ?></button>
    </form>
  </div>
<?php endforeach; ?>
