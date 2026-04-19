
// ─── v3.114 — Role convenience helpers ──────────────────────────
// Jason's vocabulary:
//   Master Super-Admin = users.is_super_admin = 1  (cross-tenant)
//   Admin   = role_in_org = 'admin'    (tenant owner; edits branding/settings)
//   Manager = role_in_org = 'manager'  (tenant; read-only settings + theme)
//   Editor  = role_in_org = 'editor'   (own records only for reports/history)

function isMasterAdmin() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!empty($_SESSION['is_super_admin'])) return true;
    // Backfill: look it up if the session was created before this flag was cached
    $uid = $_SESSION['user_id'] ?? null;
    if (!$uid) return false;
    try {
        require_once __DIR__ . '/db.php';
        $val = (int) getDB()->query("SELECT is_super_admin FROM users WHERE id = " . (int) $uid)->fetchColumn();
        $_SESSION['is_super_admin'] = $val;
        return (bool) $val;
    } catch (Throwable $e) { return false; }
}

function isTenantAdmin() {
    return (getCurrentOrgRole() === 'admin') || isMasterAdmin();
}

function isManager() {
    $r = getCurrentOrgRole();
    return $r === 'manager' || $r === 'admin' || isMasterAdmin();
}

function isEditor() {
    // Any authenticated user is at least editor-level in their tenant.
    return (bool) getCurrentUserId();
}

function canSeeAnalytics() {
    return isManager();   // admin + manager, not editor
}

function canSeeUsers() {
    return isTenantAdmin();   // admin only
}

function canEditSettings() {
    return isTenantAdmin();   // admin only; manager gets theme via per-key check in settings.php
}
