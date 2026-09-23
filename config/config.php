<?php
// Machine-specific overrides (API keys, DB credentials, local dev flags)
// live in config/local.php (gitignored, never committed) — copy
// config/local.example.php to get started. Loaded FIRST, before any
// define() below, so every setting in this file can be overridden by it via
// the same `if (!defined(...))` guard every constant below uses. A real
// environment variable works too and is checked as the next fallback.
$localConfigFile = __DIR__ . '/local.php';
if (is_file($localConfigFile)) {
    require_once $localConfigFile;
}

// Database connection settings — PostgreSQL (Supabase). Get
// HOST/PORT/USER/PASS from Supabase's Project Settings -> Database ->
// Connection string -> "Session pooler" tab (see config/db.php for why not
// "Transaction pooler").
if (!defined('DB_HOST')) {
    define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
}
if (!defined('DB_PORT')) {
    define('DB_PORT', getenv('DB_PORT') ?: '5432');
}
if (!defined('DB_NAME')) {
    define('DB_NAME', getenv('DB_NAME') ?: 'postgres');
}
if (!defined('DB_USER')) {
    define('DB_USER', getenv('DB_USER') ?: 'postgres');
}
if (!defined('DB_PASS')) {
    define('DB_PASS', getenv('DB_PASS') ?: '');
}

// Visitor identity cookie
define('VISITOR_COOKIE_NAME', 'tree_visitor_id');
define('VISITOR_COOKIE_TTL', 60 * 60 * 24 * 365 * 2); // 2 years

// Whether X-Forwarded-Proto/-For/-Host/-Real-IP can be trusted as coming
// from a real reverse proxy in front of this app, rather than directly from
// the client. Off by default: on a deployment with no proxy (this project's
// default XAMPP setup included), those are ordinary client-supplied HTTP
// headers anyone can send — trusting them unconditionally would let a
// spoofed X-Forwarded-Proto: https mark the admin session cookie Secure
// over a genuinely plain-HTTP connection, and the browser then silently
// drops that cookie, logging the admin out right after login. Set
// TRUST_PROXY=1 only on a deployment where a real reverse proxy sets (and
// strips any client-supplied copy of) these headers before forwarding.
if (!defined('TRUST_PROXY')) {
    define('TRUST_PROXY', getenv('TRUST_PROXY') === '1');
}

// Google Gemini AI translation — server-side only, never exposed to the
// browser and never stored in the database. Feature auto-disables (falls
// back to manual entry) when no key is configured, so nothing breaks in
// environments without one. Put your real key in config/local.php (loaded
// at the top of this file) — copy config/local.example.php to get started.
// A real environment variable (GEMINI_API_KEY) works too and is checked as
// a fallback.

// Base URL of the app, used for building QR target links (no trailing slash).
// It gets baked into every QR PNG, so it must be the address visitors' phones
// can actually reach — never localhost on a deployed/tunnelled setup. Override
// it in config/local.php or the APP_BASE_URL environment variable.
if (!defined('APP_BASE_URL')) {
    define('APP_BASE_URL', getenv('APP_BASE_URL') ?: 'http://localhost/tree-siam-main/public');
}

if (!defined('GEMINI_API_KEY')) {
    define('GEMINI_API_KEY', getenv('GEMINI_API_KEY') ?: '');
}
if (!defined('GEMINI_MODEL')) {
    define('GEMINI_MODEL', getenv('GEMINI_MODEL') ?: 'gemini-3.6-flash');
}
define('AI_ENABLED', GEMINI_API_KEY !== '');

// Forces the Gemini API call onto IPv4 — a workaround for hosts/networks
// where outbound IPv6 to generativelanguage.googleapis.com is misconfigured
// or blackholed (curl hangs the full timeout trying IPv6 first otherwise).
// Off by default: forcing IPv4 unconditionally would be actively worse on a
// host where IPv6 works fine but IPv4 is the restricted/slower path. Set
// GEMINI_FORCE_IPV4=1 in the environment only on hosts that need it.
if (!defined('GEMINI_FORCE_IPV4')) {
    define('GEMINI_FORCE_IPV4', getenv('GEMINI_FORCE_IPV4') === '1');
}

// Dev-only login bypass — skip typing a password while testing locally.
// OFF by default. Set in config/local.php (gitignored), never here, never
// as a real env var on a shared/production host. Even when turned on,
// includes/auth.php double-checks the request is actually coming from
// localhost before honoring it — see devLoginBypassAllowed().
if (!defined('DEV_LOGIN_BYPASS')) {
    define('DEV_LOGIN_BYPASS', false);
}

// Error display — safe by default: a real deployment (any request that
// isn't literally CLI or localhost) never shows a raw PHP error/stack
// trace to the browser, no matter what php.ini on that host happens to
// have display_errors set to. Local XAMPP dev keeps seeing errors inline
// exactly as before, with no config needed — set APP_ENV=local in
// config/local.php (gitignored) to force verbose errors from a non-localhost
// address too (e.g. testing over a LAN IP), or APP_ENV=production to force
// them off even from localhost.
if (!defined('APP_ENV')) {
    // REMOTE_ADDR alone isn't safe here: a reverse proxy (nginx/Apache in
    // front of PHP-FPM on the same box — common on a single VPS) makes
    // every real visitor look like 127.0.0.1 to PHP. A proxied request
    // always carries one of these forwarding headers; a genuine local
    // browser request never does, so their presence rules out "local"
    // even when REMOTE_ADDR says loopback.
    $isProxied = isset($_SERVER['HTTP_X_FORWARDED_FOR'])
        || isset($_SERVER['HTTP_X_FORWARDED_HOST'])
        || isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
        || isset($_SERVER['HTTP_X_REAL_IP']);
    $isLocalRequest = PHP_SAPI === 'cli'
        || (!$isProxied && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true));
    define('APP_ENV', getenv('APP_ENV') ?: ($isLocalRequest ? 'local' : 'production'));
}

if (APP_ENV === 'local') {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL);
    set_exception_handler(function (Throwable $e): void {
        error_log($e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString());
        if (!headers_sent()) {
            http_response_code(500);
        }
        echo 'เกิดข้อผิดพลาดของระบบ กรุณาลองใหม่อีกครั้ง หรือแจ้งผู้ดูแลระบบ';
    });
}

date_default_timezone_set('Asia/Bangkok');
