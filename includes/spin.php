<?php
// The 360-degree "spin" of a tree: a set of photos taken while walking once around it, shown on the public page as a
// picture that keeps turning (like a GIF) and that the visitor can drag to look around the tree.
//
// The frames are kept IN THE DATABASE, not as files: the container's own disk is wiped on every redeploy (a Railway
// Volume is needed for uploaded files - see uploadsPersistenceWarning()), and a spin that vanished after each deploy
// would be useless. Photos are base64 text, a plain string that behaves the same on every database driver.
//
// A new spin is uploaded in several small requests (PHP limits files per request) under a fresh token, and only
// commitSpin() makes it the tree's spin - atomically replacing the old one - so a visitor never sees a half-uploaded
// turn. Frame URLs contain the token, so browsers may cache them forever.
require_once __DIR__ . '/functions.php';

const SPIN_MIN_FRAMES = 8;
const SPIN_MAX_FRAMES = 72;
const SPIN_MAX_FRAME_BYTES = 400000;     // one frame (the admin page shrinks them to ~720 px, normally 40-90 KB)
const SPIN_STALE_UPLOAD_SECONDS = 86400; // frames of an upload that was never committed are discarded after a day

function spinTablesDdl(string $driver): array
{
    $id = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGSERIAL PRIMARY KEY';
    $ts = $driver === 'sqlite' ? 'DATETIME DEFAULT CURRENT_TIMESTAMP' : 'TIMESTAMP NOT NULL DEFAULT now()';
    return [
        "CREATE TABLE IF NOT EXISTS tree_spin_frames (
            id $id,
            tree_id INTEGER NOT NULL,
            token VARCHAR(16) NOT NULL,
            idx INTEGER NOT NULL,
            mime VARCHAR(20) NOT NULL,
            image_b64 TEXT NOT NULL,
            created_at $ts,
            UNIQUE (tree_id, token, idx)
        )",
        "CREATE TABLE IF NOT EXISTS tree_spins (
            tree_id INTEGER NOT NULL PRIMARY KEY,
            token VARCHAR(16) NOT NULL,
            frame_count INTEGER NOT NULL,
            updated_at $ts
        )",
    ];
}

/** Creates the tables on first use (admin side only), so an already-deployed database needs no manual migration. */
function ensureSpinTables(PDO $pdo): void
{
    static $done = null; // keyed by the PDO object itself - object ids are reused once a PDO is freed
    $done ??= new WeakMap();
    if (!isset($done[$pdo])) {
        foreach (spinTablesDdl((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) as $sql) {
            $pdo->exec($sql);
        }
        $done[$pdo] = true;
    }
}

function newSpinToken(): string
{
    return bin2hex(random_bytes(8));
}

function validSpinToken(string $token): bool
{
    return (bool) preg_match('/^[a-f0-9]{16}$/', $token);
}

/** The image type from the file's own first bytes (never from what the browser claimed), or null. */
function spinImageMime(string $bytes): ?string
{
    if (strncmp($bytes, "\xFF\xD8\xFF", 3) === 0) {
        return 'image/jpeg';
    }
    if (strncmp($bytes, "\x89PNG\r\n\x1A\n", 8) === 0) {
        return 'image/png';
    }
    if (strlen($bytes) > 12 && substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP') {
        return 'image/webp';
    }
    return null;
}

/** Stores one frame of an upload in progress. Returns false (stores nothing) for anything unacceptable. */
function saveSpinFrame(PDO $pdo, int $treeId, string $token, int $idx, string $bytes): bool
{
    $mime = spinImageMime($bytes);
    if ($treeId <= 0 || !validSpinToken($token) || $idx < 0 || $idx >= SPIN_MAX_FRAMES || $mime === null || strlen($bytes) > SPIN_MAX_FRAME_BYTES) {
        return false;
    }
    ensureSpinTables($pdo);
    $pdo->prepare('INSERT INTO tree_spin_frames (tree_id, token, idx, mime, image_b64) VALUES (:t, :k, :i, :m, :b)
                   ON CONFLICT (tree_id, token, idx) DO UPDATE SET mime = :m2, image_b64 = :b2')
        ->execute(['t' => $treeId, 'k' => $token, 'i' => $idx, 'm' => $mime, 'b' => base64_encode($bytes), 'm2' => $mime, 'b2' => base64_encode($bytes)]);
    return true;
}

/**
 * Makes an uploaded set the tree's spin, replacing the previous one. The set must be complete: frames 0..n-1 with no gap and
 * at least SPIN_MIN_FRAMES of them. Returns the number of frames, or null if the set is not usable (nothing changes then).
 */
function commitSpin(PDO $pdo, int $treeId, string $token): ?int
{
    if ($treeId <= 0 || !validSpinToken($token)) {
        return null;
    }
    ensureSpinTables($pdo);
    $stmt = $pdo->prepare('SELECT COUNT(*) AS n, MAX(idx) AS top FROM tree_spin_frames WHERE tree_id = :t AND token = :k');
    $stmt->execute(['t' => $treeId, 'k' => $token]);
    $row = $stmt->fetch();
    $count = (int) ($row['n'] ?? 0);
    if ($count < SPIN_MIN_FRAMES || $count > SPIN_MAX_FRAMES || (int) $row['top'] !== $count - 1) {
        return null; // too few, or a frame is missing in the middle
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM tree_spins WHERE tree_id = :t')->execute(['t' => $treeId]);
        $pdo->prepare('INSERT INTO tree_spins (tree_id, token, frame_count) VALUES (:t, :k, :n)')->execute(['t' => $treeId, 'k' => $token, 'n' => $count]);
        $pdo->prepare('DELETE FROM tree_spin_frames WHERE tree_id = :t AND token <> :k')->execute(['t' => $treeId, 'k' => $token]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    purgeStaleSpinUploads($pdo);
    return $count;
}

/** Drops frames of uploads that were started but never committed (a closed tab, a lost connection). */
function purgeStaleSpinUploads(PDO $pdo): void
{
    $limit = date('Y-m-d H:i:s', time() - SPIN_STALE_UPLOAD_SECONDS);
    $pdo->prepare('DELETE FROM tree_spin_frames WHERE created_at < :l AND token NOT IN (SELECT token FROM tree_spins)')->execute(['l' => $limit]);
}

/**
 * The tree's current spin ['token' => ..., 'frame_count' => ...] or null. A plain read that never creates tables, so the public
 * page costs nothing extra: if the tables do not exist yet (no spin has ever been uploaded) that simply means "no spin".
 */
function getTreeSpin(PDO $pdo, int $treeId): ?array
{
    try {
        $stmt = $pdo->prepare('SELECT token, frame_count FROM tree_spins WHERE tree_id = :t');
        $stmt->execute(['t' => $treeId]);
        $row = $stmt->fetch();
        return $row ? ['token' => (string) $row['token'], 'frame_count' => (int) $row['frame_count']] : null;
    } catch (Throwable $e) {
        return null;
    }
}

/** One frame of the tree's committed spin: ['mime' => ..., 'bytes' => ...] or null. */
function getSpinFrame(PDO $pdo, int $treeId, string $token, int $idx): ?array
{
    if (!validSpinToken($token) || $idx < 0 || $idx >= SPIN_MAX_FRAMES) {
        return null;
    }
    try {
        $stmt = $pdo->prepare('SELECT f.mime, f.image_b64 FROM tree_spin_frames f JOIN tree_spins s ON s.tree_id = f.tree_id AND s.token = f.token
                               WHERE f.tree_id = :t AND f.token = :k AND f.idx = :i');
        $stmt->execute(['t' => $treeId, 'k' => $token, 'i' => $idx]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        return null;
    }
    $bytes = $row ? base64_decode((string) $row['image_b64'], true) : false;
    return $bytes === false || $bytes === '' ? null : ['mime' => (string) $row['mime'], 'bytes' => $bytes];
}

/** Removes the tree's spin and every frame of it (also used when the tree itself is deleted). */
function deleteTreeSpin(PDO $pdo, int $treeId): void
{
    try {
        ensureSpinTables($pdo);
        $pdo->prepare('DELETE FROM tree_spin_frames WHERE tree_id = :t')->execute(['t' => $treeId]);
        $pdo->prepare('DELETE FROM tree_spins WHERE tree_id = :t')->execute(['t' => $treeId]);
    } catch (Throwable $e) {
        error_log('spin not removed: ' . $e->getMessage());
    }
}

/**
 * Uploaded photos (and the QR codes) live on the container's disk, which is wiped on every redeploy unless a persistent
 * volume is mounted at public/assets/uploads. Returns a warning (Thai) when that looks to be the case, else null.
 * Railway sets RAILWAY_VOLUME_MOUNT_PATH when a volume is attached; elsewhere /proc/mounts is checked.
 */
function uploadsPersistenceWarning(?array $env = null, ?string $mounts = null): ?string
{
    $env ??= getenv();
    $onRailway = !empty($env['RAILWAY_ENVIRONMENT']) || !empty($env['RAILWAY_PROJECT_ID']);
    if ($onRailway) {
        $volume = (string) ($env['RAILWAY_VOLUME_MOUNT_PATH'] ?? '');
        if ($volume !== '' && str_ends_with(rtrim($volume, '/'), 'public/assets/uploads')) {
            return null;
        }
        return $volume === ''
            ? 'ยังไม่ได้ผูก Volume ถาวรให้ Railway — รูปที่อัปโหลดทั้งหมด (รูปต้นไม้ รูปชนิดพันธุ์ ป้าย QR) จะหายทุกครั้งที่ deploy หรือรีสตาร์ท ไปที่ Railway → บริการนี้ → Settings → Volumes → New Volume แล้วตั้ง Mount path เป็น /var/www/html/public/assets/uploads (ภาพหมุน 360° เก็บในฐานข้อมูลจึงไม่หาย)'
            : 'Volume ที่ผูกอยู่ ( ' . $volume . ' ) ไม่ใช่โฟลเดอร์รูป — ตั้ง Mount path เป็น /var/www/html/public/assets/uploads ไม่เช่นนั้นรูปที่อัปโหลดจะหายเมื่อ deploy';
    }
    $mounts ??= is_readable('/proc/mounts') ? (string) file_get_contents('/proc/mounts') : '';
    if ($mounts !== '' && !str_contains($mounts, ' /var/www/html/public/assets/uploads ') && is_file('/.dockerenv')) {
        return 'โฟลเดอร์รูปไม่ได้อยู่บน Volume ถาวร — รูปที่อัปโหลดอาจหายเมื่อ container ถูกสร้างใหม่';
    }
    return null;
}
