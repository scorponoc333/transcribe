<?php
/**
 * Logo Upload API
 * GET  → returns current logo URL
 * POST → upload new logo image (png/jpg/svg, max 2MB)
 * DELETE → reset to default logo
 */
header('Content-Type: application/json');
require_once __DIR__ . '/auth_middleware.php';
requireAuth();

$imgDir = __DIR__ . '/../img';
$method = $_SERVER['REQUEST_METHOD'];

// ─── GET: Return current logo URL ──────────────────
if ($method === 'GET') {
    $logo = findCustomLogo($imgDir);
    echo json_encode([
        'success' => true,
        'url'     => $logo ?: 'img/logo.png',
        'custom'  => $logo !== null,
    ]);
    exit;
}

// ─── POST: Upload new logo or login background ─────────────────────────
if ($method === 'POST') {
    if (!isset($_FILES['logo'])) {
        http_response_code(400);
        echo json_encode(['error' => 'No file uploaded']);
        exit;
    }

    $uploadType = $_POST['type'] ?? 'logo'; // 'logo' or 'login_bg'
    $file = $_FILES['logo'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['error' => 'Upload error: ' . $file['error']]);
        exit;
    }

    // Validate size (max 2MB for logo, 8MB for login bg)
    $maxSize = ($uploadType === 'login_bg') ? 8 * 1024 * 1024 : 2 * 1024 * 1024;
    if ($file['size'] > $maxSize) {
        $maxLabel = ($uploadType === 'login_bg') ? '8MB' : '2MB';
        http_response_code(400);
        echo json_encode(['error' => "File too large. Maximum size is $maxLabel."]);
        exit;
    }

    // Validate type
    $allowed = ['image/png', 'image/jpeg', 'image/jpg', 'image/svg+xml', 'image/webp'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime, $allowed)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid file type. Allowed: PNG, JPG, SVG, WebP.']);
        exit;
    }

    // Determine extension
    $extMap = [
        'image/png'     => 'png',
        'image/jpeg'    => 'jpg',
        'image/jpg'     => 'jpg',
        'image/svg+xml' => 'svg',
        'image/webp'    => 'webp',
    ];
    $ext = $extMap[$mime] ?? 'png';

    if ($uploadType === 'login_bg') {
        // Remove any existing login background
        removeLoginBgs($imgDir);
        $filename = "login-bg.$ext";
    } else {
        // Remove any existing custom logo
        removeCustomLogos($imgDir);
        $filename = "custom-logo.$ext";
    }

    $destPath = "$imgDir/$filename";

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to save file']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'url'     => "img/$filename",
    ]);
    exit;
}

// ─── DELETE: Reset to default ──────────────────────
if ($method === 'DELETE') {
    $input = json_decode(file_get_contents('php://input'), true);
    $deleteType = $input['type'] ?? 'logo';

    if ($deleteType === 'login_bg') {
        removeLoginBgs($imgDir);
        echo json_encode([
            'success' => true,
            'message' => 'Login background reset',
        ]);
    } else {
        removeCustomLogos($imgDir);
        echo json_encode([
            'success' => true,
            'url'     => 'img/logo.png',
        ]);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);

// ─── Helpers ───────────────────────────────────────

function findCustomLogo($dir) {
    $exts = ['png', 'jpg', 'jpeg', 'svg', 'webp'];
    foreach ($exts as $ext) {
        $path = "$dir/custom-logo.$ext";
        if (file_exists($path)) {
            return "img/custom-logo.$ext";
        }
    }
    return null;
}

function removeCustomLogos($dir) {
    $exts = ['png', 'jpg', 'jpeg', 'svg', 'webp'];
    foreach ($exts as $ext) {
        $path = "$dir/custom-logo.$ext";
        if (file_exists($path)) {
            @unlink($path);
        }
    }
}

function removeLoginBgs($dir) {
    $exts = ['png', 'jpg', 'jpeg', 'svg', 'webp'];
    foreach ($exts as $ext) {
        $path = "$dir/login-bg.$ext";
        if (file_exists($path)) {
            @unlink($path);
        }
    }
}
