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
$perPage  = max(1, min(200, (int) ($_GET['per_page'] ?? 10)));
$offset   = ($page - 1) * $perPage;
$search   = trim($_GET['search'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo   = trim($_GET['date_to'] ?? '');
$mode     = trim($_GET['mode'] ?? '');

try {
    $db = getDB();
    $where  = [];
    $params = [];

    // TENANT BOUNDARY — org filter on every query (hardcoded, can't be bypassed by input)
    $where[]                 = 't.organization_id = :current_org_id';
    $params[':current_org_id'] = getCurrentOrgId();

    // v3.114 role-based filtering: editors see only their own records.
    // Admin + Manager (and Master Super-Admin) see everything in the tenant.
    $orgRole = getCurrentOrgRole();
    if ($orgRole === 'editor' && !isMasterAdmin()) {
        $where[]                       = 't.user_id = :current_user_id';
        $params[':current_user_id']    = getCurrentUserId();
    }

    if ($search !== '') {
        $where[]           = 't.title LIKE :search';
        $params[':search']  = "%$search%";
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

    // Fetch page (exclude large blobs) — include user info for admin/manager.
    // Joins are scoped to the current org for defense-in-depth: even if the
    // outer WHERE missed, the join filters would block cross-tenant rows.
    $sql = "SELECT t.id, t.title, t.mode, t.language, t.whisper_model, t.word_count, t.char_count,
                   t.created_at, t.user_id, (t.pdf_blob IS NOT NULL) AS has_pdf,
                   COALESCE(ec.email_count, 0) AS email_count,
                   u.name AS user_name
            FROM transcriptions t
            LEFT JOIN users u ON u.id = t.user_id AND u.organization_id = t.organization_id
            LEFT JOIN (
                SELECT transcription_id, COUNT(*) AS email_count
                FROM email_log
                WHERE organization_id = :current_org_id_join
                GROUP BY transcription_id
            ) ec ON ec.transcription_id = t.id
            $whereClause
            ORDER BY t.created_at DESC
            LIMIT $perPage OFFSET $offset";
    $params[':current_org_id_join'] = getCurrentOrgId();

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
