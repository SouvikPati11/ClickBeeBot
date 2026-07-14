<?php

/**
 * Public landing / router.
 *
 * If the platform is not installed, visitors are sent to the install wizard.
 * Otherwise this shows a minimal status page and links to the panels.
 */

declare(strict_types=1);

$configFile = __DIR__ . '/config/config.php';

if (!is_file($configFile) || (require $configFile)['installed'] !== true) {
    header('Location: install/');
    exit;
}

http_response_code(200);
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ClickBee</title>
<style>
  body{margin:0;font-family:system-ui,sans-serif;background:#0f172a;color:#e2e8f0;min-height:100vh;display:flex;align-items:center;justify-content:center}
  .c{text-align:center}.c h1{font-size:40px;margin:0}.c h1 span{color:#f59e0b}
  a{display:inline-block;margin-top:16px;color:#f59e0b;text-decoration:none;border:1px solid #f59e0b;padding:10px 20px;border-radius:10px}
</style>
</head>
<body><div class="c"><h1>Click<span>Bee</span></h1><p>Telegram Earn Platform is running.</p><a href="admin/">Admin Panel</a></div></body>
</html>
