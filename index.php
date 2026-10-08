<?php
require_once __DIR__ . '/includes/auth.php';
$__u = require_login();
if (function_exists('ensure_admin_tables')) { try { ensure_admin_tables(); } catch (Throwable $e) {} }

$page = $_GET['page'] ?? 'dashboard';
$allowed = ['dashboard', 'companies', 'company', 'directory', 'org', 'todo', 'followups', 'resources', 'settings', 'admin', 'change_password', 'collab_join'];
if (!in_array($page, $allowed, true)) {
    $page = 'dashboard';
}
if ($page === 'admin' && !is_admin()) {
    $page = 'dashboard';
}

// Changement de mot de passe obligatoire (première connexion / réinitialisation)
if (!empty($__u['must_change_pwd']) && $page !== 'change_password') {
    redirect(url('index.php?page=change_password'));
}

// Mode maintenance : seuls les administrateurs passent
if (function_exists('maintenance_active') && maintenance_active() && !is_admin() && $page !== 'change_password') {
    http_response_code(503);
    require __DIR__ . '/pages/maintenance.php';
    exit;
}

// Notifications programmées échues
if (function_exists('process_scheduled_notifications')) {
    process_scheduled_notifications();
}

// Filet de sécurité : si le cron n'est pas encore configuré, on pousse
// quelques notifications en attente à chaque chargement de page.
// (Le cron reste indispensable pour recevoir app fermée.)
require_once __DIR__ . '/includes/push.php';
if (function_exists('push_flush_pending') && push_enabled()) {
    try { push_flush_pending(5); } catch (Throwable $e) {}
}

require __DIR__ . '/pages/' . $page . '.php';
