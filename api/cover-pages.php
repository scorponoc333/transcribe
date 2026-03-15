<?php
/**
 * Cover Pages API
 * GET    → list all cover pages
 * POST   → upload new cover image (admin only)
 * DELETE → delete a cover page by id (admin only, cannot delete default)
 * PUT    ?action=set_default { id } → set a cover page as default (admin only)
 */
header('Content-Type: application/json');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_middleware.php';
requireAuth();

$method   = $_SERVER['REQUEST_METHOD'];
$coversDir = __DIR__ . '/../img/covers';

// Ensure covers directory exists
if (!is_dir($coversDir)) {
    mkdir($coversDir, 0755, true);
}

try {
    $db = getDB();

    // ─── GET: List all cover pages ───
    if ($method === 'GET') {
        $stmt = $db->query("SELECT id, filename, original_name, is_default, sort_order, created_at FROM cover_pages ORDER BY sort_order ASC, created_at ASC");
        $covers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($covers as &$c) {
            $c['id']         = (int) $c['id'];
            $c['is_default'] = (bool) $c['is_default'];
            $c['sort_order'] = (int) $c['sort_order'];
            $c['url']        = 'img/covers/' . $c['filename'];
        }
        echo json_encode(['success' => true, 'covers' => $covers]);
        exit;
    }

    // ─── POST: Upload new cover ───
    if ($method === 'POST') {
        requireRole('admin');

        if (empty($_FILES['cover_image'])) {
            http_response_code(400);
            echo json_encode(['error' => 'No file uploaded']);
            exit;
        }

        $file = $_FILES['cover_image'];
        $allowedTypes = ['image/png', 'image/jpeg', 'image/webp'];
        $maxSize = 8 * 1024 * 1024; // 8MB for high-res covers

        if (!in_array($file['type'], $allowedTypes)) {
            http_response_code(400);
            echo json_encode(['error' => 'Only PNG, JPG, and WebP images are allowed']);
            exit;
        }
        if ($file['size'] > $maxSize) {
            http_response_code(400);
            echo json_encode(['error' => 'File must be under 8MB']);
            exit;
        }

        $ext = pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'png';
        $filename = 'cover-' . uniqid() . '.' . strtolower($ext);
        $destPath = $coversDir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to save file']);
            exit;
        }

        $originalName = $file['name'];
        $stmt = $db->prepare("INSERT INTO cover_pages (filename, original_name, is_default, sort_order) VALUES (:fn, :on, 0, 99)");
        $stmt->execute([':fn' => $filename, ':on' => $originalName]);

        echo json_encode([
            'success' => true,
            'id'      => (int) $db->lastInsertId(),
            'url'     => 'img/covers/' . $filename,
            'filename'=> $filename,
        ]);
        exit;
    }

    // ─── PUT: Set default ───
    if ($method === 'PUT') {
        requireRole('admin');

        $input = json_decode(file_get_contents('php://input'), true);
        $action = $_GET['action'] ?? '';

        if ($action === 'set_default') {
            $id = (int) ($input['id'] ?? 0);
            if (!$id) {
                http_response_code(400);
                echo json_encode(['error' => 'Cover page ID is required']);
                exit;
            }

            $db->exec("UPDATE cover_pages SET is_default = 0");
            $db->prepare("UPDATE cover_pages SET is_default = 1 WHERE id = :id")->execute([':id' => $id]);

            echo json_encode(['success' => true, 'message' => 'Default cover page updated']);
            exit;
        }

        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
        exit;
    }

    // ─── DELETE: Remove cover page ───
    if ($method === 'DELETE') {
        requireRole('admin');

        $input = json_decode(file_get_contents('php://input'), true);
        $id = (int) ($input['id'] ?? ($_GET['id'] ?? 0));

        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'Cover page ID is required']);
            exit;
        }

        // Check if it's the default
        $stmt = $db->prepare("SELECT filename, is_default FROM cover_pages WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $cover = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$cover) {
            http_response_code(404);
            echo json_encode(['error' => 'Cover page not found']);
            exit;
        }

        if ($cover['is_default']) {
            http_response_code(400);
            echo json_encode(['error' => 'Cannot delete the default cover page. Set another as default first.']);
            exit;
        }

        // Delete file
        $filePath = $coversDir . '/' . $cover['filename'];
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        // Delete record
        $db->prepare("DELETE FROM cover_pages WHERE id = :id")->execute([':id' => $id]);

        echo json_encode(['success' => true, 'message' => 'Cover page deleted']);
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
