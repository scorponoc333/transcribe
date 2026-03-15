<?php
/**
 * List Transcriptions - GET endpoint with pagination and filters
 * Params: page, search, date_from, date_to, mode
 */
header('Content-Type: application/json');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_middleware.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$page     = max(1, (int) ($_GET['page'] ?? 1));
$perPage  = 10;
$offset   = ($page - 1) * $perPage;
$search   = trim($_GET['search'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo   = trim($_GET['date_to'] ?? '');
$mode     = trim($_GET['mode'] ?? '');

try {
    $db = getDB();
    $where  = [];
    $params = [];

    // Role-based filtering: Users only see their own transcriptions
    $userRole = getCurrentUserRole();
    if ($userRole === 'user') {
        $where[]         = 't.user_id = :current_user_id';
        $params[':current_user_id'] = getCurrentUserId();
    }

    if ($search !== '') {
        $where[]           = '(t.title LIKE :search OR t.transcript_text LIKE :search2)';
        $params[':search']  = "%$search%";
        $params[':search2'] = "%$search%";
    }
    if ($dateFrom !== '') {
        $where[]              = 't.created_at >= :date_from';
        $params[':date_from'] = $dateFrom . ' 00:00:00';
    }
    if ($dateTo !== '') {
        $where[]            = 't.created_at <= :date_to';
        $params[':date_to'] = $dateTo . ' 23:59:59';
    }
    if ($mode !== '' && in_array($mode, ['recording', 'meeting', 'learning'])) {
        $where[]         = 't.mode = :mode';
        $params[':mode'] = $mode;
    }

    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    // Count total
    $countSql = "SELECT COUNT(*) FROM transcriptions t $whereClause";
    $countStmt = $db->prepare($countSql);
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    // Fetch page (exclude large blobs) — include user info for admin/manager
    $sql = "SELECT t.id, t.title, t.mode, t.language, t.whisper_model, t.word_count, t.char_count,
                   t.created_at, t.user_id, (t.pdf_blob IS NOT NULL) AS has_pdf,
                   (SELECT COUNT(*) FROM email_log e WHERE e.transcription_id = t.id) AS email_count,
                   u.name AS user_name
            FROM transcriptions t
            LEFT JOIN users u ON u.id = t.user_id
            $whereClause
            ORDER BY t.created_at DESC
            LIMIT $perPage OFFSET $offset";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Cast types
    foreach ($rows as &$row) {
        $row['id']          = (int) $row['id'];
        $row['word_count']  = (int) $row['word_count'];
        $row['char_count']  = (int) $row['char_count'];
        $row['has_pdf']     = (bool) $row['has_pdf'];
        $row['email_count'] = (int) $row['email_count'];
        $row['user_id']     = $row['user_id'] ? (int) $row['user_id'] : null;
    }

    echo json_encode([
        'success'    => true,
        'data'       => $rows,
        'total'      => $total,
        'page'       => $page,
        'per_page'   => $perPage,
        'total_pages'=> (int) ceil($total / $perPage),
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
