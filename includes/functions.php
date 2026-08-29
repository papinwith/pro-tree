<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/lang.php';

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/**
 * Admin-entered map links (tree_form.php, settings.php) get rendered back
 * out as an <a href>, so a scheme other than http/https — javascript:,
 * data:, etc. — would execute in whoever's browser clicks it. Only an admin
 * can set this today, but validating it is cheap and closes the gap if that
 * ever changes. Returns null for anything empty or invalid, so callers can
 * just do `$url = validatePublicUrl($input)` and store/ignore accordingly.
 */
function validatePublicUrl(?string $url): ?string
{
    $url = trim((string) $url);
    if ($url === '') {
        return null;
    }
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return null;
    }
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return null;
    }
    return $url;
}

function clientIp(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function clientUserAgent(): string
{
    return substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
}

/**
 * Reads the visitor cookie, creating a new UUID + cookie if missing,
 * then upserts the corresponding row in `visitors`.
 * Returns the internal visitors.id.
 */
function getOrCreateVisitorId(PDO $pdo): int
{
    $uuid = $_COOKIE[VISITOR_COOKIE_NAME] ?? null;

    if (!$uuid || !preg_match('/^[0-9a-f-]{36}$/i', $uuid)) {
        $uuid = generateUuidV4();
        setcookie(VISITOR_COOKIE_NAME, $uuid, [
            'expires' => time() + VISITOR_COOKIE_TTL,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    $ip = clientIp();
    $ua = clientUserAgent();

    $stmt = $pdo->prepare('SELECT id FROM visitors WHERE visitor_uuid = :uuid');
    $stmt->execute(['uuid' => $uuid]);
    $row = $stmt->fetch();

    if ($row) {
        $update = $pdo->prepare(
            'UPDATE visitors SET last_ip_address = :ip, last_user_agent = :ua, last_seen_at = NOW() WHERE id = :id'
        );
        $update->execute(['ip' => $ip, 'ua' => $ua, 'id' => $row['id']]);
        return (int) $row['id'];
    }

    $insert = $pdo->prepare(
        'INSERT INTO visitors (visitor_uuid, last_ip_address, last_user_agent) VALUES (:uuid, :ip, :ua)'
    );
    $insert->execute(['uuid' => $uuid, 'ip' => $ip, 'ua' => $ua]);
    return (int) $pdo->lastInsertId();
}

function generateUuidV4(): string
{
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * Logs a scan event and snapshots the tree's CURRENT registered zone/
 * location onto the scan row itself — so a later tree move never rewrites
 * what this historical scan record shows. GPS columns are left NULL here;
 * the browser reports its own location asynchronously via updateScanGps()
 * after the page has already rendered (see public/scan_geo.php).
 * Returns the new tree_scans.id so the page can pair it with that follow-up call.
 */
function logScan(PDO $pdo, int $treeId, int $visitorId, ?int $registeredZoneId = null, ?float $registeredLat = null, ?float $registeredLng = null): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO tree_scans (tree_id, visitor_id, ip_address, user_agent, scanned_at, registered_zone_id, registered_lat, registered_lng)
         VALUES (:tid, :vid, :ip, :ua, NOW(), :rzid, :rlat, :rlng)'
    );
    $stmt->execute([
        'tid' => $treeId,
        'vid' => $visitorId,
        'ip' => clientIp(),
        'ua' => clientUserAgent(),
        'rzid' => $registeredZoneId,
        'rlat' => $registeredLat,
        'rlng' => $registeredLng,
    ]);
    return (int) $pdo->lastInsertId();
}

/**
 * Records the visitor's own GPS position for a scan already logged by
 * logScan(). Only updates a row that (a) belongs to this same visitor and
 * (b) was created recently — prevents an arbitrary scan_id from being
 * used to graffiti someone else's scan history. Silently does nothing
 * (returns false) on any mismatch; the calling endpoint always responds
 * 200 either way since a rejected update must never look like an error
 * to the visitor's browser.
 */
function updateScanGps(PDO $pdo, int $scanId, int $visitorId, float $lat, float $lng, ?float $accuracyM): bool
{
    $stmt = $pdo->prepare(
        "UPDATE tree_scans
         SET scan_lat = :lat, scan_lng = :lng, gps_accuracy_m = :acc, gps_available = 1
         WHERE id = :id AND visitor_id = :vid AND scanned_at >= (NOW() - INTERVAL 10 MINUTE)"
    );
    $stmt->execute([
        'lat' => $lat,
        'lng' => $lng,
        'acc' => $accuracyM,
        'id' => $scanId,
        'vid' => $visitorId,
    ]);
    return $stmt->rowCount() > 0;
}

function getScanStats(PDO $pdo, int $treeId): array
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS total, COUNT(DISTINCT visitor_id) AS unique_visitors
         FROM tree_scans WHERE tree_id = :tid'
    );
    $stmt->execute(['tid' => $treeId]);
    $row = $stmt->fetch();
    $total = (int) $row['total'];
    $unique = (int) $row['unique_visitors'];
    return [
        'total' => $total,
        'unique' => $unique,
        'repeated' => $total - $unique,
    ];
}

/** Observation history for a tree, most recent first. */
function getObservationsForTree(PDO $pdo, int $treeId): array
{
    $stmt = $pdo->prepare('SELECT * FROM observations WHERE tree_id = :tid ORDER BY observed_at DESC, id DESC');
    $stmt->execute(['tid' => $treeId]);
    return $stmt->fetchAll();
}

/** Maintenance activity history for a tree, most recent first. */
function getMaintenanceLogsForTree(PDO $pdo, int $treeId): array
{
    $stmt = $pdo->prepare('SELECT * FROM maintenance_logs WHERE tree_id = :tid ORDER BY performed_at DESC, id DESC');
    $stmt->execute(['tid' => $treeId]);
    return $stmt->fetchAll();
}

/** All nursery stock rows for a species, most recently updated first. */
function getStockForSpecies(PDO $pdo, int $speciesId): array
{
    $stmt = $pdo->prepare('SELECT * FROM nursery_stock WHERE species_id = :sid ORDER BY updated_at DESC');
    $stmt->execute(['sid' => $speciesId]);
    return $stmt->fetchAll();
}

/**
 * Data-completeness check ("ความครบถ้วนของข้อมูล", KPI target ≥95% —
 * proposal §9). A tree counts as complete when it has a photo, a recorded
 * GPS location, and at least one logged observation — i.e. staff have
 * actually been out to photograph, place, and survey it, not just created
 * a placeholder row. Returns overall totals plus a per-zone breakdown.
 */
function getDataCompletenessStats(PDO $pdo): array
{
    $rows = $pdo->query(
        "SELECT z.id AS zone_id, z.name AS zone_name,
                (t.image_path IS NOT NULL AND t.latitude IS NOT NULL AND t.longitude IS NOT NULL
                 AND EXISTS(SELECT 1 FROM observations o WHERE o.tree_id = t.id)) AS is_complete
         FROM trees t JOIN zones z ON z.id = t.zone_id"
    )->fetchAll();

    $byZone = [];
    $totalComplete = 0;
    foreach ($rows as $r) {
        $zoneId = (int) $r['zone_id'];
        if (!isset($byZone[$zoneId])) {
            $byZone[$zoneId] = ['zone_name' => $r['zone_name'], 'total' => 0, 'complete' => 0];
        }
        $byZone[$zoneId]['total']++;
        if ((int) $r['is_complete'] === 1) {
            $byZone[$zoneId]['complete']++;
            $totalComplete++;
        }
    }
    foreach ($byZone as &$z) {
        $z['percent'] = $z['total'] > 0 ? round($z['complete'] / $z['total'] * 100, 1) : 0.0;
    }
    unset($z);

    $total = count($rows);
    return [
        'total' => $total,
        'complete' => $totalComplete,
        'percent' => $total > 0 ? round($totalComplete / $total * 100, 1) : 0.0,
        'by_zone' => $byZone,
    ];
}

/**
 * Whether a species currently has any stock marked available for sale —
 * drives the "sale status" badge on the public plant page.
 */
function speciesSaleStatus(PDO $pdo, int $speciesId): ?string
{
    $stmt = $pdo->prepare(
        "SELECT sale_status FROM nursery_stock WHERE species_id = :sid AND sale_status != 'not_for_sale'
         ORDER BY FIELD(sale_status, 'available','reserved','sold_out') LIMIT 1"
    );
    $stmt->execute(['sid' => $speciesId]);
    $status = $stmt->fetchColumn();
    return $status !== false ? $status : null;
}

/** Returns all category rows (code, name_th, name_en, name_zh), ordered by code. */
function getAllCategories(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM categories ORDER BY code ASC')->fetchAll();
}

function getCategoryByCode(PDO $pdo, string $code): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM categories WHERE code = :code');
    $stmt->execute(['code' => $code]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Next free 3-digit category code, one past the highest existing code —
 * staff never pick or type this themselves (see admin/category_form.php).
 * Seed data starts at 101, so a fresh install's first new category is 111.
 */
function nextCategoryCode(PDO $pdo): string
{
    $max = (int) $pdo->query('SELECT MAX(CAST(code AS UNSIGNED)) FROM categories')->fetchColumn();
    return str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
}

/**
 * A classification_id is a 10-digit code: the first 3 digits are a
 * categories.code (e.g. 001 = Orchid), the remaining 7 digits identify the
 * species/origin/sequence within that category.
 */
function isValidClassificationIdFormat(string $id): bool
{
    return (bool) preg_match('/^\d{10}$/', $id);
}

function classificationCategoryCode(string $classificationId): string
{
    return substr($classificationId, 0, 3);
}

/** Localized category name for a species' classification_id, or null if unset/unknown. */
function classificationCategoryName(PDO $pdo, ?string $classificationId): ?string
{
    if (!$classificationId) {
        return null;
    }
    $category = getCategoryByCode($pdo, classificationCategoryCode($classificationId));
    if (!$category) {
        return null;
    }
    $locale = currentLocale();
    return $category["name_$locale"] ?? $category['name_th'];
}

/** All subtype rows ("ชนิด"), ordered by category then name. */
function getAllSubtypes(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM subtypes ORDER BY category_code ASC, name_th ASC')->fetchAll();
}

/** Subtypes belonging to one category, ordered by name. */
function getSubtypesByCategory(PDO $pdo, string $categoryCode): array
{
    $stmt = $pdo->prepare('SELECT * FROM subtypes WHERE category_code = :cc ORDER BY name_th ASC');
    $stmt->execute(['cc' => $categoryCode]);
    return $stmt->fetchAll();
}

function getSubtypeById(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM subtypes WHERE id = :id');
    $stmt->execute(['id' => $id]);
    return $stmt->fetch() ?: null;
}

/** Returns all zone rows, ordered by name. */
function getAllZones(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM zones ORDER BY name ASC')->fetchAll();
}

function getZoneById(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM zones WHERE id = :id');
    $stmt->execute(['id' => $id]);
    return $stmt->fetch() ?: null;
}

/** Returns all species rows, ordered by name. */
function getAllSpecies(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM species ORDER BY name ASC')->fetchAll();
}

/**
 * Next free 3-digit species_code within a category — staff don't need to
 * know or pick a number themselves; leaving the field blank in the admin
 * form auto-assigns one past the highest existing code in that category.
 */
function nextSpeciesCode(PDO $pdo, string $categoryCode): string
{
    $stmt = $pdo->prepare('SELECT MAX(CAST(species_code AS UNSIGNED)) FROM species WHERE category_code = :cc');
    $stmt->execute(['cc' => $categoryCode]);
    $max = (int) $stmt->fetchColumn();
    return str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
}

/** species_code values already in use, grouped by category_code. */
function getUsedSpeciesCodesByCategory(PDO $pdo): array
{
    $used = [];
    foreach ($pdo->query('SELECT category_code, species_code FROM species')->fetchAll() as $row) {
        $used[$row['category_code']][] = $row['species_code'];
    }
    return $used;
}

/** classification_id values already in use, grouped by their 3-digit category prefix. */
function getUsedClassificationIdsByCategory(PDO $pdo): array
{
    $used = [];
    $stmt = $pdo->query('SELECT classification_id FROM species WHERE classification_id IS NOT NULL');
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $cid) {
        $used[substr($cid, 0, 3)][] = $cid;
    }
    return $used;
}

function getSpeciesById(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM species WHERE id = :id');
    $stmt->execute(['id' => $id]);
    return $stmt->fetch() ?: null;
}

/**
 * Subtype ids this species is additionally tagged with, beyond its
 * required primary subtype_id — see species_subtypes in docs/install.sql.
 */
function getExtraSubtypeIdsForSpecies(PDO $pdo, int $speciesId): array
{
    $stmt = $pdo->prepare('SELECT subtype_id FROM species_subtypes WHERE species_id = :sid');
    $stmt->execute(['sid' => $speciesId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Replaces this species' extra-subtype tags wholesale (delete then
 * re-insert) — simpler than diffing, and this list is short enough (a
 * handful of checkboxes) that it's never a real cost. The primary
 * subtype_id is excluded even if it was checked, since it's already
 * implied and storing it twice would just be redundant.
 */
function setExtraSubtypesForSpecies(PDO $pdo, int $speciesId, array $subtypeIds, int $primarySubtypeId): void
{
    $pdo->prepare('DELETE FROM species_subtypes WHERE species_id = :sid')->execute(['sid' => $speciesId]);
    $insert = $pdo->prepare('INSERT INTO species_subtypes (species_id, subtype_id) VALUES (:sid, :tid)');
    foreach (array_unique(array_map('intval', $subtypeIds)) as $subtypeId) {
        if ($subtypeId > 0 && $subtypeId !== $primarySubtypeId) {
            $insert->execute(['sid' => $speciesId, 'tid' => $subtypeId]);
        }
    }
}

/**
 * A tree (individual plant asset) joined with its species and zone. Species
 * fields (name/description/care_instructions/characteristics/properties/
 * benefits/cautions/part_uses, each TH-default+EN+ZH, plus classification_id)
 * are pulled in under their own unprefixed names — trees no longer carries
 * those columns itself — so existing callers like localizedTreeField($tree,
 * 'name') keep working unchanged against the merged row. Zone fields are
 * exposed as zone_name/zone_name_en/zone_name_zh to avoid colliding with the
 * species name fields.
 */
function getTreeById(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT t.*,
                s.category_code, s.classification_id, s.name, s.name_en, s.name_zh, s.name_common, s.name_scientific,
                s.description, s.description_en, s.description_zh,
                s.care_instructions, s.care_instructions_en, s.care_instructions_zh,
                s.characteristics, s.characteristics_en, s.characteristics_zh,
                s.properties, s.properties_en, s.properties_zh,
                s.benefits, s.benefits_en, s.benefits_zh,
                s.cautions, s.cautions_en, s.cautions_zh,
                s.part_uses, s.part_uses_en, s.part_uses_zh,
                z.zone_code, z.name AS zone_name, z.name_en AS zone_name_en, z.name_zh AS zone_name_zh
         FROM trees t
         JOIN species s ON s.id = t.species_id
         JOIN zones z ON z.id = t.zone_id
         WHERE t.id = :id AND t.is_active = 1'
    );
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function getPrevTree(PDO $pdo, int $displayOrder): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, display_order FROM trees
         WHERE is_active = 1 AND display_order < :order
         ORDER BY display_order DESC LIMIT 1'
    );
    $stmt->execute(['order' => $displayOrder]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function getNextTree(PDO $pdo, int $displayOrder): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, display_order FROM trees
         WHERE is_active = 1 AND display_order > :order
         ORDER BY display_order ASC LIMIT 1'
    );
    $stmt->execute(['order' => $displayOrder]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Builds the 15-digit plant code: ประเภทพืช(3) + รหัสชนิดพืช(3) + โซน(3) +
 * พื้นที่ในสวน(2) + ลำดับ(4). Unlike assetCode()/trees.id, this code is
 * intentionally NOT stable across a move — see recomputeTreePlantCode().
 */
function computePlantCode(string $categoryCode, string $speciesCode, string $zoneNumber, string $areaCode, int $sequence): string
{
    return $categoryCode . $speciesCode . $zoneNumber . $areaCode . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
}

/**
 * Recomputes a tree's plant_code from its current species/zone/area_code.
 * The first 11 characters (category+species+zone+area) encode "where and
 * what this plant is right now"; only the trailing 4-digit sequence is
 * assigned once per combination. If those 11 characters haven't changed
 * since the last save, the code (and its sequence) is left untouched. If
 * they HAVE changed (edited fields, or species/zone reassigned), a fresh
 * sequence is assigned within the new combination and plant_code_updated_at
 * is stamped — the admin UI uses that to prompt "reprint the QR/tag".
 * Returns true if the code actually changed.
 */
function recomputeTreePlantCode(PDO $pdo, int $treeId): bool
{
    $stmt = $pdo->prepare(
        'SELECT t.plant_code, t.area_code, s.category_code, s.species_code, z.zone_number
         FROM trees t
         JOIN species s ON s.id = t.species_id
         JOIN zones z ON z.id = t.zone_id
         WHERE t.id = :id'
    );
    $stmt->execute(['id' => $treeId]);
    $row = $stmt->fetch();
    if (!$row) {
        return false;
    }

    $base = $row['category_code'] . $row['species_code'] . $row['zone_number'] . $row['area_code'];
    $currentBase = $row['plant_code'] !== null ? substr($row['plant_code'], 0, 11) : null;
    if ($currentBase === $base) {
        return false;
    }

    $seqStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM trees t2
         JOIN species s2 ON s2.id = t2.species_id
         JOIN zones z2 ON z2.id = t2.zone_id
         WHERE t2.id != :id AND s2.category_code = :cc AND s2.species_code = :sc
           AND z2.zone_number = :zn AND t2.area_code = :ac'
    );
    $seqStmt->execute([
        'id' => $treeId, 'cc' => $row['category_code'], 'sc' => $row['species_code'],
        'zn' => $row['zone_number'], 'ac' => $row['area_code'],
    ]);
    $sequence = (int) $seqStmt->fetchColumn() + 1;
    $plantCode = computePlantCode($row['category_code'], $row['species_code'], $row['zone_number'], $row['area_code'], $sequence);

    $pdo->prepare('UPDATE trees SET plant_code = :pc, plant_code_updated_at = NOW() WHERE id = :id')
        ->execute(['pc' => $plantCode, 'id' => $treeId]);

    return true;
}

/**
 * The stable, human-readable "Tree ID" shown to staff (proposal §4), derived
 * from the plant asset's own database id — it never changes when the plant's
 * species/zone/position changes, only the underlying row's zone_id/species_id
 * do. Prefix is admin-configurable via the `asset_code_prefix` setting.
 */
function assetCode(PDO $pdo, int $treeId): string
{
    $prefix = getSetting($pdo, 'asset_code_prefix', 'NN-UD');
    return $prefix . '-' . str_pad((string) $treeId, 6, '0', STR_PAD_LEFT);
}

function getSetting(PDO $pdo, string $key, ?string $default = null): ?string
{
    $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = :k');
    $stmt->execute(['k' => $key]);
    $value = $stmt->fetchColumn();
    return $value !== false ? $value : $default;
}

function setSetting(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO settings (setting_key, setting_value) VALUES (:k, :v)
         ON DUPLICATE KEY UPDATE setting_value = :v2'
    );
    $stmt->execute(['k' => $key, 'v' => $value, 'v2' => $value]);
}

function isValidEmail(string $email): bool
{
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
}

function publicDir(): string
{
    return realpath(__DIR__ . '/../public');
}

/**
 * The URL path (no scheme/host) that public/ is actually served under, e.g.
 * "/pro%20tree/public". Pages reached through a rewritten route like
 * /tree/{id} sit one path segment "deeper" than public/'s real location, so
 * plain relative hrefs (assets/css/style.css, tree.php?id=..., etc.) resolve
 * to the wrong place there. Prefix such links with this instead.
 */
function appBasePath(): string
{
    return rtrim((string) parse_url(APP_BASE_URL, PHP_URL_PATH), '/');
}

/**
 * Validates and moves an uploaded image into public/assets/uploads/{subdir}/,
 * using a random filename (never the client-supplied one). Returns the path
 * relative to public/ (e.g. "assets/uploads/tree/64f...b2.jpg"), or null if
 * no file was submitted for this field. Throws on an invalid/oversized file.
 */
function saveUploadedImage(array $file, string $subdir): ?string
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('อัปโหลดล้มเหลว (รหัสข้อผิดพลาด ' . $file['error'] . ')');
    }

    $maxBytes = 5 * 1024 * 1024;
    if ($file['size'] > $maxBytes) {
        throw new RuntimeException('ไฟล์รูปภาพมีขนาดใหญ่เกินไป (สูงสุด 5 MB)');
    }

    $imageInfo = @getimagesize($file['tmp_name']);
    if ($imageInfo === false) {
        throw new RuntimeException('ไฟล์นี้ไม่ใช่รูปภาพที่ถูกต้อง');
    }

    $allowedTypes = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_GIF => 'gif',
        IMAGETYPE_WEBP => 'webp',
    ];
    $ext = $allowedTypes[$imageInfo[2]] ?? null;
    if ($ext === null) {
        throw new RuntimeException('ไม่รองรับชนิดไฟล์นี้ กรุณาใช้ JPG, PNG, GIF หรือ WEBP');
    }

    $destDir = publicDir() . '/assets/uploads/' . $subdir;
    if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
        throw new RuntimeException('ไม่สามารถสร้างโฟลเดอร์สำหรับอัปโหลดได้');
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $ext;
    $destPath = $destDir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        throw new RuntimeException('ไม่สามารถบันทึกไฟล์ที่อัปโหลดได้');
    }

    return "assets/uploads/$subdir/$filename";
}

/**
 * Deletes a previously-stored public/ file given its relative path (as
 * returned by saveUploadedImage/generateTreeQrCode). Resolves symlinks and
 * confirms the target is actually inside public/ before unlinking, so a
 * corrupted/tampered DB value can never delete files outside it.
 */
function deletePublicFile(?string $relativePath): void
{
    if (!$relativePath) {
        return;
    }
    $base = publicDir();
    $full = realpath($base . '/' . $relativePath);
    if ($full !== false && str_starts_with($full, $base . DIRECTORY_SEPARATOR) && is_file($full)) {
        unlink($full);
    }
}

/**
 * Builds a human-readable filename for a tree's downloaded QR PNG, e.g.
 * "ต้นราชพฤกษ์-NN-UD-000001-qr.png" instead of the generic internal
 * storage name (tree-{id}.png) — strips characters that are invalid in
 * Windows/macOS/Linux filenames and collapses whitespace.
 */
function qrDownloadFilename(string $label, string $assetCode): string
{
    $base = trim($label) !== '' ? trim($label) . '-' . $assetCode : $assetCode;
    $safe = preg_replace('/[\\/\\\\:*?"<>|\\x00-\\x1F]/', '', $base);
    $safe = trim(preg_replace('/\s+/', ' ', $safe));
    return ($safe !== '' ? $safe : $assetCode) . '-qr.png';
}

/**
 * Generates (or regenerates) the QR code PNG for a tree's public page and
 * saves it to public/assets/uploads/qr/tree-{id}.png. Returns the path
 * relative to public/. Idempotent — safe to call on every save.
 */
function generateTreeQrCode(int $treeId): string
{
    $url = rtrim(APP_BASE_URL, '/') . '/tree/' . $treeId;

    $destDir = publicDir() . '/assets/uploads/qr';
    if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
        throw new RuntimeException('ไม่สามารถสร้างโฟลเดอร์สำหรับ QR code ได้');
    }

    $relativePath = "assets/uploads/qr/tree-$treeId.png";
    $destPath = $destDir . "/tree-$treeId.png";

    // The vendored library emits harmless PHP 8 "implicit required param" deprecation
    // notices (it's 2010-era code) at the moment its classes are declared, i.e. as soon
    // as it's require_once'd — so the suppression has to wrap the include itself, not
    // just the QRcode::png() call.
    $previousLevel = error_reporting(E_ALL & ~E_DEPRECATED);
    try {
        require_once __DIR__ . '/vendor/phpqrcode/phpqrcode.php';
        QRcode::png($url, $destPath, QR_ECLEVEL_M, 8, 4);
    } finally {
        error_reporting($previousLevel);
    }

    return $relativePath;
}

/**
 * Generates a full logical SQL backup of every table (structure + data) —
 * "มีฐานข้อมูลสำรอง" (proposal §9). Written against PDO directly (not
 * shelling out to mysqldump) so it works regardless of whether the mysql
 * client tools are on the web server's PATH. Streamed to the admin as a
 * downloadable .sql file by admin/backup.php.
 */
function generateDatabaseBackupSql(PDO $pdo): string
{
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

    $out = "-- tree_qr_system backup — generated " . date('Y-m-d H:i:s') . "\n";
    $out .= "-- Restore with: mysql -u root -p tree_qr_system < this_file.sql\n\n";
    $out .= "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";

    foreach ($tables as $table) {
        $createRow = $pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch();
        $out .= "DROP TABLE IF EXISTS `$table`;\n" . $createRow['Create Table'] . ";\n\n";

        $rows = $pdo->query('SELECT * FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $columns = array_keys($row);
            $values = array_map(
                fn($v) => $v === null ? 'NULL' : $pdo->quote((string) $v),
                array_values($row)
            );
            $out .= "INSERT INTO `$table` (`" . implode('`, `', $columns) . '`) VALUES ('
                . implode(', ', $values) . ");\n";
        }
        if ($rows) {
            $out .= "\n";
        }
    }

    $out .= "SET FOREIGN_KEY_CHECKS=1;\n";
    return $out;
}
