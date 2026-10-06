<?php
/**
 * The "details every time" rules (includes/species_details.php) on in-memory SQLite, with an injected writer so no AI
 * and no network are involved.   php tests/species_details_test.php
 */
require_once __DIR__ . '/../includes/species_details.php';

$pass = $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "PASS  $label\n"; } else { $fail++; echo "FAIL  $label" . ($detail !== '' ? "\n      $detail" : '') . "\n"; }
}
function memdb(): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('CREATE TABLE species (id INTEGER PRIMARY KEY, name TEXT, name_scientific TEXT, description TEXT, care_instructions TEXT,
                characteristics TEXT, properties TEXT, benefits TEXT, cautions TEXT, part_uses TEXT)');
    return $pdo;
}

$writes = 0;
$good = json_encode(['description_th' => 'คำอธิบายทั่วไป', 'care_instructions' => 'รดน้ำสัปดาห์ละครั้ง', 'characteristics' => 'ไม้ยืนต้นสูง 10 เมตร', 'properties' => 'มีสาร X',
    'benefits' => 'ให้ร่มเงา', 'cautions' => 'เมล็ดมีพิษ', 'part_uses' => 'ดอก: ชา', 'category_code' => '102', 'subtype_ids' => [1, 999]], JSON_UNESCAPED_UNICODE);
$writer = function (string $prompt, float $budget, ?string &$source) use (&$writes, $good) { $writes++; $source = 'gemini'; return $good; };
$answer = ['is_plant' => true, 'name_th' => 'ต้นทดสอบ', 'name_scientific' => 'cassia FISTULA', 'description_th' => 'ต้นไม้ในรูปมีดอกสีเหลือง',
    'care_instructions' => '', 'characteristics' => '', 'properties' => '', 'benefits' => '', 'cautions' => '', 'part_uses' => '', 'category_code' => null, 'subtype_ids'  => [], 'alternatives' => []];

// --- the bug this module must not have: a short photo answer already carries a one-line description ---
$pdo = memdb();
$r = completeWithDetails($pdo, $answer, null, 30, $writer);
check('a short answer (one-line description only) STILL gets the long write-up', $r['details_status'] === 'generated' && $r['care_instructions'] === 'รดน้ำสัปดาห์ละครั้ง' && $r['cautions'] === 'เมล็ดมีพิษ', json_encode($r, JSON_UNESCAPED_UNICODE));
check('...and keeps the engine\'s own description of this photo', $r['description_th'] === 'ต้นไม้ในรูปมีดอกสีเหลือง');
check('every one of the six long fields is filled', !array_filter(plantDetailFields(), fn($k) => trim($r[$k]) === ''));
check('the source of the text is reported', $r['details_source'] === 'gemini' && $writes === 1);

// --- cache ---
$r2 = completeWithDetails($pdo, $answer, null, 30, $writer);
check('second time for the same species: served from the cache, the writer is not called again', $r2['details_status'] === 'cache' && $writes === 1 && $r2['benefits'] === 'ให้ร่มเงา');
$other = ['name_scientific' => 'Cassia  fistula L.'] + $answer;
check('the cache key is the tidy scientific name (case, spaces, author ignored)', completeWithDetails($pdo, $other, null, 30, $writer)['details_status'] === 'cache' && $writes === 1);

// --- catalogue first ---
$pdo = memdb();
$pdo->exec("INSERT INTO species (id, name, name_scientific, description, care_instructions, benefits) VALUES (7, 'ราชพฤกษ์', 'Cassia fistula', 'ข้อความที่แอดมินเขียน', 'ดูแลตามที่บันทึกไว้', 'ประโยชน์ที่บันทึกไว้')");
$writes = 0;
$r = completeWithDetails($pdo, $answer, null, 30, $writer, 7);
check('a catalogued species uses the text people wrote: no AI call, no cache needed', $r['details_status'] === 'catalogue' && $r['details_source'] === 'catalogue' && $writes === 0
    && $r['care_instructions'] === 'ดูแลตามที่บันทึกไว้' && $r['description_th'] === 'ข้อความที่แอดมินเขียน');
$pdo->exec("INSERT INTO species (id, name, name_scientific) VALUES (8, 'ว่างเปล่า', 'Delonix regia')");
$r = completeWithDetails($pdo, ['name_scientific' => 'Delonix regia'] + $answer, null, 30, $writer, 8);
check('a catalogued species with an empty write-up falls through to the AI', $r['details_status'] === 'generated' && $writes === 1);

// --- not applicable / untouched ---
$pdo = memdb();
$writes = 0;
check('not a plant: untouched, no AI call', completeWithDetails($pdo, ['is_plant' => false] + $answer, null, 30, $writer)['details_status'] === 'not_applicable' && $writes === 0);
check('no usable name: untouched, no AI call', completeWithDetails($pdo, ['name_scientific' => ''] + $answer, null, 30, $writer)['details_status'] === 'not_applicable' && $writes === 0);
$full = ['care_instructions' => 'มีอยู่แล้ว'] + $answer;
$r = completeWithDetails($pdo, $full, null, 30, $writer);
check('a result that already has the long write-up is left as it is', $r['details_status'] === 'present' && $r['care_instructions'] === 'มีอยู่แล้ว' && $writes === 0);

// --- failures never remove the answer ---
$pdo = memdb();
$r = completeWithDetails($pdo, $answer, null, 30, fn($p, $b, &$s) => null);
check('writer unavailable: the answer survives, flagged as failed', $r['details_status'] === 'failed' && $r['name_scientific'] === 'cassia FISTULA' && $r['care_instructions'] === '');
$r = completeWithDetails($pdo, $answer, null, 30, fn($p, $b, &$s) => 'this is not json');
check('writer returns junk: failed, nothing cached', $r['details_status'] === 'failed' && (int) $pdo->query('SELECT COUNT(*) FROM species_details_cache')->fetchColumn() === 0);
$r = completeWithDetails($pdo, $answer, null, 30, fn($p, $b, &$s) => '{"care_instructions":"","benefits":""}');
check('writer returns only empty fields: failed (empty text is not a write-up)', $r['details_status'] === 'failed');
$r = completeWithDetails($pdo, $answer, null, 2, $writer);
check('not enough time left: the writer is not even tried', $r['details_status'] === 'failed' && $writes === 0);
$boom = fn($p, $b, &$s) => throw new RuntimeException('boom');
check('a writer that throws is contained', completeWithDetails($pdo, $answer, null, 30, $boom)['details_status'] === 'failed');
$broken = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); // no species table at all
check('a database problem is contained too (answer returned, status failed)', completeWithDetails($broken, $answer, null, 30, $writer, 7)['details_status'] === 'failed');

// --- bounded and typed ---
$pdo = memdb();
$huge = json_encode(['care_instructions' => str_repeat('ก', 5000), 'benefits' => ['not', 'a string'], 'cautions' => 'ok']);
$r = completeWithDetails($pdo, $answer, null, 30, fn($p, $b, &$s) => $huge);
check('over-long text is cut and wrong types become empty', mb_strlen($r['care_instructions']) === 1500 && $r['benefits'] === '' && $r['cautions'] === 'ok');

// --- category / subtype from the real lists only ---
$cat = ['categories' => [['code' => '102', 'name_th' => 'ไม้ยืนต้น']], 'subtypes' => [['id' => 1, 'name_th' => 'ไม้ดอก', 'category_code' => '102'], ['id' => 2, 'name_th' => 'ไม้ผล', 'category_code' => '999']]];
$pdo = memdb();
$r = completeWithDetails($pdo, $answer, $cat, 30, $writer);
check('category and subtypes come only from the offered lists (invented id 999 and the other category\'s subtype are dropped)',
    $r['category_code'] === '102' && $r['category_name'] === 'ไม้ยืนต้น' && $r['subtype_ids'] === [1] && $r['subtype_names'] === ['ไม้ดอก'], json_encode($r, JSON_UNESCAPED_UNICODE));
$promptSeen = '';
completeWithDetails(memdb(), $answer, $cat, 30, function ($p, $b, &$s) use (&$promptSeen, $good) { $promptSeen = $p; $s = 'x'; return $good; });
check('the prompt names the plant, asks for every field, and lists the real categories',
    str_contains($promptSeen, 'Cassia fistula') && str_contains($promptSeen, 'care_instructions') && str_contains($promptSeen, '102 = ไม้ยืนต้น') && str_contains($promptSeen, 'ต้นทดสอบ'));
$plain = '';
completeWithDetails(memdb(), $answer, null, 30, function ($p, $b, &$s) use (&$plain, $good) { $plain = $p; return $good; });
check('without a catalogue the prompt does not ask for categories', !str_contains($plain, 'category_code'));

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
