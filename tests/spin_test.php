<?php
/**
 * The 360-degree spin storage rules (includes/spin.php) on in-memory SQLite.   php tests/spin_test.php
 */
require_once __DIR__ . '/../includes/spin.php';

$pass = $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "PASS  $label\n"; } else { $fail++; echo "FAIL  $label" . ($detail !== '' ? "\n      $detail" : '') . "\n"; }
}
function memdb(): PDO
{
    return new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
}
$jpg = fn(string $seed) => "\xFF\xD8\xFF\xE0" . str_repeat($seed, 40);
$upload = function (PDO $pdo, int $tree, int $n, string $seed = 'a') use ($jpg): string {
    $token = newSpinToken();
    for ($i = 0; $i < $n; $i++) {
        saveSpinFrame($pdo, $tree, $token, $i, $jpg($seed) . chr(65 + $i % 26));
    }
    return $token;
};

$pdo = memdb();
check('tokens are 16 hex characters and differ each time', validSpinToken(newSpinToken()) && newSpinToken() !== newSpinToken() && !validSpinToken('../../x') && !validSpinToken(strtoupper(newSpinToken())));
check('the image type is read from the bytes, not trusted: jpeg/png/webp accepted, html refused',
    spinImageMime("\xFF\xD8\xFFx") === 'image/jpeg' && spinImageMime("\x89PNG\r\n\x1A\nxx") === 'image/png' && spinImageMime('RIFF1234WEBPVP8 ') === 'image/webp'
    && spinImageMime('<html><script>') === null && spinImageMime('GIF89a....') === null && spinImageMime('') === null);

// --- no spin yet ---
check('a tree with no spin (and tables that do not exist yet) simply has none', getTreeSpin($pdo, 5) === null);

// --- upload + commit ---
$t1 = $upload($pdo, 5, 12);
check('frames of an upload in progress are NOT visible to visitors until committed', getTreeSpin($pdo, 5) === null && getSpinFrame($pdo, 5, $t1, 0) === null);
check('committing a complete set makes it the tree\'s spin', commitSpin($pdo, 5, $t1) === 12 && getTreeSpin($pdo, 5) === ['token' => $t1, 'frame_count' => 12]);
$f = getSpinFrame($pdo, 5, $t1, 3);
check('a frame comes back byte for byte with its type', $f !== null && $f['mime'] === 'image/jpeg' && $f['bytes'] === $jpg('a') . 'D');
check('...but only for the right tree and the committed token', getSpinFrame($pdo, 6, $t1, 3) === null && getSpinFrame($pdo, 5, newSpinToken(), 3) === null && getSpinFrame($pdo, 5, $t1, 12) === null && getSpinFrame($pdo, 5, 'zz', 0) === null);

// --- replacing ---
$t2 = $upload($pdo, 5, 9, 'b');
check('a second upload does not disturb the live spin until it is committed', getTreeSpin($pdo, 5)['token'] === $t1 && getSpinFrame($pdo, 5, $t1, 0) !== null);
check('committing it replaces the old spin and removes the old frames', commitSpin($pdo, 5, $t2) === 9 && getTreeSpin($pdo, 5)['token'] === $t2
    && getSpinFrame($pdo, 5, $t1, 0) === null && (int) $pdo->query("SELECT COUNT(*) FROM tree_spin_frames WHERE tree_id = 5 AND token = '$t1'")->fetchColumn() === 0);

// --- incomplete / bad sets change nothing ---
$few = $upload($pdo, 5, SPIN_MIN_FRAMES - 1, 'c');
check('too few frames: refused, the live spin is untouched', commitSpin($pdo, 5, $few) === null && getTreeSpin($pdo, 5)['token'] === $t2);
$gap = newSpinToken();
foreach ([0, 1, 2, 3, 4, 5, 6, 8, 9] as $i) { saveSpinFrame($pdo, 5, $gap, $i, $jpg('d')); }
check('a missing frame in the middle: refused (no jump in the turn)', commitSpin($pdo, 5, $gap) === null && getTreeSpin($pdo, 5)['token'] === $t2);
check('an unknown token or tree: refused', commitSpin($pdo, 5, newSpinToken()) === null && commitSpin($pdo, 0, $t2) === null && commitSpin($pdo, 5, 'bad') === null);

// --- frame validation ---
$tk = newSpinToken();
check('frames that are not images, too big, or out of range are refused',
    saveSpinFrame($pdo, 5, $tk, 0, '<?php evil ?>') === false && saveSpinFrame($pdo, 5, $tk, 0, "\xFF\xD8\xFF" . str_repeat('x', SPIN_MAX_FRAME_BYTES)) === false
    && saveSpinFrame($pdo, 5, $tk, SPIN_MAX_FRAMES, $jpg('e')) === false && saveSpinFrame($pdo, 5, $tk, -1, $jpg('e')) === false
    && saveSpinFrame($pdo, 5, 'not-a-token', 0, $jpg('e')) === false && saveSpinFrame($pdo, 0, $tk, 0, $jpg('e')) === false);
check('sending a frame again replaces it (a retried request is harmless)', saveSpinFrame($pdo, 5, $tk, 0, $jpg('e') . '1') && saveSpinFrame($pdo, 5, $tk, 0, $jpg('f') . '2')
    && (int) $pdo->query("SELECT COUNT(*) FROM tree_spin_frames WHERE token = '$tk'")->fetchColumn() === 1);
$max = newSpinToken();
for ($i = 0; $i < SPIN_MAX_FRAMES; $i++) { saveSpinFrame($pdo, 7, $max, $i, $jpg('g')); }
check('the largest allowed spin (' . SPIN_MAX_FRAMES . ' frames) commits', commitSpin($pdo, 7, $max) === SPIN_MAX_FRAMES);

// --- stale uploads ---
$stale = newSpinToken();
saveSpinFrame($pdo, 8, $stale, 0, $jpg('h'));
$pdo->exec("UPDATE tree_spin_frames SET created_at = '2000-01-01 00:00:00' WHERE token = '$stale'");
$live = $upload($pdo, 8, 8, 'i');
commitSpin($pdo, 8, $live); // committing also tidies abandoned uploads
check('frames of an upload abandoned long ago are cleaned up; the live spin is kept',
    (int) $pdo->query("SELECT COUNT(*) FROM tree_spin_frames WHERE token = '$stale'")->fetchColumn() === 0 && getSpinFrame($pdo, 8, $live, 0) !== null);
$other = newSpinToken();
saveSpinFrame($pdo, 9, $other, 0, $jpg('j'));
$pdo->exec("UPDATE tree_spin_frames SET created_at = '2000-01-01 00:00:00' WHERE token = '$other'");
purgeStaleSpinUploads($pdo);
check('an old frame that belongs to a committed spin is never purged', getSpinFrame($pdo, 8, $live, 0) !== null);

// --- delete ---
deleteTreeSpin($pdo, 5);
check('deleting a tree\'s spin removes the spin and all its frames, and only that tree\'s', getTreeSpin($pdo, 5) === null
    && (int) $pdo->query('SELECT COUNT(*) FROM tree_spin_frames WHERE tree_id = 5')->fetchColumn() === 0 && getTreeSpin($pdo, 7) !== null);
deleteTreeSpin(memdb(), 1);
check('deleting on a database without the tables does not fail', true);

// --- the warning about uploads that do not survive a deploy ---
$msg = uploadsPersistenceWarning(['RAILWAY_ENVIRONMENT' => 'production']);
check('on Railway with no volume: a clear warning that says what to do', $msg !== null && str_contains($msg, 'Volume') && str_contains($msg, '/var/www/html/public/assets/uploads'));
check('on Railway with the volume on the uploads folder: no warning', uploadsPersistenceWarning(['RAILWAY_ENVIRONMENT' => 'production', 'RAILWAY_VOLUME_MOUNT_PATH' => '/var/www/html/public/assets/uploads']) === null);
check('on Railway with a volume somewhere else: warned that it is the wrong folder', str_contains((string) uploadsPersistenceWarning(['RAILWAY_ENVIRONMENT' => 'production', 'RAILWAY_VOLUME_MOUNT_PATH' => '/data']), '/data'));
check('on a developer machine (not Railway, no Docker): no warning', uploadsPersistenceWarning([], '') === null);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
