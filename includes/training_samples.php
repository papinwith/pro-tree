<?php
// Photos that should teach the local "tree" model something new.
//
// When the server AI had to be asked (tree was not sure), the answer can become a training example for tree:
//   - "approved" automatically when two teachers named the same species (the local Qwen + the second opinion),
//   - "approved" when an admin presses "use this result" (source = admin),
//   - "pending" when the two teachers disagreed - a person decides on admin/training_samples.php.
// Nothing here changes the model by itself: approved photos are downloaded with ml/pull_feedback.py and the
// model is retrained by hand (ml/README), so a wrong label can be reviewed before it is ever learned.
//
// Written with plain, portable SQL (INSERT ... ON CONFLICT DO NOTHING etc.) so the same code runs on the site's
// PostgreSQL and on SQLite in tests/training_samples_test.php. Photos are kept as base64 text, not bytea:
// a plain string parameter behaves the same on every driver.
require_once __DIR__ . '/plant_identify.php';

const TRAINING_SAMPLE_MAX_BYTES = 1500000;  // a stored photo; the identify form already shrinks to 1024 px
const TRAINING_SAMPLE_MAX_PENDING = 300;     // reviewers are people: don't let the queue grow without bound

function trainingSamplesDdl(string $driver): string
{
    $id = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGSERIAL PRIMARY KEY';
    $ts = $driver === 'sqlite' ? 'DATETIME DEFAULT CURRENT_TIMESTAMP' : 'TIMESTAMP NOT NULL DEFAULT now()';
    return "CREATE TABLE IF NOT EXISTS training_samples (
        id $id,
        image_hash VARCHAR(40) NOT NULL UNIQUE,
        name_scientific VARCHAR(160) NOT NULL,
        status VARCHAR(10) NOT NULL DEFAULT 'pending',
        source VARCHAR(20) NOT NULL,
        teachers VARCHAR(80) NULL,
        mime VARCHAR(40) NOT NULL,
        image_b64 TEXT NOT NULL,
        created_by INTEGER NULL,
        created_at $ts,
        reviewed_by INTEGER NULL
    )";
}

/** Creates the table on first use, so an already-deployed database needs no manual migration. */
function ensureTrainingSamplesTable(PDO $pdo): void
{
    static $done = [];
    $key = spl_object_id($pdo);
    if (!isset($done[$key])) {
        $pdo->exec(trainingSamplesDdl((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)));
        $done[$key] = true;
    }
}

/** "cassia FISTULA L." -> "Cassia fistula" (genus + species, tidy case), or '' if there is no usable name. */
function canonicalSpeciesName(string $name): string
{
    $key = scientificNameKey($name);
    if ($key === '' || !preg_match('/^[\p{L}][\p{L}.\'×-]*( [\p{L}.\'×-]+)?$/u', $key)) {
        return '';
    }
    [$genus, $species] = array_pad(explode(' ', $key, 2), 2, '');
    return mb_strtoupper(mb_substr($genus, 0, 1)) . mb_substr($genus, 1) . ($species !== '' ? ' ' . $species : '');
}

/**
 * Pure: what to do with an identify result. Returns ['status' => 'approved'|'pending', 'source' => ..., 'teachers' => ...,
 * 'name' => ...] or null when it should not be stored. Two teachers agreeing is auto-approved; two disagreeing
 * go to a person; a single teacher alone is not stored (it only becomes a sample if an admin confirms it).
 */
function decideTrainingSample(array $result): ?array
{
    if (empty($result['is_plant'])) {
        return null;
    }
    $second = $result['second_opinion'] ?? null;
    if (!is_array($second) || !array_key_exists('agrees', $second)) {
        return null;
    }
    $teachers = 'qwen+' . preg_replace('/[^a-z]/', '', (string) ($second['source'] ?? 'second'));
    if ($second['agrees']) {
        $name = canonicalSpeciesName((string) ($result['name_scientific'] ?? ''));
        return $name === '' ? null : ['status' => 'approved', 'source' => 'teachers', 'teachers' => $teachers, 'name' => $name];
    }
    // They disagree: queue the answer that is shown (the more confident one) for a person to judge.
    $name = canonicalSpeciesName((string) ($result['name_scientific'] ?? ''));
    return $name === '' ? null : ['status' => 'pending', 'source' => 'teachers', 'teachers' => $teachers, 'name' => $name];
}

/** True when $bytes looks like a JPEG/PNG/WebP/GIF of a size worth keeping. */
function trainingImageAcceptable(string $bytes, string $mime): bool
{
    return $bytes !== '' && strlen($bytes) <= TRAINING_SAMPLE_MAX_BYTES && in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true);
}

/**
 * Stores a photo with its label. A photo already stored (same bytes) is left alone, except that an admin's
 * confirmation upgrades a pending one to approved. Returns the sample id, or null if it was not stored.
 */
function saveTrainingSample(PDO $pdo, string $bytes, string $mime, string $name, string $status, string $source, ?string $teachers, ?int $adminId): ?int
{
    $name = canonicalSpeciesName($name);
    if ($name === '' || !trainingImageAcceptable($bytes, $mime) || !in_array($status, ['approved', 'pending'], true)) {
        return null;
    }
    ensureTrainingSamplesTable($pdo);
    $hash = sha1($bytes);

    $existing = $pdo->prepare('SELECT id, status FROM training_samples WHERE image_hash = :h');
    $existing->execute(['h' => $hash]);
    $row = $existing->fetch();
    if ($row) {
        if ($status === 'approved' && $row['status'] === 'pending') {
            $pdo->prepare("UPDATE training_samples SET status = 'approved', source = :s, name_scientific = :n, reviewed_by = :a WHERE id = :id")
                ->execute(['s' => $source, 'n' => $name, 'a' => $adminId, 'id' => $row['id']]);
        }
        return (int) $row['id'];
    }
    if ($status === 'pending') {
        $count = (int) $pdo->query("SELECT COUNT(*) FROM training_samples WHERE status = 'pending'")->fetchColumn();
        if ($count >= TRAINING_SAMPLE_MAX_PENDING) {
            return null;
        }
    }
    $pdo->prepare('INSERT INTO training_samples (image_hash, name_scientific, status, source, teachers, mime, image_b64, created_by)
                   VALUES (:h, :n, :st, :so, :t, :m, :b, :a) ON CONFLICT (image_hash) DO NOTHING')
        ->execute(['h' => $hash, 'n' => $name, 'st' => $status, 'so' => $source, 't' => $teachers, 'm' => $mime, 'b' => base64_encode($bytes), 'a' => $adminId]);
    $existing->execute(['h' => $hash]);
    $row = $existing->fetch();
    return $row ? (int) $row['id'] : null;
}

/** Records an identify result per decideTrainingSample(). Never throws: learning must not break identifying. */
function recordTrainingSampleFromResult(PDO $pdo, string $bytes, string $mime, array $result, ?int $adminId): ?int
{
    try {
        $decision = decideTrainingSample($result);
        return $decision === null ? null
            : saveTrainingSample($pdo, $bytes, $mime, $decision['name'], $decision['status'], $decision['source'], $decision['teachers'], $adminId);
    } catch (Throwable $e) {
        error_log('training sample not stored: ' . $e->getMessage());
        return null;
    }
}

/** An admin pressed "use this result": the photo + name become an approved sample. */
function confirmTrainingSample(PDO $pdo, string $bytes, string $mime, string $name, int $adminId): ?int
{
    return saveTrainingSample($pdo, $bytes, $mime, $name, 'approved', 'admin', null, $adminId);
}

/** Pending samples for the review page, newest first (image included as a data URI). */
function pendingTrainingSamples(PDO $pdo, int $limit = 50): array
{
    ensureTrainingSamplesTable($pdo);
    $stmt = $pdo->prepare("SELECT id, name_scientific, teachers, mime, image_b64, created_at FROM training_samples WHERE status = 'pending' ORDER BY id DESC LIMIT :l");
    $stmt->bindValue(':l', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/** Approve (optionally with a corrected name) or reject a pending sample. */
function reviewTrainingSample(PDO $pdo, int $id, string $decision, ?string $correctedName, int $adminId): bool
{
    ensureTrainingSamplesTable($pdo);
    if ($decision === 'approve') {
        $name = $correctedName !== null && trim($correctedName) !== '' ? canonicalSpeciesName($correctedName) : null;
        if ($correctedName !== null && trim($correctedName) !== '' && $name === '') {
            return false;
        }
        $sql = "UPDATE training_samples SET status = 'approved', source = 'admin', reviewed_by = :a" . ($name ? ', name_scientific = :n' : '') . " WHERE id = :id AND status = 'pending'";
        $params = ['a' => $adminId, 'id' => $id] + ($name ? ['n' => $name] : []);
    } elseif ($decision === 'reject') {
        $sql = "UPDATE training_samples SET status = 'rejected', reviewed_by = :a WHERE id = :id AND status = 'pending'";
        $params = ['a' => $adminId, 'id' => $id];
    } else {
        return false;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount() > 0;
}

/** Approved samples with id > $sinceId, oldest first - for ml/pull_feedback.py. */
function approvedTrainingSamplesSince(PDO $pdo, int $sinceId, int $limit = 100): array
{
    ensureTrainingSamplesTable($pdo);
    $stmt = $pdo->prepare("SELECT id, name_scientific, mime, image_b64 FROM training_samples WHERE status = 'approved' AND id > :s ORDER BY id ASC LIMIT :l");
    $stmt->bindValue(':s', $sinceId, PDO::PARAM_INT);
    $stmt->bindValue(':l', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/** Counts per status, for the review page header. */
function trainingSampleCounts(PDO $pdo): array
{
    ensureTrainingSamplesTable($pdo);
    $out = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
    foreach ($pdo->query('SELECT status, COUNT(*) AS n FROM training_samples GROUP BY status')->fetchAll() as $r) {
        $out[$r['status']] = (int) $r['n'];
    }
    return $out;
}
