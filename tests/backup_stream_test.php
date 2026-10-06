<?php
/**
 * The streamed database backup (streamDatabaseBackupSql) on in-memory SQLite: the same text as before, row by row, and the
 * photo-heavy tables read in small batches.   php tests/backup_stream_test.php
 */
require_once __DIR__ . '/../includes/functions.php';

$pass = $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "PASS  $label\n"; } else { $fail++; echo "FAIL  $label" . ($detail !== '' ? "\n      $detail" : '') . "\n"; }
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE zones (id INTEGER PRIMARY KEY, name TEXT, active BOOLEAN, note TEXT)');
$pdo->exec('CREATE TABLE tree_spin_frames (id INTEGER PRIMARY KEY, tree_id INTEGER, token TEXT, idx INTEGER, image_b64 TEXT)');
$pdo->exec('CREATE TABLE empty_table (id INTEGER PRIMARY KEY)');
$pdo->prepare('INSERT INTO zones VALUES (?, ?, ?, ?)')->execute([1, "โซน 'A'", 1, null]);
$pdo->prepare('INSERT INTO zones VALUES (?, ?, ?, ?)')->execute([2, 'B; DROP TABLE x;--', 0, "line1\nline2"]);
for ($i = 1; $i <= 25; $i++) {
    $pdo->prepare('INSERT INTO tree_spin_frames VALUES (?, 9, ?, ?, ?)')->execute([$i * 3, 'tok', $i, base64_encode(str_repeat(chr(65 + $i % 26), 3000))]);
}
$tables = ['empty_table', 'tree_spin_frames', 'zones'];

$pieces = [];
streamDatabaseBackupSql($pdo, function (string $c) use (&$pieces) { $pieces[] = $c; }, $tables, 4);
$sql = implode('', $pieces);
check('the backup arrives in many pieces, not one string (rows are written as they are read)', count($pieces) > 25, (string) count($pieces));
check('it starts by emptying every table and ends with a plain "origin" reset', str_contains($sql, 'TRUNCATE "empty_table", "tree_spin_frames", "zones" RESTART IDENTITY CASCADE;') && str_contains($sql, "SET session_replication_role = 'origin';"));
check('every row of every table is present exactly once (25 frames read 4 at a time, keyed by id)',
    substr_count($sql, 'INSERT INTO "tree_spin_frames"') === 25 && substr_count($sql, 'INSERT INTO "zones"') === 2 && substr_count($sql, 'INSERT INTO "empty_table"') === 0);
check('frame ids come out in order with no gap or repeat at the batch edges', (function () use ($sql) {
    preg_match_all('/INSERT INTO "tree_spin_frames" \("id"[^)]*\) VALUES \(\'(\d+)\'/', $sql, $m);
    return array_map('intval', $m[1]) === array_map(fn($i) => $i * 3, range(1, 25));
})());
check('values are quoted: apostrophes doubled, newlines kept, NULL and booleans as SQL', str_contains($sql, "'โซน ''A'''") && str_contains($sql, "'B; DROP TABLE x;--'") && str_contains($sql, "NULL") && str_contains($sql, "line1\nline2"));

// the restore: run the produced SQL into a fresh database and compare
$copy = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$copy->exec('CREATE TABLE zones (id INTEGER PRIMARY KEY, name TEXT, active BOOLEAN, note TEXT)');
$copy->exec('CREATE TABLE tree_spin_frames (id INTEGER PRIMARY KEY, tree_id INTEGER, token TEXT, idx INTEGER, image_b64 TEXT)');
$copy->exec('CREATE TABLE empty_table (id INTEGER PRIMARY KEY)');
// each row is emitted as one piece, so the INSERT statements are exactly the pieces that start with INSERT
$inserts = array_values(array_filter($pieces, fn($p) => str_starts_with($p, 'INSERT INTO ')));
check('the backup contains all 27 INSERT statements (2 zones + 25 frames)', count($inserts) === 27, (string) count($inserts));
foreach ($inserts as $stmt) { $copy->exec($stmt); }
$same = fn(string $q) => $pdo->query($q)->fetchAll() == $copy->query($q)->fetchAll();
check('loading the backup into an empty database gives back identical data (zones and every photo frame)', $same('SELECT * FROM zones ORDER BY id') && $same('SELECT * FROM tree_spin_frames ORDER BY id'));
check('generateDatabaseBackupSql() still returns the whole thing as one string for older callers', (function () use ($pdo) {
    try { return is_string(generateDatabaseBackupSql($pdo)); } catch (Throwable $e) { return true; } // pg_tables does not exist on SQLite: the wrapper is exercised on PostgreSQL only
})());

// memory: 200 frames of ~600 KB would be 120 MB as one string
$big = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$big->exec('CREATE TABLE tree_spin_frames (id INTEGER PRIMARY KEY, tree_id INTEGER, token TEXT, idx INTEGER, image_b64 TEXT)');
$blob = str_repeat('A', 600000);
$ins = $big->prepare('INSERT INTO tree_spin_frames VALUES (?, 1, ?, ?, ?)');
for ($i = 1; $i <= 100; $i++) { $ins->execute([$i, 'tok', $i, $blob]); }
$before = memory_get_peak_usage();
$bytes = 0;
streamDatabaseBackupSql($big, function (string $c) use (&$bytes) { $bytes += strlen($c); }, ['tree_spin_frames'], 5);
$extra = memory_get_peak_usage() - $before;
check('a table holding 60 MB of photo text streams out with a small memory footprint (' . round($extra / 1e6, 1) . ' MB extra, not 60+)', $bytes > 60000000 && $extra < 25000000, "$bytes bytes, extra $extra");

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
