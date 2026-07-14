<?php
/**
 * Shared installer layout. Expects $title, $step, $totalSteps, $content,
 * and optional $error / $notice strings set by the caller.
 *
 * @var string $title
 * @var int $step
 * @var int $totalSteps
 * @var string $content
 * @var string|null $error
 * @var string|null $notice
 */
$pct = (int) round(($step / $totalSteps) * 100);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ClickBee Installer — <?= htmlspecialchars($title, ENT_QUOTES) ?></title>
<style>
  :root { --bg:#0f172a; --card:#1e293b; --accent:#f59e0b; --text:#e2e8f0; --muted:#94a3b8; --ok:#22c55e; --err:#ef4444; }
  * { box-sizing:border-box; }
  body { margin:0; font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif; background:linear-gradient(135deg,#0f172a,#1e293b); color:var(--text); min-height:100vh; display:flex; align-items:center; justify-content:center; padding:20px; }
  .wrap { width:100%; max-width:560px; }
  .card { background:var(--card); border-radius:16px; padding:32px; box-shadow:0 20px 60px rgba(0,0,0,.4); }
  .logo { font-size:26px; font-weight:800; text-align:center; margin-bottom:4px; }
  .logo span { color:var(--accent); }
  .sub { text-align:center; color:var(--muted); margin-bottom:24px; font-size:14px; }
  .bar { height:8px; background:#334155; border-radius:8px; overflow:hidden; margin-bottom:24px; }
  .bar > i { display:block; height:100%; width:<?= $pct ?>%; background:var(--accent); transition:width .3s; }
  h2 { margin:0 0 16px; font-size:20px; }
  label { display:block; font-size:13px; color:var(--muted); margin:14px 0 6px; }
  input, select, textarea { width:100%; padding:12px 14px; border-radius:10px; border:1px solid #334155; background:#0f172a; color:var(--text); font-size:15px; }
  input:focus, select:focus { outline:none; border-color:var(--accent); }
  .btn { display:block; width:100%; margin-top:24px; padding:14px; border:0; border-radius:10px; background:var(--accent); color:#111; font-weight:700; font-size:16px; cursor:pointer; text-align:center; text-decoration:none; }
  .btn:hover { filter:brightness(1.05); }
  .check { display:flex; justify-content:space-between; padding:10px 12px; border-radius:8px; background:#0f172a; margin-bottom:8px; font-size:14px; }
  .badge-ok { color:var(--ok); font-weight:700; }
  .badge-err { color:var(--err); font-weight:700; }
  .alert { padding:12px 14px; border-radius:10px; font-size:14px; margin-bottom:16px; }
  .alert-err { background:rgba(239,68,68,.15); color:#fecaca; }
  .alert-ok { background:rgba(34,197,94,.15); color:#bbf7d0; }
  code { background:#0f172a; padding:2px 6px; border-radius:6px; font-size:13px; word-break:break-all; }
  .muted { color:var(--muted); font-size:13px; }
</style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <div class="logo">Click<span>Bee</span></div>
      <div class="sub">Step <?= $step ?> of <?= $totalSteps ?> — <?= htmlspecialchars($title, ENT_QUOTES) ?></div>
      <div class="bar"><i></i></div>
      <?php if (!empty($error)): ?><div class="alert alert-err"><?= $error ?></div><?php endif; ?>
      <?php if (!empty($notice)): ?><div class="alert alert-ok"><?= $notice ?></div><?php endif; ?>
      <?= $content ?>
    </div>
  </div>
</body>
</html>
