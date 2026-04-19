<?php
/**
 * Settings API — server-side settings storage (v3.114 role-aware).
 *
 * GET  → returns org-scope settings (sensitive values masked for non-admin)
 *        merged with the current user's personal theme preference.
 * POST → save settings with role-based gating:
 *         - Admin (role_in_org='admin') or Master Super-Admin: any allowed key.
 *         - Manager: only 'theme' (per-user).
 *         - Editor:  only 'theme' (per-user). The settings modal is hidden in
 *                    the UI, but we still accept theme so the user's stored
 *                    preference can be updated from anywhere.
 *
 * Per-user preferences use settings.user_id = the user's id. Org-scope rows
 * keep user_id = NULL. Unique index (organization_id, user_id, setting_key)
 * lets both coexist (MySQL treats NULLs as distinct in unique indexes).
 */
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_middleware.php';
requireAuth();

$method = $_SERVER['REQUEST_METHOD'];

// Keys stored per-user (never at org-scope). Extend cautiously.
$PER_USER_KEYS = ['theme'];

try {
    $db = getDB();

    $orgId  = getCurrentOrgId();
    $userId = getCurrentUserId();
    $role   = getCurrentOrgRole() ?: 'editor';
    $isMaster = isMasterAdmin();

    // ─── GET ─── org settings (masked for non-admin) + user's per-user keys.
    if ($method === 'GET') {
        $stmt = $db->prepare("SELECT setting_key, setting_value
                              FROM settings
                              WHERE organization_id = :org_id
                                AND user_id IS NULL");
        $stmt->execute([':org_id' => $orgId]);

        $settings = [];
        $sensitive = ['openRouterApiKey', 'smtpPass', 'emailitApiKey'];
        $canSeeRaw = ($role === 'admin') || $isMaster;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $key = $row['setting_key'];
            $val = $row['setting_value'];
            if (in_array($key, $sensitive) && !$canSeeRaw) {
                $val = $val ? str_repeat('•', min(20, max(0, strlen($val) - 4))) . substr($val, -4) : '';
            }
            $settings[$key] = $val;
        }

        // Per-user overlays (theme, future prefs)
        $stmt2 = $db->prepare("SELECT setting_key, setting_value
                               FROM settings
                               WHERE organization_id = :org_id
                                 AND user_id = :uid");
        $stmt2->execute([':org_id' => $orgId, ':uid' => $userId]);
        while ($row = $stmt2->fetch(PDO::FETCH_ASSOC)) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }

        echo json_encode([
            'success'   => true,
            'settings'  => $settings,
            'role'      => $role,
            'is_master' => (bool) $isMaster,
        ]);
        exit;
    }

    // ─── POST ─── role-gated per key.
    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $incoming = $input['settings'] ?? [];

        if (empty($incoming)) {
            http_response_code(400);
            echo json_encode(['error' => 'No settings provided']);
            exit;
        }

        $allowedOrgKeys = [
            'openRouterApiKey', 'openRouterModel',
            'smtpHost', 'smtpPort', 'smtpEncryption', 'smtpUser', 'smtpPass',
            'emailitApiKey',
            'senderEmail', 'senderName',
            'whisperModel',
            'footerText',
            'brandColor',
            'loginAnimation', 'loginAnimationEnabled',
            'loginAnimationOpacity', 'loginAnimationSpeed',
        ];

        $canEditOrg = ($role === 'admin') || $isMaster;

        $orgStmt = $db->prepare(
            "INSERT INTO settings (organization_id, user_id, setting_key, setting_value)
             VALUES (:org_id, NULL, :key, :value)
             ON DUPLICATE KEY UPDATE setting_value = :value2, updated_at = NOW()"
        );
        $userStmt = $db->prepare(
            "INSERT INTO settings (organization_id, user_id, setting_key, setting_value)
             VALUES (:org_id, :uid, :key, :value)
             ON DUPLICATE KEY UPDATE setting_value = :value2, updated_at = NOW()"
        );

        $saved = 0;
        $rejected = [];
        foreach ($incoming as $key => $value) {
            if (in_array($key, $PER_USER_KEYS, true)) {
                $userStmt->execute([
                    ':org_id' => $orgId,
                    ':uid'    => $userId,
                    ':key'    => $key,
                    ':value'  => $value,
                    ':value2' => $value,
                ]);
                $saved++;
                continue;
            }
            if (!in_array($key, $allowedOrgKeys, true)) {
                continue; // silently drop unknown keys
            }
            if (!$canEditOrg) {
                $rejected[] = $key;
                continue;
            }
            $orgStmt->execute([
                ':org_id' => $orgId,
                ':key'    => $key,
                ':value'  => $value,
                ':value2' => $value,
            ]);
            $saved++;
        }

        if (!empty($rejected) && $saved === 0) {
            http_response_code(403);
            echo json_encode([
                'error'    => 'Insufficient permissions for these keys',
                'rejected' => $rejected,
            ]);
            exit;
        }

        echo json_encode([
            'success'  => true,
            'saved'    => $saved,
            'rejected' => $rejected,
        ]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
