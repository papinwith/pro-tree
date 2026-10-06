<?php
/** The full backup ZIP (database + photos) and restoring photos from it.   php tests/full_backup_test.php */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/full_backup.php';

$pass = $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "PASS  $label\n"; } else { $fail++; echo "FAIL  $label" . ($detail !== '' ? "\n      $detail" : '') . "\n"; }
}

$base = sys_get_temp_dir() . '/fullbackup_' . getmypid();
$src = "$base/src"; $dst = "$base/dst";
foreach (["$src/tree", "$src/species", "$src/qr", $dst] as $d) { mkdir($d, 0775, true); }
register_shutdown_function(function () use ($base) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
    @rmdir($base);
});

$img = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
file_put_contents("$src/tree/a1.jpg", $img);
file_put_contents("$src/species/b2.jpg", $img . 'x');
file_put_contents("$src/qr/tree-1.png", 'qr');
file_put_contents("$src/tree/.hidden", 'h');
file_put_contents("$src/tree/fake.jpg", 'not an image at all');

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE zones (id INTEGER PRIMARY KEY, name TEXT)');
$pdo->exec("INSERT INTO zones VALUES (1, 'โซน A')");

check('QR codes and dotfiles are not backed up', listUploadFilesForBackup($src) === ['species/b2.jpg', 'tree/a1.jpg', 'tree/fake.jpg'], json_encode(listUploadFilesForBackup($src)));

$zipPath = "$base/full.zip";
$out = fopen($zipPath, 'wb');
$r = streamFullBackupZip($pdo, $out, $src, ["zones"]);
fclose($out);
$z = new StoredZipReader($zipPath);
check('the ZIP has README, database, every photo and a manifest', $z->names() === ['README.txt', 'database/backup.sql', 'uploads/species/b2.jpg', 'uploads/tree/a1.jpg', 'uploads/tree/fake.jpg', 'manifest.json'] && $r['files'] === 3, json_encode($z->names()));
check('the database inside is the real backup', str_contains($z->read('database/backup.sql'), "INSERT INTO \"zones\"") && str_contains($z->read('database/backup.sql'), 'โซน A'));
check('photos are byte-identical', $z->read('uploads/tree/a1.jpg') === $img);

check('only safe photo paths are restorable', isRestorablePhotoEntry('uploads/tree/a1.jpg') && !isRestorablePhotoEntry('uploads/tree/../../x.jpg') && !isRestorablePhotoEntry('uploads/tree/a.php') && !isRestorablePhotoEntry('uploads/qr/a.png') && !isRestorablePhotoEntry('../uploads/tree/a.jpg') && !isRestorablePhotoEntry('uploads/tree/sub/a.jpg') && !isRestorablePhotoEntry('database/backup.sql'));

$res = restorePhotosFromZip($zipPath, $dst);
check('restore puts real images back and refuses the fake one', $res['restored'] === 2 && $res['rejected'] === 1 && is_file("$dst/tree/a1.jpg") && !file_exists("$dst/tree/fake.jpg") && file_get_contents("$dst/species/b2.jpg") === $img . 'x', json_encode($res));
file_put_contents("$dst/tree/a1.jpg", 'changed');
$res = restorePhotosFromZip($zipPath, $dst);
check('a second restore does not overwrite existing files', $res['restored'] === 0 && $res['skipped'] === 2 && file_get_contents("$dst/tree/a1.jpg") === 'changed');

// a hostile ZIP: path traversal and a PHP file
$evil = "$base/evil.zip";
$o = fopen($evil, 'wb'); $w = new StreamingZip($o);
$w->addString('uploads/tree/shell.php', '<?php echo 1;');
$w->addString('uploads/tree/a..b.jpg', $img);
$w->addString('uploads/tree/ok.jpg', $img);
$w->finish(); fclose($o);
$res = restorePhotosFromZip($evil, $dst);
check('a hostile ZIP cannot write outside the folder or plant a script', $res['restored'] === 1 && $res['rejected'] === 2 && !file_exists("$dst/tree/shell.php") && !file_exists("$dst/tree/a..b.jpg"), json_encode($res));

file_put_contents("$base/junk.zip", 'junk');
$caught = false;
try { restorePhotosFromZip("$base/junk.zip", $dst); } catch (RuntimeException $e) { $caught = true; }
check('a file that is not a ZIP is refused', $caught);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
