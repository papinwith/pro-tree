<?php
// Full website backup: database (SQL) + uploaded photos in one ZIP, and restoring the photos from such a ZIP.
require_once __DIR__ . '/zip_stream.php';

const BACKUP_RESTORE_MAX_FILE_BYTES = 20 * 1024 * 1024;   // one photo
const BACKUP_RESTORE_MAX_FILES = 5000;

/** Folder the photos live in (a Railway Volume in production). */
function uploadsRoot(): string
{
    return publicDir() . '/assets/uploads';
}

/**
 * Every uploaded file worth backing up, as relative paths under the uploads folder ("tree/abc.jpg").
 * QR codes (qr/) are skipped - they are regenerated from the tree id; symlinks and dotfiles are skipped.
 * @return string[]
 */
function listUploadFilesForBackup(?string $root = null): array
{
    $root = $root ?? uploadsRoot();
    if (!is_dir($root)) {
        return [];
    }
    $found = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isLink() || !$f->isFile()) {
            continue;
        }
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
        if (str_starts_with($rel, 'qr/') || str_starts_with(basename($rel), '.')) {
            continue;
        }
        $found[] = $rel;
    }
    sort($found);
    return $found;
}

function fullBackupReadme(int $photoCount, string $date): string
{
    return "Tree QR system - full backup ($date)\n"
        . "=====================================\n\n"
        . "database/backup.sql   all data of the database (SQL)\n"
        . "uploads/              $photoCount uploaded photos (trees, species, maps, logo; 360-degree frames live in the database)\n"
        . "manifest.json         list of the files with sizes\n\n"
        . "RESTORE\n"
        . "1. Database: create the tables first (docs/install.postgres.sql), then run:\n"
        . "     psql \"\$DATABASE_URL\" -f database/backup.sql\n"
        . "   (the file empties the tables it fills, so run it only on the system you want to restore.)\n"
        . "2. Photos: admin > Backup > \"Restore photos from a backup ZIP\", choose this ZIP.\n"
        . "   Or copy the contents of uploads/ into public/assets/uploads/ on the server.\n";
}

/**
 * Streams the whole backup ZIP into $out (php://output or a file). Nothing is held in memory but one chunk.
 * @param resource $out
 * @return array{files:int, bytes:int}
 */
function streamFullBackupZip(PDO $pdo, $out, ?string $uploadsRoot = null, ?array $tables = null): array
{
    $root = $uploadsRoot ?? uploadsRoot();
    $files = listUploadFilesForBackup($root);
    $zip = new StreamingZip($out);
    $zip->addString('README.txt', fullBackupReadme(count($files), date('Y-m-d H:i:s')));
    $zip->beginEntry('database/backup.sql');
    streamDatabaseBackupSql($pdo, fn(string $chunk) => $zip->write($chunk), $tables);
    $zip->endEntry();
    $manifest = [];
    $bytes = 0;
    foreach ($files as $rel) {
        $full = $root . '/' . $rel;
        $size = @filesize($full);
        if ($size === false || !is_readable($full)) {
            continue;   // vanished or unreadable since the listing
        }
        $zip->addFile('uploads/' . $rel, $full);
        $manifest[] = ['path' => 'uploads/' . $rel, 'bytes' => $size];
        $bytes += $size;
    }
    $zip->addString('manifest.json', json_encode(['created' => date('c'), 'photos' => $manifest], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    $zip->finish();
    return ['files' => count($manifest), 'bytes' => $bytes];
}

/** True for an entry name that is a safe photo path: uploads/<folder>/<file>, plain characters, an image extension. */
function isRestorablePhotoEntry(string $name): bool
{
    if (!preg_match('#^uploads/((?:tree|species|maps|logo|spin)/[A-Za-z0-9._-]{1,120})$#', $name, $m)) {
        return false;
    }
    return !str_contains($name, '..') && (bool) preg_match('/\.(jpe?g|png|webp|gif)$/i', $name);
}

/**
 * Puts the photos of a backup ZIP back into the uploads folder. Existing files with the same name are kept
 * (names are random hashes, so the same name means the same photo) unless $overwrite.
 * @return array{restored:int, skipped:int, rejected:int, errors:string[]}
 */
function restorePhotosFromZip(string $zipPath, ?string $uploadsRoot = null, bool $overwrite = false): array
{
    $root = $uploadsRoot ?? uploadsRoot();
    $res = ['restored' => 0, 'skipped' => 0, 'rejected' => 0, 'errors' => []];
    $reader = new StoredZipReader($zipPath);
    $n = 0;
    foreach ($reader->names() as $name) {
        if (!str_starts_with($name, 'uploads/')) {
            continue;   // database/, README, manifest are not photos
        }
        if (++$n > BACKUP_RESTORE_MAX_FILES) {
            $res['errors'][] = 'too many files';
            break;
        }
        if (!isRestorablePhotoEntry($name) || $reader->size($name) > BACKUP_RESTORE_MAX_FILE_BYTES) {
            $res['rejected']++;
            continue;
        }
        $target = $root . '/' . substr($name, strlen('uploads/'));
        if (!$overwrite && file_exists($target)) {
            $res['skipped']++;
            continue;
        }
        try {
            $bytes = $reader->read($name);
            if (@getimagesizefromstring($bytes) === false) {   // must really be an image
                $res['rejected']++;
                continue;
            }
            $dir = dirname($target);
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException('cannot create folder');
            }
            $tmp = $target . '.part' . getmypid();
            if (file_put_contents($tmp, $bytes) === false || !rename($tmp, $target)) {
                @unlink($tmp);
                throw new RuntimeException('cannot write file');
            }
            $res['restored']++;
        } catch (RuntimeException $e) {
            $res['errors'][] = $name . ': ' . $e->getMessage();
        }
    }
    return $res;
}
