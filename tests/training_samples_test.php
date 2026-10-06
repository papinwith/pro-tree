<?php
/**
 * Training-sample rules (includes/training_samples.php) against an in-memory SQLite database - no network, no
 * PostgreSQL needed. Only the PostgreSQL-specific DDL (BIGSERIAL / TIMESTAMP now()) is not exercised here.
 *
 *   php tests/training_samples_test.php
 */
require_once __DIR__ . '/../includes/training_samples.php';

$pass = $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "PASS  $label\n"; } else { $fail++; echo "FAIL  $label" . ($detail !== '' ? "\n      $detail" : '') . "\n"; }
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$jpeg = fn(string $seed) => "\xFF\xD8\xFF\xE0" . str_repeat($seed, 50);

// --- names ---
check('name: tidy case, genus + species only', canonicalSpeciesName("  cassia FISTULA L. 'Alba' ") === 'Cassia fistula');
check('name: genus-only stays as written', canonicalSpeciesName('Vanda sp.') === 'Vanda sp.' && canonicalSpeciesName('Ficus') === 'Ficus');
check('name: junk or empty gives nothing', canonicalSpeciesName('') === '' && canonicalSpeciesName('123 456') === '' && canonicalSpeciesName("x'; DROP TABLE t;--") === '');

// --- decision rules ---
$res = fn(array $over = []) => $over + ['is_plant' => true, 'name_scientific' => 'Cassia fistula', 'second_opinion' => ['source' => 'plantnet', 'agrees' => true]];
$d = decideTrainingSample($res());
check('two teachers agree -> approved automatically, both teachers recorded', $d && $d['status'] === 'approved' && $d['source'] === 'teachers' && $d['teachers'] === 'qwen+plantnet' && $d['name'] === 'Cassia fistula', json_encode($d));
$d = decideTrainingSample($res(['second_opinion' => ['source' => 'gemini', 'agrees' => false]]));
check('two teachers disagree -> pending for a person', $d && $d['status'] === 'pending' && $d['teachers'] === 'qwen+gemini', json_encode($d));
check('one teacher only (no second opinion) -> not stored', decideTrainingSample($res(['second_opinion' => null])) === null);
check('not a plant, or no name -> not stored', decideTrainingSample($res(['is_plant' => false])) === null && decideTrainingSample($res(['name_scientific' => ''])) === null);

// --- storing ---
$a = saveTrainingSample($pdo, $jpeg('a'), 'image/jpeg', 'cassia fistula', 'approved', 'teachers', 'qwen+plantnet', 1);
check('a sample is stored and gets an id', is_int($a) && $a > 0);
check('the same photo again is not stored twice', saveTrainingSample($pdo, $jpeg('a'), 'image/jpeg', 'Cassia fistula', 'approved', 'teachers', null, 1) === $a
    && (int) $pdo->query('SELECT COUNT(*) FROM training_samples')->fetchColumn() === 1);
check('the photo is kept intact (base64 round trip)', base64_decode($pdo->query('SELECT image_b64 FROM training_samples')->fetchColumn()) === $jpeg('a'));
check('bad photos are refused: empty, too big, wrong type',
    saveTrainingSample($pdo, '', 'image/jpeg', 'A b', 'approved', 'admin', null, 1) === null
    && saveTrainingSample($pdo, str_repeat('x', TRAINING_SAMPLE_MAX_BYTES + 1), 'image/jpeg', 'A b', 'approved', 'admin', null, 1) === null
    && saveTrainingSample($pdo, $jpeg('b'), 'text/html', 'A b', 'approved', 'admin', null, 1) === null);
check('a bad name or status is refused', saveTrainingSample($pdo, $jpeg('c'), 'image/jpeg', '', 'approved', 'admin', null, 1) === null
    && saveTrainingSample($pdo, $jpeg('c'), 'image/jpeg', 'A b', 'whatever', 'admin', null, 1) === null);

// --- pending -> admin confirmation ---
$p = saveTrainingSample($pdo, $jpeg('p'), 'image/jpeg', 'Delonix regia', 'pending', 'teachers', 'qwen+gemini', null);
check('a pending sample is queued', pendingTrainingSamples($pdo)[0]['id'] == $p && trainingSampleCounts($pdo)['pending'] === 1);
confirmTrainingSample($pdo, $jpeg('p'), 'image/jpeg', 'Delonix regia', 7);
$row = $pdo->query("SELECT status, source, reviewed_by FROM training_samples WHERE id = $p")->fetch();
check('an admin confirming a pending photo approves it', $row['status'] === 'approved' && $row['source'] === 'admin' && (int) $row['reviewed_by'] === 7);
$c = confirmTrainingSample($pdo, $jpeg('n'), 'image/jpeg', 'Mangifera indica', 7);
check('an admin confirming a photo not seen before stores it approved', $c && $pdo->query("SELECT status FROM training_samples WHERE id = $c")->fetchColumn() === 'approved');

// --- review page actions ---
$q1 = saveTrainingSample($pdo, $jpeg('q1'), 'image/jpeg', 'Ficus benjamina', 'pending', 'teachers', 'qwen+gemini', null);
$q2 = saveTrainingSample($pdo, $jpeg('q2'), 'image/jpeg', 'Ficus religiosa', 'pending', 'teachers', 'qwen+gemini', null);
$q3 = saveTrainingSample($pdo, $jpeg('q3'), 'image/jpeg', 'Ficus elastica', 'pending', 'teachers', 'qwen+gemini', null);
check('approve with a corrected name stores the corrected name', reviewTrainingSample($pdo, $q1, 'approve', 'tectona GRANDIS', 3)
    && $pdo->query("SELECT name_scientific FROM training_samples WHERE id = $q1")->fetchColumn() === 'Tectona grandis');
check('reject marks it rejected, and rejected photos are never exported', reviewTrainingSample($pdo, $q2, 'reject', null, 3)
    && !in_array($q2, array_map(fn($r) => (int) $r['id'], approvedTrainingSamplesSince($pdo, 0, 100)), true));
check('an unusable corrected name is refused and nothing changes', reviewTrainingSample($pdo, $q3, 'approve', '123', 3) === false
    && $pdo->query("SELECT status FROM training_samples WHERE id = $q3")->fetchColumn() === 'pending');
check('an already-reviewed sample cannot be reviewed again', reviewTrainingSample($pdo, $q2, 'approve', null, 3) === false);
check('an unknown decision does nothing', reviewTrainingSample($pdo, $q3, 'delete', null, 3) === false);

// --- the pending queue is bounded ---
$pdo2 = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
for ($i = 0; $i < TRAINING_SAMPLE_MAX_PENDING; $i++) {
    saveTrainingSample($pdo2, $jpeg("x$i"), 'image/jpeg', 'Aa bb', 'pending', 'teachers', null, null);
}
check('the pending queue stops growing at its limit',
    saveTrainingSample($pdo2, $jpeg('over'), 'image/jpeg', 'Aa bb', 'pending', 'teachers', null, null) === null
    && trainingSampleCounts($pdo2)['pending'] === TRAINING_SAMPLE_MAX_PENDING
    && saveTrainingSample($pdo2, $jpeg('approved-anyway'), 'image/jpeg', 'Aa bb', 'approved', 'admin', null, 1) !== null);

// --- export for retraining ---
$exp = approvedTrainingSamplesSince($pdo, 0, 100);
$names = array_column($exp, 'name_scientific');
check('export lists exactly the approved samples, oldest first, with their names',
    $names === ['Cassia fistula', 'Delonix regia', 'Mangifera indica', 'Tectona grandis'], json_encode($names));
$after = approvedTrainingSamplesSince($pdo, (int) $exp[0]['id'], 100);
check('export since id N returns only the later samples (incremental download)',
    count($after) === 3 && (int) $after[0]['id'] > (int) $exp[0]['id'] && count(approvedTrainingSamplesSince($pdo, (int) end($exp)['id'], 100)) === 0);
check('export respects the batch limit', count(approvedTrainingSamplesSince($pdo, 0, 2)) === 2);

// --- recordTrainingSampleFromResult never throws ---
$broken = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$broken->exec('CREATE TABLE training_samples (x)'); // wrong shape on purpose: ensure...() finds the table, INSERT fails
check('a database problem is swallowed, not thrown', recordTrainingSampleFromResult($broken, $jpeg('z'), 'image/jpeg', $res(), 1) === null);
check('a good result is recorded through the helper', is_int(recordTrainingSampleFromResult($pdo, $jpeg('r'), 'image/jpeg', $res(), 1)));

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
