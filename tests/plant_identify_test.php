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

foreach ([['true', true], [1, true], ['1', true], ['yes', true], ['banana', false], [0, false], ['false', false], [['x'], false], [null, false]] as [$claim, $expected]) {
    $n = normalizePlantIdentification(['is_plant' => $claim, 'name_th' => 'x']);
    check('normalize: is_plant=' . json_encode($claim) . ' -> ' . ($expected ? 'plant' : 'not a plant'), $n['is_plant'] === $expected);
}

$n = normalizePlantIdentification(['is_plant' => false, 'name_th' => 'ควรถูกทิ้ง', 'name_scientific' => 'X y', 'notes_th' => 'ไม่ใช่พืช', 'alternatives' => [['name_th' => 'z']]]);
check('normalize: not-a-plant clears every suggestion but keeps the note',
    $n['name_th'] === '' && $n['name_scientific'] === '' && $n['alternatives'] === [] && $n['notes_th'] === 'ไม่ใช่พืช');

$n = normalizePlantIdentification(['is_plant' => true, 'name_th' => str_repeat('ก', 500), 'description_th' => str_repeat('ข', 5000), 'name_common' => ['array']]);
check('normalize: over-long strings are truncated (multibyte-safe), wrong types become empty',
    mb_strlen($n['name_th']) === 120 && mb_strlen($n['description_th']) === 1200 && $n['name_common'] === '');

$n = normalizePlantIdentification([]);
check('normalize: empty input yields a safe not-identified result', $n['is_plant'] === false && $n['confidence'] === 'low' && $n['alternatives'] === []);

$n = normalizePlantIdentification([
    'is_plant' => true, 'name_th' => 'x', 'care_instructions' => "  รดน้ำวันละครั้ง  ", 'characteristics' => str_repeat('ข', 5000),
    'properties' => ['not a string'], 'benefits' => 'ร่มเงา', 'cautions' => null, 'part_uses' => 'ดอก: ชา',
    'category_code' => ' 01 ', 'subtype_ids' => ['1', 2, 2, 'x', -1, 0, '3', 4, 1.5, ['9']],
]);
check('normalize: long-form fields are trimmed, truncated to 1500, wrong types become empty',
    $n['care_instructions'] === 'รดน้ำวันละครั้ง' && mb_strlen($n['characteristics']) === 1500 && $n['properties'] === '' && $n['cautions'] === '' && $n['part_uses'] === 'ดอก: ชา');
check('normalize: category_code trimmed; subtype_ids keep only positive ints, de-duplicated, max 3', $n['category_code'] === '01' && $n['subtype_ids'] === [1, 2, 3], json_encode($n['subtype_ids']));
$n = normalizePlantIdentification(['is_plant' => false, 'care_instructions' => 'ทิ้ง', 'category_code' => '01', 'subtype_ids' => [1]]);
check('normalize: not-a-plant clears the long-form fields, category and subtypes too',
    $n['care_instructions'] === '' && $n['category_code'] === null && $n['subtype_ids'] === []);

$cats = [['code' => '01', 'name_th' => 'ไม้ผล'], ['code' => '02', 'name_th' => 'ไม้ดอก']];
$subs = [['id' => 1, 'name_th' => 'ไม้ผลกินได้', 'category_code' => '01'], ['id' => 2, 'name_th' => 'ไม้ดอกหอม', 'category_code' => '02'], ['id' => 3, 'name_th' => 'ยังไม่จัดประเภท', 'category_code' => null]];
$c = constrainToCatalogue(['category_code' => '01', 'subtype_ids' => [1, 2, 3, 99]], $cats, $subs);
check('constrain: keeps a real category, drops subtypes that do not exist or belong to another category, keeps unassigned ones',
    $c['category_code'] === '01' && $c['category_name'] === 'ไม้ผล' && $c['subtype_ids'] === [1, 3] && $c['subtype_names'] === ['ไม้ผลกินได้', 'ยังไม่จัดประเภท'], json_encode($c, JSON_UNESCAPED_UNICODE));
$c = constrainToCatalogue(['category_code' => '99', 'subtype_ids' => [1, 2]], $cats, $subs);
check('constrain: an invented category code is dropped (category_name null); subtypes then only need to exist',
    $c['category_code'] === null && $c['category_name'] === null && $c['subtype_ids'] === [1, 2]);

foreach ([['Unknown plant', 'x'], ['unidentified', 'x'], ['N/A', 'x']] as [$sci, $th]) {
    $n = normalizePlantIdentification(['is_plant' => true, 'name_scientific' => $sci]);
    check('normalize: "' . $sci . '" is the model giving up, not a name -> not identified', $n['is_plant'] === false && $n['name_scientific'] === '');
}
$n = normalizePlantIdentification(['is_plant' => true, 'name_scientific' => 'Ficus sp.', 'name_th' => 'ไม่ทราบ']);
check('normalize: a real genus-level answer is kept, a Thai "ไม่ทราบ" (unknown) name is dropped', $n['is_plant'] === true && $n['name_scientific'] === 'Ficus sp.' && $n['name_th'] === '');

$rows = [['name_scientific' => 'Cassia fistula L.'], ['name_scientific' => 'cassia FISTULA'], ['name_scientific' => 'Ficus'], ['name_scientific' => null], ['name_scientific' => 'Plumeria rubra']];
check('knownSpeciesForPrompt: genus + species only, de-duplicated, single-word/empty names skipped', knownSpeciesForPrompt($rows) === ['Cassia fistula', 'Plumeria rubra'], json_encode(knownSpeciesForPrompt($rows)));
check('prompt: known species are offered as candidates, with the "ignore it unless it clearly matches" wording',
    str_contains(buildPlantIdentifyPrompt(null, $rows), 'Cassia fistula; Plumeria rubra') && str_contains(buildPlantIdentifyPrompt(null, $rows), 'ignore this list'));
check('prompt: no list paragraph when there are no known species', !str_contains(buildPlantIdentifyPrompt(null, []), 'already catalogues'));
$briefPrompt = buildPlantIdentifyPrompt();
$fullPrompt = buildPlantIdentifyPrompt(['categories' => $cats, 'subtypes' => $subs]);
check('prompt: brief mode asks for the identification only', !str_contains($briefPrompt, 'care_instructions') && !str_contains($briefPrompt, 'category_code'));
check('prompt: full mode asks for every long-form field and lists the real categories/subtypes to choose from',
    str_contains($fullPrompt, 'care_instructions') && str_contains($fullPrompt, 'part_uses') && str_contains($fullPrompt, '01 = ไม้ผล') && str_contains($fullPrompt, '3 = ยังไม่จัดประเภท'));
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

// consumeIdentifyQuota(): per-admin sliding window, explicit clock so no sleeping
$quotaAdmin = 900000000 + random_int(1, 99999);
$quotaFiles = [sys_get_temp_dir() . "/tree_ai_identify_$quotaAdmin.json", sys_get_temp_dir() . "/tree_ai_identify_" . ($quotaAdmin + 1) . ".json"];
$t0 = 1_000_000;
check('quota: first 3 calls under a limit of 3 are allowed',
    consumeIdentifyQuota($quotaAdmin, 3, $t0) && consumeIdentifyQuota($quotaAdmin, 3, $t0 + 10) && consumeIdentifyQuota($quotaAdmin, 3, $t0 + 20));
check('quota: the 4th within the hour is refused', consumeIdentifyQuota($quotaAdmin, 3, $t0 + 30) === false);
check('quota: a refused call is not counted (still refused, and frees up on schedule)',
    consumeIdentifyQuota($quotaAdmin, 3, $t0 + 3599) === false && consumeIdentifyQuota($quotaAdmin, 3, $t0 + 3601) === true);
check('quota: it is per admin — another admin id is unaffected', consumeIdentifyQuota($quotaAdmin + 1, 3, $t0 + 30) === true);
foreach ($quotaFiles as $qf) {
    @unlink($qf);
}

// identifyCached(): reuses a result for the TTL, TTL 0 always reloads
$cacheName = 'unittest' . random_int(1000, 999999);
$cacheFile = sys_get_temp_dir() . '/tree_ai_cache_' . $cacheName . '.json';
$loads = 0;
$loader = function () use (&$loads) {
    $loads++;
    return [['id' => 1, 'name' => 'ก']];
};
identifyCached($cacheName, 60, $loader);
$second = identifyCached($cacheName, 60, $loader);
check('identifyCached: a second call within the TTL reuses the cache (loader ran once) and returns the same data', $loads === 1 && $second === [['id' => 1, 'name' => 'ก']]);
touch($cacheFile, time() - 500);
identifyCached($cacheName, 60, $loader);
check('identifyCached: an entry older than the TTL is reloaded', $loads === 2);
identifyCached($cacheName, 0, $loader);
check('identifyCached: TTL 0 disables caching', $loads === 3);
@unlink($cacheFile);

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
$mockFailFile = "$tmp/mock_gemini_fail_models.txt";
$mockDelayFile = "$tmp/mock_gemini_delay.txt";
@unlink($mockDelayFile);
$mockPathsFile = "$tmp/mock_gemini_paths.log";
@unlink($mockFailFile);
@unlink($mockPathsFile);
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
file_put_contents("$dir/mock_gemini_paths.log", parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) . "\n", FILE_APPEND);
$delay = (float) (@file_get_contents("$dir/mock_gemini_delay.txt") ?: 0);
if ($delay > 0) {
    usleep((int) ($delay * 1000000));
}
$status = (int) (@file_get_contents("$dir/mock_gemini_status.txt") ?: 200);
// models listed here (comma-separated) answer 429 regardless of the status file
foreach (array_filter(explode(',', (string) @file_get_contents("$dir/mock_gemini_fail_models.txt"))) as $failModel) {
    if (str_contains($_SERVER['REQUEST_URI'], '/models/' . $failModel . ':')) {
        $status = 429;
    }
}
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
    // Server output goes to the null device, NOT a pipe: php -S logs every request
    // (and error_log() lines) to stderr, and a pipe nobody reads fills up after a few
    // KB — at which point the server blocks on its next write and every later request
    // hangs until curl times out.
    $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $proc = proc_open($cmd, [1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes, __DIR__, array_merge(getenv(), $env), ['bypass_shell' => true]);
    if (!is_resource($proc)) {
        fwrite(STDERR, "Could not start server: $cmd\n");
        exit(1);
    }
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
    // 12 identify calls/hour/admin: exactly the number of calls that reach the
    // mock below, so the next one exercises the rate limit. Two models (a, then
    // b as its fallback) so the model chain can be tested.
    ['GEMINI_API_KEY' => 'test-key-123', 'GEMINI_API_BASE' => "http://$host:$mockPort", 'AI_IDENTIFY_MAX_PER_HOUR' => '12',
     'GEMINI_MODEL' => 'mock-model-a', 'GEMINI_FALLBACK_MODELS' => 'mock-model-b',
     // 3 s total for the whole request (the minimum), and no list cache so the DB-derived checks stay deterministic
     'AI_IDENTIFY_MAX_SECONDS' => '3', 'AI_IDENTIFY_CACHE_SECONDS' => '0']
);
// More app instances, each configured for one specific path:
//  - no API key ("AI not configured")
//  - a tiny upload_max_filesize (PHP rejects the file itself: UPLOAD_ERR_INI_SIZE)
//  - a tiny post_max_size (PHP drops the whole body: empty $_POST/$_FILES)
$noKeyPort = 8098;
$iniSizePort = 8100;
$postSizePort = 8101;
$docRoot = escapeshellarg(__DIR__ . '/..');
$noKeyProc = startServer("$php -S $host:$noKeyPort -t $docRoot", $host, $noKeyPort, ['GEMINI_API_KEY' => '']);
$iniSizeProc = startServer("$php -d upload_max_filesize=256 -S $host:$iniSizePort -t $docRoot", $host, $iniSizePort, ['GEMINI_API_KEY' => 'test-key-123', 'GEMINI_API_BASE' => "http://$host:$mockPort"]);
$warmPort = 8102;
$warmProc = startServer("$php -S $host:$warmPort -t $docRoot", $host, $warmPort, ['GEMINI_API_KEY' => 'test-key-123', 'GEMINI_API_BASE' => "http://$host:$mockPort", 'AI_IDENTIFY_CACHE_SECONDS' => '600', 'GEMINI_MODEL' => 'mock-model-a', 'GEMINI_FALLBACK_MODELS' => '']);
$postSizeProc = startServer("$php -d post_max_size=200 -S $host:$postSizePort -t $docRoot", $host, $postSizePort, ['GEMINI_API_KEY' => 'test-key-123', 'GEMINI_API_BASE' => "http://$host:$mockPort"]);

// Throwaway admins (tests/_test_admin.php deletes them at exit, however the
// script ends): an executive — no tree/species permissions — and a
// programmer, who has them.
require_once __DIR__ . '/_test_admin.php';
$execAdmin = createTestAdmin($pdo, 2);
$editorAdmin = createTestAdmin($pdo, 1);
$warmAdmin = createTestAdmin($pdo, 1);
$cookieFiles = [];

register_shutdown_function(function () use (&$appProc, &$mockProc, &$noKeyProc, &$iniSizeProc, &$postSizeProc, &$warmProc, &$cookieFiles, $mockRouter, $mockResponseFile, $mockStatusFile, $mockLogFile, $mockFailFile, $mockPathsFile, $mockDelayFile) {
    foreach ([$appProc, $mockProc, $noKeyProc, $iniSizeProc, $postSizeProc, $warmProc] as $proc) {
        if (is_resource($proc)) {
            proc_terminate($proc);
            proc_close($proc);
        }
    }
    foreach (array_merge($cookieFiles, [$mockRouter, $mockResponseFile, $mockStatusFile, $mockLogFile, $mockFailFile, $mockPathsFile, $mockDelayFile]) as $f) {
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
    // PHP may print a startup warning (e.g. "POST Content-Length exceeds the limit")
    // ahead of the JSON when display_errors is on, as it is for a localhost server.
    $jsonStart = strpos((string) $body, '{"ok"');
    return ['status' => $status, 'json' => json_decode($jsonStart === false ? (string) $body : substr((string) $body, $jsonStart), true), 'body' => (string) $body];
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
    $exec = login($app, $execAdmin['username'], $execAdmin['password'], $cookieFiles);
    check('executive test admin can log in', $exec['ok']);
    $r = request($endpoint, $exec['cookie'], ['image' => new CURLFile($jpegPath, 'image/jpeg', 'p.jpg'), 'csrf_token' => $exec['csrf']], true);
    check('a role without species/tree permissions -> 403', $r['status'] === 403, "got {$r['status']}: {$r['body']}");

    $admin = login($app, $editorAdmin['username'], $editorAdmin['password'], $cookieFiles);
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

    // PHP-level upload limits must give a specific "too large" answer, not
    // "pick a photo" (file rejected by upload_max_filesize) or "CSRF expired"
    // (whole body dropped by post_max_size).
    $iniAdmin = login("http://$host:$iniSizePort", $editorAdmin['username'], $editorAdmin['password'], $cookieFiles);
    $r = request("http://$host:$iniSizePort/admin/identify_tree.php", $iniAdmin['cookie'], ['image' => $photo(), 'csrf_token' => $iniAdmin['csrf']], true);
    check('a file over PHP upload_max_filesize -> 400 "too large", not "pick a photo"',
        $r['status'] === 400 && str_contains($r['json']['error'] ?? '', 'ใหญ่เกินไป'), "got {$r['status']}: {$r['body']}");
    $postAdmin = login("http://$host:$postSizePort", $editorAdmin['username'], $editorAdmin['password'], $cookieFiles);
    $r = request("http://$host:$postSizePort/admin/identify_tree.php", $postAdmin['cookie'], ['image' => $photo(), 'csrf_token' => $postAdmin['csrf']], true);
    check('a request over PHP post_max_size -> 413 "too large", not a CSRF error',
        $r['status'] === 413 && str_contains($r['json']['error'] ?? '', 'ใหญ่เกินไป'), "got {$r['status']}: {$r['body']}");
    check('none of the rejected requests reached the (mock) Gemini API', !is_file($mockLogFile));

    // --- AI not configured ---
    $noKeyAdmin = login("http://$host:$noKeyPort", $editorAdmin['username'], $editorAdmin['password'], $cookieFiles);
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

    // brief request (tree form style): the prompt must not ask for the long write-up.
    // The last call the mock saw was the "not a plant" one above, made without detail=full.
    $sentBrief = json_decode((string) @file_get_contents($mockLogFile), true) ?: [];
    $briefText = '';
    foreach ($sentBrief['body']['contents'][0]['parts'] ?? [] as $p) {
        $briefText .= $p['text'] ?? '';
    }
    check('a normal request does not ask Gemini for the long write-up (cheaper, faster)', $briefText !== '' && !str_contains($briefText, 'care_instructions'));

    // full request (species form): every field, and category/subtypes limited to real ones
    $realCats = getAllCategories($pdo);
    $realSubs = getAllSubtypes($pdo);
    if ($realCats && $realSubs) {
        $catCode = $realCats[0]['code'];
        $subOk = null;
        foreach ($realSubs as $s) {
            if ($s['category_code'] === null || $s['category_code'] === $catCode) {
                $subOk = $s;
                break;
            }
        }
        file_put_contents($mockResponseFile, json_encode([
            'is_plant' => true, 'name_th' => 'ต้นทดสอบ', 'name_common' => 'Test tree', 'name_scientific' => 'Testus fullus', 'confidence' => 'medium',
            'description_th' => 'อธิบาย', 'care_instructions' => 'ดูแลอย่างนี้', 'characteristics' => 'ลักษณะอย่างนี้', 'properties' => 'คุณสมบัติ',
            'benefits' => 'ประโยชน์', 'cautions' => 'ระวัง', 'part_uses' => 'ดอก: ชา', 'category_code' => $catCode,
            'subtype_ids' => array_values(array_filter([$subOk['id'] ?? null, 999999])), 'alternatives' => [],
        ], JSON_UNESCAPED_UNICODE));
        $r = request($endpoint, $admin['cookie'], ['image' => $photo(), 'csrf_token' => $admin['csrf'], 'detail' => 'full'], true);
        $res = $r['json']['result'] ?? [];
        check('detail=full returns all the long-form fields', $r['status'] === 200
            && ($res['care_instructions'] ?? '') === 'ดูแลอย่างนี้' && ($res['characteristics'] ?? '') === 'ลักษณะอย่างนี้' && ($res['properties'] ?? '') === 'คุณสมบัติ'
            && ($res['benefits'] ?? '') === 'ประโยชน์' && ($res['cautions'] ?? '') === 'ระวัง' && ($res['part_uses'] ?? '') === 'ดอก: ชา', $r['body']);
        check('detail=full: the category is resolved to its real name and the invented subtype id is dropped',
            ($res['category_code'] ?? null) === $catCode && ($res['category_name'] ?? null) === $realCats[0]['name_th']
            && !in_array(999999, $res['subtype_ids'] ?? [], true) && (($subOk === null) || in_array((int) $subOk['id'], $res['subtype_ids'] ?? [], true)), $r['body']);
        $sentFull = json_decode((string) @file_get_contents($mockLogFile), true) ?: [];
        $fullText = '';
        foreach ($sentFull['body']['contents'][0]['parts'] ?? [] as $p) {
            $fullText .= $p['text'] ?? '';
        }
        check('detail=full: the prompt offers the real category list from the database',
            str_contains($fullText, $catCode . ' = ' . $realCats[0]['name_th']) && str_contains($fullText, 'care_instructions'));

        file_put_contents($mockResponseFile, json_encode(['is_plant' => true, 'name_th' => 'ต้นทดสอบ', 'category_code' => 'ZZZ', 'subtype_ids' => [999998]], JSON_UNESCAPED_UNICODE));
        $r = request($endpoint, $admin['cookie'], ['image' => $photo(), 'csrf_token' => $admin['csrf'], 'detail' => 'full'], true);
        $res = $r['json']['result'] ?? [];
        check('detail=full: an invented category code / subtype id never reaches the browser',
            $r['status'] === 200 && array_key_exists('category_code', $res) && $res['category_code'] === null && ($res['subtype_ids'] ?? 'x') === [], $r['body']);
    } else {
        echo "SKIP  no categories/subtypes in the DB to test the full write-up against.\n";
        // keep the number of calls that reach the mock at 8 so the quota check below still lines up
        request($endpoint, $admin['cookie'], ['image' => $photo(), 'csrf_token' => $admin['csrf']], true);
        request($endpoint, $admin['cookie'], ['image' => $photo(), 'csrf_token' => $admin['csrf']], true);
    }
    // --- model chain: primary model can't answer -> the fallback model does ---
    $pathsNow = fn() => array_values(array_filter(explode("\n", (string) @file_get_contents($mockPathsFile))));
    $modelOf = fn(string $path) => preg_match('#/models/([^:/]+):#', $path, $mm) ? $mm[1] : '';
    file_put_contents($mockResponseFile, json_encode(['is_plant' => true, 'name_th' => 'ตอบจากโมเดลสำรอง', 'name_scientific' => 'Chainus fallbackus', 'confidence' => 'medium'], JSON_UNESCAPED_UNICODE));
    file_put_contents($mockFailFile, 'mock-model-a');
    @unlink($mockPathsFile);
    $r = request($endpoint, $admin['cookie'], ['image' => $photo(), 'csrf_token' => $admin['csrf']], true);
    $tried = array_map($modelOf, $pathsNow());
    check('primary model out of quota (429) -> the fallback model answers, no error shown',
        $r['status'] === 200 && ($r['json']['result']['name_th'] ?? '') === 'ตอบจากโมเดลสำรอง' && $tried === ['mock-model-a', 'mock-model-b'], "got {$r['status']} tried " . implode(',', $tried) . ": {$r['body']}");
    @unlink($mockFailFile);

    file_put_contents($mockStatusFile, '503');
    @unlink($mockPathsFile);
    $r = request($endpoint, $admin['cookie'], ['image' => $photo(), 'csrf_token' => $admin['csrf']], true);
    $tried = array_map($modelOf, $pathsNow());
    check('every model overloaded (503) -> each model tried once, straight away (no pause: the request has a hard time cap), then a clean 502',
        $r['status'] === 502 && $tried === ['mock-model-a', 'mock-model-b'] && str_contains($r['json']['error'] ?? '', '503'), "got {$r['status']} tried " . implode(',', $tried) . ": {$r['body']}");

    file_put_contents($mockStatusFile, '400');
    @unlink($mockPathsFile);
    $r = request($endpoint, $admin['cookie'], ['image' => $photo(), 'csrf_token' => $admin['csrf']], true);
    $tried = array_map($modelOf, $pathsNow());
    check('a request error (400) would fail on every model, so it is not retried on another', $r['status'] === 502 && $tried === ['mock-model-a'], "tried " . implode(',', $tried));
    file_put_contents($mockStatusFile, '200');
    // --- hard time cap: a slow AI must not hold the request past AI_IDENTIFY_MAX_SECONDS (3 here) ---
    file_put_contents($mockDelayFile, '5');
    $t0 = microtime(true);
    $r = request($endpoint, $admin['cookie'], ['image' => $photo(), 'csrf_token' => $admin['csrf']], true);
    $elapsed = microtime(true) - $t0;
    check('an AI that answers in 5 s is cut off: 504 with a "too slow, try again" message, flagged timed_out',
        $r['status'] === 504 && ($r['json']['timed_out'] ?? false) === true && str_contains($r['json']['error'] ?? '', 'ช้าเกิน'), "got {$r['status']}: {$r['body']}");
    check('...and the whole request returned within the cap (3 s + a little), not after the AI\'s 5 s', $elapsed < 4.2, round($elapsed, 1) . 's');
    unlink($mockDelayFile);
    sleep(3); // the single-threaded mock is still finishing the abandoned 5 s request

    // failure modes -> a clean JSON error, never a PHP error
    file_put_contents($mockResponseFile, 'this is not json at all');
    $r = request($endpoint, $admin['cookie'], ['image' => $photo(), 'csrf_token' => $admin['csrf']], true);
    check('malformed AI output -> 502 with a friendly message', $r['status'] === 502 && ($r['json']['ok'] ?? null) === false && !str_contains($r['body'], 'Warning'), "got {$r['status']}: {$r['body']}");

    file_put_contents($mockStatusFile, '500');
    $r = request($endpoint, $admin['cookie'], ['image' => $photo(), 'csrf_token' => $admin['csrf']], true);
    check('Gemini returning HTTP 500 -> 502 naming the status code',
        $r['status'] === 502 && str_contains($r['json']['error'] ?? '', '500'), "got {$r['status']}: {$r['body']}");
    check("the provider's own error text is NOT passed on to the browser", !str_contains($r['body'], 'mock failure'), $r['body']);
    check('the API key never appears in an error response', !str_contains($r['body'], 'test-key-123'));

    file_put_contents($mockStatusFile, '429');
    $r = request($endpoint, $admin['cookie'], ['image' => $photo(), 'csrf_token' => $admin['csrf']], true);
    check('Gemini quota exhausted (429) -> 502 with a plain "quota" message, no provider text',
        $r['status'] === 502 && str_contains($r['json']['error'] ?? '', 'โควตา') && !str_contains($r['body'], 'mock failure'), "got {$r['status']}: {$r['body']}");
    file_put_contents($mockStatusFile, '200');

    // Twelve calls have now reached the mock (limit is 12/hour): the seventh is
    // refused locally, before it can cost anything.
    @unlink($mockLogFile);
    $r = request($endpoint, $admin['cookie'], ['image' => $photo(), 'csrf_token' => $admin['csrf']], true);
    check('over the hourly quota -> 429 and Gemini is not called', $r['status'] === 429 && !is_file($mockLogFile), "got {$r['status']}: {$r['body']}");

    // --- "warm" path: opening the form pre-loads what the click needs, so the click makes no database round trips ---
    file_put_contents($mockResponseFile, json_encode(['is_plant' => true, 'name_th' => 'ต้นทดสอบ', 'name_scientific' => 'Warmus testus', 'confidence' => 'high'], JSON_UNESCAPED_UNICODE));
    $warm = login("http://$host:$warmPort", $warmAdmin['username'], $warmAdmin['password'], $cookieFiles);
    $warmEndpoint = "http://$host:$warmPort/admin/identify_tree.php";
    request("http://$host:$warmPort/admin/species_form.php", $warm['cookie']); // opening the form is what warms it
    check('opening the species form writes the category/subtype/species lists to the cache',
        identifyCacheAge('categories') !== null && identifyCacheAge('subtypes') !== null && identifyCacheAge('species') !== null);
    // Take the role away in the database. A request that had to consult the database would now be refused;
    // one served from the just-verified marker is not (that short window is the documented trade-off).
    if ($existing) {
        $r0 = request($warmEndpoint, $warm['cookie'], ['image' => $photo(), 'csrf_token' => $warm['csrf']], true);
        $sentWarm = json_decode((string) @file_get_contents($mockLogFile), true) ?: [];
        $warmText = '';
        foreach ($sentWarm['body']['contents'][0]['parts'] ?? [] as $p) {
            $warmText .= $p['text'] ?? '';
        }
        check('with the species list cached (form opened), the prompt offers the nursery\'s own species as candidates',
            $r0['status'] === 200 && str_contains($warmText, scientificNameKey($existing['name_scientific']) === '' ? 'zzz' : implode(' ', array_slice(preg_split('/\s+/', trim($existing['name_scientific'])), 0, 2))), substr($warmText, -700));
    }
    $pdo->prepare('UPDATE admins SET role_id = 2 WHERE username = :u')->execute(['u' => $warmAdmin['username']]);
    $r = request($warmEndpoint, $warm['cookie'], ['image' => $photo(), 'csrf_token' => $warm['csrf'], 'detail' => 'full'], true);
    check('after the form was opened, the click is served with no permission lookup (verified marker, session-side)', $r['status'] === 200 && ($r['json']['ok'] ?? false) === true, "got {$r['status']}: {$r['body']}");
    $cold = login("http://$host:$warmPort", $warmAdmin['username'], $warmAdmin['password'], $cookieFiles);
    $r = request($warmEndpoint, $cold['cookie'], ['image' => $photo(), 'csrf_token' => $cold['csrf']], true);
    check('a session that never opened the form is checked against the database, so the revoked role is refused (403)', $r['status'] === 403, "got {$r['status']}");
    $pdo->prepare('UPDATE admins SET role_id = 1 WHERE username = :u')->execute(['u' => $warmAdmin['username']]);

    // the two forms actually expose the button + script
    foreach (['species_form.php', 'tree_form.php'] as $formPage) {
        $page = request("$app/admin/$formPage", $admin['cookie']);
        check("$formPage renders the AI-identify button and loads its script, with the key configured",
            $page['status'] === 200 && str_contains($page['body'], 'data-ai-identify-for="image"') && str_contains($page['body'], 'ai-identify-button.js') && !str_contains($page['body'], 'data-ai-unavailable'), "got {$page['status']}");
    }
    $page = request("$app/admin/species_form.php", $admin['cookie']);
    check('species_form.php asks for the full write-up; tree_form.php does not',
        str_contains($page['body'], 'data-detail="full"') && !str_contains(request("$app/admin/tree_form.php", $admin['cookie'])['body'], 'data-detail="full"'));
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
