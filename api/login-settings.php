<?php
/**
 * Public endpoint — returns only non-sensitive login page settings
 * No authentication required (login page needs these before user signs in)
 */
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/db.php';

try {
    $db = getDB();
    $stmt = $db->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('loginAnimation', 'loginAnimationEnabled')");
    $settings = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    echo json_encode(['success' => true, 'settings' => $settings]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'settings' => []]);
}
