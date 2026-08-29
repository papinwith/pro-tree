<?php
// Copy this file to `local.php` (same folder) and fill in your real keys.
// local.php is gitignored — it never gets committed, so your key stays
// out of version control and off this machine only.
//
//   cp config/local.example.php config/local.php
//
// Then edit config/local.php and paste your key on the line below.

if (!defined('GEMINI_API_KEY')) {
    define('GEMINI_API_KEY', 'paste-your-gemini-api-key-here');
}

// Optional — only uncomment if you want a model other than the default
// set in config/config.php.
// if (!defined('GEMINI_MODEL')) {
//     define('GEMINI_MODEL', 'gemini-2.0-flash');
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
