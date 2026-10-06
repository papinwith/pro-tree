<?php
/**
 * Page translation without making the visitor wait (includes/page_translation.php) on in-memory SQLite, with an injected
 * "AI" so there is no network.   php tests/page_translation_test.php
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/page_translation.php';

$pass = $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "PASS  $label\n"; } else { $fail++; echo "FAIL  $label" . ($detail !== '' ? "\n      $detail" : '') . "\n"; }
}

function freshDb(): PDO
{
    $cache = &settingsCache(); // getSetting() keeps a per-request copy of the table; each fake database starts clean
    $cache = null;
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('CREATE TABLE species (id INTEGER PRIMARY KEY, name TEXT, name_en TEXT, name_zh TEXT, description TEXT, description_en TEXT, description_zh TEXT,
        care_instructions TEXT, care_instructions_en TEXT, care_instructions_zh TEXT, characteristics TEXT, characteristics_en TEXT, characteristics_zh TEXT,
        properties TEXT, properties_en TEXT, properties_zh TEXT, benefits TEXT, benefits_en TEXT, benefits_zh TEXT, cautions TEXT, cautions_en TEXT, cautions_zh TEXT,
        part_uses TEXT, part_uses_en TEXT, part_uses_zh TEXT)');
    $pdo->exec('CREATE TABLE zones (id INTEGER PRIMARY KEY, name TEXT, name_en TEXT, name_zh TEXT, description TEXT, description_en TEXT, description_zh TEXT)');
    $pdo->exec('CREATE TABLE categories (code TEXT PRIMARY KEY, name_th TEXT, name_en TEXT, name_zh TEXT)');
    $pdo->exec('CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, updated_at TEXT)');
    $pdo->exec("INSERT INTO species (id, name, description, care_instructions, benefits, name_zh) VALUES (1, 'ราชพฤกษ์', 'ไม้ดอกสีเหลือง', 'รดน้ำสัปดาห์ละครั้ง', 'ให้ร่มเงา', '腊肠树')");
    $pdo->exec("INSERT INTO zones (id, name, description) VALUES (5, 'โซน A', 'ใกล้ประตู'), (6, 'โซน B', NULL)");
    $pdo->exec("INSERT INTO categories (code, name_th, name_en) VALUES ('102', 'ไม้ยืนต้น', 'Trees'), ('103', 'ไม้พุ่ม', NULL)");
    $pdo->exec("INSERT INTO settings (setting_key, setting_value) VALUES ('contact_address', 'ถนนทดสอบ 1'), ('opening_hours', '08:00-17:00'), ('contact_phone', '0812345678')");
    return $pdo;
}
$treeRow = fn() => ['id' => 9, 'species_id' => 1, 'zone_id' => 5, 'category_code' => '102', 'name' => 'ราชพฤกษ์', 'description' => 'ไม้ดอกสีเหลือง', 'care_instructions' => 'รดน้ำสัปดาห์ละครั้ง', 'benefits' => 'ให้ร่มเงา',
    'name_en' => '', 'name_zh' => '腊肠树', 'description_en' => null, 'care_instructions_en' => '', 'benefits_en' => ''];

// ---- what is missing (no AI) ----
$pdo = freshDb();
$items = treePageMissing($pdo, $treeRow(), 'en');
$keys = array_column($items, 'key');
sort($keys);
check('English: species (4 filled fields), the zone (name + description), the category 103 is NOT this tree\'s so it is ignored, and both contact texts',
    $keys === ['setting.contact_address', 'setting.opening_hours', 'species.1.benefits', 'species.1.care_instructions', 'species.1.description', 'species.1.name', 'zone.5.description', 'zone.5.name'], implode(',', $keys));
check('a field already translated is not asked for again (Chinese name is there)', !in_array('species.1.name', array_column(treePageMissing($pdo, $treeRow(), 'zh'), 'key'), true));
check('the category of this tree is asked for only if it lacks a translation', !in_array('category.102', $keys, true));
$t2 = ['category_code' => '103'] + $treeRow();
check('...and is asked for when it does', in_array('category.103', array_column(treePageMissing($pdo, $t2, 'en'), 'key'), true));
check('Thai (the source language) and unknown languages need nothing', treePageMissing($pdo, $treeRow(), 'th') === [] && treePageMissing($pdo, $treeRow(), 'fr') === []);
check('empty Thai fields are never sent (zone B has no description, nothing to translate)', zonePageMissing($pdo, 6, 'en') === [['key' => 'zone.6.name', 'text' => 'โซน B', 'kind' => 'zone', 'id' => 6, 'field' => 'name']]);

// ---- one call for everything ----
$calls = [];
$engine = function (string $prompt, float $budget) use (&$calls) {
    $calls[] = $prompt;
    preg_match_all('/^- ([\w.]+): /m', $prompt, $m);
    return json_encode(['en' => array_combine($m[1], array_map(fn($k) => "EN($k)", $m[1]))]);
};
$r = translateItems($pdo, $items, 'en', 30, $engine);
check('everything goes to the AI in ONE call (it used to be up to five, one after another)', count($calls) === 1 && $r['status'] === 'translated' && $r['stored'] === 8 && $r['wanted'] === 8, json_encode($r));
check('...and that one call lists every item', count(array_filter(['species.1.name', 'zone.5.name', 'setting.contact_address', 'species.1.care_instructions'], fn($k) => str_contains($calls[0], "- $k:"))) === 4);
$sp = $pdo->query('SELECT name_en, description_en, care_instructions_en, benefits_en, name_zh FROM species WHERE id = 1')->fetch();
check('each text is stored in its own column; the Chinese one that existed is untouched', $sp['name_en'] === 'EN(species.1.name)' && $sp['care_instructions_en'] === 'EN(species.1.care_instructions)' && $sp['name_zh'] === '腊肠树');
check('zone and settings are stored too', $pdo->query('SELECT name_en FROM zones WHERE id = 5')->fetchColumn() === 'EN(zone.5.name)'
    && getSetting($pdo, 'contact_address_en') === 'EN(setting.contact_address)' && getSetting($pdo, 'opening_hours_en') === 'EN(setting.opening_hours)');
$after = $pdo->query('SELECT * FROM species WHERE id = 1')->fetch() + ['species_id' => 1, 'zone_id' => 5, 'category_code' => '102'];
check('the page-level check agrees: with the stored values loaded, nothing is missing', treePageMissing($pdo, $after, 'en') === [], json_encode(array_column(treePageMissing($pdo, $after, 'en'), 'key')));
check('translating when nothing is missing makes no AI call', translateItems($pdo, [], 'en', 30, function () use (&$calls) { $calls[] = 'x'; return '{}'; })['status'] === 'complete' && count($calls) === 1);

// ---- never overwrite, never trust the model's shape ----
$pdo = freshDb();
$pdo->exec("UPDATE species SET name_en = 'Written by an admin' WHERE id = 1");
$one = [['key' => 'species.1.name', 'text' => 'ราชพฤกษ์', 'kind' => 'species', 'id' => 1, 'field' => 'name']];
translateItems($pdo, $one, 'en', 30, fn($p, $b) => json_encode(['en' => ['species.1.name' => 'AI text']]));
check('a translation that appeared in the meantime is never overwritten', $pdo->query('SELECT name_en FROM species WHERE id = 1')->fetchColumn() === 'Written by an admin');
$evil = [['key' => 'k', 'text' => 'x', 'kind' => 'species', 'id' => 1, 'field' => 'name_en"; DROP TABLE species;--']];
check('a column name outside the fixed list is refused (nothing is run)', storeTranslation($pdo, $evil[0], 'en', 'x') === false && (int) $pdo->query('SELECT COUNT(*) FROM species')->fetchColumn() === 1);
check('an unknown kind of item is refused', storeTranslation($pdo, ['kind' => 'users', 'id' => 1, 'field' => 'name'], 'en', 'x') === false);
check('a language other than en/zh stores nothing', translateItems($pdo, $one, 'fr', 30, fn() => '{}')['status'] === 'failed');

// ---- partial and failed answers ----
$pdo = freshDb();
$two = [['key' => 'a', 'text' => 'ก', 'kind' => 'zone', 'id' => 5, 'field' => 'name'], ['key' => 'b', 'text' => 'ข', 'kind' => 'zone', 'id' => 6, 'field' => 'name']];
$r = translateItems($pdo, $two, 'en', 30, fn($p, $b) => json_encode(['en' => ['a' => 'A only']]));
check('an answer covering only some items stores those and reports partial', $r['status'] === 'partial' && $r['stored'] === 1 && $r['changed'] === true && $pdo->query('SELECT name_en FROM zones WHERE id = 5')->fetchColumn() === 'A only' && $pdo->query('SELECT name_en FROM zones WHERE id = 6')->fetchColumn() === null);
check('the AI being unavailable gives failed, no change, no exception', translateItems($pdo, $two, 'en', 30, fn($p, $b) => null) === ['status' => 'failed', 'changed' => false, 'stored' => 0, 'wanted' => 2]);
check('junk output from the AI gives failed', translateItems($pdo, $two, 'en', 30, fn($p, $b) => 'not json')['status'] === 'failed');
check('an AI that throws is contained', translateItems($pdo, $two, 'en', 30, function () { throw new RuntimeException('boom'); })['status'] === 'failed');
check('empty strings from the AI are not stored', translateItems($pdo, [$two[1]], 'en', 30, fn($p, $b) => json_encode(['en' => ['b' => '  ']]))['status'] === 'failed');

// ---- guard: one at a time, and a pause after a failure ----
$dir = sys_get_temp_dir() . '/ptguard_' . getmypid();
mkdir($dir);
$ran = 0;
$ok = guardedTranslation('tree_1_en', function () use (&$ran) { $ran++; return ['status' => 'translated', 'changed' => true, 'stored' => 1, 'wanted' => 1]; }, $dir);
check('the guard runs the work and returns its result', $ran === 1 && $ok['status'] === 'translated');
$held = fopen("$dir/page_translate_lock_tree_2_en", 'c');
flock($held, LOCK_EX);
$busy = guardedTranslation('tree_2_en', function () use (&$ran) { $ran++; return ['status' => 'translated', 'changed' => true, 'stored' => 1, 'wanted' => 1]; }, $dir);
check('while another request is translating the same page, this one answers "busy" without calling the AI', $busy['status'] === 'busy' && $ran === 1);
flock($held, LOCK_UN);
fclose($held);
guardedTranslation('tree_3_en', fn() => ['status' => 'failed', 'changed' => false, 'stored' => 0, 'wanted' => 1], $dir);
$again = guardedTranslation('tree_3_en', function () use (&$ran) { $ran++; return ['status' => 'translated', 'changed' => true, 'stored' => 1, 'wanted' => 1]; }, $dir);
check('after a failure the AI is not called again for that page for a few minutes', $again['status'] === 'failed' && !empty($again['paused']) && $ran === 1);
touch("$dir/page_translate_fail_tree_3_en", time() - PAGE_TRANSLATION_FAIL_PAUSE - 5);
$later = guardedTranslation('tree_3_en', function () use (&$ran) { $ran++; return ['status' => 'translated', 'changed' => true, 'stored' => 1, 'wanted' => 1]; }, $dir);
check('...and is called again once the pause is over (the failure mark is cleared by a success)', $later['status'] === 'translated' && $ran === 2 && !is_file("$dir/page_translate_fail_tree_3_en"));
check('keys cannot escape the lock directory', guardedTranslation('../../etc/passwd', fn() => ['status' => 'translated', 'changed' => false, 'stored' => 0, 'wanted' => 0], $dir)['status'] === 'translated' && !is_file("$dir/../../etc/page_translate_lock_passwd"));
array_map('unlink', glob("$dir/*") ?: []);
rmdir($dir);

// ---- shared content for the warm-up ----
$pdo = freshDb();
$shared = array_column(sharedMissingItems($pdo, 'en'), 'key');
sort($shared);
check('the warm-up tool covers every zone, every category lacking a translation, and the contact texts',
    $shared === ['category.103', 'setting.contact_address', 'setting.opening_hours', 'zone.5.description', 'zone.5.name', 'zone.6.name'], implode(',', $shared));

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
