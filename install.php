<?php
/**
 * Alternis — Assistant d'installation
 * -----------------------------------
 * 1. Vérifie la connexion à la base (config/config.php).
 * 2. Crée les tables à partir de sql/install.sql.
 * 3. Crée le premier compte administrateur.
 *
 * ⚠️  SUPPRIMEZ CE FICHIER une fois l'installation terminée.
 */
require_once __DIR__ . '/includes/functions.php';

$step   = 'form';
$errors = [];
$notice = '';

/* Connexion BDD */
try {
    $pdo = db();
} catch (Throwable $e) {
    $step = 'error';
    $errors[] = "Impossible de se connecter à la base de données. Vérifiez config/config.php.";
}

/* Un admin existe-t-il déjà ? (table présente + au moins un admin) */
$adminExists = false;
if ($step !== 'error') {
    try {
        $adminExists = (bool)$pdo->query("SELECT 1 FROM users WHERE role='admin' LIMIT 1")->fetchColumn();
    } catch (Throwable $e) {
        $adminExists = false; // table pas encore créée
    }
}
if ($adminExists) {
    $step = 'done_already';
}

/* Traitement du formulaire */
if ($step === 'form' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $full  = trim($_POST['full_name'] ?? '');
    $user  = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $pass  = (string)($_POST['password'] ?? '');
    $pass2 = (string)($_POST['password2'] ?? '');

    if ($full === '')                              $errors[] = "Le nom complet est requis.";
    if (!preg_match('/^[A-Za-z0-9_.-]{3,60}$/', $user)) $errors[] = "Identifiant invalide (3–60 caractères : lettres, chiffres, . _ -).";
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Adresse e-mail invalide.";
    if (strlen($pass) < 8)                          $errors[] = "Le mot de passe doit contenir au moins 8 caractères.";
    if ($pass !== $pass2)                           $errors[] = "Les deux mots de passe ne correspondent pas.";

    if (!$errors) {
        try {
            // 1. Création des tables — on exécute chaque instruction séparément
            //    (plus robuste que exec() multi-requêtes sur hébergement mutualisé).
            $sql = file_get_contents(__DIR__ . '/sql/install.sql');
            if ($sql === false) throw new RuntimeException("Fichier sql/install.sql introuvable.");
            // Retire les commentaires en début de ligne
            $sql = preg_replace('/^\s*--.*$/m', '', $sql);
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
                if ($stmt !== '') $pdo->exec($stmt);
            }

            // 2. Premier administrateur
            $st = $pdo->prepare(
                'INSERT INTO users (username, email, password_hash, full_name, role, is_active)
                 VALUES (?,?,?,?,?,1)'
            );
            $st->execute([$user, $email, password_hash($pass, PASSWORD_DEFAULT), $full, 'admin']);

            $step = 'success';
            $notice = "Compte administrateur « $user » créé.";
        } catch (Throwable $e) {
            $errors[] = "Erreur pendant l'installation : " . $e->getMessage();
        }
    }
}

/* Dossier d'upload */
if (!is_dir(UPLOAD_DIR)) {
    @mkdir(UPLOAD_DIR, 0775, true);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Installation · Alternis</title>
<style>
  :root { --grad: linear-gradient(135deg,#7c5cfc,#22d3ee); }
  * { box-sizing: border-box; }
  body { margin:0; min-height:100vh; display:grid; place-items:center; padding:24px;
    font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;
    background:#0e1020; color:#e8eaf2; }
  .card { width:100%; max-width:460px; background:#181b2e; border:1px solid #262a44;
    border-radius:18px; padding:30px 28px; box-shadow:0 24px 60px rgba(0,0,0,.4); }
  .logo { width:46px; height:46px; border-radius:12px; object-fit:contain; 
    display:grid; place-items:center; font-weight:800; font-size:22px; color:#fff; margin-bottom:16px; }
  h1 { font-size:21px; margin:0 0 4px; }
  p.sub { color:#9aa0bd; margin:0 0 20px; font-size:14px; }
  label { display:block; font-size:13px; margin:14px 0 5px; color:#c3c7dd; }
  input { width:100%; padding:11px 13px; border-radius:10px; border:1px solid #2c3152;
    background:#11142a; color:#fff; font-size:14px; }
  input:focus { outline:2px solid #7c5cfc55; border-color:#7c5cfc; }
  button { width:100%; margin-top:22px; padding:12px; border:0; border-radius:10px;
    background:var(--grad); color:#fff; font-weight:700; font-size:15px; cursor:pointer; }
  .msg { padding:11px 13px; border-radius:10px; font-size:13.5px; margin-bottom:8px; }
  .err { background:#3a1720; border:1px solid #7f2536; color:#ffb3c0; }
  .ok  { background:#12301f; border:1px solid #1f5a38; color:#9ff0c0; }
  .warn{ background:#3a2f12; border:1px solid #7a5f1f; color:#ffe08a; }
  a.btn { display:block; text-align:center; margin-top:18px; padding:12px; border-radius:10px;
    background:var(--grad); color:#fff; text-decoration:none; font-weight:700; }
  code { background:#11142a; padding:2px 6px; border-radius:6px; font-size:12.5px; }
</style>
</head>
<body>
<div class="card">
  <img class="logo" src="assets/img/mark-512.png" alt="Alternis">

<?php if ($step === 'error'): ?>
  <h1>Connexion impossible</h1>
  <p class="sub">Corrigez les identifiants puis rechargez la page.</p>
  <?php foreach ($errors as $e): ?><div class="msg err"><?= e($e) ?></div><?php endforeach; ?>
  <p class="sub" style="margin-top:16px">Ouvrez <code>config/config.php</code> et renseignez
  <code>DB_NAME</code>, <code>DB_USER</code>, <code>DB_PASS</code>.</p>

<?php elseif ($step === 'done_already'): ?>
  <h1>Déjà installé</h1>
  <p class="sub">Un compte administrateur existe déjà. Pour votre sécurité, supprimez ce fichier.</p>
  <div class="msg warn">Supprimez maintenant <code>install.php</code> du serveur.</div>
  <a class="btn" href="<?= e(url('auth/login.php')) ?>">Aller à la connexion</a>

<?php elseif ($step === 'success'): ?>
  <h1>Installation terminée 🎉</h1>
  <p class="sub"><?= e($notice) ?></p>
  <div class="msg ok">Les tables ont été créées et votre compte administrateur est prêt.</div>
  <div class="msg warn"><strong>Important :</strong> supprimez <code>install.php</code> du serveur dès maintenant.</div>
  <a class="btn" href="<?= e(url('auth/login.php')) ?>">Se connecter</a>

<?php else: ?>
  <h1>Installer Alternis</h1>
  <p class="sub">Créez le compte administrateur principal. Les autres comptes (étudiant, parent) se créeront ensuite depuis le tableau de bord.</p>
  <?php foreach ($errors as $e): ?><div class="msg err"><?= e($e) ?></div><?php endforeach; ?>
  <form method="post" autocomplete="off">
    <label>Nom complet</label>
    <input name="full_name" value="<?= e($_POST['full_name'] ?? '') ?>" required>
    <label>Identifiant de connexion</label>
    <input name="username" value="<?= e($_POST['username'] ?? '') ?>" required>
    <label>Adresse e-mail</label>
    <input type="email" name="email" value="<?= e($_POST['email'] ?? '') ?>" required>
    <label>Mot de passe (8 caractères min.)</label>
    <input type="password" name="password" required>
    <label>Confirmer le mot de passe</label>
    <input type="password" name="password2" required>
    <button type="submit">Installer et créer l'administrateur</button>
  </form>
<?php endif; ?>
</div>
</body>
</html>
