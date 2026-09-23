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
        // Only mark the cookie Secure when the request actually arrived over
        // HTTPS — hardcoding true would break local XAMPP dev over plain
        // HTTP (the browser silently drops a Secure cookie on a non-HTTPS
        // origin), and hardcoding false would let the session cookie travel
        // over plain HTTP on a real deployment. $_SERVER['HTTPS']/SERVER_PORT
        // alone miss the common single-box reverse-proxy setup (nginx/Apache
        // terminates TLS, proxies to PHP-FPM over plain HTTP) — PHP would see
        // that as an ordinary HTTP request and drop Secure even though the
        // visitor is genuinely on https://; X-Forwarded-Proto is what the
        // proxy sets to say so. Only trusted when TRUST_PROXY is on
        // (config/config.php) — X-Forwarded-Proto is an ordinary
        // client-settable header on any deployment with no real proxy in
        // front, and trusting it there would let a spoofed "https" mark this
        // cookie Secure over plain HTTP, silently logging the admin out.
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['SERVER_PORT'] ?? null) == 443
            || (TRUST_PROXY && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        // Also raise server-side GC's own idea of session lifetime to match
        // — php.ini's session.gc_maxlifetime defaults to 1440s (24 min) on
        // most installs, which would let an idle admin's session file get
        // garbage-collected (forcing a surprise re-login) long before the
        // midnight cutoff the cookie above promises.
        $secondsUntilMidnight = secondsUntilBangkokMidnight();
        ini_set('session.gc_maxlifetime', (string) $secondsUntilMidnight);
        session_set_cookie_params([
            'lifetime' => $secondsUntilMidnight,
            'path' => '/',
            'domain' => '',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

/**
 * Every admin is logged out together at midnight Bangkok time, not on a
 * rolling N-hours-since-login basis — the cookie's own expiry is a first
 * pass, but browsers don't always honor it precisely (an already-open tab
 * keeps sending a cookie the OS hasn't purged yet), so this is the actual
 * enforcement: session data itself is wiped the first request after the
 * date (Asia/Bangkok) has rolled over from the one recorded at login.
 */
function secondsUntilBangkokMidnight(): int
{
    $tz = new DateTimeZone('Asia/Bangkok');
    $now = new DateTime('now', $tz);
    $nextMidnight = (new DateTime('tomorrow', $tz));
    return max(1, $nextMidnight->getTimestamp() - $now->getTimestamp());
}

function bangkokToday(): string
{
    return (new DateTime('now', new DateTimeZone('Asia/Bangkok')))->format('Y-m-d');
}

function adminLoggedIn(): bool
{
    startAdminSession();
    if (!empty($_SESSION['admin_id']) && ($_SESSION['admin_session_day'] ?? null) !== bangkokToday()) {
        adminLogout();
        return false;
    }
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
 * One CSRF token per session, not per form — regenerating per-form breaks
 * back/forward navigation and multiple tabs open on different admin forms
 * at once. Session-scoped is the standard tradeoff for a same-site admin
 * panel like this one.
 */
function csrfToken(): string
{
    startAdminSession();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Hidden input to drop inside every <form method="post">. */
function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrfToken()) . '">';
}

/**
 * Call as the first line of every POST handler in admin/*.php, before
 * touching $_POST for anything else. Rejects the request outright rather
 * than falling through, so a missing/forged token can't reach any DB write.
 */
function requireCsrf(): void
{
    startAdminSession();
    $submitted = $_POST['csrf_token'] ?? '';
    if (!is_string($submitted) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $submitted)) {
        http_response_code(400);
        echo 'คำขอไม่ถูกต้องหรือหมดอายุ (CSRF token ไม่ถูกต้อง) กรุณาย้อนกลับและลองใหม่';
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
    $_SESSION['admin_session_day'] = bangkokToday();
    return true;
}

const LOGIN_LOCKOUT_THRESHOLD = 5;
const LOGIN_LOCKOUT_MINUTES = 15;

/**
 * Deliberately identical for "no such username", "wrong password", and
 * "account is currently locked" — a distinct lockout message would confirm
 * a guessed username is real just by watching which text comes back
 * (classic enumeration via account-lockout side channel), and an exact
 * remaining-minutes figure would be its own smaller leak on top of that.
 * The trade-off: a legitimately locked-out admin doesn't get an exact wait
 * time either, just "later" — acceptable here since they still have other
 * ways to notice (the failed attempts were theirs).
 */
const LOGIN_GENERIC_ERROR = 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง หรือบัญชีถูกล็อกชั่วคราวจากการเข้าสู่ระบบผิดพลาดหลายครั้ง กรุณาลองใหม่อีกครั้งในภายหลัง';

/**
 * Unlimited password guesses against a known username was the gap here —
 * lock the account for LOGIN_LOCKOUT_MINUTES after LOGIN_LOCKOUT_THRESHOLD
 * consecutive failures. Counts per-account (not per-IP): simpler, needs no
 * extra table, and still stops the realistic threat (guessing one admin's
 * password) even though it can't stop someone spraying many usernames from
 * one IP — that's a smaller risk here since usernames aren't public.
 *
 * One SELECT does both the lock check and the credential fetch (not two
 * separate queries), and the failure path is a single atomic UPDATE rather
 * than a read-modify-write — a read-then-write here would let concurrent
 * guesses race the counter and take far more than LOGIN_LOCKOUT_THRESHOLD
 * tries to actually lock. The new failed_login_attempts value is computed
 * with the same CASE expression in both SET clauses (rather than having
 * `locked_until` reference the other clause's result) because — unlike
 * MySQL, which evaluates a single-table UPDATE's SET clauses left to right
 * so a later one can see an earlier one's just-written value — Postgres
 * evaluates every SET expression against the pre-update row, so
 * `locked_until` would otherwise check the OLD attempt count and lock the
 * account one attempt later than intended.
 *
 * Returns null on success (session is set up); otherwise LOGIN_GENERIC_ERROR
 * for admin/login.php to show as-is.
 */
function attemptAdminLogin(PDO $pdo, string $username, string $password): ?string
{
    $stmt = $pdo->prepare(
        'SELECT id, password_hash, role_id, failed_login_attempts, locked_until FROM admins WHERE username = :u'
    );
    $stmt->execute(['u' => $username]);
    $admin = $stmt->fetch();

    // Reaching the code below (the lock check didn't already return) means
    // any locked_until on this row is either NULL or already in the past —
    // so a lock that just expired is a fresh start, not "5 more on top of
    // the old count": otherwise a single mistyped password any time after
    // the 15 minutes is up re-locks the account for another 15 immediately,
    // forever, since the stale counter was already sitting at the threshold.
    $priorLockExpired = (bool) ($admin['locked_until'] ?? null);
    if ($admin && $admin['locked_until'] && strtotime($admin['locked_until']) > time()) {
        return LOGIN_GENERIC_ERROR;
    }

    if ($admin && password_verify($password, $admin['password_hash'])) {
        if ((int) $admin['failed_login_attempts'] > 0 || $admin['locked_until']) {
            $pdo->prepare('UPDATE admins SET failed_login_attempts = 0, locked_until = NULL WHERE id = :id')
                ->execute(['id' => $admin['id']]);
        }
        startAdminSession();
        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int) $admin['id'];
        $_SESSION['admin_username'] = $username;
        $_SESSION['admin_role_id'] = (int) $admin['role_id'];
        $_SESSION['admin_session_day'] = bangkokToday();
        return null;
    }

    if ($admin) {
        $pdo->prepare(
            'UPDATE admins
             SET failed_login_attempts = CASE WHEN :priorLockExpired = 1 THEN 1 ELSE failed_login_attempts + 1 END,
                 locked_until = CASE
                                     WHEN (CASE WHEN :priorLockExpired2 = 1 THEN 1 ELSE failed_login_attempts + 1 END) >= :threshold
                                     THEN NOW() + (INTERVAL \'1 minute\' * :minutes)
                                     ELSE NULL
                                 END
             WHERE id = :id'
        )->execute([
            'priorLockExpired' => $priorLockExpired ? 1 : 0,
            // Same value as priorLockExpired, bound under its own name rather
            // than reused — PDO's native (non-emulated) prepare for pgsql
            // doesn't reliably support one named placeholder appearing twice
            // in the same query.
            'priorLockExpired2' => $priorLockExpired ? 1 : 0,
            'threshold' => LOGIN_LOCKOUT_THRESHOLD,
            'minutes' => LOGIN_LOCKOUT_MINUTES,
            'id' => $admin['id'],
        ]);
    }
    return LOGIN_GENERIC_ERROR;
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
