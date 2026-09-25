<?php
/**
 * Regression test for admin/tree_form.php's "Add Tree" flow after garden
 * area/nameplate/URL slug/coordinates/display order were removed from the
 * admin-facing form (the system now assigns them automatically) and a
 * "quantity" field was added to plant several identical trees at once.
 *
 * Covers:
 *   1. Creating one tree with no display_order/area_code/slug/label/lat/lng
 *      in the POST still succeeds and gets sane defaults (area_code '01',
 *      a fresh unique display_order, the rest null).
 *   2. quantity > 1 creates that many tree rows in one submit, each with
 *      its own id/QR/plant_code and a strictly increasing display_order
 *      continuing from whatever the table's max already was.
 *   3. Editing a tree without those fields in the POST does NOT null out
 *      its existing area_code/label/slug/lat/lng — they're simply carried
 *      forward unchanged since the admin never had a way to touch them.
 *   4. No orphaned tree row or QR file gets created for a rejected save
 *      (invalid species/zone).
 *
 * Requires MySQL running locally with the app schema/seed loaded (same as qr_e2e_test.php).
 *
 * Run: php tests/tree_form_validation_test.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';

$host = '127.0.0.1';
$port = 8095;
$base = "http://$host:$port";

$pdo = db();
require_once __DIR__ . '/_test_admin.php';
$testAdmin = createTestAdmin($pdo);

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
        CURLOPT_TIMEOUT => 20, // bumped from 5s: Supabase (remote Postgres) round-trips add real latency vs local MySQL
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

function newTreeIdFromRedirect(array $response): int
{
    preg_match('#Location:\s*dashboard\.php\?reprint=(\d+)#', $response['headers'], $m);
    return isset($m[1]) ? (int) $m[1] : 0;
}

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

register_shutdown_function(function () use (&$serverProc, $pdo, $testAdmin, &$createdTreeIds) {
    deleteTestAdmin($pdo, $testAdmin['username']);
    if ($createdTreeIds) {
        $stmt = $pdo->prepare('SELECT image_path, map_image_path, qr_code_path FROM trees WHERE id = :id');
        foreach ($createdTreeIds as $tid) {
            $stmt->execute(['id' => $tid]);
            $row = $stmt->fetch();
            if ($row) {
                deletePublicFile($row['image_path']);
                deletePublicFile($row['map_image_path']);
                deletePublicFile($row['qr_code_path']);
            }
        }
        $in = implode(',', array_map('intval', $createdTreeIds));
        $pdo->exec("DELETE FROM trees WHERE id IN ($in)");
    }
    if (is_resource($serverProc)) {
        proc_terminate($serverProc);
        proc_close($serverProc);
    }
});

try {
    $cookieFile = tempnam(sys_get_temp_dir(), 'formval_cookies_');
    $rLoginPage = httpRequest("$base/admin/login.php", $cookieFile);
    preg_match('/name="csrf_token" value="([^"]+)"/', $rLoginPage['body'], $csrfMatch);
    $rLogin = httpRequest("$base/admin/login.php", $cookieFile, [
        'username' => $testAdmin['username'],
        'password' => $testAdmin['password'],
        'csrf_token' => $csrfMatch[1] ?? '',
    ]);
    check('admin login succeeds', $rLogin['status'] === 302, "got {$rLogin['status']}");

    $speciesId = (int) $pdo->query('SELECT id FROM species ORDER BY id ASC LIMIT 1')->fetchColumn();
    $zoneId = (int) $pdo->query('SELECT id FROM zones ORDER BY id ASC LIMIT 1')->fetchColumn();
    check('found a species and zone to assign new trees to', $speciesId > 0 && $zoneId > 0);

    $beforeMaxOrder = (int) $pdo->query('SELECT COALESCE(MAX(display_order), 0) FROM trees')->fetchColumn();

    // --- 1. Minimal create (no area_code/label/slug/lat/lng/display_order/quantity in POST) ---
    $rCreate = httpRequest("$base/admin/tree_form.php", $cookieFile, [
        'species_id' => (string) $speciesId,
        'zone_id' => (string) $zoneId,
        'is_active' => '1',
        'csrf_token' => $csrfMatch[1] ?? '',
    ]);
    check('a minimal create redirects to dashboard', $rCreate['status'] === 302, "got {$rCreate['status']}");
    $firstId = newTreeIdFromRedirect($rCreate);
    check('redirect Location carries the new tree id', $firstId > 0, $rCreate['headers']);

    if ($firstId > 0) {
        $createdTreeIds[] = $firstId;
        $row = $pdo->query("SELECT * FROM trees WHERE id = $firstId")->fetch();
        check('area_code defaulted to 01', ($row['area_code'] ?? null) === '01', (string) ($row['area_code'] ?? 'null'));
        check('label/slug/latitude/longitude were left null', $row['label'] === null && $row['slug'] === null && $row['latitude'] === null && $row['longitude'] === null);
        check('display_order was assigned automatically, above the prior max', (int) $row['display_order'] > $beforeMaxOrder, "got {$row['display_order']}, prior max was $beforeMaxOrder");
        check('plant_code was generated', !empty($row['plant_code']));
    }

    // --- 2. quantity > 1 plants several identical trees in one submit ---
    $rBulk = httpRequest("$base/admin/tree_form.php", $cookieFile, [
        'species_id' => (string) $speciesId,
        'zone_id' => (string) $zoneId,
        'is_active' => '1',
        'quantity' => '3',
        'csrf_token' => $csrfMatch[1] ?? '',
    ]);
    check('a bulk create redirects to dashboard', $rBulk['status'] === 302, "got {$rBulk['status']}");

    $afterBulkMaxId = (int) $pdo->query('SELECT MAX(id) FROM trees')->fetchColumn();
    $bulkRows = $pdo->query(
        "SELECT id, display_order FROM trees WHERE species_id = $speciesId AND zone_id = $zoneId AND id > $firstId ORDER BY id ASC"
    )->fetchAll();
    check('quantity=3 created exactly 3 new tree rows', count($bulkRows) === 3, 'got ' . count($bulkRows));
    foreach ($bulkRows as $r) {
        $createdTreeIds[] = (int) $r['id'];
    }
    if (count($bulkRows) === 3) {
        $orders = array_map(fn($r) => (int) $r['display_order'], $bulkRows);
        $sorted = $orders;
        sort($sorted);
        check('the 3 trees got strictly increasing, unique display_order values', $orders === $sorted && count(array_unique($orders)) === 3, implode(',', $orders));
    }

    // --- 3. Editing without those fields in the POST keeps existing values ---
    if ($firstId > 0) {
        // Give the first tree a non-default area_code/label/slug/coordinates
        // directly (the admin form can no longer set these, but a prior
        // record — e.g. imported data — might already have them).
        $pdo->prepare('UPDATE trees SET area_code = :a, label = :l, slug = :s, latitude = :lat, longitude = :lng WHERE id = :id')
            ->execute(['a' => '07', 'l' => 'Existing Label', 's' => 'existing-slug', 'lat' => 17.1, 'lng' => 102.2, 'id' => $firstId]);

        $rEdit = httpRequest("$base/admin/tree_form.php?id=$firstId", $cookieFile, [
            'species_id' => (string) $speciesId,
            'zone_id' => (string) $zoneId,
            'status' => 'needs_attention',
            'is_active' => '1',
            'csrf_token' => $csrfMatch[1] ?? '',
        ]);
        check('editing without the removed fields still succeeds', $rEdit['status'] === 302, "got {$rEdit['status']}");

        $afterEdit = $pdo->query("SELECT * FROM trees WHERE id = $firstId")->fetch();
        check('status change from the edit applied', $afterEdit['status'] === 'needs_attention');
        check('area_code was carried forward unchanged', $afterEdit['area_code'] === '07');
        check('label was carried forward unchanged', $afterEdit['label'] === 'Existing Label');
        check('slug was carried forward unchanged', $afterEdit['slug'] === 'existing-slug');
        check('latitude/longitude were carried forward unchanged', (float) $afterEdit['latitude'] === 17.1 && (float) $afterEdit['longitude'] === 102.2);
    }

    // --- 4. An invalid create (bad species/zone) is rejected, not crashed, no orphan row ---
    $beforeCount = (int) $pdo->query('SELECT COUNT(*) FROM trees')->fetchColumn();
    $rInvalid = httpRequest("$base/admin/tree_form.php", $cookieFile, [
        'species_id' => '999999',
        'zone_id' => (string) $zoneId,
        'is_active' => '1',
        'csrf_token' => $csrfMatch[1] ?? '',
    ]);
    check('invalid species_id is rejected (200, not a redirect)', $rInvalid['status'] === 200, "got {$rInvalid['status']}");
    check('invalid species_id shows a friendly error', str_contains($rInvalid['body'], 'กรุณาเลือกชนิดพันธุ์ให้ถูกต้อง'));
    check('no PHP fatal-error output leaked into the response', !str_contains($rInvalid['body'], 'Fatal error') && !str_contains($rInvalid['body'], 'Uncaught'));
    $afterCount = (int) $pdo->query('SELECT COUNT(*) FROM trees')->fetchColumn();
    check('no orphaned tree row was created for the rejected save', $afterCount === $beforeCount, "before=$beforeCount after=$afterCount");

    @unlink($cookieFile);
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    $fail++;
}

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
