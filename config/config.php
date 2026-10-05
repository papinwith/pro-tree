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

// Local Ollama AI (translation + photo identification) — server-side only,
// no API key or paid service. Talks to an Ollama server (default
// http://localhost:11434): OLLAMA_MODEL translates (default qwen3:8b — `ollama
// pull qwen3:8b`), OLLAMA_VISION_MODEL identifies trees from photos (default
// qwen2.5vl:7b — `ollama pull qwen2.5vl:7b`). Set OLLAMA_MODEL to an empty
// string to turn all AI features off; if the server is unreachable they fail
// gracefully (translation falls back to Thai). Override in config/local.php
// (loaded at the top of this file; copy config/local.example.php) or via
// environment variables.

// Base URL of the app, used for building QR target links (no trailing slash).
// It gets baked into every QR PNG, so it must be the address visitors' phones
// can actually reach — never localhost on a deployed/tunnelled setup. Override
// it in config/local.php or the APP_BASE_URL environment variable.
if (!defined('APP_BASE_URL')) {
    define('APP_BASE_URL', getenv('APP_BASE_URL') ?: 'http://localhost/tree-siam-main/public');
}

if (!defined('OLLAMA_URL')) {
    define('OLLAMA_URL', rtrim(getenv('OLLAMA_URL') ?: 'http://localhost:11434', '/'));
}
// Shared secret sent as `Authorization: Bearer ...` — set it when Ollama is reached through
// tools/ollama_auth_proxy.py (e.g. a tunnel from a deployed site back to your own machine).
if (!defined('OLLAMA_API_KEY')) {
    define('OLLAMA_API_KEY', (string) (getenv('OLLAMA_API_KEY') ?: ''));
}
// Second opinion: when the local model is not confident enough (below AI_CONFIDENCE_MIN, a
// percentage), the photo is also sent to Gemini and the two answers are compared. Off unless
// GEMINI_API_KEY is set (server-side only, never sent to the browser).
// Pl@ntNet plant-identification API (https://my.plantnet.org) - the preferred "teacher" for a second
// opinion; Gemini is used only when this is not configured or fails. Server-side only.
if (!defined('PLANTNET_API_KEY')) {
    define('PLANTNET_API_KEY', (string) (getenv('PLANTNET_API_KEY') ?: ''));
}
if (!defined('GEMINI_API_KEY')) {
    define('GEMINI_API_KEY', (string) (getenv('GEMINI_API_KEY') ?: ''));
}
if (!defined('GEMINI_MODEL')) {
    define('GEMINI_MODEL', getenv('GEMINI_MODEL') ?: 'gemini-3.5-flash-lite');
}
if (!defined('AI_CONFIDENCE_MIN')) {
    define('AI_CONFIDENCE_MIN', min(100, max(1, (int) (getenv('AI_CONFIDENCE_MIN') ?: 70))));
}
if (!defined('OLLAMA_MODEL')) {
    $ollamaModelEnv = getenv('OLLAMA_MODEL');
    define('OLLAMA_MODEL', $ollamaModelEnv === false ? 'qwen3:8b' : $ollamaModelEnv);
}
if (!defined('OLLAMA_VISION_MODEL')) {
    define('OLLAMA_VISION_MODEL', getenv('OLLAMA_VISION_MODEL') ?: 'qwen2.5vl:7b');
}
define('AI_ENABLED', OLLAMA_MODEL !== '');

// Hard cap, in seconds, on how long one "identify this tree from a photo" request
// may take end to end (checked from the moment the request starts). If the AI
// hasn't answered by then the admin gets a clear "too slow, try again" instead
// of waiting. A local vision model takes tens of seconds, longer on its first
// call while the model loads into memory.
if (!defined('AI_IDENTIFY_MAX_SECONDS')) {
    define('AI_IDENTIFY_MAX_SECONDS', max(2.0, (float) (getenv('AI_IDENTIFY_MAX_SECONDS') ?: 120)));
}

// How long (seconds) the category/subtype/species lists the identify request
// needs are reused from a small cache file instead of re-read from the database.
// Each database query costs ~0.3 s when the database is remote, and the whole
// request has only AI_IDENTIFY_MAX_SECONDS. 0 turns the cache off.
if (!defined('AI_IDENTIFY_CACHE_SECONDS')) {
    $cacheEnv = getenv('AI_IDENTIFY_CACHE_SECONDS');
    define('AI_IDENTIFY_CACHE_SECONDS', $cacheEnv === false ? 600 : max(0, (int) $cacheEnv));
}

// Max "identify this tree from a photo" calls per admin per hour — each one
// occupies the local model for a while, so this stops a stuck/abused button
// from tying up the machine.
if (!defined('AI_IDENTIFY_MAX_PER_HOUR')) {
    define('AI_IDENTIFY_MAX_PER_HOUR', max(1, (int) (getenv('AI_IDENTIFY_MAX_PER_HOUR') ?: 30)));
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
