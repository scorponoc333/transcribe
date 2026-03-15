<?php
/**
 * Contacts API - Full CRUD + CSV import + name matching + search autocomplete
 *
 * GET  ?action=list&page=1&limit=20&q=search  → paginated contact list
 * GET  ?action=search&q=john                   → autocomplete search (limit 10)
 * POST ?action=create    { name, email, company }           → create single contact
 * POST ?action=upsert    { contacts: [{name, email, company}] } → upsert batch (backward compat, also default)
 * POST ?action=match_names { names: ["John", "Sarah"] }     → match names against contacts
 * POST ?action=import_csv  (multipart file upload)          → import CSV file
 * PUT  ?action=update    { id, name, email, company }       → update contact
 * DELETE ?action=delete  { id }                             → delete contact
 */
header('Content-Type: application/json');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_middleware.php';
requireRole(['admin','manager']);

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    $db = getDB();

    // ─── GET ─────────────────────────────────────────────
    if ($method === 'GET') {

        // Paginated list
        if ($action === 'list') {
            $page  = max(1, (int)($_GET['page'] ?? 1));
            $limit = max(1, min(100, (int)($_GET['limit'] ?? 20)));
            $q     = trim($_GET['q'] ?? '');
            $offset = ($page - 1) * $limit;

            $where = '';
            $params = [];
            if ($q !== '') {
                $like = '%' . $q . '%';
                $where = "WHERE name LIKE :q1 OR email LIKE :q2 OR company LIKE :q3";
                $params = [':q1' => $like, ':q2' => $like, ':q3' => $like];
            }

            // Total count
            $countStmt = $db->prepare("SELECT COUNT(*) as total FROM contacts $where");
            $countStmt->execute($params);
            $total = (int) $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

            // Page data
            $dataStmt = $db->prepare("SELECT id, name, email, company, use_count, last_used_at
                FROM contacts $where
                ORDER BY name ASC, last_used_at DESC
                LIMIT $limit OFFSET $offset");
            $dataStmt->execute($params);
            $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as &$r) {
                $r['id'] = (int) $r['id'];
                $r['use_count'] = (int) $r['use_count'];
            }

            echo json_encode([
                'success' => true,
                'data'    => $rows,
                'total'   => $total,
                'page'    => $page,
                'limit'   => $limit,
                'pages'   => max(1, ceil($total / $limit)),
            ]);
            exit;
        }

        // Autocomplete search (backward compat — default GET behavior)
        $q = trim($_GET['q'] ?? '');
        if (strlen($q) < 1) {
            echo json_encode(['success' => true, 'data' => []]);
            exit;
        }

        $like = '%' . $q . '%';
        $stmt = $db->prepare("SELECT id, name, email, company, use_count FROM contacts WHERE name LIKE :q1 OR email LIKE :q2 OR company LIKE :q3 ORDER BY use_count DESC, last_used_at DESC LIMIT 10");
        $stmt->execute([':q1' => $like, ':q2' => $like, ':q3' => $like]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'data' => $rows]);
        exit;
    }

    // ─── POST ────────────────────────────────────────────
    if ($method === 'POST') {

        // CSV Import (multipart form)
        if ($action === 'import_csv') {
            if (!isset($_FILES['csv_file'])) {
                http_response_code(400);
                echo json_encode(['error' => 'No CSV file uploaded']);
                exit;
            }

            $file = $_FILES['csv_file'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                http_response_code(400);
                echo json_encode(['error' => 'Upload error: ' . $file['error']]);
                exit;
            }

            $handle = fopen($file['tmp_name'], 'r');
            if (!$handle) {
                http_response_code(500);
                echo json_encode(['error' => 'Could not read CSV file']);
                exit;
            }

            // Read header row
            $header = fgetcsv($handle);
            if (!$header) {
                fclose($handle);
                http_response_code(400);
                echo json_encode(['error' => 'CSV file is empty']);
                exit;
            }

            // Normalize header names
            $header = array_map(function($h) {
                return strtolower(trim(preg_replace('/[^a-zA-Z0-9]/', '_', $h)));
            }, $header);

            // Map column indices
            $nameIdx    = null;
            $emailIdx   = null;
            $companyIdx = null;
            foreach ($header as $i => $h) {
                if (in_array($h, ['full_name', 'name', 'fullname', 'contact_name'])) $nameIdx = $i;
                if (in_array($h, ['email', 'email_address', 'e_mail'])) $emailIdx = $i;
                if (in_array($h, ['company', 'organization', 'org', 'company_name'])) $companyIdx = $i;
            }

            if ($emailIdx === null) {
                fclose($handle);
                http_response_code(400);
                echo json_encode(['error' => 'CSV must have an Email column']);
                exit;
            }

            $stmt = $db->prepare("INSERT INTO contacts (name, email, company, use_count, last_used_at)
                VALUES (:name, :email, :company, 1, NOW())
                ON DUPLICATE KEY UPDATE
                    name = COALESCE(NULLIF(:name2, ''), name),
                    company = COALESCE(NULLIF(:company2, ''), company),
                    last_used_at = NOW()");

            $imported = 0;
            $skipped  = 0;
            while (($row = fgetcsv($handle)) !== false) {
                $email = trim($row[$emailIdx] ?? '');
                if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $skipped++;
                    continue;
                }
                $name    = $nameIdx !== null ? trim($row[$nameIdx] ?? '') : '';
                $company = $companyIdx !== null ? trim($row[$companyIdx] ?? '') : '';

                $stmt->execute([
                    ':name'     => $name,
                    ':email'    => $email,
                    ':company'  => $company,
                    ':name2'    => $name,
                    ':company2' => $company,
                ]);
                $imported++;
            }
            fclose($handle);

            echo json_encode([
                'success'  => true,
                'imported' => $imported,
                'skipped'  => $skipped,
            ]);
            exit;
        }

        // JSON body actions
        $input = json_decode(file_get_contents('php://input'), true);

        // Create single contact
        if ($action === 'create') {
            $name    = trim($input['name'] ?? '');
            $email   = trim($input['email'] ?? '');
            $company = trim($input['company'] ?? '');

            if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                http_response_code(400);
                echo json_encode(['error' => 'Valid email is required']);
                exit;
            }

            // Check duplicate
            $check = $db->prepare("SELECT id FROM contacts WHERE email = :email");
            $check->execute([':email' => $email]);
            if ($check->fetch()) {
                http_response_code(409);
                echo json_encode(['error' => 'A contact with this email already exists']);
                exit;
            }

            $stmt = $db->prepare("INSERT INTO contacts (name, email, company, use_count, last_used_at) VALUES (:name, :email, :company, 0, NOW())");
            $stmt->execute([':name' => $name, ':email' => $email, ':company' => $company]);

            echo json_encode(['success' => true, 'id' => (int) $db->lastInsertId()]);
            exit;
        }

        // Match names against contacts
        if ($action === 'match_names') {
            $names = $input['names'] ?? [];
            if (empty($names)) {
                echo json_encode(['success' => true, 'matches' => new \stdClass()]);
                exit;
            }

            $matches = [];
            foreach ($names as $name) {
                $name = trim($name);
                if (!$name) continue;

                // Try exact match first
                $stmt = $db->prepare("SELECT id, name, email, company FROM contacts WHERE LOWER(name) = LOWER(:name) LIMIT 1");
                $stmt->execute([':name' => $name]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$row) {
                    // Try partial match (name contains the search term)
                    $like = '%' . $name . '%';
                    $stmt = $db->prepare("SELECT id, name, email, company FROM contacts WHERE LOWER(name) LIKE LOWER(:name) ORDER BY use_count DESC LIMIT 1");
                    $stmt->execute([':name' => $like]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                }

                if ($row) {
                    $matches[$name] = [
                        'id'      => (int) $row['id'],
                        'name'    => $row['name'],
                        'email'   => $row['email'],
                        'company' => $row['company'],
                    ];
                }
            }

            echo json_encode(['success' => true, 'matches' => (object) $matches]);
            exit;
        }

        // Upsert batch (backward compat — default POST behavior)
        $contacts = $input['contacts'] ?? [];
        if (empty($contacts)) {
            echo json_encode(['success' => true]);
            exit;
        }

        $stmt = $db->prepare("INSERT INTO contacts (name, email, company, use_count, last_used_at)
            VALUES (:name, :email, :company, 1, NOW())
            ON DUPLICATE KEY UPDATE
                name = COALESCE(NULLIF(:name2, ''), name),
                company = COALESCE(NULLIF(:company2, ''), company),
                use_count = use_count + 1,
                last_used_at = NOW()");

        foreach ($contacts as $c) {
            $email = trim($c['email'] ?? '');
            if (!$email) continue;
            $name    = trim($c['name'] ?? '');
            $company = trim($c['company'] ?? '');
            $stmt->execute([
                ':name'     => $name,
                ':email'    => $email,
                ':company'  => $company,
                ':name2'    => $name,
                ':company2' => $company,
            ]);
        }

        echo json_encode(['success' => true]);
        exit;
    }

    // ─── PUT ─────────────────────────────────────────────
    if ($method === 'PUT') {
        $input = json_decode(file_get_contents('php://input'), true);
        $id      = (int)($input['id'] ?? 0);
        $name    = trim($input['name'] ?? '');
        $email   = trim($input['email'] ?? '');
        $company = trim($input['company'] ?? '');

        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'Contact ID is required']);
            exit;
        }
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['error' => 'Valid email is required']);
            exit;
        }

        // Check email uniqueness (exclude self)
        $check = $db->prepare("SELECT id FROM contacts WHERE email = :email AND id != :id");
        $check->execute([':email' => $email, ':id' => $id]);
        if ($check->fetch()) {
            http_response_code(409);
            echo json_encode(['error' => 'Another contact with this email already exists']);
            exit;
        }

        $stmt = $db->prepare("UPDATE contacts SET name = :name, email = :email, company = :company WHERE id = :id");
        $stmt->execute([':name' => $name, ':email' => $email, ':company' => $company, ':id' => $id]);

        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['error' => 'Contact not found']);
            exit;
        }

        echo json_encode(['success' => true]);
        exit;
    }

    // ─── DELETE ──────────────────────────────────────────
    if ($method === 'DELETE') {
        $input = json_decode(file_get_contents('php://input'), true);
        $id = (int)($input['id'] ?? 0);

        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'Contact ID is required']);
            exit;
        }

        $stmt = $db->prepare("DELETE FROM contacts WHERE id = :id");
        $stmt->execute([':id' => $id]);

        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['error' => 'Contact not found']);
            exit;
        }

        echo json_encode(['success' => true]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
