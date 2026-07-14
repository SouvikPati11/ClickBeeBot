<?php
/**
 * Admin login screen.
 *
 * @var \App\Core\Security $security
 * @var string|null $loginError
 */
declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ClickBee Admin — Sign In</title>
<style>
  *{box-sizing:border-box}body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:linear-gradient(135deg,#0f172a,#1e293b);color:#e2e8f0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
  .login{width:100%;max-width:380px;background:#1e293b;padding:32px;border-radius:16px;border:1px solid #334155;box-shadow:0 20px 60px rgba(0,0,0,.4)}
  .login h1{text-align:center;margin:0 0 20px;font-size:24px}.login h1 span{color:#f59e0b}
  .login input{width:100%;padding:12px 14px;margin-top:10px;border-radius:10px;border:1px solid #334155;background:#0f172a;color:#e2e8f0;font-size:15px}
  .login input:focus{outline:none;border-color:#f59e0b}
  .btn{width:100%;margin-top:18px;padding:13px;border:0;border-radius:10px;background:#f59e0b;color:#111;font-weight:700;font-size:15px;cursor:pointer}
  .err{background:rgba(239,68,68,.15);color:#fecaca;padding:10px 12px;border-radius:8px;margin-top:12px;font-size:14px}
</style>
</head>
<body>
  <div class="login">
    <h1>Click<span>Bee</span> Admin</h1>
    <?php if (!empty($loginError)): ?><div class="err"><?= h($loginError) ?></div><?php endif; ?>
    <?php foreach (take_flash() as $f): ?><div class="err"><?= h($f['message']) ?></div><?php endforeach; ?>
    <form method="post" action="index.php">
      <input type="hidden" name="do" value="login">
      <?= csrf_field() ?>
      <input name="username" placeholder="Username" required autofocus autocomplete="username">
      <input type="password" name="password" placeholder="Password" required autocomplete="current-password">
      <button class="btn" type="submit">Sign In</button>
    </form>
  </div>
</body>
</html>
