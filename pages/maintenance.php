<?php
require_once __DIR__ . '/../includes/auth.php';
$msg = setting_get('maintenance_message', '') ?: "Alternis est momentanément en maintenance. Merci de revenir dans quelques instants.";
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Maintenance · <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= e(url('assets/css/style.css?v=' . ASSET_VERSION)) ?>">
</head>
<body>
<div style="min-height:100vh;display:grid;place-items:center;padding:24px">
  <div style="max-width:460px;text-align:center">
    <div style="width:72px;height:72px;margin:0 auto 20px;border-radius:20px;display:grid;place-items:center;background:var(--grad);color:#fff">
      <svg viewBox="0 0 24 24" width="34" height="34" fill="none"><path d="M14.7 6.3a4 4 0 0 0-5.4 5.4l-6 6a2 2 0 1 0 3 3l6-6a4 4 0 0 0 5.4-5.4l-2.5 2.5-2-2z" stroke="#fff" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </div>
    <h1 style="font-size:24px;margin:0 0 10px">Maintenance en cours</h1>
    <p style="color:var(--muted);line-height:1.6"><?= nl2br(e($msg)) ?></p>
    <a href="<?= e(url('index.php')) ?>" class="btn btn-ghost" style="margin-top:20px;display:inline-flex">Réessayer</a>
  </div>
</div>
</body>
</html>
