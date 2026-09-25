<?php
/**
 * Tests for the "AI identifies a tree from its photo" feature:
 *   - includes/plant_identify.php: normalizePlantIdentification() (pure),
 *     scientificNameKey(), findMatchingSpecies() (against the real DB)
 *   - admin/identify_tree.php end to end over real HTTP — auth, permission,
 *     CSRF and upload validation, and the full success/failure paths against
 *     a local mock of the Gemini API (GEMINI_API_BASE points at it), so this
 *     needs neither a real API key nor internet access. The mock also
 *     records what the server actually sent it, to check the photo and the
 *     API key header really go out.
 *
 * Requires the app DB reachable (same as the other tests) and the GD
 * extension (to fabricate a test photo).
 *
 * Run: php tests/plant_identify_test.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/plant_identify.php';

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

// ---------------------------------------------------------------- pure logic

$n = normalizePlantIdentification([
    'is_plant' => true, 'name_th' => '  ปาล์มฤาษี ', 'name_common' => 'Silver thatch palm',
    'name_scientific' => 'Coccothrinax crinita', 'confidence' => 'high',
    'description_th' => 'ปาล์มเล็ก', 'notes_th' => 'เห็นใบชัด',
    'alternatives' => [['name_th' => 'ก', 'name_scientific' => 'A a'], ['name_th' => 'ข'], 'junk', ['name_th' => '', 'name_scientific' => ''],
                       ['name_scientific' => 'C c'], ['name_th' => 'ง'], ['name_th' => 'จ']],
]);
check('normalize: trims and keeps valid fields', $n['is_plant'] === true && $n['name_th'] === 'ปาล์มฤาษี' && $n['confidence'] === 'high');
check('normalize: alternatives — junk/empty entries dropped, capped at 3', count($n['alternatives']) === 3
    && $n['alternatives'][0]['name_th'] === 'ก' && $n['alternatives'][2]['name_scientific'] === 'C c', json_encode($n['alternatives'], JSON_UNESCAPED_UNICODE));

$n = normalizePlantIdentification(['is_plant' => true, 'name_th' => 'x', 'confidence' => 'banana']);
check('normalize: unknown confidence falls back to low', $n['confidence'] === 'low');

$n = normalizePlantIdentification(['is_plant' => true, 'name_th' => '', 'name_scientific' => '']);
check('normalize: is_plant=true but no name at all is treated as not identified', $n['is_plant'] === false);

$n = normalizePlantIdentification(['is_plant' => 'true', 'name_th' => 'x']);
check('normalize: non-boolean is_plant is not trusted', $n['is_plant'] === false);

$n = normalizePlantIdentification(['is_plant' => false, 'name_th' => 'ควรถูกทิ้ง', 'name_scientific' => 'X y', 'notes_th' => 'ไม่ใช่พืช', 'alternatives' => [['name_th' => 'z']]]);
check('normalize: not-a-plant clears every suggestion but keeps the note',
    $n['name_th'] === '' && $n['name_scientific'] === '' && $n['alternatives'] === [] && $n['notes_th'] === 'ไม่ใช่พืช');

$n = normalizePlantIdentification(['is_plant' => true, 'name_th' => str_repeat('ก', 500), 'description_th' => str_repeat('ข', 5000), 'name_common' => ['array']]);
check('normalize: over-long strings are truncated (multibyte-safe), wrong types become empty',
    mb_strlen($n['name_th']) === 120 && mb_strlen($n['description_th']) === 1200 && $n['name_common'] === '');

$n = normalizePlantIdentification([]);
check('normalize: empty input yields a safe not-identified result', $n['is_plant'] === false && $n['confidence'] === 'low' && $n['alternatives'] === []);

check('scientificNameKey: genus + species only, case/author/cultivar-insensitive',
    scientificNameKey("  Cassia FISTULA L. 'Alba' ") === 'cassia fistula' && scientificNameKey('Ficus') === 'ficus' && scientificNameKey('') === '');

$pdo = db();
$existing = $pdo->query("SELECT id, name, name_scientific FROM species WHERE name_scientific IS NOT NULL AND name_scientific <> '' ORDER BY id LIMIT 1")->fetch();
if ($existing) {
    $sci = $existing['name_scientific'];
    $m = findMatchingSpecies($pdo, ['name_scientific' => strtoupper($sci) . ' Author', 'name_th' => 'ชื่ออื่นที่ไม่มีอยู่จริง']);
    check('findMatchingSpecies: matches an existing species by scientific name despite case/author suffix', $m !== null && (int) $m['id'] === (int) $existing['id']);
    $m = findMatchingSpecies($pdo, ['name_scientific' => '', 'name_th' => $existing['name']]);
    check('findMatchingSpecies: falls back to the exact Thai name', $m !== null && (int) $m['id'] === (int) $existing['id']);
} else {
    echo "SKIP  no species with a scientific name in the DB to test matching against\n";
}
check('findMatchingSpecies: unknown plant matches nothing', findMatchingSpecies($pdo, ['name_scientific' => 'Zzyzx nonexistens', 'name_th' => 'ไม่มีชื่อนี้แน่นอน']) === null);

// ------------------------------------------------------- endpoint over HTTP

if (!function_exists('imagecreatetruecolor')) {
    echo "SKIP  GD extension unavailable — cannot fabricate a test photo for the endpoint tests.\n";
    echo "\n$pass passed, $fail failed\n";
    exit($fail === 0 ? 0 : 1);
}

$host = '127.0.0.1';
$appPort = 8096;
$mockPort = 8097;
$app = "http://$host:$appPort";
$tmp = sys_get_temp_dir();
$mockResponseFile = "$tmp/mock_gemini_response.txt";
$mockStatusFile = "$tmp/mock_gemini_status.txt";
$mockLogFile = "$tmp/mock_gemini_request.json";
$mockRouter = "$tmp/mock_gemini_router.php";
@unlink($mockLogFile);

// A stand-in for generativelanguage.googleapis.com: records the request it
// got and replies with whatever the test put in the response/status files.
file_put_contents($mockRouter, <<<'PHP'
<?php
$dir = sys_get_temp_dir();
file_put_contents("$dir/mock_gemini_request.json", json_encode([
    'path' => parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH),
    'api_key_header' => $_SERVER['HTTP_X_GOOG_API_KEY'] ?? null,
    'body' => json_decode(file_get_contents('php://input'), true),
]));
$status = (int) (@file_get_contents("$dir/mock_gemini_status.txt") ?: 200);
http_response_code($status);
header('Content-Type: application/json');
if ($status !== 200) {
    echo json_encode(['error' => ['message' => 'mock failure']]);
    return;
}
echo json_encode(['candidates' => [['content' => ['parts' => [['text' => (string) @file_get_contents("$dir/mock_gemini_response.txt")]]]]]]);
PHP);

function startServer(string $cmd, string $host, int $port, array $env = []): mixed
{
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__, array_merge(getenv(), $env), ['bypass_shell' => true]);
    if (!is_resource($proc)) {
        fwrite(STDERR, "Could not start server: $cmd\n");
        exit(1);
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    for ($i = 0; $i < 40; $i++) {
        $conn = @fsockopen($host, $port, $errno, $errstr, 0.25);
        if ($conn) {
            fclose($conn);
            return $proc;
        }
        usleep(100000);
    }
    fwrite(STDERR, "Server never came up on $host:$port\n");
    proc_terminate($proc);
    exit(1);
}

// XAMPP's own php.exe (not whatever "php" is on PATH), same as the other
// tests, so the server loads the same php.ini/extensions as Apache does.
$php = '"C:\\xampp\\php\\php.exe"';
$mockProc = startServer("$php -S $host:$mockPort " . escapeshellarg($mockRouter), $host, $mockPort);
$appProc = startServer(
    "$php -S $host:$appPort -t " . escapeshellarg(__DIR__ . '/..'),
    $host,
    $appPort,
    ['GEMINI_API_KEY' => 'test-key-123', 'GEMINI_API_BASE' => "http://$host:$mockPort"]
);
// Second app instance with NO API key, for the "AI not configured" path.
$noKeyPort = 8098;
$noKeyProc = startServer("$php -S $host:$noKeyPort -t " . escapeshellarg(__DIR__ . '/..'), $host, $noKeyPort, ['GEMINI_API_KEY' => '']);

$testAdminUser = 'zz_test_identify_' . bin2hex(random_bytes(3));       // executive role: no tree/species permissions
$testEditorUser = 'zz_test_identify_ed_' . bin2hex(random_bytes(3));   // programmer role: allowed
$testAdminPass = 'T3st-' . bin2hex(random_bytes(6));
$cookieFiles = [];

register_shutdown_function(function () use (&$appProc, &$mockProc, &$noKeyProc, $pdo, $testAdminUser, $testEditorUser, &$cookieFiles, $mockRouter, $mockResponseFile, $mockStatusFile, $mockLogFile) {
    try {
        $del = $pdo->prepare('DELETE FROM admins WHERE username = :u');
        $del->execute(['u' => $testAdminUser]);
        $del->execute(['u' => $testEditorUser]);
    } catch (Throwable $e) {
        echo "WARN  could not delete test admins: {$e->getMessage()}\n";
    }
    foreach ([$appProc, $mockProc, $noKeyProc] as $proc) {
        if (is_resource($proc)) {
            proc_terminate($proc);
            proc_close($proc);
        }
    }
    foreach (array_merge($cookieFiles, [$mockRouter, $mockResponseFile, $mockStatusFile, $mockLogFile]) as $f) {
        @unlink($f);
    }
});

/** @return array{status:int, json:?array, body:string} */
function request(string $url, string $cookieFile, ?array $post = null, bool $asMultipart = false): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $asMultipart ? $post : http_build_query($post));
    }
    $body = curl_exec($ch);
    if ($body === false) {
        throw new RuntimeException('curl error: ' . curl_error($ch));
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'json' => json_decode((string) $body, true), 'body' => (string) $body];
}

function login(string $base, string $user, string $pass, array &$cookieFiles): array
{
    $cookie = tempnam(sys_get_temp_dir(), 'identify_cookies_');
    $cookieFiles[] = $cookie;
    $page = request("$base/admin/login.php", $cookie);
    preg_match('/name="csrf_token" value="([^"]+)"/', $page['body'], $m);
    $csrf = $m[1] ?? '';
    $r = request("$base/admin/login.php", $cookie, ['username' => $user, 'password' => $pass, 'csrf_token' => $csrf]);
    return ['cookie' => $cookie, 'csrf' => $csrf, 'ok' => $r['status'] === 302];
}

// A real (small) JPEG, so getimagesize() accepts it.
$img = imagecreatetruecolor(64, 48);
imagefilledrectangle($img, 0, 0, 63, 47, imagecolorallocate($img, 30, 140, 60));
$jpegPath = "$tmp/identify_test_photo.jpg";
imagejpeg($img, $jpegPath, 80);
$cookieFiles[] = $jpegPath;
$notImagePath = "$tmp/identify_test_not_image.jpg";
file_put_contents($notImagePath, 'this is plain text, not an image');
$cookieFiles[] = $notImagePath;

$endpoint = "$app/admin/identify_tree.php";

try {
    // --- access control / validation (none of these should ever reach Gemini) ---
    $anon = tempnam($tmp, 'identify_anon_');
    $cookieFiles[] = $anon;
    $r = request($endpoint, $anon);
    check('GET is rejected (405)', $r['status'] === 405 && ($r['json']['ok'] ?? null) === false, "got {$r['status']}");
    $r = request($endpoint, $anon, ['image' => new CURLFile($jpegPath, 'image/jpeg', 'p.jpg'), 'csrf_token' => 'x'], true);
    check('not logged in -> 401 JSON, not a login redirect', $r['status'] === 401 && ($r['json']['ok'] ?? null) === false, "got {$r['status']}: {$r['body']}");

    // executive role (2): a real admin account that lacks every tree/species permission
    $pdo->prepare('INSERT INTO admins (username, password_hash, role_id) VALUES (:u, :p, 2)')
        ->execute(['u' => $testAdminUser, 'p' => password_hash($testAdminPass, PASSWORD_DEFAULT)]);
    $exec = login($app, $testAdminUser, $testAdminPass, $cookieFiles);
    check('executive test admin can log in', $exec['ok']);
    $r = request($endpoint, $exec['cookie'], ['image' => new CURLFile($jpegPath, 'image/jpeg', 'p.jpg'), 'csrf_token' => $exec['csrf']], true);
    check('a role without species/tree permissions -> 403', $r['status'] === 403, "got {$r['status']}: {$r['body']}");

    $pdo->prepare('INSERT INTO admins (username, password_hash, role_id) VALUES (:u, :p, 1)')
        ->execute(['u' => $testEditorUser, 'p' => password_hash($testAdminPass, PASSWORD_DEFAULT)]);
    $admin = login($app, $testEditorUser, $testAdminPass, $cookieFiles);
    check('an admin with tree/species permissions can log in', $admin['ok']);

    $photo = fn() => new CURLFile($jpegPath, 'image/jpeg', 'photo.jpg');
    $r = request($endpoint, $admin['cookie'], ['image' => $photo()], true);
    check('missing CSRF token -> 400', $r['status'] === 400, "got {$r['status']}: {$r['body']}");
    $r = request($endpoint, $admin['cookie'], ['image' => $photo(), 'csrf_token' => 'wrong'], true);
    check('wrong CSRF token -> 400', $r['status'] === 400, "got {$r['status']}");
    $r = request($endpoint, $admin['cookie'], ['csrf_token' => $admin['csrf']], true);
    check('no photo -> 400', $r['status'] === 400 && str_contains($r['json']['error'] ?? '', 'รูป'), "got {$r['status']}: {$r['body']}");
    $r = request($endpoint, $admin['cookie'], ['image' => new CURLFile($notImagePath, 'image/jpeg', 'fake.jpg'), 'csrf_token' => $admin['csrf']], true);
    check('a non-image file (even if named .jpg) -> 400', $r['status'] === 400, "got {$r['status']}: {$r['body']}");
    check('none of the rejected requests reached the (mock) Gemini API', !is_file($mockLogFile));

    // --- AI not configured ---
    $noKeyAdmin = login("http://$host:$noKeyPort", $testEditorUser, $testAdminPass, $cookieFiles);
    if ($noKeyAdmin['ok']) {
        $r = request("http://$host:$noKeyPort/admin/identify_tree.php", $noKeyAdmin['cookie'], ['image' => $photo(), 'csrf_token' => $noKeyAdmin['csrf']], true);
        // Only meaningful when the machine's config/local.php has no key either.
        if (!AI_ENABLED) {
            check('no API key configured -> 503 with a setup hint', $r['status'] === 503 && str_contains($r['json']['error'] ?? '', 'API key'), "got {$r['status']}: {$r['body']}");
        } else {
            echo "SKIP  config/local.php defines a real GEMINI_API_KEY, so the no-key path can't be exercised here.\n";
        }
    }

    // --- success paths, against the mock Gemini ---
    $sciExisting = $existing['name_scientific'] ?? 'Coccothrinax crinita';
    file_put_contents($mockStatusFile, '200');
    file_put_contents($mockResponseFile, json_encode([
        'is_plant' => true, 'name_th' => 'ชื่อที่ AI ตั้ง', 'name_common' => 'Some palm', 'name_scientific' => $sciExisting . ' subsp. x',
        'confidence' => 'high', 'description_th' => 'คำอธิบายทดสอบ', 'notes_th' => 'ดูจากใบ', 'alternatives' => [],
    ], JSON_UNESCAPED_UNICODE));
    $r = request($endpoint, $admin['cookie'], ['image' => $photo(), 'csrf_token' => $admin['csrf']], true);
    check('identification succeeds (200, ok=true)', $r['status'] === 200 && ($r['json']['ok'] ?? null) === true, "got {$r['status']}: {$r['body']}");
    $res = $r['json']['result'] ?? [];
    check('result carries the AI suggestion', ($res['name_th'] ?? '') === 'ชื่อที่ AI ตั้ง' && ($res['confidence'] ?? '') === 'high' && ($res['is_plant'] ?? false) === true);
    if ($existing) {
        check('result points at the already-catalogued species with the same scientific name',
            ($res['matched_species_id'] ?? null) === (int) $existing['id'] && ($res['matched_species_name'] ?? null) === $existing['name'], json_encode($res, JSON_UNESCAPED_UNICODE));
    }

    $sent = json_decode((string) @file_get_contents($mockLogFile), true) ?: [];
    check('server called the Gemini generateContent endpoint', str_contains($sent['path'] ?? '', ':generateContent'), (string) ($sent['path'] ?? 'no request recorded'));
    check('API key is sent as the X-goog-api-key header (not in the URL)', ($sent['api_key_header'] ?? null) === 'test-key-123' && !str_contains($sent['path'] ?? '', 'test-key'));
    $parts = $sent['body']['contents'][0]['parts'] ?? [];
    $inline = null;
    foreach ($parts as $p) {
        if (isset($p['inline_data'])) {
            $inline = $p['inline_data'];
        }
    }
    check('the uploaded photo was forwarded to Gemini as inline image data',
        $inline !== null && ($inline['mime_type'] ?? '') === 'image/jpeg' && ($inline['data'] ?? '') === base64_encode((string) file_get_contents($jpegPath)));
    check('the prompt asks for JSON output', ($sent['body']['generationConfig']['response_mime_type'] ?? '') === 'application/json');

    // unknown plant -> no match
    file_put_contents($mockResponseFile, json_encode(['is_plant' => true, 'name_th' => 'ต้นที่ไม่มีในระบบ', 'name_scientific' => 'Zzyzx nonexistens', 'confidence' => 'low',
        'alternatives' => [['name_th' => 'อื่น', 'name_scientific' => 'Aa bb']]], JSON_UNESCAPED_UNICODE));
    $r = request($endpoint, $admin['cookie'], ['image' => $photo(), 'csrf_token' => $admin['csrf']], true);
    check('a plant not in the catalogue -> matched_species_id null, alternatives passed through',
        $r['status'] === 200 && array_key_exists('matched_species_id', $r['json']['result'] ?? []) && $r['json']['result']['matched_species_id'] === null && count($r['json']['result']['alternatives'] ?? []) === 1, $r['body']);

    // not a plant
    file_put_contents($mockResponseFile, json_encode(['is_plant' => false, 'name_th' => '', 'notes_th' => 'เป็นรูปโต๊ะ'], JSON_UNESCAPED_UNICODE));
    $r = request($endpoint, $admin['cookie'], ['image' => $photo(), 'csrf_token' => $admin['csrf']], true);
    check('a photo that is not a plant -> 200 with is_plant=false and the AI note',
        $r['status'] === 200 && ($r['json']['result']['is_plant'] ?? true) === false && ($r['json']['result']['notes_th'] ?? '') === 'เป็นรูปโต๊ะ' && array_key_exists('matched_species_id', $r['json']['result'] ?? []) && $r['json']['result']['matched_species_id'] === null, $r['body']);

    // failure modes -> a clean JSON error, never a PHP error
    file_put_contents($mockResponseFile, 'this is not json at all');
    $r = request($endpoint, $admin['cookie'], ['image' => $photo(), 'csrf_token' => $admin['csrf']], true);
    check('malformed AI output -> 502 with a friendly message', $r['status'] === 502 && ($r['json']['ok'] ?? null) === false && !str_contains($r['body'], 'Warning'), "got {$r['status']}: {$r['body']}");

    file_put_contents($mockStatusFile, '500');
    $r = request($endpoint, $admin['cookie'], ['image' => $photo(), 'csrf_token' => $admin['csrf']], true);
    check('Gemini returning HTTP 500 -> 502 that includes the upstream reason',
        $r['status'] === 502 && str_contains($r['json']['error'] ?? '', '500') && str_contains($r['json']['error'] ?? '', 'mock failure'), "got {$r['status']}: {$r['body']}");
    check('the API key never appears in an error response', !str_contains($r['body'], 'test-key-123'));
    file_put_contents($mockStatusFile, '200');

    // the two forms actually expose the button + script
    foreach (['species_form.php', 'tree_form.php'] as $formPage) {
        $page = request("$app/admin/$formPage", $admin['cookie']);
        check("$formPage renders the AI-identify button and loads its script, with the key configured",
            $page['status'] === 200 && str_contains($page['body'], 'data-ai-identify-for="image"') && str_contains($page['body'], 'ai-identify-button.js') && !str_contains($page['body'], 'data-ai-unavailable'), "got {$page['status']}");
    }
    $page = request("http://$host:$noKeyPort/admin/species_form.php", $noKeyAdmin['cookie']);
    if (!AI_ENABLED) {
        check('species_form.php with no API key marks the button unavailable and explains why', str_contains($page['body'], 'data-ai-unavailable="1"') && str_contains($page['body'], 'Gemini API key'));
    }
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    $fail++;
}

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
