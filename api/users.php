<?php
/**
 * User Management API (Admin only)
 * GET  ?action=list&page=1&limit=20&q=search → paginated user list
 * POST ?action=create { name, email, role, password } → create user
 * PUT  ?action=update { id, name, email, role } → update user
 * POST ?action=reset_password { id, new_password } → reset password
 * PUT  ?action=toggle_active { id, is_active } → enable/disable
 */
header('Content-Type: application/json');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_middleware.php';
requireRole('admin');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'list';

try {
    $db = getDB();

    // ─── LIST users ───
    if ($method === 'GET' && $action === 'list') {
        $page  = max(1, (int) ($_GET['page'] ?? 1));
        $limit = max(1, min(100, (int) ($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        $query = trim($_GET['q'] ?? '');

        $where = [];
        $params = [];
        if ($query !== '') {
            $where[] = '(u.name LIKE :q OR u.email LIKE :q2)';
            $params[':q']  = "%$query%";
            $params[':q2'] = "%$query%";
        }
        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // Count
        $countStmt = $db->prepare("SELECT COUNT(*) FROM users u $whereClause");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        // Fetch
        $sql = "SELECT u.id, u.name, u.email, u.role, u.is_active, u.created_at, u.last_login_at,
                       (SELECT COUNT(*) FROM transcriptions t WHERE t.user_id = u.id) AS transcription_count
                FROM users u
                $whereClause
                ORDER BY u.created_at DESC
                LIMIT $limit OFFSET $offset";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['id']         = (int) $row['id'];
            $row['is_active']  = (bool) $row['is_active'];
            $row['transcription_count'] = (int) $row['transcription_count'];
        }

        echo json_encode([
            'success'     => true,
            'data'        => $rows,
            'total'       => $total,
            'page'        => $page,
            'limit'       => $limit,
            'total_pages' => (int) ceil($total / $limit),
        ]);
        exit;
    }

    // ─── CREATE user ───
    if ($method === 'POST' && $action === 'create') {
        $input = json_decode(file_get_contents('php://input'), true);
        $name     = trim($input['name'] ?? '');
        $email    = trim($input['email'] ?? '');
        $role     = $input['role'] ?? 'user';
        $password = $input['password'] ?? '';

        if (!$name || !$email || !$password) {
            http_response_code(400);
            echo json_encode(['error' => 'Name, email, and password are required']);
            exit;
        }

        if (!in_array($role, ['admin', 'manager', 'user'])) {
            $role = 'user';
        }

        if (strlen($password) < 8) {
            http_response_code(400);
            echo json_encode(['error' => 'Password must be at least 8 characters']);
            exit;
        }

        // Check duplicate email
        $check = $db->prepare("SELECT id FROM users WHERE email = :email");
        $check->execute([':email' => $email]);
        if ($check->fetch()) {
            http_response_code(409);
            echo json_encode(['error' => 'A user with this email already exists']);
            exit;
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $db->prepare("INSERT INTO users (name, email, password_hash, role) VALUES (:name, :email, :hash, :role)");
        $stmt->execute([':name' => $name, ':email' => $email, ':hash' => $hash, ':role' => $role]);

        echo json_encode([
            'success' => true,
            'id'      => (int) $db->lastInsertId(),
            'message' => 'User created successfully'
        ]);
        exit;
    }

    // ─── UPDATE user ───
    if ($method === 'PUT' && $action === 'update') {
        $input = json_decode(file_get_contents('php://input'), true);
        $id    = (int) ($input['id'] ?? 0);
        $name  = trim($input['name'] ?? '');
        $email = trim($input['email'] ?? '');
        $role  = $input['role'] ?? '';

        if (!$id || !$name || !$email) {
            http_response_code(400);
            echo json_encode(['error' => 'ID, name, and email are required']);
            exit;
        }

        if ($role && !in_array($role, ['admin', 'manager', 'user'])) {
            $role = 'user';
        }

        // Check email uniqueness (exclude current user)
        $check = $db->prepare("SELECT id FROM users WHERE email = :email AND id != :id");
        $check->execute([':email' => $email, ':id' => $id]);
        if ($check->fetch()) {
            http_response_code(409);
            echo json_encode(['error' => 'Another user with this email already exists']);
            exit;
        }

        if ($role) {
            $db->prepare("UPDATE users SET name = :name, email = :email, role = :role WHERE id = :id")
               ->execute([':name' => $name, ':email' => $email, ':role' => $role, ':id' => $id]);
        } else {
            $db->prepare("UPDATE users SET name = :name, email = :email WHERE id = :id")
               ->execute([':name' => $name, ':email' => $email, ':id' => $id]);
        }

        echo json_encode(['success' => true, 'message' => 'User updated']);
        exit;
    }

    // ─── RESET PASSWORD ───
    if ($method === 'POST' && $action === 'reset_password') {
        $input = json_decode(file_get_contents('php://input'), true);
        $id       = (int) ($input['id'] ?? 0);
        $password = $input['new_password'] ?? '';

        if (!$id || !$password) {
            http_response_code(400);
            echo json_encode(['error' => 'User ID and new password are required']);
            exit;
        }

        if (strlen($password) < 8) {
            http_response_code(400);
            echo json_encode(['error' => 'Password must be at least 8 characters']);
            exit;
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $db->prepare("UPDATE users SET password_hash = :hash WHERE id = :id")
           ->execute([':hash' => $hash, ':id' => $id]);

        echo json_encode(['success' => true, 'message' => 'Password reset successfully']);
        exit;
    }

    // ─── TOGGLE ACTIVE ───
    if ($method === 'PUT' && $action === 'toggle_active') {
        $input = json_decode(file_get_contents('php://input'), true);
        $id       = (int) ($input['id'] ?? 0);
        $isActive = (int) ($input['is_active'] ?? 0);

        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'User ID is required']);
            exit;
        }

        // Prevent admin from deactivating themselves
        if ($id === getCurrentUserId() && $isActive === 0) {
            http_response_code(400);
            echo json_encode(['error' => 'You cannot deactivate your own account']);
            exit;
        }

        $db->prepare("UPDATE users SET is_active = :active WHERE id = :id")
           ->execute([':active' => $isActive, ':id' => $id]);

        echo json_encode(['success' => true, 'message' => $isActive ? 'User activated' : 'User deactivated']);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action: ' . $action]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
