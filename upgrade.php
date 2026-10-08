<?php
/**
 * Alternis — Mise à jour de la base
 * ---------------------------------
 * Crée les nouvelles tables (clés d'accès + ressources) sur une installation
 * déjà en place. Sûr à exécuter plusieurs fois. Réservé à l'administrateur.
 *
 * ⚠️  SUPPRIMEZ CE FICHIER une fois la mise à jour effectuée.
 */
require_once __DIR__ . '/includes/auth.php';
require_admin();

$done = false;
$errors = [];
try {
    $sql = file_get_contents(__DIR__ . '/sql/upgrade.sql');
    if ($sql === false) throw new RuntimeException('Fichier sql/upgrade.sql introuvable.');
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        if ($stmt !== '') db()->exec($stmt);
    }

    // Ajout conditionnel de colonnes (ALTER échoue si la colonne existe déjà)
    $addColumn = function (string $table, string $col, string $ddl) {
        $c = db()->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS
                            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $c->execute([$table, $col]);
        if ((int)$c->fetchColumn() === 0) {
            db()->exec("ALTER TABLE `$table` ADD COLUMN $ddl");
        }
    };
    $addColumn('companies', 'apply_channel', "`apply_channel` VARCHAR(60) NOT NULL DEFAULT '' AFTER `source`");

    // Ajoute le statut « À postuler » (offres à faire) si absent de l'ENUM
    try {
        $col = db()->query("SHOW COLUMNS FROM companies LIKE 'status'")->fetch();
        if ($col && strpos((string)$col['Type'], 'a_postuler') === false) {
            db()->exec("ALTER TABLE companies MODIFY COLUMN `status`
                ENUM('a_postuler','brouillon','envoye','relance','repondu','entretien','accepte','refuse')
                NOT NULL DEFAULT 'brouillon'");
        }
    } catch (Throwable $e) { /* ignore */ }
    // Dossier des ressources
    $dir = UPLOAD_DIR . '/resources';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $done = true;
} catch (Throwable $e) {
    $errors[] = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="fr"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Mise à jour · Alternis</title>
<style>
  body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;
    font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#0e1020;color:#e8eaf2}
  .card{width:100%;max-width:460px;background:#181b2e;border:1px solid #262a44;border-radius:18px;
    padding:30px 28px;box-shadow:0 24px 60px rgba(0,0,0,.4)}
  .logo{width:46px;height:46px;border-radius:12px;object-fit:contain;
    display:grid;place-items:center;font-weight:800;font-size:22px;color:#fff;margin-bottom:16px}
  h1{font-size:21px;margin:0 0 8px}p{color:#9aa0bd;font-size:14px}
  .msg{padding:11px 13px;border-radius:10px;font-size:13.5px;margin:8px 0}
  .ok{background:#12301f;border:1px solid #1f5a38;color:#9ff0c0}
  .err{background:#3a1720;border:1px solid #7f2536;color:#ffb3c0}
  .warn{background:#3a2f12;border:1px solid #7a5f1f;color:#ffe08a}
  a.btn{display:block;text-align:center;margin-top:18px;padding:12px;border-radius:10px;
    background:linear-gradient(135deg,#7c5cfc,#22d3ee);color:#fff;text-decoration:none;font-weight:700}
  code{background:#11142a;padding:2px 6px;border-radius:6px;font-size:12.5px}
</style></head><body>
<div class="card">
  <img class="logo" src="assets/img/mark-512.png" alt="Alternis">
<?php if ($done): ?>
  <h1>Mise à jour réussie ✅</h1>
  <p>Les fonctionnalités « clés d'accès » et « ressources » sont désormais actives.</p>
  <div class="msg ok">Tables créées ou déjà présentes — aucune donnée existante modifiée.</div>
  <div class="msg warn"><strong>Important :</strong> supprimez <code>upgrade.php</code> du serveur.</div>
  <a class="btn" href="<?= e(url('index.php?page=settings')) ?>">Retour aux paramètres</a>
<?php else: ?>
  <h1>Échec de la mise à jour</h1>
  <?php foreach ($errors as $e): ?><div class="msg err"><?= e($e) ?></div><?php endforeach; ?>
  <p>Vérifiez les droits de la base puis rechargez la page.</p>
<?php endif; ?>
</div>
</body></html>
