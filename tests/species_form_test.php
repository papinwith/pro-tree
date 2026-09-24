<?php
/**
 * Tests for the "add species" form (admin/species_form.php) and its image /
 * new-tree / starting-stock handling.
 *
 * Covers:
 *   1. Creating a species with a species photo + a zone/quantity + a tree
 *      photo + status + starting stock stores everything: photo under
 *      assets/uploads/species/ with a randomized name, N trees each carrying
 *      the tree photo/status/QR/plant_code, and the stock row.
 *   2. The public tree page shows the tree's own photo when it has one and
 *      falls back to the species photo when it doesn't.
 *   3. Editing: a new photo replaces the file (old one deleted); "remove
 *      photo" clears the column and deletes the file.
 *   4. Rejected input (a non-image file; a tree photo with no zone/quantity)
 *      creates nothing and leaves no file behind.
 *   5. A failure partway through the save (forced with a temporary trigger on
 *      nursery_stock) rolls EVERYTHING back — no half-created species, no
 *      trees, no orphaned photo or QR files.
 *   6. Deleting a species deletes its photo file.
 *
 * Creates a temporary admin account (removed at the end) so it doesn't need
 * to know any real password, and deletes every row/file it creates.
 *
 * Requires MySQL running locally with the app schema loaded.
 *
 * Run: C:\xampp\php\php.exe tests/species_form_test.php
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

function httpRequest(string $url, ?string $cookieFile = null, ?array $post = null, ?array $files = null): array
{
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 20,
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
        $opts[CURLOPT_POSTFIELDS] = $fields;
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
    return ['status' => $status, 'headers' => substr($raw, 0, $headerSize), 'body' => substr($raw, $headerSize)];
}

function makeTestPng(string $path, int $r, int $g, int $b): void
{
    $im = imagecreatetruecolor(40, 40);
    imagefill($im, 0, 0, imagecolorallocate($im, $r, $g, $b));
    imagepng($im, $path);
    imagedestroy($im);
}

function csrfFrom(string $html): string
{
    return preg_match('/name="csrf_token" value="([0-9a-f]+)"/', $html, $m) ? $m[1] : '';
}

function fileCount(string $relDir): int
{
    clearstatcache();
    $files = glob(publicDir() . '/' . $relDir . '/*') ?: [];
    return count($files);
}

// --- Boot the built-in PHP server against the project root (XAMPP's own php.exe,
// so it loads the same php.ini/extensions as the real Apache) ---
$docRoot = __DIR__ . '/..';
$cmd = sprintf('"C:\\xampp\\php\\php.exe" -S %s:%d -t %s', $host, $port, escapeshellarg($docRoot));
// The server logs every request to stderr. Send that to a file, NOT a pipe:
// nobody drains a pipe here, and once it fills (~4 KB on Windows, i.e. a few
// dozen requests) the server blocks on writing its log line and every later
// request just times out.
$serverLog = tempnam(sys_get_temp_dir(), 'species_srv_');
$serverProc = proc_open($cmd, [1 => ['file', $serverLog, 'a'], 2 => ['file', $serverLog, 'a']], $pipes, __DIR__, null, ['bypass_shell' => true]);
if (!is_resource($serverProc)) {
    fwrite(STDERR, "Could not start PHP built-in server\n");
    exit(1);
}
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

$testAdminUser = 'zz_test_species_' . bin2hex(random_bytes(3));
$testAdminPass = 'Test-Pass-' . bin2hex(random_bytes(4));
$testAdminId = null;
$tempFiles = [$serverLog];
$triggerName = 'zz_test_force_stock_failure';
$speciesBaselineIds = array_map('intval', $pdo->query('SELECT id FROM species')->fetchAll(PDO::FETCH_COLUMN));

$cleanup = function () use (&$serverProc, $pdo, &$testAdminId, &$tempFiles, $triggerName, $speciesBaselineIds) {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $pdo->exec("DROP TRIGGER IF EXISTS $triggerName ON nursery_stock");
        $pdo->exec("DROP FUNCTION IF EXISTS {$triggerName}_fn()");
        $pdo->exec("DELETE FROM planting_plans WHERE zone_id IN (SELECT id FROM zones WHERE zone_code LIKE 'ZZTEST%')");
        $pdo->exec("DELETE FROM zones WHERE zone_code LIKE 'ZZTEST%'");
        // Everything this run created = species whose id wasn't there at the start.
        $all = array_map('intval', $pdo->query('SELECT id FROM species')->fetchAll(PDO::FETCH_COLUMN));
        foreach (array_diff($all, $speciesBaselineIds) as $sid) {
            $trees = $pdo->prepare('SELECT id, image_path, map_image_path, qr_code_path FROM trees WHERE species_id = :s');
            $trees->execute(['s' => $sid]);
            foreach ($trees->fetchAll() as $t) {
                deletePublicFile($t['image_path']);
                deletePublicFile($t['map_image_path']);
                deletePublicFile($t['qr_code_path']);
            }
            $pdo->prepare('DELETE FROM trees WHERE species_id = :s')->execute(['s' => $sid]);
            $pdo->prepare('DELETE FROM planting_plans WHERE species_id = :s')->execute(['s' => $sid]);
            $pdo->prepare('DELETE FROM sale_transactions WHERE species_id = :s')->execute(['s' => $sid]);
            $img = $pdo->prepare('SELECT image_path FROM species WHERE id = :s');
            $img->execute(['s' => $sid]);
            deletePublicFile($img->fetchColumn() ?: null);
            $pdo->prepare('DELETE FROM species WHERE id = :s')->execute(['s' => $sid]);
        }
        if ($testAdminId) {
            $pdo->prepare('DELETE FROM admins WHERE id = :id')->execute(['id' => $testAdminId]);
        }
    } catch (Throwable $e) {
        fwrite(STDERR, 'cleanup problem: ' . $e->getMessage() . "\n");
    }
    foreach ($tempFiles as $f) {
        @unlink($f);
    }
    if (is_resource($serverProc)) {
        proc_terminate($serverProc);
        proc_close($serverProc);
    }
};
register_shutdown_function($cleanup);

try {
    // --- Temp admin + login (with CSRF token, as a real browser would) ---
    $pdo->prepare('INSERT INTO admins (username, password_hash, role_id) VALUES (:u, :p, 1)')
        ->execute(['u' => $testAdminUser, 'p' => password_hash($testAdminPass, PASSWORD_DEFAULT)]);
    $testAdminId = (int) $pdo->lastInsertId();

    $cookieFile = tempnam(sys_get_temp_dir(), 'species_cookies_');
    $tempFiles[] = $cookieFile;
    $loginPage = httpRequest("$base/admin/login.php", $cookieFile);
    $rLogin = httpRequest("$base/admin/login.php", $cookieFile, [
        'username' => $testAdminUser, 'password' => $testAdminPass, 'csrf_token' => csrfFrom($loginPage['body']),
    ]);
    check('temporary admin can log in', $rLogin['status'] === 302, "got {$rLogin['status']}: " . substr($rLogin['body'], 0, 200));

    // --- Reference data for a valid submission ---
    $categoryCode = (string) $pdo->query('SELECT code FROM categories ORDER BY code LIMIT 1')->fetchColumn();
    $subtypeStmt = $pdo->prepare('SELECT id FROM subtypes WHERE category_code = :c OR category_code IS NULL ORDER BY id LIMIT 1');
    $subtypeStmt->execute(['c' => $categoryCode]);
    $subtypeId = (int) $subtypeStmt->fetchColumn();
    $zoneId = (int) $pdo->query('SELECT id FROM zones ORDER BY id LIMIT 1')->fetchColumn();
    $sizeId = (int) $pdo->query('SELECT id FROM stock_sizes ORDER BY id LIMIT 1 OFFSET 1')->fetchColumn();
    check('found a category, subtype, zone and stock size to use', $categoryCode !== '' && $subtypeId > 0 && $zoneId > 0 && $sizeId > 0);

    $speciesImg = tempnam(sys_get_temp_dir(), 'spimg_') . '.png';
    $treeImg = tempnam(sys_get_temp_dir(), 'trimg_') . '.png';
    $speciesImg2 = tempnam(sys_get_temp_dir(), 'spimg2_') . '.png';
    $notAnImage = tempnam(sys_get_temp_dir(), 'notimg_') . '.png';
    array_push($tempFiles, $speciesImg, $treeImg, $speciesImg2, $notAnImage);
    makeTestPng($speciesImg, 200, 30, 30);
    makeTestPng($treeImg, 30, 140, 30);
    makeTestPng($speciesImg2, 30, 30, 200);
    file_put_contents($notAnImage, "this is plain text, not an image\n");

    $formToken = function () use ($base, $cookieFile): string {
        return csrfFrom(httpRequest("$base/admin/species_form.php", $cookieFile)['body']);
    };
    $basePost = function (string $name) use ($categoryCode, $subtypeId, $formToken): array {
        return [
            'csrf_token' => $formToken(), 'category_code' => $categoryCode, 'subtype_id[]' => (string) $subtypeId,
            'name' => $name, 'name_scientific' => 'Testus formus',
        ];
    };
    $speciesCount = fn() => (int) $pdo->query('SELECT COUNT(*) FROM species')->fetchColumn();
    $findSpecies = function (string $name) use ($pdo): ?array {
        $s = $pdo->prepare('SELECT * FROM species WHERE name = :n ORDER BY id DESC LIMIT 1');
        $s->execute(['n' => $name]);
        return $s->fetch() ?: null;
    };

    // --- 1. Full create: species photo + 2 trees (photo, status) + starting stock ---
    $name1 = 'ทดสอบพันธุ์ ' . bin2hex(random_bytes(3));
    $r1 = httpRequest("$base/admin/species_form.php", $cookieFile, $basePost($name1) + [
        'plant_zone_id' => (string) $zoneId, 'plant_quantity' => '2', 'plant_status' => 'needs_attention',
        'stock_size_id' => (string) $sizeId, 'stock_quantity' => '25', 'stock_price' => '350',
        'stock_status' => 'available', 'stock_channel' => 'เรือนเพาะชำ',
    ], ['image' => $speciesImg, 'tree_image' => $treeImg]);
    check('full create redirects to the species list', $r1['status'] === 302 && str_contains($r1['headers'], 'Location: species.php'), "got {$r1['status']}: " . substr($r1['body'], 0, 300));

    $sp1 = $findSpecies($name1);
    check('species row was created', (bool) $sp1);
    $sp1Id = (int) ($sp1['id'] ?? 0);
    check(
        'species photo saved under assets/uploads/species/ with a randomized name',
        (bool) preg_match('#^assets/uploads/species/[0-9a-f]{32}\.png$#', (string) ($sp1['image_path'] ?? '')),
        (string) ($sp1['image_path'] ?? 'null')
    );
    clearstatcache();
    check('species photo exists on disk', is_file(publicDir() . '/' . ($sp1['image_path'] ?? 'missing')));

    $treesStmt = $pdo->prepare('SELECT * FROM trees WHERE species_id = :s ORDER BY id');
    $treesStmt->execute(['s' => $sp1Id]);
    $trees1 = $treesStmt->fetchAll();
    check('2 trees were created', count($trees1) === 2, 'got ' . count($trees1));
    $treePhotoPaths = array_unique(array_column($trees1, 'image_path'));
    check(
        'every new tree carries the uploaded tree photo (one shared file)',
        count($treePhotoPaths) === 1 && (bool) preg_match('#^assets/uploads/tree/[0-9a-f]{32}\.png$#', (string) $treePhotoPaths[0]),
        json_encode($treePhotoPaths)
    );
    check('every new tree got the chosen status', array_unique(array_column($trees1, 'status')) === ['needs_attention'], json_encode(array_column($trees1, 'status')));
    check('every new tree has a plant_code', count(array_filter(array_column($trees1, 'plant_code'))) === 2);
    $qrOk = true;
    foreach ($trees1 as $t) {
        $qrOk = $qrOk && !empty($t['qr_code_path']) && is_file(publicDir() . '/' . $t['qr_code_path']);
    }
    check('every new tree has a QR file on disk', $qrOk);

    $stockStmt = $pdo->prepare('SELECT * FROM nursery_stock WHERE species_id = :s');
    $stockStmt->execute(['s' => $sp1Id]);
    $stock = $stockStmt->fetchAll();
    check(
        'starting stock row was saved (size, qty, price, status, channel)',
        count($stock) === 1 && (int) $stock[0]['size_id'] === $sizeId && (int) $stock[0]['quantity'] === 25
            && (float) $stock[0]['price'] === 350.0 && $stock[0]['sale_status'] === 'available' && $stock[0]['sales_channel'] === 'เรือนเพาะชำ',
        json_encode($stock, JSON_UNESCAPED_UNICODE)
    );

    // --- 2. Public page: tree's own photo vs. species-photo fallback ---
    $treePageUrl = fn(int $id) => "$base/public/tree.php?id=$id";
    $pageOwn = httpRequest($treePageUrl((int) $trees1[0]['id']));
    check('public page shows the tree\'s own photo', $pageOwn['status'] === 200 && str_contains($pageOwn['body'], basename((string) $treePhotoPaths[0])), "status {$pageOwn['status']}");
    check('public page does NOT show the species photo when the tree has its own', !str_contains($pageOwn['body'], basename((string) $sp1['image_path'])));

    $name2 = 'ทดสอบพันธุ์ ' . bin2hex(random_bytes(3));
    $r2 = httpRequest("$base/admin/species_form.php", $cookieFile, $basePost($name2) + [
        'plant_zone_id' => (string) $zoneId, 'plant_quantity' => '1', 'plant_status' => 'healthy',
    ], ['image' => $speciesImg2]);
    check('create with no tree photo redirects', $r2['status'] === 302, "got {$r2['status']}");
    $sp2 = $findSpecies($name2);
    $t2 = $sp2 ? $pdo->query('SELECT id, image_path FROM trees WHERE species_id = ' . (int) $sp2['id'])->fetch() : null;
    check('a tree created without its own photo has none stored', $t2 && empty($t2['image_path']));
    $pageFallback = $t2 ? httpRequest($treePageUrl((int) $t2['id'])) : ['status' => 0, 'body' => ''];
    check(
        'public page falls back to the species photo',
        $pageFallback['status'] === 200 && $sp2 && str_contains($pageFallback['body'], basename((string) $sp2['image_path'])),
        "status {$pageFallback['status']}"
    );

    // --- 3. Edit: replace photo, then remove it ---
    $oldPath = (string) $sp1['image_path'];
    $editPost = fn(array $extra) => [
        'csrf_token' => csrfFrom(httpRequest("$base/admin/species_form.php?id=$sp1Id", $cookieFile)['body']),
        'category_code' => $categoryCode, 'subtype_id[]' => (string) $subtypeId,
        'species_code' => $sp1['species_code'], 'name' => $name1,
    ] + $extra;
    $r3 = httpRequest("$base/admin/species_form.php?id=$sp1Id", $cookieFile, $editPost([]), ['image' => $speciesImg2]);
    check('editing with a new photo redirects', $r3['status'] === 302, "got {$r3['status']}: " . substr($r3['body'], 0, 300));
    $sp1b = $pdo->query("SELECT image_path FROM species WHERE id = $sp1Id")->fetch();
    clearstatcache();
    check('photo path changed to the new file', $sp1b['image_path'] !== $oldPath && is_file(publicDir() . '/' . $sp1b['image_path']));
    check('the replaced photo file was deleted from disk', !is_file(publicDir() . '/' . $oldPath));

    $r4 = httpRequest("$base/admin/species_form.php?id=$sp1Id", $cookieFile, $editPost(['remove_image' => 'on']));
    check('editing with "remove photo" redirects', $r4['status'] === 302, "got {$r4['status']}");
    $sp1c = $pdo->query("SELECT image_path FROM species WHERE id = $sp1Id")->fetch();
    clearstatcache();
    check('remove photo clears the column', $sp1c['image_path'] === null);
    check('remove photo deletes the file', !is_file(publicDir() . '/' . $sp1b['image_path']));

    $r5 = httpRequest("$base/admin/species_form.php?id=$sp1Id", $cookieFile, $editPost([]));
    $sp1d = $pdo->query("SELECT image_path FROM species WHERE id = $sp1Id")->fetch();
    check('re-saving without touching the photo keeps it unchanged (still none)', $r5['status'] === 302 && $sp1d['image_path'] === null);

    // --- 4. Rejected input creates nothing and leaves no file ---
    $countBefore = $speciesCount();
    $filesBefore = fileCount('assets/uploads/species') + fileCount('assets/uploads/tree');
    $r6 = httpRequest("$base/admin/species_form.php", $cookieFile, $basePost('ทดสอบไฟล์ผิด ' . bin2hex(random_bytes(2))), ['image' => $notAnImage]);
    check('a non-image upload is rejected with an error (form re-shown)', $r6['status'] === 200 && str_contains($r6['body'], 'ไม่ใช่รูปภาพ'), "status {$r6['status']}");
    check('rejected upload created no species', $speciesCount() === $countBefore);

    $r7 = httpRequest("$base/admin/species_form.php", $cookieFile, $basePost('ทดสอบรูปไม่มีโซน ' . bin2hex(random_bytes(2))), ['tree_image' => $treeImg]);
    check('a tree photo without zone/quantity is rejected', $r7['status'] === 200 && str_contains($r7['body'], 'ยังไม่ได้เลือกโซน'), "status {$r7['status']}");
    check('that rejection created no species', $speciesCount() === $countBefore);
    check('rejections left no files behind', fileCount('assets/uploads/species') + fileCount('assets/uploads/tree') === $filesBefore);

    // --- 5. A failure mid-save rolls everything back ---
    // Postgres has no inline trigger-body syntax (MySQL's `SIGNAL SQLSTATE`
    // in a bare CREATE TRIGGER) — the trigger body has to be its own
    // PL/pgSQL function, referenced by the trigger.
    $pdo->exec("DROP TRIGGER IF EXISTS $triggerName ON nursery_stock");
    $pdo->exec("DROP FUNCTION IF EXISTS {$triggerName}_fn()");
    $pdo->exec("CREATE FUNCTION {$triggerName}_fn() RETURNS TRIGGER AS \$\$
        BEGIN
            RAISE EXCEPTION 'forced test failure';
        END;
        \$\$ LANGUAGE plpgsql");
    $pdo->exec("CREATE TRIGGER $triggerName BEFORE INSERT ON nursery_stock FOR EACH ROW EXECUTE FUNCTION {$triggerName}_fn()");
    $countBefore = $speciesCount();
    $treesBefore = (int) $pdo->query('SELECT COUNT(*) FROM trees')->fetchColumn();
    $filesBefore = [
        fileCount('assets/uploads/species'), fileCount('assets/uploads/tree'), fileCount('assets/uploads/qr'),
    ];
    $rFail = httpRequest("$base/admin/species_form.php", $cookieFile, $basePost('ทดสอบล้มเหลว ' . bin2hex(random_bytes(2))) + [
        'plant_zone_id' => (string) $zoneId, 'plant_quantity' => '2', 'plant_status' => 'healthy',
        'stock_quantity' => '5', 'stock_price' => '100', 'stock_status' => 'available',
    ], ['image' => $speciesImg, 'tree_image' => $treeImg]);
    $pdo->exec("DROP TRIGGER IF EXISTS $triggerName ON nursery_stock");
    $pdo->exec("DROP FUNCTION IF EXISTS {$triggerName}_fn()");
    // Status alone is unreliable here: in local dev PHP prints the uncaught
    // exception into a 200 response (production has display_errors off and
    // returns 500). What matters: it must not look like a successful save,
    // and it must be OUR forced failure (not some earlier validation error),
    // otherwise the rollback checks below would pass vacuously.
    check(
        'the forced mid-save failure is not reported as a successful save',
        $rFail['status'] !== 302 && !str_contains($rFail['headers'], 'Location: species.php'),
        "got {$rFail['status']}"
    );
    check('the failure really came from the forced trigger (not an earlier error)', str_contains($rFail['body'], 'forced test failure'), substr(strip_tags($rFail['body']), 0, 300));
    check('rollback: no species row was left behind', $speciesCount() === $countBefore);
    check('rollback: no tree rows were left behind', (int) $pdo->query('SELECT COUNT(*) FROM trees')->fetchColumn() === $treesBefore);
    check('rollback: no orphaned species photo file', fileCount('assets/uploads/species') === $filesBefore[0]);
    check('rollback: no orphaned tree photo file', fileCount('assets/uploads/tree') === $filesBefore[1]);
    check('rollback: no orphaned QR files', fileCount('assets/uploads/qr') === $filesBefore[2]);

    // --- 6. Deleting a species deletes its photo file ---
    $name3 = 'ทดสอบลบ ' . bin2hex(random_bytes(3));
    httpRequest("$base/admin/species_form.php", $cookieFile, $basePost($name3), ['image' => $speciesImg]);
    $sp3 = $findSpecies($name3);
    $sp3Path = (string) ($sp3['image_path'] ?? '');
    clearstatcache();
    check('species with a photo and no trees exists to delete', $sp3 && is_file(publicDir() . '/' . $sp3Path));
    if ($sp3) {
        $rDel = httpRequest("$base/admin/species_delete.php", $cookieFile, ['csrf_token' => $formToken(), 'id' => (string) $sp3['id']]);
        clearstatcache();
        check('delete redirects to the list', $rDel['status'] === 302, "got {$rDel['status']}");
        check('species row deleted', $findSpecies($name3) === null);
        check('species photo file deleted with it', !is_file(publicDir() . '/' . $sp3Path));
    }

    // --- 7. A photo shared by a bulk-created batch survives removing one sibling ---
    $name4 = 'ทดสอบรูปร่วม ' . bin2hex(random_bytes(3));
    httpRequest("$base/admin/species_form.php", $cookieFile, $basePost($name4) + [
        'plant_zone_id' => (string) $zoneId, 'plant_quantity' => '3', 'plant_status' => 'healthy',
    ], ['tree_image' => $treeImg]);
    $sp4 = $findSpecies($name4);
    $sib = $sp4 ? $pdo->query('SELECT id, image_path FROM trees WHERE species_id = ' . (int) $sp4['id'] . ' ORDER BY id')->fetchAll() : [];
    check('a batch of 3 trees sharing one photo exists', count($sib) === 3 && count(array_unique(array_column($sib, 'image_path'))) === 1);
    if (count($sib) === 3) {
        $shared = (string) $sib[0]['image_path'];
        $sharedAbs = publicDir() . '/' . $shared;

        // 7a. Delete one tree.
        $rDelTree = httpRequest("$base/admin/tree_delete.php", $cookieFile, ['csrf_token' => $formToken(), 'id' => (string) $sib[0]['id']]);
        clearstatcache();
        check('deleting one tree redirects', $rDelTree['status'] === 302, "got {$rDelTree['status']}");
        check('deleted tree row is gone', (int) $pdo->query('SELECT COUNT(*) FROM trees WHERE id = ' . (int) $sib[0]['id'])->fetchColumn() === 0);
        check('the shared photo is KEPT while sibling trees still use it', is_file($sharedAbs));

        // 7b. Re-photograph one sibling through the tree form.
        $treeFormPage = httpRequest("$base/admin/tree_form.php?id=" . (int) $sib[1]['id'], $cookieFile);
        $rEdit = httpRequest("$base/admin/tree_form.php?id=" . (int) $sib[1]['id'], $cookieFile, [
            'csrf_token' => csrfFrom($treeFormPage['body']), 'species_id' => (string) $sp4['id'], 'zone_id' => (string) $zoneId,
            'status' => 'healthy', 'is_active' => '1',
        ], ['image' => $speciesImg2]);
        clearstatcache();
        $editedRow = $pdo->query('SELECT image_path FROM trees WHERE id = ' . (int) $sib[1]['id'])->fetch();
        check('re-photographing one tree redirects', $rEdit['status'] === 302, "got {$rEdit['status']}: " . substr(strip_tags($rEdit['body']), 0, 200));
        check('that tree now has its own new photo', $editedRow && $editedRow['image_path'] !== $shared && is_file(publicDir() . '/' . $editedRow['image_path']));
        check('the old shared photo is KEPT while the last sibling still uses it', is_file($sharedAbs));

        // 7c. Bulk-delete the last user of the shared photo -> now it goes.
        $rBulk = httpRequest("$base/admin/tree_bulk_delete.php", $cookieFile, ['csrf_token' => $formToken(), 'ids' => [(int) $sib[2]['id']]]);
        clearstatcache();
        check('bulk-deleting the last sibling redirects', $rBulk['status'] === 302, "got {$rBulk['status']}");
        check('the shared photo is deleted once nothing references it', !is_file($sharedAbs));
        check('the other tree\'s own new photo is untouched', $editedRow && is_file(publicDir() . '/' . $editedRow['image_path']));
    }

    // --- 8. A species that only a planting plan references can't be deleted (and doesn't crash) ---
    $name5 = 'ทดสอบแผนปลูก ' . bin2hex(random_bytes(3));
    httpRequest("$base/admin/species_form.php", $cookieFile, $basePost($name5));
    $sp5 = $findSpecies($name5);
    check('a tree-less species exists for the plan test', (bool) $sp5);
    if ($sp5) {
        $pdo->prepare('INSERT INTO planting_plans (zone_id, species_id, planned_quantity) VALUES (:z, :s, 1)')
            ->execute(['z' => $zoneId, 's' => $sp5['id']]);
        check('speciesUsageCount() counts the planting plan', speciesUsageCount($pdo, (int) $sp5['id']) === 1);

        $rD1 = httpRequest("$base/admin/species_delete.php", $cookieFile, ['csrf_token' => $formToken(), 'id' => (string) $sp5['id']]);
        check('single delete of a plan-referenced species is refused cleanly (no server error)', $rD1['status'] === 302 && str_contains($rD1['headers'], 'bulk_skipped=1'), "got {$rD1['status']}: " . substr(strip_tags($rD1['body']), 0, 200));
        check('the species is still there after the refused delete', $findSpecies($name5) !== null);

        $rD2 = httpRequest("$base/admin/species_bulk_delete.php", $cookieFile, ['csrf_token' => $formToken(), 'ids' => [(int) $sp5['id']]]);
        check('bulk delete counts it as skipped instead of crashing', $rD2['status'] === 302 && str_contains($rD2['headers'], 'bulk_deleted=0&bulk_skipped=1'), "got {$rD2['status']}: " . substr($rD2['headers'], 0, 300));
        check('the species is still there after the refused bulk delete', $findSpecies($name5) !== null);

        $pdo->prepare('DELETE FROM planting_plans WHERE species_id = :s')->execute(['s' => $sp5['id']]);
        $rD3 = httpRequest("$base/admin/species_delete.php", $cookieFile, ['csrf_token' => $formToken(), 'id' => (string) $sp5['id']]);
        check('once the plan is gone the species can be deleted', $rD3['status'] === 302 && $findSpecies($name5) === null);
    }

    // --- 9. Deleting a species that is in use: sales history is protected, "move then delete" carries everything over ---
    $insertSale = fn(int $speciesId) => $pdo->prepare('INSERT INTO sale_transactions (species_id, quantity, unit_price, total_price) VALUES (:s, 2, 100, 200)')->execute(['s' => $speciesId]);
    $countFor = fn(string $table, int $speciesId) => (int) $pdo->query("SELECT COUNT(*) FROM $table WHERE species_id = " . $speciesId)->fetchColumn();
    $locationOf = fn(array $r) => preg_match('/Location:\s*(\S+)/', $r['headers'], $m) ? $m[1] : '';

    $nameSrc = 'ทดสอบต้นทาง ' . bin2hex(random_bytes(3));
    $nameDst = 'ทดสอบปลายทาง ' . bin2hex(random_bytes(3));
    $nameSalesOnly = 'ทดสอบเฉพาะการขาย ' . bin2hex(random_bytes(3));
    httpRequest("$base/admin/species_form.php", $cookieFile, $basePost($nameSrc) + [
        'plant_zone_id' => (string) $zoneId, 'plant_quantity' => '2', 'plant_status' => 'healthy',
        'stock_size_id' => (string) $sizeId, 'stock_quantity' => '7', 'stock_price' => '120', 'stock_status' => 'available',
    ], ['image' => $speciesImg]);
    httpRequest("$base/admin/species_form.php", $cookieFile, $basePost($nameDst));
    httpRequest("$base/admin/species_form.php", $cookieFile, $basePost($nameSalesOnly));
    $src = $findSpecies($nameSrc);
    $dst = $findSpecies($nameDst);
    $salesOnly = $findSpecies($nameSalesOnly);
    check('source, target and sales-only species exist', $src && $dst && $salesOnly);

    if ($src && $dst && $salesOnly) {
        $srcId = (int) $src['id'];
        $dstId = (int) $dst['id'];
        $srcImageAbs = publicDir() . '/' . $src['image_path'];
        $insertSale($srcId);
        $pdo->prepare('INSERT INTO planting_plans (zone_id, species_id, planned_quantity) VALUES (:z, :s, 3)')->execute(['z' => $zoneId, 's' => $srcId]);
        $insertSale((int) $salesOnly['id']);
        check('speciesUsageCount() counts trees + plan + sale', speciesUsageCount($pdo, $srcId) === 4, (string) speciesUsageCount($pdo, $srcId));

        // 9a. Sales history alone blocks a plain delete (no silent loss via ON DELETE CASCADE).
        $rSales = httpRequest("$base/admin/species_delete.php", $cookieFile, ['csrf_token' => $formToken(), 'id' => (string) $salesOnly['id']]);
        check('a species with only sales history is refused (bulk_skipped=1)', $rSales['status'] === 302 && str_contains($locationOf($rSales), 'bulk_skipped=1'), $locationOf($rSales));
        check('...and its species row is still there', $findSpecies($nameSalesOnly) !== null);
        check('...and its sales history is still there', $countFor('sale_transactions', (int) $salesOnly['id']) === 1);

        // 9b. Invalid reassign targets change nothing.
        foreach ([['a target that does not exist', '999999999'], ['the species itself', (string) $srcId]] as [$why, $badTarget]) {
            $rBad = httpRequest("$base/admin/species_delete.php", $cookieFile, ['csrf_token' => $formToken(), 'id' => (string) $srcId, 'reassign_to' => $badTarget]);
            check("reassigning to $why is rejected", $rBad['status'] === 302 && str_contains($locationOf($rBad), 'reassign_error=invalid'), $locationOf($rBad));
        }
        check('...and nothing was moved or deleted', $findSpecies($nameSrc) !== null && $countFor('trees', $srcId) === 2 && $countFor('sale_transactions', $srcId) === 1);

        // 9c. Move everything to the target, then delete the source.
        $rMove = httpRequest("$base/admin/species_delete.php", $cookieFile, ['csrf_token' => $formToken(), 'id' => (string) $srcId, 'reassign_to' => (string) $dstId]);
        $loc = $locationOf($rMove);
        clearstatcache();
        check('"move then delete" redirects with a moved count', $rMove['status'] === 302 && str_contains($loc, 'moved=2') && str_contains($loc, 'bulk_deleted=1'), $loc);
        check('the source species is deleted', $findSpecies($nameSrc) === null);
        check('its 2 trees now belong to the target species', $countFor('trees', $dstId) === 2);
        $codes = $pdo->query('SELECT plant_code FROM trees WHERE species_id = ' . $dstId)->fetchAll(PDO::FETCH_COLUMN);
        $wantPrefix = $dst['category_code'] . $dst['species_code'];
        check(
            'the moved trees\' plant_code was recomputed for the target species',
            count($codes) === 2 && count(array_filter($codes, fn($c) => str_starts_with((string) $c, $wantPrefix))) === 2,
            json_encode($codes) . " want prefix $wantPrefix"
        );
        check('its planting plan moved to the target', $countFor('planting_plans', $dstId) === 1);
        check('its stock row moved to the target', $countFor('nursery_stock', $dstId) === 1);
        check('its sales history moved to the target (not lost)', $countFor('sale_transactions', $dstId) === 1);
        check('the source species photo file was deleted', !is_file($srcImageAbs));

        // The sales-only species can also be merged away without losing its sale.
        $rMove2 = httpRequest("$base/admin/species_delete.php", $cookieFile, ['csrf_token' => $formToken(), 'id' => (string) $salesOnly['id'], 'reassign_to' => (string) $dstId]);
        check('a sales-only species can be merged into the target', $rMove2['status'] === 302 && $findSpecies($nameSalesOnly) === null && $countFor('sale_transactions', $dstId) === 2);
    }

    // --- 10. A zone that only a planting plan references can't be deleted (and doesn't crash) ---
    $zoneNumber = null;
    foreach (range(900, 999) as $n) {
        if (!$pdo->query("SELECT 1 FROM zones WHERE zone_number = '$n'")->fetchColumn()) {
            $zoneNumber = (string) $n;
            break;
        }
    }
    if ($zoneNumber !== null) {
        $pdo->prepare('INSERT INTO zones (zone_code, zone_number, name) VALUES (:c, :n, :name)')
            ->execute(['c' => 'ZZTEST' . $zoneNumber, 'n' => $zoneNumber, 'name' => 'โซนทดสอบ ' . $zoneNumber]);
        $tempZoneId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO planting_plans (zone_id, planned_quantity) VALUES (:z, 1)')->execute(['z' => $tempZoneId]);
        $zoneExists = fn() => (bool) $pdo->query("SELECT 1 FROM zones WHERE id = $tempZoneId")->fetchColumn();
        check('zoneUsageCount() counts the planting plan', zoneUsageCount($pdo, $tempZoneId) === 1);

        $rZ1 = httpRequest("$base/admin/zone_delete.php", $cookieFile, ['csrf_token' => $formToken(), 'id' => (string) $tempZoneId]);
        check('single delete of a plan-referenced zone is refused cleanly (no server error)', $rZ1['status'] === 302 && str_contains($locationOf($rZ1), 'bulk_skipped=1'), "got {$rZ1['status']}: " . substr(strip_tags($rZ1['body']), 0, 200));
        check('the zone is still there after the refused delete', $zoneExists());
        $rZ2 = httpRequest("$base/admin/zone_bulk_delete.php", $cookieFile, ['csrf_token' => $formToken(), 'ids' => [$tempZoneId]]);
        check('bulk delete counts the zone as skipped instead of crashing', $rZ2['status'] === 302 && str_contains($locationOf($rZ2), 'bulk_deleted=0&bulk_skipped=1'), "got {$rZ2['status']}: " . substr(strip_tags($rZ2['body']), 0, 200));
        check('the zone is still there after the refused bulk delete', $zoneExists());

        $pdo->prepare('DELETE FROM planting_plans WHERE zone_id = :z')->execute(['z' => $tempZoneId]);
        $rZ3 = httpRequest("$base/admin/zone_delete.php", $cookieFile, ['csrf_token' => $formToken(), 'id' => (string) $tempZoneId]);
        check('once the plan is gone the zone can be deleted', $rZ3['status'] === 302 && !$zoneExists());
    } else {
        check('found a free zone_number for the zone test', false, 'zone numbers 900-999 all taken');
    }
} catch (Throwable $e) {
    check('test run completed without an unexpected exception', false, $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

$cleanup();
echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
