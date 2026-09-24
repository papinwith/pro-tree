<?php
// TEMPORARY diagnostic page — checks outbound connectivity from this
// container to Supabase, to debug a Railway deploy that times out on every
// request (502 after ~15s, matching a hung DB connection attempt).
// DELETE THIS FILE once the deploy is confirmed working — it reveals
// network/config details that shouldn't stay publicly reachable.

header('Content-Type: text/plain; charset=utf-8');

echo "PHP version: " . PHP_VERSION . "\n";
echo "Loaded pdo drivers: " . implode(', ', PDO::getAvailableDrivers()) . "\n\n";

require_once __DIR__ . '/../config/config.php';

echo "DB_HOST = " . DB_HOST . "\n";
echo "DB_PORT = " . DB_PORT . "\n";
echo "DB_NAME = " . DB_NAME . "\n";
echo "DB_USER = " . DB_USER . "\n";
echo "DB_PASS = " . (DB_PASS !== '' ? '(set, ' . strlen(DB_PASS) . ' chars)' : '(EMPTY)') . "\n\n";

function testSocket(string $label, string $host, int $port, int $timeout = 5): void
{
    $start = microtime(true);
    $conn = @fsockopen($host, $port, $errno, $errstr, $timeout);
    $elapsed = round(microtime(true) - $start, 2);
    if ($conn) {
        echo "[OK]   $label ($host:$port) — connected in {$elapsed}s\n";
        fclose($conn);
    } else {
        echo "[FAIL] $label ($host:$port) — errno=$errno errstr=$errstr — took {$elapsed}s\n";
    }
}

echo "--- Raw TCP connectivity tests ---\n";
testSocket('Google DNS (sanity check: any outbound network at all?)', '8.8.8.8', 53);
testSocket('Supabase session pooler (configured DB_HOST/DB_PORT)', DB_HOST, (int) DB_PORT);
testSocket('Supabase transaction pooler (alt port 6543)', DB_HOST, 6543);
testSocket('Supabase direct connection host (db.<ref>.supabase.co:5432)', 'db.dcamfqrgouypsprdhsfb.supabase.co', 5432);

echo "\n--- PDO connection attempt (what the real app does) ---\n";
$start = microtime(true);
try {
    $dsn = 'pgsql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';sslmode=require';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 8,
    ]);
    $elapsed = round(microtime(true) - $start, 2);
    echo "[OK] PDO connected in {$elapsed}s. Server version: " . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . "\n";
} catch (Throwable $e) {
    $elapsed = round(microtime(true) - $start, 2);
    echo "[FAIL] PDO connection failed after {$elapsed}s: " . $e->getMessage() . "\n";
}
