<?php
/**
 * Authentication Middleware — multi-tenant edition.
 *
 * Include at the top of every API endpoint that requires authentication.
 *
 * Functions:
 *   requireAuth()           — must be logged in
 *   requireRole($roles)     — logged in AND has one of the given global roles
 *   requireOrgRole($roles)  — logged in AND has one of the given in-org roles
 *   getCurrentUserId()      — returns the logged-in user's id (or null)
 *   getCurrentUserRole()    — returns 'admin' | 'manager' | 'user' | null
 *   getCurrentOrgId()       — returns the logged-in user's organization_id
 *                             This is the TENANT BOUNDARY for every query.
 *   getCurrentOrgRole()     — returns 'owner' | 'admin' | 'editor' | 'viewer'
 *
 * SECURITY CONTRACT:
 *   Every query on a per-tenant table MUST include a
 *     WHERE organization_id = :org_id
 *   clause, using getCurrentOrgId() as the source of truth. Do NOT trust
 *   organization_id values from POST/GET input — the only legitimate source
 *   is the session. An attacker changing org_id in a request body must be
 *   ignored (and ideally logged).
 */

function requireAuth() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Not authenticated']);
        exit;
    }

    // Maintenance mode — block non-super-admin users
    try {
        require_once __DIR__ . '/db.php';
        $db = getDB();
        $m = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'maintenance_mode' LIMIT 1")->fetchColumn();
        if ($m === '1') {
            // Allow super admins through, block everyone else
            $realId = $_SESSION['real_user_id'] ?? $_SESSION['user_id'];
            $isSuper = (bool) $db->query("SELECT is_super_admin FROM users WHERE id = " . (int) $realId)->fetchColumn();
            if (!$isSuper) {
                $msg = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'maintenance_message' LIMIT 1")->fetchColumn() ?: 'We will be right back.';
                http_response_code(503);
                header('Content-Type: application/json');
                header('Retry-After: 300');
                echo json_encode(['error' => 'maintenance', 'message' => $msg]);
                exit;
            }
        }
    } catch (Throwable $e) { /* fail open on error */ }
    // Backfill organization_id for legacy sessions created before Phase 1.
    // If an old session exists without an org_id, look it up from the DB.
    if (empty($_SESSION['organization_id'])) {
        require_once __DIR__ . '/db.php';
        try {
            $db = getDB();
            $stmt = $db->prepare("SELECT organization_id, role_in_org FROM users WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $_SESSION['user_id']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row || empty($row['organization_id'])) {
                // Stale session — force re-login
                session_destroy();
                http_response_code(401);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Session expired. Please sign in again.']);
                exit;
            }
            $_SESSION['organization_id'] = (int) $row['organization_id'];
            $_SESSION['role_in_org']     = $row['role_in_org'] ?: 'editor';
        } catch (PDOException $e) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Database error']);
            exit;
        }
    }
    return $_SESSION;
}

function requireRole($allowedRoles) {
    $session = requireAuth();
    $allowedRoles = (array) $allowedRoles;
    if (!in_array($session['user_role'], $allowedRoles)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Insufficient permissions']);
        exit;
    }
    return $session;
}

function requireOrgRole($allowedRoles) {
    $session = requireAuth();
    $allowedRoles = (array) $allowedRoles;
    $roleInOrg = $session['role_in_org'] ?? 'editor';
    if (!in_array($roleInOrg, $allowedRoles)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Insufficient organization permissions']);
        exit;
    }
    return $session;
}

function getCurrentUserId() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    return $_SESSION['user_id'] ?? null;
}

function getCurrentUserRole() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    return $_SESSION['user_role'] ?? null;
}

function getCurrentUserName() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    return $_SESSION['user_name'] ?? null;
}

/**
 * Returns the logged-in user's organization_id.
 *
 * THIS IS THE TENANT BOUNDARY. Every query on a per-tenant table MUST
 * filter by this value. Never trust organization_id from request input.
 *
 * Call requireAuth() first — this function assumes the session has been
 * validated.
 */
function getCurrentOrgId() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $id = $_SESSION['organization_id'] ?? null;
    return $id ? (int) $id : null;
}

function getCurrentOrgRole() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    return $_SESSION['role_in_org'] ?? null;
}

function getCurrentOrgPlan() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    return $_SESSION['org_plan'] ?? null;
}

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
