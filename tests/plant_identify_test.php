<?php
/**
 * Tests for the "AI identifies a tree from its photo" feature:
 *   - includes/plant_identify.php: normalizePlantIdentification() (pure),
 *     scientificNameKey(), findMatchingSpecies() (against the real DB)
 *   - admin/identify_tree.php end to end over real HTTP — auth, permission,
 *     CSRF and upload validation, and the full success/failure paths against
 *     a local mock of the Ollama API (OLLAMA_URL points at it), so this
 *     needs neither a real model nor internet access. The mock also
 *     records what the server actually sent it, to check the photo and
 *     model really go out.
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

// --- confidence percentage and second opinion (pure logic, no network) ---
$n = fn(array $r) => normalizePlantIdentification($r + ['is_plant' => true, 'name_th' => 'ต้น', 'name_scientific' => 'Aa bb']);
check('confidence_pct: an integer is kept, clamped to 0-100, label derived',
    $n(['confidence_pct' => 82])['confidence_pct'] === 82 && $n(['confidence_pct' => 82])['confidence'] === 'high'
    && $n(['confidence_pct' => 140])['confidence_pct'] === 100 && $n(['confidence_pct' => -5])['confidence_pct'] === 0
    && $n(['confidence_pct' => 55])['confidence'] === 'medium' && $n(['confidence_pct' => 20])['confidence'] === 'low');
check('confidence_pct: "73%" strings are understood; junk falls back to low (30)',
    $n(['confidence_pct' => '73%'])['confidence_pct'] === 73 && $n(['confidence_pct' => 'lots'])['confidence_pct'] === 30 && $n([])['confidence_pct'] === 30);
check('confidence_pct: the older high/medium/low still maps to a percentage',
    $n(['confidence' => 'high'])['confidence_pct'] === 85 && $n(['confidence' => 'medium'])['confidence_pct'] === 60);
check('prompt asks for confidence_pct (a number), not high/medium/low',
    str_contains(buildPlantIdentifyPrompt(), 'confidence_pct') && !str_contains(buildPlantIdentifyPrompt(), 'high|medium|low'));

$mk = fn(string $th, string $sci, int $pct, bool $plant = true) => normalizePlantIdentification(['is_plant' => $plant, 'name_th' => $th, 'name_scientific' => $sci, 'confidence_pct' => $pct]);
$agree = applySecondOpinion($mk('ราชพฤกษ์', 'Cassia fistula', 55), $mk('ชัยพฤกษ์', 'Cassia fistula L.', 60), 70);
check('second opinion agrees: confidence rises to max+10 and the case is no longer flagged',
    $agree['second_opinion']['agrees'] === true && $agree['confidence_pct'] === 70 && $agree['needs_review'] === false && $agree['answered_by'] === 'local', json_encode($agree, JSON_UNESCAPED_UNICODE));
$cap = applySecondOpinion($mk('ก', 'Aa bb', 90), $mk('ก', 'Aa bb', 92), 70);
check('second opinion agrees: confidence is capped at 95', $cap['confidence_pct'] === 95);
$diffLocal = applySecondOpinion($mk('ราชพฤกษ์', 'Cassia fistula', 60), $mk('หางนกยูง', 'Delonix regia', 40), 70);
check('second opinion differs and is less sure: keep the local answer, list the other as an alternative, lower confidence, flag for review',
    $diffLocal['name_scientific'] === 'Cassia fistula' && $diffLocal['confidence_pct'] === 40 && $diffLocal['needs_review'] === true
    && $diffLocal['alternatives'][0]['name_scientific'] === 'Delonix regia' && $diffLocal['second_opinion']['agrees'] === false && $diffLocal['answered_by'] === 'local');
$diffSecond = applySecondOpinion($mk('ราชพฤกษ์', 'Cassia fistula', 40), $mk('หางนกยูง', 'Delonix regia', 80), 70);
check('second opinion differs and is more sure: it becomes the main answer, the local one moves to alternatives',
    $diffSecond['name_scientific'] === 'Delonix regia' && $diffSecond['answered_by'] === 'second' && $diffSecond['confidence_pct'] === 60
    && $diffSecond['alternatives'][0]['name_scientific'] === 'Cassia fistula' && $diffSecond['needs_review'] === true);
$none = applySecondOpinion($mk('ราชพฤกษ์', 'Cassia fistula', 50), null, 70);
check('no second opinion: answer unchanged, flagged when under the threshold', $none['second_opinion'] === null && $none['confidence_pct'] === 50 && $none['needs_review'] === true);
check('a confident local answer is not flagged', applySecondOpinion($mk('ก', 'Aa bb', 88), null, 70)['needs_review'] === false);
check('not-a-plant is never flagged or compared', applySecondOpinion($mk('', '', 0, false), $mk('ก', 'Aa bb', 90), 70)['needs_review'] === false);

// --- Pl@ntNet as the teacher (response parsing and merge, no network) ---
$pnJson = json_decode('{"results":[{"score":0.8612,"species":{"scientificNameWithoutAuthor":"Cassia fistula","commonNames":["Golden shower"]}},'
    . '{"score":0.02,"species":{"scientificNameWithoutAuthor":"Cassia abbreviata","commonNames":[]}},{"score":0.01,"species":{"scientificNameWithoutAuthor":"Cassia javanica"}},{"score":"x"},{"species":{}}]}', true);
$pn = parsePlantnetResults($pnJson);
check('Pl@ntNet: best match, rounded percentage, common name and alternatives parsed',
    $pn !== null && $pn['name_scientific'] === 'Cassia fistula' && $pn['name_common'] === 'Golden shower' && $pn['confidence_pct'] === 86
    && count($pn['alternatives']) === 2 && $pn['alternatives'][0]['name_scientific'] === 'Cassia abbreviata', json_encode($pn));
check('Pl@ntNet: empty or junk results give null', parsePlantnetResults([]) === null && parsePlantnetResults(['results' => [['score' => 'x']]]) === null);
$pnNorm = normalizePlantIdentification(['is_plant' => true, 'name_scientific' => 'Cassia fistula', 'name_common' => 'Golden shower', 'confidence_pct' => 86, 'alternatives' => $pn['alternatives']]);
$swap = applySecondOpinion($mk('สัก', 'Tectona grandis', 40), $pnNorm, 70, 'plantnet');
check('Pl@ntNet disagrees and is surer: it becomes the main answer, source recorded, note says Thai name/details must be filled in by hand',
    $swap['name_scientific'] === 'Cassia fistula' && $swap['answered_by'] === 'second' && $swap['second_opinion']['source'] === 'plantnet'
    && $swap['confidence_pct'] === 66 && $swap['needs_review'] === true && str_contains($swap['notes_th'], 'Pl@ntNet') && $swap['name_th'] === '', json_encode($swap, JSON_UNESCAPED_UNICODE));
$okPn = applySecondOpinion($mk('ราชพฤกษ์', 'Cassia fistula', 50), $pnNorm, 70, 'plantnet');
check('Pl@ntNet agrees with the local model: the local write-up is kept and confidence rises',
    $okPn['name_th'] === 'ราชพฤกษ์' && $okPn['confidence_pct'] === 96 - 1 && $okPn['second_opinion']['source'] === 'plantnet' && $okPn['needs_review'] === false, json_encode($okPn, JSON_UNESCAPED_UNICODE));
check('the Pl@ntNet key never appears in the helper\'s source output or in error text', !str_contains((string) file_get_contents(__DIR__ . '/../includes/plantnet.php'), '2b10'));

// The whole flow with a fake Ollama is covered by the HTTP tests below; here only the second-opinion decision,
// using an injected "ask" so no Gemini call is made.
$asked = [];
$fakeAsk = function (string $prompt, string $bytes, string $mime, float $budget, ?string &$err) use (&$asked) {
    $asked[] = $budget;
    return json_encode(['is_plant' => true, 'name_th' => 'ที่สอง', 'name_scientific' => 'Zz yy', 'confidence_pct' => 90]);
};
check('second-opinion helper is available only with a key', secondOpinionAvailable() === (GEMINI_API_KEY !== ''));

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
$mockResponseFile = "$tmp/mock_ollama_response.txt";
$mockStatusFile = "$tmp/mock_ollama_status.txt";
$mockLogFile = "$tmp/mock_ollama_request.json";
$mockRouter = "$tmp/mock_ollama_router.php";
$mockDelayFile = "$tmp/mock_ollama_delay.txt";
@unlink($mockDelayFile);
@unlink($mockLogFile);

// A stand-in for the Ollama server: records the request it got and replies
// with whatever the test put in the response/status files.
file_put_contents($mockRouter, <<<'PHP'
<?php
$dir = sys_get_temp_dir();
file_put_contents("$dir/mock_ollama_request.json", json_encode([
    'path' => parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH),
    'body' => json_decode(file_get_contents('php://input'), true),
]));
$delay = (float) (@file_get_contents("$dir/mock_ollama_delay.txt") ?: 0);
if ($delay > 0) {
    usleep((int) ($delay * 1000000));
}
$status = (int) (@file_get_contents("$dir/mock_ollama_status.txt") ?: 200);
http_response_code($status);
header('Content-Type: application/json');
if ($status !== 200) {
    echo json_encode(['error' => 'mock failure']);
    return;
}
echo json_encode(['message' => ['role' => 'assistant', 'content' => (string) @file_get_contents("$dir/mock_ollama_response.txt")], 'done' => true]);
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
$localConfig = __DIR__ . '/../config/local.php';
$localOverridesModel = is_file($localConfig) && str_contains((string) file_get_contents($localConfig), 'OLLAMA_MODEL');
$mockProc = startServer("$php -S $host:$mockPort " . escapeshellarg($mockRouter), $host, $mockPort);
$appProc = startServer(
    "$php -S $host:$appPort -t " . escapeshellarg(__DIR__ . '/..'),
    $host,
    $appPort,
    // 10 identify calls/hour/admin: exactly the number of requests that get past
    // validation below, so the next one exercises the rate limit.
    ['OLLAMA_URL' => "http://$host:$mockPort", 'OLLAMA_VISION_MODEL' => 'mock-vision', 'AI_IDENTIFY_MAX_PER_HOUR' => '10',
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
$noKeyProc = startServer("$php -S $host:$noKeyPort -t $docRoot", $host, $noKeyPort, ['OLLAMA_MODEL' => '']);
$iniSizeProc = startServer("$php -d upload_max_filesize=256 -S $host:$iniSizePort -t $docRoot", $host, $iniSizePort, ['OLLAMA_URL' => "http://$host:$mockPort"]);
$warmPort = 8102;
$warmProc = startServer("$php -S $host:$warmPort -t $docRoot", $host, $warmPort, ['OLLAMA_URL' => "http://$host:$mockPort", 'OLLAMA_VISION_MODEL' => 'mock-vision', 'AI_IDENTIFY_CACHE_SECONDS' => '600']);
$postSizeProc = startServer("$php -d post_max_size=200 -S $host:$postSizePort -t $docRoot", $host, $postSizePort, ['OLLAMA_URL' => "http://$host:$mockPort"]);

// Throwaway admins (tests/_test_admin.php deletes them at exit, however the
// script ends): an executive — no tree/species permissions — and a
// programmer, who has them.
require_once __DIR__ . '/_test_admin.php';
$execAdmin = createTestAdmin($pdo, 2);
$editorAdmin = createTestAdmin($pdo, 1);
$warmAdmin = createTestAdmin($pdo, 1);
$cookieFiles = [];

register_shutdown_function(function () use (&$appProc, &$mockProc, &$noKeyProc, &$iniSizeProc, &$postSizeProc, &$warmProc, &$cookieFiles, $mockRouter, $mockResponseFile, $mockStatusFile, $mockLogFile, $mockDelayFile) {
    foreach ([$appProc, $mockProc, $noKeyProc, $iniSizeProc, $postSizeProc, $warmProc] as $proc) {
        if (is_resource($proc)) {
            proc_terminate($proc);
            proc_close($proc);
        }
    }
    foreach (array_merge($cookieFiles, [$mockRouter, $mockResponseFile, $mockStatusFile, $mockLogFile, $mockDelayFile]) as $f) {
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
    // --- access control / validation (none of these should ever reach Ollama) ---
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
    check('none of the rejected requests reached the (mock) Ollama server', !is_file($mockLogFile));

    // --- AI not configured ---
    $noKeyAdmin = login("http://$host:$noKeyPort", $editorAdmin['username'], $editorAdmin['password'], $cookieFiles);
    if ($noKeyAdmin['ok']) {
        $r = request("http://$host:$noKeyPort/admin/identify_tree.php", $noKeyAdmin['cookie'], ['image' => $photo(), 'csrf_token' => $noKeyAdmin['csrf']], true);
        // Only meaningful when the machine's config/local.php doesn't override OLLAMA_MODEL.
        if (!$localOverridesModel) {
            check('AI turned off (OLLAMA_MODEL empty) -> 503 with a setup hint', $r['status'] === 503 && str_contains($r['json']['error'] ?? '', 'ปิดการใช้งาน AI'), "got {$r['status']}: {$r['body']}");
        } else {
            echo "SKIP  config/local.php defines OLLAMA_MODEL, so the AI-off path can't be exercised here.\n";
        }
    }

    // --- success paths, against the mock Ollama ---
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
    check('server called the Ollama /api/chat endpoint', ($sent['path'] ?? '') === '/api/chat', (string) ($sent['path'] ?? 'no request recorded'));
    $msg = $sent['body']['messages'][0] ?? [];
    check('the vision model is used', ($sent['body']['model'] ?? '') === 'mock-vision');
    check('the uploaded photo was forwarded to Ollama as a base64 image',
        ($msg['images'][0] ?? '') === base64_encode((string) file_get_contents($jpegPath)));
    check('the request asks for JSON output without streaming', ($sent['body']['format'] ?? '') === 'json' && ($sent['body']['stream'] ?? null) === false);

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
    $briefText = (string) ($sentBrief['body']['messages'][0]['content'] ?? '');
    check('a normal request does not ask the model for the long write-up (cheaper, faster)', $briefText !== '' && !str_contains($briefText, 'care_instructions'));

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
        $fullText = (string) ($sentFull['body']['messages'][0]['content'] ?? '');
        check('detail=full: the prompt offers the real category list from the database',
            str_contains($fullText, $catCode . ' = ' . $realCats[0]['name_th']) && str_contains($fullText, 'care_instructions'));

        file_put_contents($mockResponseFile, json_encode(['is_plant' => true, 'name_th' => 'ต้นทดสอบ', 'category_code' => 'ZZZ', 'subtype_ids' => [999998]], JSON_UNESCAPED_UNICODE));
        $r = request($endpoint, $admin['cookie'], ['image' => $photo(), 'csrf_token' => $admin['csrf'], 'detail' => 'full'], true);
        $res = $r['json']['result'] ?? [];
        check('detail=full: an invented category code / subtype id never reaches the browser',
            $r['status'] === 200 && array_key_exists('category_code', $res) && $res['category_code'] === null && ($res['subtype_ids'] ?? 'x') === [], $r['body']);
    } else {
        echo "SKIP  no categories/subtypes in the DB to test the full write-up against.\n";
        // keep the number of served requests at 5 so the quota check below still lines up
        request($endpoint, $admin['cookie'], ['image' => $photo(), 'csrf_token' => $admin['csrf']], true);
        request($endpoint, $admin['cookie'], ['image' => $photo(), 'csrf_token' => $admin['csrf']], true);
    }
    // --- upstream failures ---
    file_put_contents($mockResponseFile, json_encode(['is_plant' => true, 'name_th' => 'x', 'confidence' => 'low'], JSON_UNESCAPED_UNICODE));
    file_put_contents($mockStatusFile, '503');
    $r = request($endpoint, $admin['cookie'], ['image' => $photo(), 'csrf_token' => $admin['csrf']], true);
    check('Ollama overloaded (503) -> clean 502 naming the status code', $r['status'] === 502 && str_contains($r['json']['error'] ?? '', '503'), "got {$r['status']}: {$r['body']}");

    file_put_contents($mockStatusFile, '404');
    $r = request($endpoint, $admin['cookie'], ['image' => $photo(), 'csrf_token' => $admin['csrf']], true);
    check('vision model not pulled (404) -> 502 telling the admin to run ollama pull', $r['status'] === 502 && str_contains($r['json']['error'] ?? '', 'ollama pull mock-vision'), "got {$r['status']}: {$r['body']}");
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
    check('Ollama returning HTTP 500 -> 502 naming the status code',
        $r['status'] === 502 && str_contains($r['json']['error'] ?? '', '500'), "got {$r['status']}: {$r['body']}");
    check("the provider's own error text is NOT passed on to the browser", !str_contains($r['body'], 'mock failure'), $r['body']);
    file_put_contents($mockStatusFile, '200');

    // Ten requests have now been served (limit is 10/hour): the next is
    // refused locally, before it reaches the model.
    @unlink($mockLogFile);
    $r = request($endpoint, $admin['cookie'], ['image' => $photo(), 'csrf_token' => $admin['csrf']], true);
    check('over the hourly quota -> 429 and Ollama is not called', $r['status'] === 429 && !is_file($mockLogFile), "got {$r['status']}: {$r['body']}");

    // --- "warm" path: opening the form pre-loads the lists the click needs; permission is still checked on every click ---
    file_put_contents($mockResponseFile, json_encode(['is_plant' => true, 'name_th' => 'ต้นทดสอบ', 'name_scientific' => 'Warmus testus', 'confidence' => 'high'], JSON_UNESCAPED_UNICODE));
    $warm = login("http://$host:$warmPort", $warmAdmin['username'], $warmAdmin['password'], $cookieFiles);
    $warmEndpoint = "http://$host:$warmPort/admin/identify_tree.php";
    request("http://$host:$warmPort/admin/species_form.php", $warm['cookie']); // opening the form is what warms it
    check('opening the species form writes the category/subtype/species lists to the cache',
        identifyCacheAge('categories') !== null && identifyCacheAge('subtypes') !== null && identifyCacheAge('species') !== null);
    if ($existing) {
        $r0 = request($warmEndpoint, $warm['cookie'], ['image' => $photo(), 'csrf_token' => $warm['csrf']], true);
        $sentWarm = json_decode((string) @file_get_contents($mockLogFile), true) ?: [];
        $warmText = '';
        $warmText = (string) ($sentWarm['body']['messages'][0]['content'] ?? '');
        check('with the species list cached (form opened), the prompt offers the nursery\'s own species as candidates',
            $r0['status'] === 200 && str_contains($warmText, scientificNameKey($existing['name_scientific']) === '' ? 'zzz' : implode(' ', array_slice(preg_split('/\s+/', trim($existing['name_scientific'])), 0, 2))), substr($warmText, -700));
    }
    $pdo->prepare('UPDATE admins SET role_id = 2 WHERE username = :u')->execute(['u' => $warmAdmin['username']]);
    $r = request($warmEndpoint, $warm['cookie'], ['image' => $photo(), 'csrf_token' => $warm['csrf'], 'detail' => 'full'], true);
    check('a role revoked after the form was opened is refused at once (403), not after a grace period', $r['status'] === 403, "got {$r['status']}: {$r['body']}");
    $cold = login("http://$host:$warmPort", $warmAdmin['username'], $warmAdmin['password'], $cookieFiles);
    $r = request($warmEndpoint, $cold['cookie'], ['image' => $photo(), 'csrf_token' => $cold['csrf']], true);
    check('a session that never opened the form is refused too (403)', $r['status'] === 403, "got {$r['status']}");
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
    if (!$localOverridesModel) {
        check('species_form.php with AI off marks the button unavailable and explains why', str_contains($page['body'], 'data-ai-unavailable="1"') && str_contains($page['body'], 'ปิดการใช้งาน AI'));
    }
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    $fail++;
}

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
