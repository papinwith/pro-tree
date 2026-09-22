<?php
/**
 * Tests for the admin QR-generation pages (admin/tree_qr.php, admin/qr_all.php)
 * and the server-side QR persistence in includes/functions.php.
 *
 * Covers:
 *   1. Both pages require an authenticated admin session (redirect to login otherwise).
 *   2. Once logged in, tree_qr.php renders the correct tree name + public /tree/{id} URL
 *      and points <img> at a real, persisted PNG file under public/assets/uploads/qr/.
 *   3. That PNG is a genuine, scannable QR code — decoded with jsQR to prove the
 *      round trip (encode server-side -> pixels -> decode) lands on the right URL.
 *   4. qr_code_path is actually written to the trees row (not just present on disk).
 *   5. qr_all.php lists every tree for bulk printing, each with its own PNG.
 *   6. A tree that predates this feature (qr_code_path NULL) gets one generated
 *      and persisted lazily on first view.
 *
 * Requires MySQL running locally with the app schema/seed loaded (same as qr_e2e_test.php).
 *
 * Run: php tests/qr_generate_test.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';

$host = '127.0.0.1';
$port = 8097;
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

function httpRequest(string $url, ?string $cookieFile = null, ?array $post = null): array
{
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 5,
    ];
    if ($cookieFile !== null) {
        $opts[CURLOPT_COOKIEJAR] = $cookieFile;
        $opts[CURLOPT_COOKIEFILE] = $cookieFile;
    }
    if ($post !== null) {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($post);
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    if ($raw === false) {
        throw new RuntimeException('curl error: ' . curl_error($ch));
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return [
        'status' => $status,
        'headers' => substr($raw, 0, $headerSize),
        'body' => substr($raw, $headerSize),
    ];
}

/** Decodes a QR PNG with jsQR (via Node) and returns the decoded text, or null. */
function decodeQrPng(string $pngPath): ?string
{
    $im = @imagecreatefrompng($pngPath);
    if ($im === false) {
        return null;
    }
    $w = imagesx($im);
    $h = imagesy($im);
    $rgba = fopen('php://temp', 'w+b');
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $idx = imagecolorat($im, $x, $y);
            $c = imagecolorsforindex($im, $idx);
            fwrite($rgba, chr($c['red']) . chr($c['green']) . chr($c['blue']) . chr(255));
        }
    }
    rewind($rgba);
    $pixelData = stream_get_contents($rgba);
    fclose($rgba);

    $pixelsPath = tempnam(sys_get_temp_dir(), 'qrpix_') . '.bin';
    file_put_contents($pixelsPath, $pixelData);

    $scriptPath = tempnam(sys_get_temp_dir(), 'qrdecode_') . '.js';
    file_put_contents($scriptPath, sprintf(
        "var fs = require('fs');\n" .
        "var buf = fs.readFileSync(%s);\n" .
        "var data = new Uint8ClampedArray(buf);\n" .
        "var jsQR = require(%s);\n" .
        "var result = jsQR(data, %d, %d);\n" .
        "console.log(result ? ('OK ' + result.data) : 'FAIL');\n",
        json_encode($pixelsPath),
        json_encode(__DIR__ . '/../public/assets/js/jsQR.js'),
        $w,
        $h
    ));

    $out = trim((string) shell_exec('node ' . escapeshellarg($scriptPath) . ' 2>&1'));
    @unlink($pixelsPath);
    @unlink($scriptPath);

    if (!str_starts_with($out, 'OK ')) {
        return null;
    }
    return substr($out, 3);
}

// --- Boot the built-in PHP server against the project root (admin/ pages
// reference ../public/... for shared assets, so it needs both dirs visible) ---
$docRoot = __DIR__ . '/..';
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
$createdSpeciesId = null;

register_shutdown_function(function () use (&$serverProc, $pdo, &$createdTreeIds, &$createdSpeciesId) {
    if ($createdTreeIds) {
        $stmt = $pdo->prepare('SELECT qr_code_path FROM trees WHERE id = :id');
        foreach ($createdTreeIds as $tid) {
            $stmt->execute(['id' => $tid]);
            $row = $stmt->fetch();
            if ($row) {
                deletePublicFile($row['qr_code_path']);
            }
        }
        $in = implode(',', array_map('intval', $createdTreeIds));
        $pdo->exec("DELETE FROM trees WHERE id IN ($in)");
    }
    if ($createdSpeciesId) {
        $pdo->prepare('DELETE FROM species WHERE id = :id')->execute(['id' => $createdSpeciesId]);
    }
    if (is_resource($serverProc)) {
        proc_terminate($serverProc);
        proc_close($serverProc);
    }
});

try {
    // --- Seed an isolated test species + tree so we don't touch real data ---
    $categoryCode = (string) $pdo->query('SELECT code FROM categories ORDER BY code ASC LIMIT 1')->fetchColumn();
    $insertSpecies = $pdo->prepare(
        'INSERT INTO species (category_code, species_code, name, description) VALUES (:cc, :sc, :n, :d)'
    );
    $insertSpecies->execute(['cc' => $categoryCode, 'sc' => '901', 'n' => 'QR-Gen Test Tree', 'd' => 'seeded by qr_generate_test.php']);
    $createdSpeciesId = (int) $pdo->lastInsertId();

    $zoneId = (int) $pdo->query('SELECT id FROM zones ORDER BY id ASC LIMIT 1')->fetchColumn();
    check('found a zone to assign the test tree to', $zoneId > 0);

    $insert = $pdo->prepare(
        'INSERT INTO trees (species_id, zone_id, display_order, is_active) VALUES (:sp, :z, :o, 1)'
    );
    $insert->execute(['sp' => $createdSpeciesId, 'z' => $zoneId, 'o' => 900101]);
    $testTreeId = (int) $pdo->lastInsertId();
    $createdTreeIds[] = $testTreeId;

    // --- 1. Unauthenticated access is redirected to login ---
    $rNoAuth = httpRequest("$base/admin/tree_qr.php?id=$testTreeId");
    check(
        'tree_qr.php redirects to login when not authenticated',
        $rNoAuth['status'] === 302 && str_contains($rNoAuth['headers'], 'Location: login.php')
    );

    $rNoAuthAll = httpRequest("$base/admin/qr_all.php");
    check(
        'qr_all.php redirects to login when not authenticated',
        $rNoAuthAll['status'] === 302 && str_contains($rNoAuthAll['headers'], 'Location: login.php')
    );

    // --- 2. Log in as the seeded admin ---
    $cookieFile = tempnam(sys_get_temp_dir(), 'qrtest_cookies_');
    httpRequest("$base/admin/login.php", $cookieFile); // prime session cookie
    $rLogin = httpRequest("$base/admin/login.php", $cookieFile, [
        'username' => 'admin',
        'password' => 'ChangeMe123!',
    ]);
    check('admin login succeeds (redirect to dashboard)', $rLogin['status'] === 302, "got {$rLogin['status']}");

    // --- 3. Single-tree QR page: a NULL qr_code_path is backfilled lazily ---
    $before = $pdo->query("SELECT qr_code_path FROM trees WHERE id = $testTreeId")->fetchColumn();
    check('freshly-seeded test tree has no qr_code_path yet', $before === null);

    $rQr = httpRequest("$base/admin/tree_qr.php?id=$testTreeId", $cookieFile);
    check('tree_qr.php returns 200 once authenticated', $rQr['status'] === 200, "got {$rQr['status']}");
    check('tree_qr.php shows the tree name', str_contains($rQr['body'], 'QR-Gen Test Tree'));

    $expectedUrl = rtrim(APP_BASE_URL, '/') . '/tree/' . $testTreeId;
    check('tree_qr.php shows the correct public /tree/{id} URL', str_contains($rQr['body'], $expectedUrl), "expected $expectedUrl");

    $qrPath = $pdo->query("SELECT qr_code_path FROM trees WHERE id = $testTreeId")->fetchColumn();
    check('viewing tree_qr.php persists qr_code_path to the DB', is_string($qrPath) && $qrPath !== '', "got " . var_export($qrPath, true));
    check('tree_qr.php <img> points at the persisted PNG', $qrPath && str_contains($rQr['body'], e($qrPath)));

    $absPngPath = publicDir() . '/' . $qrPath;
    check('the QR PNG file actually exists on disk', is_file($absPngPath), $absPngPath);

    if (is_file($absPngPath)) {
        $decoded = decodeQrPng($absPngPath);
        check('the QR PNG decodes (via jsQR) back to the correct tree URL', $decoded === $expectedUrl, "decoded: " . var_export($decoded, true));
    }

    $rQrMissing = httpRequest("$base/admin/tree_qr.php?id=999999999", $cookieFile);
    check('tree_qr.php 404s for a non-existent tree', $rQrMissing['status'] === 404, "got {$rQrMissing['status']}");

    // --- 4. Bulk QR page lists every tree, each backed by a real file ---
    $allTrees = $pdo->query(
        'SELECT t.id, s.name FROM trees t JOIN species s ON s.id = t.species_id'
    )->fetchAll();
    $rAll = httpRequest("$base/admin/qr_all.php", $cookieFile);
    check('qr_all.php returns 200 once authenticated', $rAll['status'] === 200, "got {$rAll['status']}");
    $allNamesPresent = true;
    foreach ($allTrees as $t) {
        if (!str_contains($rAll['body'], htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8'))) {
            $allNamesPresent = false;
            break;
        }
    }
    check('qr_all.php includes every tree', $allNamesPresent);

    @unlink($cookieFile);
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    $fail++;
}

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
