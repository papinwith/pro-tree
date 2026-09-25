<?php
/**
 * Tests for admin image uploads (admin/tree_form.php) and the file cleanup
 * that goes with them (admin/tree_delete.php, includes/functions.php).
 *
 * Covers:
 *   1. Creating a tree with an uploaded tree image + map image saves both
 *      files under public/assets/uploads/{tree,maps}/ with randomized names
 *      (never the client-supplied filename) and records the paths in the DB.
 *   2. A QR PNG is generated automatically for the new tree as part of the save.
 *   3. Editing a tree with a new uploaded image replaces the file on disk and
 *      deletes the old one (no orphans).
 *   4. Uploading a non-image file is rejected with a validation error and
 *      nothing is written to disk or the DB.
 *   5. Deleting a tree removes its image, map image, and QR files from disk.
 *
 * Requires MySQL running locally with the app schema/seed loaded (same as qr_e2e_test.php).
 *
 * Run: php tests/tree_upload_test.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';

$host = '127.0.0.1';
$port = 8096;
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

function httpRequest(string $url, ?string $cookieFile = null, ?array $post = null, ?array $files = null): array
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
    if ($files !== null) {
        $fields = $post ?? [];
        foreach ($files as $field => $path) {
            $fields[$field] = new CURLFile($path);
        }
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = $fields; // multipart, since it contains CURLFile
    } elseif ($post !== null) {
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

function makeTestPng(string $path, int $r, int $g, int $b): void
{
    $im = imagecreatetruecolor(40, 40);
    imagefill($im, 0, 0, imagecolorallocate($im, $r, $g, $b));
    imagepng($im, $path);
    imagedestroy($im);
}

// --- Boot the built-in PHP server against the project root ---
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

$createdTreeId = null;
$tempFiles = [];

register_shutdown_function(function () use (&$serverProc, $pdo, &$createdTreeId, &$tempFiles) {
    if ($createdTreeId) {
        $stmt = $pdo->prepare('SELECT image_path, map_image_path, qr_code_path FROM trees WHERE id = :id');
        $stmt->execute(['id' => $createdTreeId]);
        $row = $stmt->fetch();
        if ($row) {
            deletePublicFile($row['image_path']);
            deletePublicFile($row['map_image_path']);
            deletePublicFile($row['qr_code_path']);
        }
        $pdo->prepare('DELETE FROM trees WHERE id = :id')->execute(['id' => $createdTreeId]);
    }
    foreach ($tempFiles as $f) {
        @unlink($f);
    }
    if (is_resource($serverProc)) {
        proc_terminate($serverProc);
        proc_close($serverProc);
    }
});

try {
    // --- Log in as the seeded admin ---
    $cookieFile = tempnam(sys_get_temp_dir(), 'upload_cookies_');
    $tempFiles[] = $cookieFile;
    $rLoginPage = httpRequest("$base/admin/login.php", $cookieFile);
    preg_match('/name="csrf_token" value="([^"]+)"/', $rLoginPage['body'], $csrfMatch);
    $rLogin = httpRequest("$base/admin/login.php", $cookieFile, [
        'username' => $testAdmin['username'],
        'password' => $testAdmin['password'],
        'csrf_token' => $csrfMatch[1] ?? '',
    ]);
    check('admin login succeeds', $rLogin['status'] === 302, "got {$rLogin['status']}");

    // Every tree needs a valid species_id/zone_id now — grab whatever the seed created.
    $speciesId = (int) $pdo->query('SELECT id FROM species ORDER BY id ASC LIMIT 1')->fetchColumn();
    $zoneId = (int) $pdo->query('SELECT id FROM zones ORDER BY id ASC LIMIT 1')->fetchColumn();
    check('found a species and zone to assign new trees to', $speciesId > 0 && $zoneId > 0);

    // --- 1. Unauthenticated create is redirected to login ---
    $rNoAuth = httpRequest("$base/admin/tree_form.php");
    check(
        'tree_form.php redirects to login when not authenticated',
        $rNoAuth['status'] === 302 && str_contains($rNoAuth['headers'], 'Location: login.php')
    );

    // --- 2. Create a tree with an uploaded tree image + map image ---
    $treeImgPath = tempnam(sys_get_temp_dir(), 'treeimg_') . '.png';
    $mapImgPath = tempnam(sys_get_temp_dir(), 'mapimg_') . '.png';
    $tempFiles[] = $treeImgPath;
    $tempFiles[] = $mapImgPath;
    makeTestPng($treeImgPath, 34, 139, 34);
    makeTestPng($mapImgPath, 70, 130, 180);

    $rCreate = httpRequest("$base/admin/tree_form.php", $cookieFile, [
        'species_id' => (string) $speciesId,
        'zone_id' => (string) $zoneId,
        'is_active' => '1',
        'csrf_token' => $csrfMatch[1] ?? '',
    ], [
        'image' => $treeImgPath,
        'map_image' => $mapImgPath,
    ]);
    check('creating a tree with uploads redirects to dashboard', $rCreate['status'] === 302, "got {$rCreate['status']}, body: " . substr($rCreate['body'], 0, 300));

    // area_code/label/slug/display_order are no longer admin-settable — the
    // system assigns them, so the only reliable way to find the tree we just
    // created is the id the redirect Location carries (?reprint=<id>, always
    // set for a brand-new tree since its plant_code is new).
    preg_match('#Location:\s*dashboard\.php\?reprint=(\d+)#', $rCreate['headers'], $locMatch);
    $newTreeId = isset($locMatch[1]) ? (int) $locMatch[1] : 0;
    check('redirect Location carries the new tree id', $newTreeId > 0, $rCreate['headers']);

    $stmt = $pdo->prepare('SELECT * FROM trees WHERE id = :id');
    $stmt->execute(['id' => $newTreeId]);
    $tree = $stmt->fetch();
    check('new tree row exists in the DB', (bool) $tree);

    if ($tree) {
        $createdTreeId = (int) $tree['id'];

        check('image_path was saved under assets/uploads/tree/', (bool) preg_match('#^assets/uploads/tree/[0-9a-f]{32}\.png$#', (string) $tree['image_path']), (string) $tree['image_path']);
        check('map_image_path was saved under assets/uploads/maps/', (bool) preg_match('#^assets/uploads/maps/[0-9a-f]{32}\.png$#', (string) $tree['map_image_path']), (string) $tree['map_image_path']);
        check('uploaded filename is randomized, not the original name', !str_contains((string) $tree['image_path'], 'treeimg'));

        $imgAbs = publicDir() . '/' . $tree['image_path'];
        $mapAbs = publicDir() . '/' . $tree['map_image_path'];
        clearstatcache(); // files were just written by the OTHER php process (the dev server)
        check('uploaded tree image file exists on disk', is_file($imgAbs));
        check('uploaded map image file exists on disk', is_file($mapAbs));

        // --- 3. QR code was generated automatically on save ---
        check('qr_code_path was set automatically', (bool) preg_match('#^assets/uploads/qr/tree-\d+\.png$#', (string) $tree['qr_code_path']), (string) $tree['qr_code_path']);
        check('generated QR file exists on disk', is_file(publicDir() . '/' . $tree['qr_code_path']));

        // --- 4. Editing with a new image replaces the old file (no orphan) ---
        $newImgPath = tempnam(sys_get_temp_dir(), 'treeimg2_') . '.png';
        $tempFiles[] = $newImgPath;
        makeTestPng($newImgPath, 200, 50, 50);

        $rEdit = httpRequest("$base/admin/tree_form.php?id={$tree['id']}", $cookieFile, [
            'species_id' => (string) $speciesId,
            'zone_id' => (string) $zoneId,
            'is_active' => '1',
            'csrf_token' => $csrfMatch[1] ?? '',
        ], [
            'image' => $newImgPath,
        ]);
        check('editing with a new image redirects to dashboard', $rEdit['status'] === 302, "got {$rEdit['status']}");

        $stmt->execute(['id' => $newTreeId]);
        $treeAfterEdit = $stmt->fetch();
        clearstatcache();
        check('image_path changed to the new file', $treeAfterEdit['image_path'] !== $tree['image_path']);
        check('old tree image file was deleted', !is_file($imgAbs));
        check('new tree image file exists on disk', is_file(publicDir() . '/' . $treeAfterEdit['image_path']));
        check('map_image_path untouched by an edit that didn\'t upload a new one', $treeAfterEdit['map_image_path'] === $tree['map_image_path']);

        // --- 5. Uploading a non-image file is rejected ---
        $fakePath = tempnam(sys_get_temp_dir(), 'notanimage_') . '.jpg';
        $tempFiles[] = $fakePath;
        file_put_contents($fakePath, "<?php echo 'not actually an image'; ?>");

        $beforeBadUpload = $pdo->query("SELECT image_path FROM trees WHERE id = {$tree['id']}")->fetchColumn();
        $rBadUpload = httpRequest("$base/admin/tree_form.php?id={$tree['id']}", $cookieFile, [
            'species_id' => (string) $speciesId,
            'zone_id' => (string) $zoneId,
            'is_active' => '1',
            'csrf_token' => $csrfMatch[1] ?? '',
        ], [
            'image' => $fakePath,
        ]);
        check('uploading a non-image returns the form with an error (200, not a redirect)', $rBadUpload['status'] === 200, "got {$rBadUpload['status']}");
        check('non-image upload shows a validation error message', str_contains($rBadUpload['body'], 'ไม่ใช่รูปภาพที่ถูกต้อง') || str_contains($rBadUpload['body'], 'ไม่รองรับชนิดไฟล์นี้'));
        $afterBadUpload = $pdo->query("SELECT image_path FROM trees WHERE id = {$tree['id']}")->fetchColumn();
        check('rejected upload did not change image_path in the DB', $afterBadUpload === $beforeBadUpload);

        // --- 6. Deleting the tree removes its files from disk ---
        $imgToCheck = publicDir() . '/' . $treeAfterEdit['image_path'];
        $mapToCheck = publicDir() . '/' . $treeAfterEdit['map_image_path'];
        $qrToCheck = publicDir() . '/' . $treeAfterEdit['qr_code_path'];

        $rDelete = httpRequest("$base/admin/tree_delete.php", $cookieFile, [
            'id' => (string) $tree['id'],
            'csrf_token' => $csrfMatch[1] ?? '',
        ]);
        check('delete redirects to dashboard', $rDelete['status'] === 302, "got {$rDelete['status']}");

        $stmt->execute(['id' => $newTreeId]);
        check('tree row is gone from the DB', $stmt->fetch() === false);
        clearstatcache();
        check('tree image file removed from disk on delete', !is_file($imgToCheck));
        check('map image file removed from disk on delete', !is_file($mapToCheck));
        check('QR file removed from disk on delete', !is_file($qrToCheck));

        $createdTreeId = null; // already cleaned up, nothing left for shutdown handler to do
    }
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    $fail++;
}

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
