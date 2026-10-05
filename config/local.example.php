<?php
// Copy this file to `local.php` (same folder) to override local settings.
// local.php is gitignored — it never gets committed.
//
//   cp config/local.example.php config/local.php
//
// AI features use a local Ollama server (no API key). The defaults in
// config/config.php work out of the box once the models are pulled
// (`ollama pull qwen3:8b` and `ollama pull qwen2.5vl:7b`) — uncomment only
// to change them.
// if (!defined('OLLAMA_URL')) {
//     define('OLLAMA_URL', 'http://localhost:11434');
// }
// if (!defined('OLLAMA_MODEL')) {
//     define('OLLAMA_MODEL', 'qwen3:8b'); // translation; '' turns all AI off
// }
// if (!defined('OLLAMA_VISION_MODEL')) {
//     define('OLLAMA_VISION_MODEL', 'qwen2.5vl:7b'); // photo identification
// }

// Optional — dev-only login bypass, lets admin/login.php show "log in as
// ___" buttons for every existing admin account instead of typing a
// password, so you can quickly test different roles while developing.
// Only uncomment this on YOUR OWN machine. Never enable it anywhere
// reachable over a network other than localhost — includes/auth.php also
// refuses to honor it for any request that isn't from 127.0.0.1/::1, as a
// second layer of protection in case this ever ends up somewhere it
// shouldn't.
// if (!defined('DEV_LOGIN_BYPASS')) {
//     define('DEV_LOGIN_BYPASS', true);
// }

// Optional — force verbose in-browser PHP errors even when this isn't a
// request from localhost (e.g. testing over a LAN IP/hostname). Requests
// from 127.0.0.1/::1 already get verbose errors automatically with no
// config needed; this is only for the "testing from another device on the
// network" case. Never set this to 'local' on anything reachable from the
// internet — it would leak stack traces (file paths, query text) to anyone
// who can trigger an error.
// if (!defined('APP_ENV')) {
//     define('APP_ENV', 'local');
// }
