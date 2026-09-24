<?php
// TEMPORARY diagnostic page — checks outbound connectivity from this
// container to Supabase, to debug a Railway deploy that times out on every
// request (502 after ~15s, matching a hung DB connection attempt).
// Output is flushed line-by-line (not buffered) so partial results still
// reach the client even if the whole script would otherwise run past
// Railway's proxy timeout.
// DELETE THIS FILE once the deploy is confirmed working — it reveals
// network/config details that shouldn't stay publicly reachable.

header('Content-Type: text/plain; charset=utf-8');
header('X-Accel-Buffering: no');
while (ob_get_level()) {
    ob_end_flush();
}
ob_implicit_flush(true);

function out(string $line): void
{
    echo $line . "\n";
    if (function_exists('flush')) {
        flush();
    }
}

out("PHP version: " . PHP_VERSION);
out("Loaded pdo drivers: " . implode(', ', PDO::getAvailableDrivers()));
out("");

require_once __DIR__ . '/../config/config.php';

out("DB_HOST = " . DB_HOST);
out("DB_PORT = " . DB_PORT);
out("DB_NAME = " . DB_NAME);
out("DB_USER = " . DB_USER);
out("DB_PASS = " . (DB_PASS !== '' ? '(set, ' . strlen(DB_PASS) . ' chars)' : '(EMPTY)'));
out("");

function testSocket(string $label, string $host, int $port, int $timeout = 3): void
{
    out("... testing $label ($host:$port), timeout {$timeout}s");
    $start = microtime(true);
    $conn = @fsockopen($host, $port, $errno, $errstr, $timeout);
    $elapsed = round(microtime(true) - $start, 2);
    if ($conn) {
        out("[OK]   $label — connected in {$elapsed}s");
        fclose($conn);
    } else {
        out("[FAIL] $label — errno=$errno errstr=$errstr — took {$elapsed}s");
    }
}

out("--- Raw TCP connectivity tests ---");
testSocket('Google DNS (sanity check: any outbound network at all?)', '8.8.8.8', 53);
testSocket('Supabase session pooler (configured DB_HOST/DB_PORT)', DB_HOST, (int) DB_PORT);
testSocket('Supabase transaction pooler (alt port 6543)', DB_HOST, 6543);
testSocket('Supabase direct connection host', 'db.dcamfqrgouypsprdhsfb.supabase.co', 5432);

out("");
out("--- PDO connection attempt (what the real app does), timeout 3s ---");
$start = microtime(true);
try {
    $dsn = 'pgsql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';sslmode=require';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 3,
    ]);
    $elapsed = round(microtime(true) - $start, 2);
    out("[OK] PDO connected in {$elapsed}s. Server version: " . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION));
} catch (Throwable $e) {
    $elapsed = round(microtime(true) - $start, 2);
    out("[FAIL] PDO connection failed after {$elapsed}s: " . $e->getMessage());
}

out("");
out("DONE.");
