<?php
/**
 * Admin chrome: responsive sidebar + topbar wrapping page content.
 *
 * @var string $content
 * @var string $activePage
 * @var string $pageTitle
 */
declare(strict_types=1);

$nav = [
    'dashboard'   => ['📊', 'Dashboard'],
    'users'       => ['👥', 'Users'],
    'advertisers' => ['📣', 'Advertisers'],
    'campaigns'   => ['🎯', 'Campaigns'],
    'deposits'    => ['💰', 'Deposits'],
    'withdrawals' => ['💸', 'Withdrawals'],
    'tasktypes'   => ['🧩', 'Task & Profit'],
    'payment'     => ['🏦', 'Payment'],
    'settings'    => ['⚙️', 'Settings'],
    'broadcast'   => ['📢', 'Broadcast'],
    'logs'        => ['📜', 'Logs'],
];
// Sub-pages highlight their parent.
$activeNav = $activePage === 'user' ? 'users' : $activePage;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ClickBee Admin — <?= h($pageTitle) ?></title>
<style>
  :root{--bg:#0f172a;--panel:#1e293b;--line:#334155;--text:#e2e8f0;--muted:#94a3b8;--accent:#f59e0b;--ok:#22c55e;--err:#ef4444}
  *{box-sizing:border-box}body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:var(--bg);color:var(--text)}
  a{color:inherit;text-decoration:none}
  .shell{display:flex;min-height:100vh}
  .side{width:230px;background:var(--panel);border-right:1px solid var(--line);position:fixed;top:0;bottom:0;left:0;overflow-y:auto;transition:transform .2s;z-index:30}
  .brand{padding:18px 20px;font-weight:800;font-size:20px;border-bottom:1px solid var(--line)}.brand span{color:var(--accent)}
  .nav a{display:flex;gap:10px;align-items:center;padding:12px 20px;color:var(--muted);font-size:15px}
  .nav a.active,.nav a:hover{background:#0f172a;color:var(--text);border-left:3px solid var(--accent)}
  .main{flex:1;margin-left:230px;min-width:0}
  .top{background:var(--panel);border-bottom:1px solid var(--line);padding:14px 20px;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;z-index:20}
  .top .hamb{display:none;background:none;border:0;color:var(--text);font-size:22px;cursor:pointer}
  .content{padding:20px;max-width:1200px}
  h1{font-size:22px;margin:0 0 18px}
  .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:14px}
  .card{background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:18px}
  .card .k{color:var(--muted);font-size:13px}.card .v{font-size:24px;font-weight:800;margin-top:6px}
  .panel{background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:20px;margin-bottom:20px}
  .panel h2{margin:0 0 14px;font-size:17px}
  table{width:100%;border-collapse:collapse;font-size:14px}
  th,td{text-align:left;padding:10px 8px;border-bottom:1px solid var(--line);vertical-align:middle}
  th{color:var(--muted);font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.03em}
  .table-wrap{overflow-x:auto}
  label{display:block;font-size:13px;color:var(--muted);margin:12px 0 6px}
  input,select,textarea{width:100%;padding:10px 12px;border-radius:9px;border:1px solid var(--line);background:#0f172a;color:var(--text);font-size:14px}
  input:focus,select:focus,textarea:focus{outline:none;border-color:var(--accent)}
  .row2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
  .btn{display:inline-block;padding:10px 16px;border:0;border-radius:9px;background:var(--accent);color:#111;font-weight:700;font-size:14px;cursor:pointer}
  .btn.sm{padding:6px 11px;font-size:13px}
  .btn.gray{background:#334155;color:var(--text)}.btn.red{background:var(--err);color:#fff}.btn.green{background:var(--ok);color:#052e16}
  .pill{display:inline-block;padding:3px 9px;border-radius:20px;font-size:12px;font-weight:600}
  .pill.active,.pill.paid,.pill.completed,.pill.approved{background:rgba(34,197,94,.15);color:#86efac}
  .pill.pending,.pill.pending_payment,.pill.pending_review,.pill.draft{background:rgba(245,158,11,.15);color:#fcd34d}
  .pill.banned,.pill.rejected,.pill.failed,.pill.expired,.pill.cancelled,.pill.deleted{background:rgba(239,68,68,.15);color:#fca5a5}
  .pill.paused{background:rgba(148,163,184,.18);color:#cbd5e1}
  .flash{padding:11px 14px;border-radius:10px;margin-bottom:14px;font-size:14px}
  .flash.ok{background:rgba(34,197,94,.15);color:#bbf7d0}.flash.err{background:rgba(239,68,68,.15);color:#fecaca}
  .muted{color:var(--muted);font-size:13px}
  .search{display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap}.search input,.search select{max-width:240px}
  .actions{display:flex;gap:6px;flex-wrap:wrap}
  .overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:25}
  @media(max-width:820px){.side{transform:translateX(-100%)}.side.open{transform:none}.main{margin-left:0}.top .hamb{display:block}.row2{grid-template-columns:1fr}.overlay.show{display:block}}
</style>
</head>
<body>
<div class="shell">
  <aside class="side" id="side">
    <div class="brand">Click<span>Bee</span></div>
    <nav class="nav">
      <?php foreach ($nav as $key => [$icon, $label]): ?>
        <a class="<?= $activeNav === $key ? 'active' : '' ?>" href="<?= h(admin_url($key)) ?>"><span><?= $icon ?></span><?= h($label) ?></a>
      <?php endforeach; ?>
      <a href="index.php?action=logout"><span>🚪</span>Logout</a>
    </nav>
  </aside>
  <div class="overlay" id="overlay" onclick="toggleNav()"></div>
  <div class="main">
    <div class="top">
      <button class="hamb" onclick="toggleNav()">☰</button>
      <div style="font-weight:700"><?= h($pageTitle) ?></div>
      <div class="muted">Hi, <?= h($_SESSION['admin_name'] ?? '') ?></div>
    </div>
    <div class="content">
      <?php foreach (take_flash() as $f): ?>
        <div class="flash <?= $f['type'] === 'err' ? 'err' : 'ok' ?>"><?= h($f['message']) ?></div>
      <?php endforeach; ?>
      <?= $content ?>
    </div>
  </div>
</div>
<script>
function toggleNav(){document.getElementById('side').classList.toggle('open');document.getElementById('overlay').classList.toggle('show');}
</script>
</body>
</html>
