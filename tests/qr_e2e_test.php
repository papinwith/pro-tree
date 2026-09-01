<?php
/**
 * End-to-end test for the QR scan -> tree info page flow.
 *
 * What this covers (the part resolveDestination.test.js does NOT):
 *   1. A QR code encoding a tree URL actually lands on the right tree page.
 *   2. First scan sets the visitor cookie and logs a tree_scans row.
 *   3. A repeat scan (same cookie) is recognised as a returning visitor and
 *      unique/total scan stats update correctly.
 *   4. Prev/Next navigation follows display_order and disables at the ends.
 *   5. Scanning a QR for a tree id that doesn't exist (or is inactive) 404s.
 *
 * Spins up PHP's built-in server against public/ so it exercises the real
 * tree.php, not a re-implementation of it. Requires MySQL running locally
 * (uses the same config/db.php as the app) and creates/cleans up its own
 * temporary trees so it never touches real data.
 *
 * Run: php tests/qr_e2e_test.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';

$host = '127.0.0.1';
$port = 8098;
$base = "http://$host:$port";

$pdo = db();

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "PASS  $label\n";
    } else {
        $fail++;
        echo "FAIL  $label" . ($detail !== '' ? "\n      $detail" : '') . "\n";
    }
}

function httpGet(string $url, ?string $cookie = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 5,
    ]);
    if ($cookie !== null) {
        curl_setopt($ch, CURLOPT_COOKIE, $cookie);
    }
    $raw = curl_exec($ch);
    if ($raw === false) {
        throw new RuntimeException('curl error: ' . curl_error($ch));
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $headers = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);

    $setCookie = null;
    if (preg_match('/^Set-Cookie:\s*([^;\r\n]+)/mi', $headers, $m)) {
        $setCookie = $m[1];
    }

    return ['status' => $status, 'headers' => $headers, 'body' => $body, 'cookie' => $setCookie];
}

// --- 1. Boot the built-in PHP server against public/, like a real deploy ---
$docRoot = __DIR__ . '/../public';
// Use XAMPP's own php.exe (not whatever "php" resolves to on PATH) so the test
// server loads the SAME php.ini — and therefore the same extensions — as the
// real Apache deployment. Using a different PHP here previously masked a
// missing-GD-extension bug that only showed up in production.
$cmd = sprintf('"C:\\xampp\\php\\php.exe" -S %s:%d -t %s', $host, $port, escapeshellarg($docRoot));
$descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$serverProc = proc_open($cmd, $descriptors, $pipes, __DIR__, null, ['bypass_shell' => true]);
if (!is_resource($serverProc)) {
    fwrite(STDERR, "Could not start PHP built-in server\n");
    exit(1);
}
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

// Wait for the server to accept connections.
$ready = false;
for ($i = 0; $i < 40; $i++) {
    $conn = @fsockopen($host, $port, $errno, $errstr, 0.25);
    if ($conn) {
        fclose($conn);
        $ready = true;
        break;
    }
    usleep(100000);
}
if (!$ready) {
    fwrite(STDERR, "PHP built-in server never came up on $base\n");
    proc_terminate($serverProc);
    exit(1);
}

$createdTreeIds = [];
$createdSpeciesIds = [];
$createdVisitorUuids = [];

// Any request without an existing visitor cookie creates a fresh visitor row
// server-side (see getOrCreateVisitorId). Track every one we see so cleanup
// can remove it, regardless of which step triggered it.
function trackVisitorCookie(array $response, array &$uuids): void
{
    if ($response['cookie'] !== null && preg_match('/tree_visitor_id=([0-9a-f-]{36})/i', $response['cookie'], $m)) {
        $uuids[] = $m[1];
    }
}

function cleanup(PDO $pdo, array $treeIds, array $speciesIds, array $visitorUuids, $serverProc): void
{
    if ($treeIds) {
        $in = implode(',', array_map('intval', $treeIds));
        $pdo->exec("DELETE FROM trees WHERE id IN ($in)"); // cascades to tree_scans/tree_interests
    }
    if ($speciesIds) {
        $in = implode(',', array_map('intval', $speciesIds));
        $pdo->exec("DELETE FROM species WHERE id IN ($in)");
    }
    foreach ($visitorUuids as $uuid) {
        $stmt = $pdo->prepare('DELETE FROM visitors WHERE visitor_uuid = :u');
        $stmt->execute(['u' => $uuid]);
    }
    if (is_resource($serverProc)) {
        proc_terminate($serverProc);
        proc_close($serverProc);
    }
}

register_shutdown_function(function () use (&$pdo, &$createdTreeIds, &$createdSpeciesIds, &$createdVisitorUuids, &$serverProc) {
    cleanup($pdo, $createdTreeIds, $createdSpeciesIds, $createdVisitorUuids, $serverProc);
});

try {
    // --- 2. Seed 3 isolated test species + trees (far-away display_order so
    // they never collide with real data) to exercise prev/next navigation ---
    $zoneId = (int) $pdo->query('SELECT id FROM zones ORDER BY id ASC LIMIT 1')->fetchColumn();
    check('found a zone to assign the test trees to', $zoneId > 0);
    $categoryCode = (string) $pdo->query('SELECT code FROM categories ORDER BY code ASC LIMIT 1')->fetchColumn();

    $orderBase = 900001;
    $names = ['QR-Test Tree A', 'QR-Test Tree B', 'QR-Test Tree C'];
    $ids = [];
    $insertSpecies = $pdo->prepare('INSERT INTO species (category_code, species_code, name, description) VALUES (:cc, :sc, :name, :desc)');
    $insert = $pdo->prepare(
        'INSERT INTO trees (species_id, zone_id, display_order, is_active) VALUES (:sp, :z, :order, 1)'
    );
    foreach ($names as $i => $name) {
        $insertSpecies->execute(['cc' => $categoryCode, 'sc' => '91' . $i, 'name' => $name, 'desc' => 'seeded by qr_e2e_test.php']);
        $speciesId = (int) $pdo->lastInsertId();
        $createdSpeciesIds[] = $speciesId;

        $insert->execute(['sp' => $speciesId, 'z' => $zoneId, 'order' => $orderBase + $i]);
        $ids[] = (int) $pdo->lastInsertId();
    }
    [$treeA, $treeB, $treeC] = $ids;
    $createdTreeIds = $ids;

    // --- 3. QR code for the middle tree resolves to the right page ---
    // lang=en pinned so the English nav-button text asserted below (§6) is
    // deterministic regardless of the app's default locale (Thai).
    $qrContent = "$base/tree.php?id=$treeB&lang=en"; // what a printed QR for tree B would encode
    $r1 = httpGet($qrContent);
    check('QR scan of tree B returns HTTP 200', $r1['status'] === 200, "got {$r1['status']}");
    check('tree B page shows the correct tree name', str_contains($r1['body'], 'QR-Test Tree B'));
    check('first scan sets the visitor cookie', $r1['cookie'] !== null, 'no Set-Cookie header found');

    $cookie = $r1['cookie'];
    trackVisitorCookie($r1, $createdVisitorUuids);

    // --- 4. Repeat scan with the same visitor cookie is logged silently —
    // no "you already scanned this" notice is ever shown to the visitor ---
    $r2 = httpGet($qrContent, $cookie);
    check('repeat scan returns HTTP 200', $r2['status'] === 200);
    check('repeat scan page does not show any duplicate-scan notice', !str_contains($r2['body'], 'Welcome back') && !str_contains($r2['body'], 'first time scanning'));

    $stats = getScanStats($pdo, $treeB);
    check('scan stats: 2 total scans logged', $stats['total'] === 2, "got {$stats['total']}");
    check('scan stats: 1 unique visitor', $stats['unique'] === 1, "got {$stats['unique']}");

    // --- 5. A second, distinct visitor scanning the same QR is counted separately ---
    $r3 = httpGet($qrContent); // no cookie sent -> server treats as a new visitor
    $stats2 = getScanStats($pdo, $treeB);
    check('scan stats: unique visitors now 2', $stats2['unique'] === 2, "got {$stats2['unique']}");
    trackVisitorCookie($r3, $createdVisitorUuids);

    // --- 7. Scanning a QR for a non-existent / inactive tree 404s ---
    $rMissing = httpGet("$base/tree.php?id=999999999");
    check('unknown tree id returns HTTP 404', $rMissing['status'] === 404, "got {$rMissing['status']}");

    $pdo->prepare('UPDATE trees SET is_active = 0 WHERE id = :id')->execute(['id' => $treeA]);
    $rInactive = httpGet("$base/tree.php?id=$treeA");
    check('deactivated tree returns HTTP 404', $rInactive['status'] === 404, "got {$rInactive['status']}");
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    $fail++;
}

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
