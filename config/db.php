<?php
require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        // PostgreSQL (Supabase) — ported from MySQL. Use Supabase's "Session
        // pooler" or "Direct connection" string, NOT the "Transaction
        // pooler" (port 6543): this app runs with PDO::ATTR_EMULATE_PREPARES
        // = false (real server-side prepared statements), which PgBouncer's
        // transaction-pooling mode does not reliably support. Session
        // pooler/direct connection give each request's PDO connection a
        // real dedicated backend for its lifetime, same as talking to MySQL
        // directly did.
        $dsn = 'pgsql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';sslmode=require';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            // Send each query and its parameter values in ONE round trip
            // (PQexecParams) instead of prepare + execute + DEALLOCATE — three
            // network round trips per statement otherwise. Parameters are still
            // bound server-side (no string interpolation), so it is exactly as
            // safe; only named server-side prepared statements are given up,
            // which this app never reuses (every statement is prepared, run
            // once and discarded). With a database ~100 ms away that was
            // roughly half of every page's time.
            PDO::PGSQL_ATTR_DISABLE_PREPARES => true,
        ]);
        // Match Postgres's own session clock to Asia/Bangkok — the app's PHP
        // side is already pinned there (config.php's
        // date_default_timezone_set), but Postgres's NOW()/CURRENT_TIMESTAMP
        // follow whatever timezone the connection is configured with
        // (commonly UTC on a managed host like Supabase). Without this, a
        // value Postgres stamps with NOW() (e.g. admins.locked_until) and
        // the same value read back and interpreted by PHP's strtotime()
        // disagree by the server's UTC offset — up to several hours off.
        // (MySQL's equivalent was `SET time_zone = '+07:00'` — Postgres
        // additionally accepts the IANA zone name directly, which is used
        // here since it's self-documenting and Thailand has no DST to worry
        // about either way.)
        $pdo->exec("SET TIME ZONE 'Asia/Bangkok'");
    }
    return $pdo;
}
