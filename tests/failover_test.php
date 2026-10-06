<?php
/**
 * When the local model (Qwen on Ollama) is down, Gemini / Pl@ntNet take over - tested over real HTTP against local mock
 * servers, with Ollama pointed at a closed port so it really is unreachable. No database, no internet, no keys needed.
 *
 *   php tests/failover_test.php
 *
 * The parent process starts the mocks, then runs each scenario in a child process (the settings are PHP constants,
 * fixed once per process) and checks what comes back.
 */
$mockPort = 8793;
$php = PHP_BINARY;

// ------------------------------------------------------------------ child: one scenario
if (($argv[1] ?? '') === '--case') {
    $case = $argv[2];
    require __DIR__ . '/../includes/plant_identify.php';
    require __DIR__ . '/../includes/translation.php';
    $jpeg = "\xFF\xD8\xFF\xE0" . str_repeat('x', 200);
    if ($case === 'identify_with_details') {
        require __DIR__ . '/../includes/species_details.php';
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $o = identifyPlantFromImage($jpeg, 'image/jpeg', null, 60);
        $r = completeWithDetails($pdo, $o['result'] ?? ['is_plant' => false], null, 40);
        echo json_encode(['ai_enabled' => AI_ENABLED, 'ok' => $o['ok'], 'result' => $r], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($case === 'translate') {
        echo json_encode(['ai_enabled' => AI_ENABLED, 'translation' => aiTranslateFields(['name' => 'ต้นพิกุล'], ['en'])], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['ai_enabled' => AI_ENABLED] + identifyPlantFromImage($jpeg, 'image/jpeg', null, 60), JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ------------------------------------------------------------------ parent
$pass = $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "PASS  $label\n"; } else { $fail++; echo "FAIL  $label" . ($detail !== '' ? "\n      $detail" : '') . "\n"; }
}

$dir = sys_get_temp_dir() . '/failover_mock_' . getmypid();
mkdir($dir);
$modeFile = "$dir/mode.txt";
$callsFile = "$dir/calls.log";
file_put_contents("$dir/router.php", <<<'PHP'
<?php
$dir = __DIR__;
$mode = trim((string) @file_get_contents("$dir/mode.txt"));
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
file_put_contents("$dir/calls.log", $path . "\n", FILE_APPEND);
header('Content-Type: application/json');
if (str_contains($path, ':generateContent')) {
    if ($mode === 'gemini_500') { http_response_code(500); echo '{"error":{"message":"boom"}}'; return; }
    $body = json_decode(file_get_contents('php://input'), true);
    $hasImage = false;
    foreach ($body['contents'][0]['parts'] ?? [] as $p) { if (isset($p['inline_data'])) $hasImage = true; }
    $promptText = $body['contents'][0]['parts'][0]['text'] ?? '';
    if (!$hasImage && str_contains($promptText, 'care_instructions')) {
        $text = json_encode(['description_th' => 'คำอธิบายทั่วไป', 'care_instructions' => 'รดน้ำสัปดาห์ละครั้ง', 'characteristics' => 'ไม้ยืนต้น', 'properties' => 'มีสาร X', 'benefits' => 'ให้ร่มเงา', 'cautions' => 'เมล็ดมีพิษ', 'part_uses' => 'ดอก: ชา'], JSON_UNESCAPED_UNICODE);
        echo json_encode(['candidates' => [['content' => ['parts' => [['text' => $text]]]]]]);
        return;
    }
    $text = $hasImage
        ? json_encode(['is_plant' => true, 'name_th' => 'ราชพฤกษ์', 'name_scientific' => 'Cassia fistula', 'confidence_pct' => 70, 'description_th' => 'คำอธิบายจาก Gemini', 'alternatives' => []], JSON_UNESCAPED_UNICODE)
        : json_encode(['en' => ['name' => 'Translated by Gemini']]);
    echo json_encode(['candidates' => [['content' => ['parts' => [['text' => $text]]]]]]);
    return;
}
if (str_contains($path, '/v2/identify/all')) {
    if ($mode === 'plantnet_404') { http_response_code(404); echo '{"message":"Species not found"}'; return; }
    echo json_encode(['results' => [['score' => 0.86, 'species' => ['scientificNameWithoutAuthor' => $mode === 'plantnet_other' ? 'Delonix regia' : 'Cassia fistula', 'commonNames' => ['Golden shower']]]]]);
    return;
}
http_response_code(404);
PHP);
$mock = proc_open([$php, '-S', "127.0.0.1:$mockPort", "$dir/router.php"], [1 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w'], 2 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w']], $pipes);
register_shutdown_function(function () use ($mock, $dir) {
    proc_terminate($mock);
    proc_close($mock);
    foreach (glob("$dir/*") ?: [] as $f) { @unlink($f); }
    @rmdir($dir);
});
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $mockPort, $e, $s, 0.2); $i++) { usleep(100000); }

/** Runs one scenario in a fresh PHP process. */
function scenario(string $case, array $env, string $mode = 'ok'): array
{
    global $php, $modeFile, $callsFile, $mockPort;
    file_put_contents($modeFile, $mode);
    @unlink($callsFile);
    $base = [
        'OLLAMA_URL' => 'http://127.0.0.1:9', // nothing listens there: the local model is "down"
        'OLLAMA_MODEL' => 'qwen3:8b', 'OLLAMA_VISION_MODEL' => 'qwen2.5vl:7b',
        'GEMINI_API_BASE' => "http://127.0.0.1:$mockPort", 'PLANTNET_API_BASE' => "http://127.0.0.1:$mockPort",
        'GEMINI_API_KEY' => '', 'PLANTNET_API_KEY' => '', 'AI_CONFIDENCE_MIN' => '70',
    ];
    $proc = proc_open([$php, __FILE__, '--case', $case], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, array_merge(getenv(), $base, $env));
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]); fclose($pipes[2]);
    proc_close($proc);
    $decoded = json_decode($out, true);
    return [is_array($decoded) ? $decoded : ['_raw' => $out], is_file($callsFile) ? array_filter(explode("\n", (string) file_get_contents($callsFile))) : []];
}
$both = ['GEMINI_API_KEY' => 'g-key-1234567890', 'PLANTNET_API_KEY' => 'p-key-1234567890'];

[$r, $calls] = scenario('identify', $both);
$res = $r['result'] ?? [];
check('Ollama down + both services set: the request still succeeds', ($r['ok'] ?? false) === true, json_encode($r, JSON_UNESCAPED_UNICODE));
check('Gemini is the main answer (full Thai write-up) and says so', ($res['main_source'] ?? '') === 'gemini' && ($res['name_th'] ?? '') === 'ราชพฤกษ์' && ($res['description_th'] ?? '') === 'คำอธิบายจาก Gemini');
check('Pl@ntNet gave the second opinion, agreed, and confidence rose (86 + 10 = 96, capped at 95)', ($res['second_opinion']['source'] ?? '') === 'plantnet' && ($res['second_opinion']['agrees'] ?? null) === true && ($res['confidence_pct'] ?? 0) === 95, json_encode($res['second_opinion'] ?? null) . ' pct=' . ($res['confidence_pct'] ?? '?'));
check('the page is told the local model was not used', str_contains($res['fallback_note'] ?? '', 'Qwen') && str_contains($res['fallback_note'] ?? '', 'Gemini'));
check('both outside services were actually called', count(array_filter($calls, fn($c) => str_contains($c, 'generateContent'))) === 1 && count(array_filter($calls, fn($c) => str_contains($c, 'identify'))) === 1, implode(',', $calls));

[$r, $calls] = scenario('identify', $both, 'plantnet_other');
$res = $r['result'] ?? [];
check('Gemini and Pl@ntNet disagree: the more confident one wins, the other is listed, flagged for review',
    ($res['name_scientific'] ?? '') === 'Delonix regia' && ($res['answered_by'] ?? '') === 'second' && ($res['needs_review'] ?? false) === true
    && ($res['alternatives'][0]['name_scientific'] ?? '') === 'Cassia fistula', json_encode($res, JSON_UNESCAPED_UNICODE));

[$r] = scenario('identify', ['PLANTNET_API_KEY' => 'p-key-1234567890']);
$res = $r['result'] ?? [];
check('Ollama down + only Pl@ntNet: a name-only answer with a note to fill the Thai name by hand',
    ($r['ok'] ?? false) === true && ($res['main_source'] ?? '') === 'plantnet' && ($res['name_scientific'] ?? '') === 'Cassia fistula' && ($res['name_th'] ?? 'x') === '' && str_contains($res['notes_th'] ?? '', 'Pl@ntNet'), json_encode($r, JSON_UNESCAPED_UNICODE));

[$r, $calls] = scenario('identify', ['GEMINI_API_KEY' => 'g-key-1234567890']);
$res = $r['result'] ?? [];
check('Ollama down + only Gemini: answered by Gemini alone, no Pl@ntNet call', ($r['ok'] ?? false) === true && ($res['main_source'] ?? '') === 'gemini' && array_key_exists('second_opinion', $res) && $res['second_opinion'] === null
    && !array_filter($calls, fn($c) => str_contains($c, 'identify')), implode(',', $calls));

[$r] = scenario('identify', $both, 'gemini_500');
$res = $r['result'] ?? [];
check('Gemini itself failing: Pl@ntNet still answers', ($r['ok'] ?? false) === true && ($res['main_source'] ?? '') === 'plantnet', json_encode($r, JSON_UNESCAPED_UNICODE));

[$r] = scenario('identify', $both, 'plantnet_404');
$res = $r['result'] ?? [];
check('Pl@ntNet finding no plant: Gemini alone answers', ($r['ok'] ?? false) === true && ($res['main_source'] ?? '') === 'gemini' && array_key_exists('second_opinion', $res) && $res['second_opinion'] === null);

[$r] = scenario('identify', []);
check('Ollama down and nothing else configured: a clear error, not a crash', ($r['ok'] ?? true) === false && str_contains($r['error'] ?? '', 'Ollama'), json_encode($r, JSON_UNESCAPED_UNICODE));

[$r] = scenario('identify', $both + ['OLLAMA_MODEL' => 'off']);
check('Ollama switched off (OLLAMA_MODEL=off) works the same way', ($r['ok'] ?? false) === true && ($r['result']['main_source'] ?? '') === 'gemini' && ($r['ai_enabled'] ?? false) === true);

[$r] = scenario('identify', ['OLLAMA_MODEL' => 'off']);
check('everything off: AI is reported as disabled', ($r['ai_enabled'] ?? true) === false);

[$r] = scenario('translate', ['GEMINI_API_KEY' => 'g-key-1234567890']);
check('translation: Ollama down, Gemini translates instead', ($r['translation']['en']['name'] ?? '') === 'Translated by Gemini', json_encode($r, JSON_UNESCAPED_UNICODE));
[$r] = scenario('translate', []);
check('translation: Ollama down and no Gemini key: quietly nothing (the page keeps the Thai text)', array_key_exists('translation', $r) && $r['translation'] === null, json_encode($r));

// ---- details are filled in every time, whichever engine named the plant ----
[$r, $calls] = scenario('identify_with_details', $both);
$res = $r['result'] ?? [];
check('Ollama down: the answer comes with the full write-up (care, characteristics, benefits, cautions...) written by Gemini',
    ($r['ok'] ?? false) === true && ($res['details_status'] ?? '') === 'generated' && ($res['details_source'] ?? '') === 'gemini'
    && ($res['care_instructions'] ?? '') === 'รดน้ำสัปดาห์ละครั้ง' && ($res['cautions'] ?? '') === 'เมล็ดมีพิษ', json_encode($res, JSON_UNESCAPED_UNICODE));
check('...and Gemini was asked twice: once to name the plant (with the photo), once to write the details (text only)',
    count(array_filter($calls, fn($c) => str_contains($c, 'generateContent'))) === 2, implode(',', $calls));
[$r] = scenario('identify_with_details', ['PLANTNET_API_KEY' => 'p-key-1234567890']);
$res = $r['result'] ?? [];
check('only Pl@ntNet available (names only): the details cannot be written, and the answer still goes out marked as such',
    ($r['ok'] ?? false) === true && ($res['name_scientific'] ?? '') === 'Cassia fistula' && ($res['details_status'] ?? '') === 'failed' && ($res['care_instructions'] ?? 'x') === '', json_encode($res, JSON_UNESCAPED_UNICODE));

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
