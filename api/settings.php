<?php
/**
 * Settings API - Server-side settings storage
 * GET  → returns all settings (sensitive keys masked for non-admin)
 * POST → save settings (admin only). Accepts { settings: { key: value, ... } }
 */
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_middleware.php';
requireAuth();

$method = $_SERVER['REQUEST_METHOD'];

try {
    $db = getDB();

    // ─── GET: Fetch all settings ───
    if ($method === 'GET') {
        $role = getCurrentUserRole();
        $stmt = $db->query("SELECT setting_key, setting_value FROM settings");
        $settings = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $key = $row['setting_key'];
            $val = $row['setting_value'];

            // Mask sensitive values for non-admin users
            $sensitiveKeys = ['openRouterApiKey', 'smtpPass'];
            if (in_array($key, $sensitiveKeys) && $role !== 'admin') {
                $val = $val ? str_repeat('•', min(20, strlen($val) - 4)) . substr($val, -4) : '';
            }

            $settings[$key] = $val;
        }

        echo json_encode(['success' => true, 'settings' => $settings]);
        exit;
    }

    // ─── POST: Save settings (admin only) ───
    if ($method === 'POST') {
        requireRole('admin');

        $input = json_decode(file_get_contents('php://input'), true);
        $settings = $input['settings'] ?? [];

        if (empty($settings)) {
            http_response_code(400);
            echo json_encode(['error' => 'No settings provided']);
            exit;
        }

        // Allowed setting keys
        $allowedKeys = [
            'openRouterApiKey', 'openRouterModel',
            'smtpHost', 'smtpPort', 'smtpEncryption', 'smtpUser', 'smtpPass',
            'senderEmail', 'senderName',
            'whisperModel',
            'footerText',
            'loginAnimation', 'loginAnimationEnabled',
        ];

        $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (:key, :value)
                              ON DUPLICATE KEY UPDATE setting_value = :value2, updated_at = NOW()");

        $saved = 0;
        foreach ($settings as $key => $value) {
            if (!in_array($key, $allowedKeys)) continue;
            $stmt->execute([':key' => $key, ':value' => $value, ':value2' => $value]);
            $saved++;
        }

        echo json_encode(['success' => true, 'saved' => $saved]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
