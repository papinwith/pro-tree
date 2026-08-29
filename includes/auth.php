<?php
require_once __DIR__ . '/functions.php';

function startAdminSession(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_name('tree_admin_sess');
        // Set explicitly rather than relying on php.ini — a blank/default
        // SameSite or cookie_path there is what let this cookie silently
        // fail to round-trip in some browsers, forcing a fresh login on
        // every single navigation.
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => false,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function adminLoggedIn(): bool
{
    startAdminSession();
    return !empty($_SESSION['admin_id']);
}

function requireAdmin(): void
{
    if (!adminLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

/**
 * Deny-by-default permission check (see docs/rbac.md). Queried fresh
 * against role_permissions on every call rather than cached in session —
 * these are small tables (a few dozen rows total) and this keeps a role
 * change (via admin.manage) take effect on the admin's very next request
 * instead of needing a session-invalidation mechanism.
 */
function can(string $permissionKey): bool
{
    if (empty($_SESSION['admin_role_id'])) {
        return false;
    }
    $stmt = db()->prepare(
        'SELECT 1 FROM role_permissions rp
         JOIN permissions p ON p.id = rp.permission_id
         WHERE rp.role_id = :role_id AND p.permission_key = :key
         LIMIT 1'
    );
    $stmt->execute(['role_id' => $_SESSION['admin_role_id'], 'key' => $permissionKey]);
    return (bool) $stmt->fetchColumn();
}

/**
 * Gates a page/action to a specific permission (e.g.
 * requirePermission('tree.create')). Must be the first check in any
 * admin/*.php or api/*.php script that touches protected data — frontend
 * button-hiding is UX only, this is the actual security boundary. Sends a
 * plain 403 rather than redirecting, since the visitor is already logged
 * in and a login redirect would just loop.
 */
function requirePermission(string $permissionKey): void
{
    requireAdmin();
    if (!can($permissionKey)) {
        http_response_code(403);
        echo 'Forbidden — your role does not have access to this page.';
        exit;
    }
}

/**
 * True only when DEV_LOGIN_BYPASS is on AND the request is actually
 * arriving from localhost — a second, independent check so a stray
 * DEV_LOGIN_BYPASS=true in the wrong place (an env var leaked to a real
 * host, a copy-pasted config/local.php) still can't be used to skip a
 * password from anywhere else on the network.
 */
function devLoginBypassAllowed(): bool
{
    if (!DEV_LOGIN_BYPASS) {
        return false;
    }
    return in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
}

/**
 * Dev-only: logs in as an existing admin without checking a password.
 * Never reachable unless devLoginBypassAllowed() is true — callers
 * (admin/login.php) must check that themselves before offering this at
 * all, but this function re-checks it too rather than trusting the caller.
 */
function devBypassLogin(PDO $pdo, int $adminId): bool
{
    if (!devLoginBypassAllowed()) {
        return false;
    }
    $stmt = $pdo->prepare('SELECT id, username, role_id FROM admins WHERE id = :id');
    $stmt->execute(['id' => $adminId]);
    $admin = $stmt->fetch();
    if (!$admin) {
        return false;
    }

    startAdminSession();
    session_regenerate_id(true);
    $_SESSION['admin_id'] = (int) $admin['id'];
    $_SESSION['admin_username'] = $admin['username'];
    $_SESSION['admin_role_id'] = (int) $admin['role_id'];
    return true;
}

function attemptAdminLogin(PDO $pdo, string $username, string $password): bool
{
    $stmt = $pdo->prepare('SELECT id, password_hash, role_id FROM admins WHERE username = :u');
    $stmt->execute(['u' => $username]);
    $admin = $stmt->fetch();

    if ($admin && password_verify($password, $admin['password_hash'])) {
        startAdminSession();
        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int) $admin['id'];
        $_SESSION['admin_username'] = $username;
        $_SESSION['admin_role_id'] = (int) $admin['role_id'];
        return true;
    }
    return false;
}

function adminLogout(): void
{
    startAdminSession();
    $_SESSION = [];

    // Also expire the cookie itself, not just the server-side session data —
    // session_destroy() alone leaves the old session-id cookie sitting in
    // the browser, which is unnecessary residue once the session is gone.
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }

    session_destroy();
}

/**
 * Which admin page to land on after login (or link "home" to from the nav)
 * — not everyone has tree.view (e.g. Executive doesn't), so hardcoding
 * dashboard.php everywhere would 403 that role the instant they log in.
 * Falls back to dashboard.php if a role somehow has none of these —
 * that page's own requirePermission() is still the real gate either way.
 */
function adminHomeUrl(): string
{
    if (can('tree.view')) return 'dashboard.php';
    if (can('dashboard.view')) return 'executive_dashboard.php';
    if (can('reports.export')) return 'reports.php';
    if (can('interest.view')) return 'interests.php';
    return 'dashboard.php';
}

/** Current admin's role_key/name_th, for display (e.g. "signed in as Programmer"). */
function currentAdminRole(PDO $pdo): ?array
{
    if (empty($_SESSION['admin_role_id'])) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT role_key, name_th, name_en FROM roles WHERE id = :id');
    $stmt->execute(['id' => $_SESSION['admin_role_id']]);
    return $stmt->fetch() ?: null;
}
