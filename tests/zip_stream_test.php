<?php
/**
 * The plain-PHP ZIP writer/reader (includes/zip_stream.php). The archives it writes are opened with PHP's own ZipArchive
 * (present on a developer machine, not in the web container) and, when Python is available, with Python's zipfile too.
 *   php tests/zip_stream_test.php
 */
require_once __DIR__ . '/../includes/zip_stream.php';

$pass = $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "PASS  $label\n"; } else { $fail++; echo "FAIL  $label" . ($detail !== '' ? "\n      $detail" : '') . "\n"; }
}

$dir = sys_get_temp_dir() . '/ziptest_' . getmypid();
mkdir($dir);
register_shutdown_function(function () use ($dir) { array_map('unlink', glob("$dir/*") ?: []); @rmdir($dir); });

$big = random_bytes(300000);                       // not text, bigger than one 64 KB chunk
file_put_contents("$dir/photo.jpg", $big);
$thai = "ข้อมูลภาษาไทย 🌳\nบรรทัดที่สอง";
$zipPath = "$dir/out.zip";
$out = fopen($zipPath, 'wb');
$z = new StreamingZip($out);
$z->addString('README.txt', 'hello');
$z->beginEntry('database/backup.sql');
$z->write("INSERT 1;\n");
$z->write('');
$z->write("INSERT 2;\n");
$z->endEntry();
$z->addFile('uploads/tree/photo.jpg', "$dir/photo.jpg");
$z->addString('uploads/ชื่อไทย/รูป.txt', $thai);
$z->addString('empty.txt', '');
$z->finish();
fclose($out);

$reader = new StoredZipReader($zipPath);
check('the reader lists every entry', $reader->names() === ['README.txt', 'database/backup.sql', 'uploads/tree/photo.jpg', 'uploads/ชื่อไทย/รูป.txt', 'empty.txt'], json_encode($reader->names(), JSON_UNESCAPED_UNICODE));
check('contents come back exactly, including binary data over one chunk and Thai/emoji names',
    $reader->read('README.txt') === 'hello' && $reader->read('database/backup.sql') === "INSERT 1;\nINSERT 2;\n" && $reader->read('uploads/tree/photo.jpg') === $big && $reader->read('uploads/ชื่อไทย/รูป.txt') === $thai);
check('an empty entry works and sizes are reported', $reader->read('empty.txt') === '' && $reader->size('uploads/tree/photo.jpg') === 300000);

if (class_exists('ZipArchive')) {
    $za = new ZipArchive();
    $opened = $za->open($zipPath, ZipArchive::CHECKCONS);
    check('PHP\'s own ZipArchive opens it and finds nothing inconsistent', $opened === true, (string) $opened);
    check('ZipArchive reads the same bytes (its own CRC check passes)', $za->getFromName('uploads/tree/photo.jpg') === $big && $za->getFromName('database/backup.sql') === "INSERT 1;\nINSERT 2;\n" && $za->numFiles === 5);
    $za->close();
} else {
    echo "SKIP  ZipArchive not installed here\n";
}
$py = trim((string) @shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where python 2>NUL' : 'command -v python3'));
if ($py !== '') {
    $script = "import zipfile,sys; z=zipfile.ZipFile(sys.argv[1]); print('BAD' if z.testzip() else 'OK', len(z.namelist()), z.read('README.txt').decode())";
    $res = trim((string) shell_exec('python -c ' . escapeshellarg($script) . ' ' . escapeshellarg($zipPath) . ' 2>&1'));
    check('Python\'s zipfile agrees: no bad entries, 5 files', str_starts_with($res, 'OK 5 hello'), $res);
}

// --- damaged / unusual input ---
$bad = file_get_contents($zipPath);
$offsetOfPhoto = strpos($bad, substr($big, 0, 32));
$flip = substr_replace($bad, chr(ord($bad[$offsetOfPhoto + 100]) ^ 0xFF), $offsetOfPhoto + 100, 1);
file_put_contents("$dir/flipped.zip", $flip);
$caught = false;
try { (new StoredZipReader("$dir/flipped.zip"))->read('uploads/tree/photo.jpg'); } catch (RuntimeException $e) { $caught = str_contains($e->getMessage(), 'checksum'); }
check('a damaged byte inside a photo is detected by the checksum', $caught);
file_put_contents("$dir/junk.zip", 'this is not a zip file at all');
$caught = false;
try { new StoredZipReader("$dir/junk.zip"); } catch (RuntimeException $e) { $caught = true; }
check('a file that is not a ZIP is refused', $caught);
file_put_contents("$dir/cut.zip", substr($bad, 0, strlen($bad) - 30));
$caught = false;
try { new StoredZipReader("$dir/cut.zip"); } catch (RuntimeException $e) { $caught = true; }
check('a truncated ZIP is refused', $caught);

$deflate = null;
if (class_exists('ZipArchive')) {                                     // an archive somebody re-zipped with real compression
    $za = new ZipArchive();
    $za->open("$dir/deflated.zip", ZipArchive::CREATE);
    $za->addFromString('uploads/tree/a.txt', str_repeat('abc', 5000));
    $za->close();
    $deflate = (new StoredZipReader("$dir/deflated.zip"))->read('uploads/tree/a.txt');
    check('archives re-zipped with normal compression can be read too', $deflate === str_repeat('abc', 5000));
}

$o = fopen("$dir/x.zip", 'wb');
$w = new StreamingZip($o);
$rejected = 0;
foreach (['../evil', 'a/../../b', '', '/'] as $n) { try { $w->addString($n, 'x'); } catch (InvalidArgumentException $e) { $rejected++; } }
check('entry names that climb out of the archive or are empty are refused', $rejected === 4, (string) $rejected);
fclose($o);

$memBefore = memory_get_peak_usage();
$o = fopen("$dir/bigstream.zip", 'wb');
$w = new StreamingZip($o);
$w->beginEntry('big.bin');
for ($i = 0; $i < 400; $i++) { $w->write(str_repeat('x', 100000)); }   // 40 MB, written in pieces
$w->endEntry();
$w->finish();
fclose($o);
check('a 40 MB entry is written piece by piece without holding it in memory', filesize("$dir/bigstream.zip") > 40000000 && memory_get_peak_usage() - $memBefore < 5000000, number_format(memory_get_peak_usage() - $memBefore) . ' bytes extra');

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
