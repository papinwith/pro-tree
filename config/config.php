<?php
// Database connection settings — adjust for your environment.
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('DB_NAME') ?: 'tree_qr_system');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_CHARSET', 'utf8mb4');

// Visitor identity cookie
define('VISITOR_COOKIE_NAME', 'tree_visitor_id');
define('VISITOR_COOKIE_TTL', 60 * 60 * 24 * 365 * 2); // 2 years

// Base URL of the app, used for building QR target links (no trailing slash).
// The htdocs folder for this project is literally named "pro tree" (with a
// space), so the space must be percent-encoded here or every QR/link built
// from this constant 404s.
define('APP_BASE_URL', getenv('APP_BASE_URL') ?: 'http://localhost/pro%20tree/public');

// Google Gemini AI translation — server-side only, never exposed to the
// browser and never stored in the database. Feature auto-disables (falls
// back to manual entry) when no key is configured, so nothing breaks in
// environments without one.
//
// Put your real key in config/local.php (gitignored, never committed) —
// copy config/local.example.php to get started. A real environment
// variable (GEMINI_API_KEY) works too and is checked as a fallback.
$localConfigFile = __DIR__ . '/local.php';
if (is_file($localConfigFile)) {
    require_once $localConfigFile;
}

if (!defined('GEMINI_API_KEY')) {
    define('GEMINI_API_KEY', getenv('GEMINI_API_KEY') ?: '');
}
if (!defined('GEMINI_MODEL')) {
    define('GEMINI_MODEL', getenv('GEMINI_MODEL') ?: 'gemini-3.6-flash');
}
define('AI_ENABLED', GEMINI_API_KEY !== '');

// Dev-only login bypass — skip typing a password while testing locally.
// OFF by default. Set in config/local.php (gitignored), never here, never
// as a real env var on a shared/production host. Even when turned on,
// includes/auth.php double-checks the request is actually coming from
// localhost before honoring it — see devLoginBypassAllowed().
if (!defined('DEV_LOGIN_BYPASS')) {
    define('DEV_LOGIN_BYPASS', false);
}

date_default_timezone_set('Asia/Bangkok');
