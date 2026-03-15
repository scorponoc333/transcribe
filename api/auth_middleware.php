<?php
/**
 * Authentication Middleware
 * Include at top of every API endpoint that requires authentication.
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
