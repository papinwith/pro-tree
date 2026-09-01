<?php
require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        // Match MySQL's own session clock to Asia/Bangkok — the app's PHP
        // side is already pinned there (config.php's
        // date_default_timezone_set), but MySQL's NOW()/CURRENT_TIMESTAMP
        // follow whatever timezone the server itself is configured with
        // (commonly UTC). Without this, a value MySQL stamps with NOW()
        // (e.g. admins.locked_until) and the same value read back and
        // interpreted by PHP's strtotime() disagree by the server's UTC
        // offset — up to several hours off.
        $pdo->exec("SET time_zone = '+07:00'");
    }
    return $pdo;
}
