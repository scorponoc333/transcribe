<?php
/**
 * v3.114 — Session info endpoint.
 *
 * Returns JSON describing the current user's role-in-org, master-admin flag,
 * user id, organization id, plus the user's saved theme preference. The main
 * SPA calls this once at load to:
 *   - set <body data-role="admin|manager|editor" data-is-master="0|1">
 *     so CSS can hide role-gated menu items
 *   - apply the saved theme before the first paint (falls back to
 *     localStorage if the user is new)
 *
 * Does NOT return anything sensitive (no email PII, no org info beyond id).
 */

require_once __DIR__ . '/auth_middleware.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$session = requireAuth();

$userId = (int) ($session['user_id'] ?? 0);
$orgId  = (int) ($session['organization_id'] ?? 0);
$role   = $session['role_in_org'] ?? 'editor';
$isMaster = isMasterAdmin();

// Read the per-user theme preference (falls back to org-level, then null).
$theme = null;
try {
    $db = getDB();
    $q = $db->prepare("SELECT setting_value FROM settings
                       WHERE organization_id = :org
                         AND user_id = :uid
                         AND setting_key = 'theme'
                       LIMIT 1");
    $q->execute([':org' => $orgId, ':uid' => $userId]);
    $theme = $q->fetchColumn() ?: null;
    if (!$theme) {
        // Org-level default as a fallback
        $q2 = $db->prepare("SELECT setting_value FROM settings
                            WHERE organization_id = :org
                              AND user_id IS NULL
                              AND setting_key = 'theme'
                            LIMIT 1");
        $q2->execute([':org' => $orgId]);
        $theme = $q2->fetchColumn() ?: null;
    }
} catch (Throwable $e) {
    // Theme lookup is best-effort; don't fail session endpoint on it
    $theme = null;
}

// If the user is actively impersonating someone, flag it so the UI can show
// a banner.
$impersonating = !empty($_SESSION['real_user_id']) && $_SESSION['real_user_id'] !== $userId;

echo json_encode([
    'user_id'          => $userId,
    'organization_id'  => $orgId,
    'role_in_org'      => $role,          // 'admin' | 'manager' | 'editor'
    'is_master_admin'  => (bool) $isMaster,
    'theme'            => $theme,         // 'light' | 'dark' | null
    'impersonating'    => (bool) $impersonating,
    'real_user_id'     => $impersonating ? (int) $_SESSION['real_user_id'] : null,
]);
