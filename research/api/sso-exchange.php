<?php
/**
 * SSO exchange endpoint — called from hub at app.jasonai.ca.
 *
 * Flow:
 *   1. Hub mints a short-lived HS256 JWT with the user's email/role/tier
 *   2. Hub redirects browser to:
 *        https://transcribe.jasonai.ca/api/sso-exchange.php?t=<jwt>&r=<returnPath>
 *   3. We verify the JWT with the shared JWT_SECRET
 *   4. Find user by email (normalized lowercase); auto-provision if new
 *   5. Mint local PHP session ($_SESSION['user_id'], etc) — same shape as auth.php login
 *   6. Redirect to ?r= or '/'
 *
 * Security: same JWT_SECRET as hub, HS256, constant-time signature compare,
 * exp enforced. Never trusts payload fields beyond what it needs.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/_jwt.php';

function sso_fail(string $msg, int $code = 400): void {
    http_response_code($code);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><meta charset=utf-8><title>Sign in</title>';
    echo '<style>body{background:#0a0e1a;color:#e4ecfa;font:15px system-ui;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}.c{max-width:420px;padding:32px;text-align:center}h1{font-size:18px;font-weight:600;margin:0 0 12px}p{color:#94a3b8;margin:0 0 20px}a{color:#60a5fa;text-decoration:none}</style>';
    echo '<div class=c><h1>Sign-in handoff failed</h1><p>' . htmlspecialchars($msg, ENT_QUOTES) . '</p><p><a href="/login.php">Sign in directly</a></p></div>';
    exit;
}

function sso_redirect(string $url): void {
    header('Location: ' . $url, true, 302);
    exit;
}

// ─── Validate input ────────────────────────────────────────────────────
$token  = trim((string)($_GET['t'] ?? ''));
$return = (string)($_GET['r'] ?? '/');
// Only allow same-site return paths
if (!preg_match('#^/[^/]#', $return) && $return !== '/') $return = '/';
if ($token === '') sso_fail('Missing token.');

// ─── Verify JWT ────────────────────────────────────────────────────────
$secret = cfg('JWT_SECRET', '');
if ($secret === '') sso_fail('Server not configured for SSO.', 500);

$payload = jwt_verify($token, $secret);
if (!$payload) sso_fail('Invalid or expired sign-in link.');

$email = strtolower(trim((string)($payload['email'] ?? '')));
$name  = trim((string)($payload['display_name'] ?? $payload['name'] ?? ''));
$role  = strtolower(trim((string)($payload['role'] ?? 'user')));
$tier  = strtolower(trim((string)($payload['tier'] ?? 'trial')));
$isOwner = ($role === 'owner');

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) sso_fail('Token missing a valid email.');
if ($name === '') $name = strtok($email, '@');

// ─── Find or provision local user ──────────────────────────────────────
$pdo = getDB();
$stmt = $pdo->prepare("SELECT id, name, email, role, organization_id, is_active, is_super_admin FROM users WHERE email = ? LIMIT 1");
$stmt->execute([$email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    // Auto-provision: new org + new admin user tied to that org.
    // Map hub tier → transcribe org plan (coarse).
    $planMap = ['personal'=>'solo', 'professional'=>'team', 'enterprise'=>'enterprise', 'comp'=>'enterprise'];
    $plan = $planMap[$tier] ?? 'trial';

    // Generate a unique org slug
    $slugBase = preg_replace('#[^a-z0-9]+#', '-', strtolower($name));
    $slugBase = trim($slugBase, '-') ?: 'org';
    $slug = substr($slugBase, 0, 50) . '-' . substr(bin2hex(random_bytes(3)), 0, 6);

    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO organizations (name, slug, plan, seats_included, seats_used) VALUES (?, ?, ?, 1, 1)")
            ->execute([$name . "'s Team", $slug, $plan]);
        $orgId = (int)$pdo->lastInsertId();

        $randPwd = bin2hex(random_bytes(24));
        $hash    = password_hash($randPwd, PASSWORD_BCRYPT);
        $pdo->prepare("
            INSERT INTO users
                (organization_id, name, email, password_hash, role, role_in_org, is_super_admin, is_active)
            VALUES (?, ?, ?, ?, 'admin', 'admin', ?, 1)
        ")->execute([$orgId, $name, $email, $hash, $isOwner ? 1 : 0]);
        $userId = (int)$pdo->lastInsertId();

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        sso_fail('Could not provision your account: ' . $e->getMessage(), 500);
    }

    $user = [
        'id'              => $userId,
        'name'            => $name,
        'email'           => $email,
        'role'            => 'admin',
        'organization_id' => $orgId,
        'is_active'       => 1,
        'is_super_admin'  => $isOwner ? 1 : 0,
    ];
}

// Block inactive
if ((int)$user['is_active'] !== 1) sso_fail('This account is inactive.', 403);

// ─── Mint local session (mirrors /api/auth.php:106–110) ─────────────────
$_SESSION['user_id']    = (int)$user['id'];
$_SESSION['user_name']  = $user['name'];
$_SESSION['user_email'] = $user['email'];
$_SESSION['user_role']  = $user['role'];

try {
    $pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?")->execute([(int)$user['id']]);
} catch (Throwable $e) { /* non-fatal */ }

sso_redirect($return);
