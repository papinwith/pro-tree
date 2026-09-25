<?php
require_once __DIR__ . '/../includes/auth.php';
requirePermission('species.manage');

$pdo = db();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$species = $id ? getSpeciesById($pdo, $id) : null;
if ($id && !$species) {
    http_response_code(404);
    exit('ไม่พบชนิดพันธุ์นี้');
}

$errors = [];
$categories = getAllCategories($pdo);
$subtypes = getAllSubtypes($pdo);
$zones = getAllZones($pdo);
$stockRows = $id ? getStockForSpecies($pdo, $id) : [];
$stockSizes = getAllStockSizes($pdo);
$salesRows = $id ? getSalesForSpecies($pdo, $id) : [];
$extraSubtypeIds = $id ? getExtraSubtypeIdsForSpecies($pdo, $id) : [];
// "โซน"/"จำนวนต้น" below are an add-more-trees action, not stored fields
// of the species itself — they always start blank on a fresh page load
// (see the field-hint next to them). This is the actual existing count,
// shown read-only for context, grouped by zone since a species can already
// have trees spread across more than one.
$existingTreesByZone = [];
if ($id) {
    $stmt = $pdo->prepare(
        'SELECT z.name AS zone_name, COUNT(*) AS tree_count
         FROM trees t JOIN zones z ON z.id = t.zone_id
         WHERE t.species_id = :sid
         GROUP BY z.id, z.name
         ORDER BY z.name ASC'
    );
    $stmt->execute(['sid' => $id]);
    $existingTreesByZone = $stmt->fetchAll();
}
$saleStatusLabels = ['available' => 'พร้อมขาย', 'reserved' => 'จองแล้ว', 'sold_out' => 'ขายหมด', 'not_for_sale' => 'ไม่ขาย'];
$treeStatuses = ['healthy' => 'สมบูรณ์', 'needs_attention' => 'ต้องดูแล', 'removed' => 'นำออกแล้ว'];

// Thai-only fields. English/Chinese are never entered here — they're
// generated automatically the first time a visitor views a tree page in
// that language (see includes/translation.php, called from public/tree.php)
// and cached on these same columns, so this form must never overwrite them.
$detailSections = [
    'care_instructions' => 'วิธีดูแล',
    'characteristics' => 'ลักษณะ',
    'properties' => 'คุณสมบัติ',
    'benefits' => 'ประโยชน์',
    'cautions' => 'ข้อควรระวัง (ผลเสีย / ผู้ที่ควรหลีกเลี่ยง)',
    'part_uses' => 'การใช้ประโยชน์แต่ละส่วน (เช่น ดอก: ..., ผล: ..., ลำต้น: ...)',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    // Category is its own required choice (subtype_form.php lets a subtype
    // exist without a category yet — "เพิ่มชนิด" only asks for a name — so
    // it can't always be relied on to supply the category here).
    $categoryCode = trim($_POST['category_code'] ?? '');
    // The "ชนิด" list is a multi-select — a species that's genuinely both
    // e.g. ไม้ผล and ไม้ดอก picks more than one option directly in it,
    // Ctrl/Cmd-click. Whichever comes first (list order) becomes the
    // required "primary" subtype that plant_code/category assignment still
    // uses; the rest are purely organizational tags (species_subtypes in
    // docs/install.sql).
    $validSubtypeIds = array_column($subtypes, 'id');
    $selectedSubtypeIds = array_values(array_intersect(
        array_unique(array_filter(array_map('intval', $_POST['subtype_id'] ?? []))),
        $validSubtypeIds
    ));
    $subtypeId = $selectedSubtypeIds[0] ?? 0;
    // Only ids that actually exist in `subtypes` reach here (filtered
    // above) — an id for an already-deleted subtype (stale browser state,
    // or a tampered form) is silently dropped instead of hitting the
    // species_subtypes foreign key and surfacing as a misleading "someone
    // else just saved this" conflict error.
    $extraSubtypeIds = array_slice($selectedSubtypeIds, 1);
    $subtype = $subtypeId ? getSubtypeById($pdo, $subtypeId) : null;
    // species_code / classification_id are never admin-entered (see the
    // form below) — a hidden field carries the existing value forward on
    // edit, and is simply absent when creating, which the logic below
    // already treats as "assign automatically" / "not set".
    $speciesCode = trim($_POST['species_code'] ?? '');
    $classificationId = trim($_POST['classification_id'] ?? '') ?: null;
    $name = trim($_POST['name'] ?? '');
    $nameCommon = trim($_POST['name_common'] ?? '') ?: null;
    $nameScientific = trim($_POST['name_scientific'] ?? '') ?: null;
    $description = trim($_POST['description'] ?? '') ?: null;

    $detailValues = [];
    foreach (array_keys($detailSections) as $field) {
        $detailValues[$field] = trim($_POST[$field] ?? '') ?: null;
    }

    // Optional: immediately stock this species into a zone — pick a zone and
    // a quantity, and the system creates that many individual tree rows
    // (each with its own Tree ID / QR / plant_code) instead of the admin
    // having to add them one at a time.
    $plantZoneId = (int) ($_POST['plant_zone_id'] ?? 0);
    $plantQuantity = max(0, (int) ($_POST['plant_quantity'] ?? 0));
    if ($plantZoneId && !getZoneById($pdo, $plantZoneId)) {
        $errors[] = 'กรุณาเลือกโซนให้ถูกต้อง';
    }
    // Status every newly-created tree starts with (same choices as
    // tree_form.php) — only used together with the zone/quantity above.
    $plantStatus = $_POST['plant_status'] ?? 'healthy';
    if (!isset($treeStatuses[$plantStatus])) {
        $errors[] = 'สถานะต้นไม้ไม่ถูกต้อง';
        $plantStatus = 'healthy';
    }
    $willCreateTrees = $plantZoneId && $plantQuantity > 0;
    // A photo picked for the new trees is meaningless (and would be silently
    // dropped) if no trees are being created — tell the admin instead.
    $treeImageChosen = ($_FILES['tree_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    if ($treeImageChosen && !$willCreateTrees) {
        $errors[] = 'เลือกรูปต้นไม้แล้ว แต่ยังไม่ได้เลือกโซนและจำนวนต้นที่จะเพิ่ม — เลือกโซนและจำนวนต้น หรือเอารูปนี้ออก';
    }

    // Optional starting stock row, create-only (an existing species manages
    // its stock in the section further down the page).
    $stockSizeId = $id ? 0 : validateStockSizeId($pdo, (int) ($_POST['stock_size_id'] ?? 0));
    $stockQuantity = $id ? 0 : max(0, (int) ($_POST['stock_quantity'] ?? 0));
    $stockPriceRaw = $id ? '' : trim($_POST['stock_price'] ?? '');
    $stockPrice = null;
    if ($stockPriceRaw !== '') {
        if (!is_numeric($stockPriceRaw) || (float) $stockPriceRaw < 0) {
            $errors[] = 'ราคาสต็อกต้องเป็นตัวเลขตั้งแต่ 0 ขึ้นไป';
        } else {
            $stockPrice = (float) $stockPriceRaw;
        }
    }
    $stockStatus = $_POST['stock_status'] ?? 'not_for_sale';
    if (!isset($saleStatusLabels[$stockStatus])) {
        $stockStatus = 'not_for_sale';
    }
    $stockChannel = $id ? null : (trim($_POST['stock_channel'] ?? '') ?: null);
    $addStock = !$id && ($stockSizeId || $stockQuantity > 0 || $stockPrice !== null || $stockChannel !== null);

    if ($name === '') {
        $errors[] = 'กรุณาระบุชื่อ';
    }
    if (!getCategoryByCode($pdo, $categoryCode)) {
        $errors[] = 'กรุณาเลือกประเภทพืชให้ถูกต้อง';
    }
    if (!$subtype) {
        $errors[] = 'กรุณาเลือกชนิดให้ถูกต้อง';
    } elseif ($subtype['category_code'] !== null && $subtype['category_code'] !== $categoryCode) {
        // Shouldn't happen via the UI (the subtype dropdown is filtered to
        // the chosen category, plus any not-yet-assigned ones) — only
        // reachable by tampering with the form. Fail safe.
        $errors[] = 'ชนิดที่เลือกไม่ตรงกับประเภทพืชที่เลือก';
    }
    // Species code: assigned automatically when blank (always true for a
    // new species, since the admin is never shown this field at all).
    if ($speciesCode === '') {
        if ($categoryCode !== '' && getCategoryByCode($pdo, $categoryCode)) {
            $speciesCode = nextSpeciesCode($pdo, $categoryCode);
        }
    } elseif ($categoryCode !== '') {
        $dupCodeStmt = $pdo->prepare('SELECT name FROM species WHERE category_code = :cc AND species_code = :sc AND id != :id');
        $dupCodeStmt->execute(['cc' => $categoryCode, 'sc' => $speciesCode, 'id' => $id]);
        $codeConflict = $dupCodeStmt->fetchColumn();
        if ($codeConflict !== false) {
            $errors[] = "รหัสชนิดพืช \"$speciesCode\" ในประเภทนี้ถูกใช้โดย \"$codeConflict\" แล้ว";
        }
    }
    if ($classificationId !== null && !isValidClassificationIdFormat($classificationId)) {
        // Can only happen if someone tampers with the hidden field directly —
        // the admin never types this. Fail safe by dropping it rather than
        // blocking the whole save over a field they can't even see.
        $classificationId = null;
    }

    // Uploaded photos: the species photo keeps whatever it already had unless
    // a new one is chosen (or "remove" is ticked); the tree photo is applied
    // to every tree created below. Files written here must be deleted again
    // if the save doesn't go through, or they'd be orphaned on disk.
    $imagePath = $species['image_path'] ?? null;
    $oldImagePath = $imagePath;
    $newImagePath = null;
    $newTreeImagePath = null;
    if (!$errors) {
        try {
            $newImagePath = saveUploadedImage($_FILES['image'] ?? [], 'species');
            $newTreeImagePath = saveUploadedImage($_FILES['tree_image'] ?? [], 'tree');
        } catch (RuntimeException $e) {
            deletePublicFile($newImagePath);
            $newImagePath = null;
            $errors[] = $e->getMessage();
        }
    }
    if ($newImagePath !== null) {
        $imagePath = $newImagePath;
    } elseif ($id && isset($_POST['remove_image'])) {
        $imagePath = null;
    }

    if (!$errors) {
        $params = [
            'category_code' => $categoryCode, 'subtype_id' => $subtypeId, 'species_code' => $speciesCode,
            'classification_id' => $classificationId,
            'name' => $name, 'name_common' => $nameCommon, 'name_scientific' => $nameScientific,
            'image_path' => $imagePath,
            'description' => $description,
        ] + $detailValues;
        $columns = array_keys($params);
        $createdQrPaths = [];
        // One transaction for the whole save: species row, subtype links,
        // plant-code recompute, new trees and starting stock either all land
        // or none do — a failure partway (e.g. QR generation) used to leave a
        // half-created species and trees with no QR behind.
        $pdo->beginTransaction();
        try {
            if ($id) {
                $setSql = implode(', ', array_map(fn($c) => "$c=:$c", $columns));
                $stmt = $pdo->prepare("UPDATE species SET $setSql WHERE id=:id");
                $stmt->execute($params + ['id' => $id]);
                $speciesId = $id;
            } else {
                $colSql = implode(', ', $columns);
                $placeholderSql = implode(', ', array_map(fn($c) => ":$c", $columns));
                $stmt = $pdo->prepare("INSERT INTO species ($colSql) VALUES ($placeholderSql)");
                $stmt->execute($params);
                $speciesId = (int) $pdo->lastInsertId();
            }

            setExtraSubtypesForSpecies($pdo, $speciesId, $extraSubtypeIds, $subtypeId);

            // First time this subtype is actually used for a species — link
            // it to the chosen category permanently (subtype_form.php's
            // "เพิ่มชนิด" never asks for one, so this is how most subtypes
            // end up assigned).
            if ($subtype['category_code'] === null) {
                $pdo->prepare('UPDATE subtypes SET category_code = :cc WHERE id = :id')
                    ->execute(['cc' => $categoryCode, 'id' => $subtype['id']]);
            }

            // category_code/species_code feed directly into every one of this
            // species' trees' plant_code — recompute them all now.
            $affectedTreeIds = $pdo->prepare('SELECT id FROM trees WHERE species_id = :sid');
            $affectedTreeIds->execute(['sid' => $speciesId]);
            foreach ($affectedTreeIds->fetchAll(PDO::FETCH_COLUMN) as $affectedTreeId) {
                recomputeTreePlantCode($pdo, (int) $affectedTreeId);
            }

            // Bulk-create individual tree rows for this species, if requested.
            if ($willCreateTrees) {
                $maxOrder = (int) $pdo->query('SELECT COALESCE(MAX(display_order), 0) FROM trees')->fetchColumn();
                $insertTree = $pdo->prepare(
                    'INSERT INTO trees (species_id, zone_id, area_code, status, image_path, display_order, is_active)
                     VALUES (:sp, :z, :a, :st, :img, :o, 1)'
                );
                for ($i = 1; $i <= $plantQuantity; $i++) {
                    $insertTree->execute([
                        'sp' => $speciesId, 'z' => $plantZoneId, 'a' => '01', 'st' => $plantStatus,
                        'img' => $newTreeImagePath, 'o' => $maxOrder + $i,
                    ]);
                    $newTreeId = (int) $pdo->lastInsertId();
                    $qrPath = generateTreeQrCode($newTreeId);
                    $createdQrPaths[] = $qrPath;
                    $pdo->prepare('UPDATE trees SET qr_code_path = :qr WHERE id = :id')->execute(['qr' => $qrPath, 'id' => $newTreeId]);
                    recomputeTreePlantCode($pdo, $newTreeId);
                }
            }

            // Starting stock row, if any stock field was filled in.
            if ($addStock) {
                $pdo->prepare(
                    'INSERT INTO nursery_stock (species_id, size_id, quantity, price, sale_status, sales_channel)
                     VALUES (:sid, :size, :qty, :price, :status, :channel)'
                )->execute([
                    'sid' => $speciesId, 'size' => $stockSizeId ?: null, 'qty' => $stockQuantity,
                    'price' => $stockPrice, 'status' => $stockStatus, 'channel' => $stockChannel,
                ]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            deletePublicFile($newImagePath);
            deletePublicFile($newTreeImagePath);
            foreach ($createdQrPaths as $orphanQr) {
                deletePublicFile($orphanQr);
            }
            if ($e instanceof PDOException && $e->getCode() === '23000') {
                $errors[] = 'ข้อมูลนี้เพิ่งถูกใช้โดยการบันทึกอื่น กรุณาลองใหม่อีกครั้ง';
                $imagePath = $oldImagePath;
            } else {
                throw $e;
            }
        }

        if (!$errors) {
            // Saved — now it's safe to drop the photo this one replaced/removed.
            if ($oldImagePath && $oldImagePath !== $imagePath) {
                deletePublicFile($oldImagePath);
            }
            header('Location: species.php');
            exit;
        }
    } else {
        // Validation failed after a file was already stored — don't leave it behind.
        deletePublicFile($newImagePath);
        deletePublicFile($newTreeImagePath);
        $imagePath = $oldImagePath;
    }

    $species = array_merge($species ?? [], [
        'category_code' => $categoryCode, 'subtype_id' => $subtypeId, 'species_code' => $speciesCode,
        'classification_id' => $classificationId,
        'name' => $name, 'name_common' => $nameCommon, 'name_scientific' => $nameScientific,
        'description' => $description, 'image_path' => $imagePath,
        'plant_zone_id' => $plantZoneId, 'plant_quantity' => $plantQuantity, 'plant_status' => $plantStatus,
        'stock_size_id' => $stockSizeId, 'stock_quantity' => $stockQuantity, 'stock_price' => $stockPriceRaw,
        'stock_status' => $stockStatus, 'stock_channel' => $stockChannel,
    ] + $detailValues);
}

$v = fn($key, $default = '') => e((string) ($species[$key] ?? $default));
$currentCode = $species['species_code'] ?? '';
$currentClassificationId = $species['classification_id'] ?? '';
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $id ? 'แก้ไข' : 'เพิ่ม' ?>ชนิดพันธุ์</title>
<link rel="stylesheet" href="../public/assets/css/style.css">
</head>
<body>
<div class="admin-wrap admin-wrap-narrow">
  <p><a class="btn-outline btn-sm" href="species.php">&larr; กลับไปหน้าชนิดพันธุ์</a></p>
  <h1><?= $id ? 'แก้ไขชนิดพันธุ์' : 'เพิ่มชนิดพันธุ์' ?></h1>
  <p class="field-hint">กรอกเป็นภาษาไทยอย่างเดียว — ระบบจะแปลเป็นอังกฤษ/จีนให้อัตโนมัติตอนผู้เข้าชมเปิดหน้าต้นไม้ด้วยภาษานั้น<?= AI_ENABLED ? '' : ' (ต้องตั้งค่า Gemini API key ก่อน — ดูที่หน้า <a href="settings.php#ai-translation">ตั้งค่า</a>)' ?></p>

  <?php foreach ($errors as $err): ?>
    <div class="flash error"><?= e($err) ?></div>
  <?php endforeach; ?>

  <?php if (!$subtypes): ?>
    <div class="flash error">ยังไม่มีชนิดในระบบ กรุณา<a href="subtype_form.php">เพิ่มชนิด</a>ก่อน</div>
  <?php else: ?>

  <form method="post" enctype="multipart/form-data">
    <?= csrfField() ?>
    <label for="category_code">ประเภทพืช</label>
    <select id="category_code" name="category_code" required onchange="filterSubtypesByCategory(this.value)">
      <option value="">— เลือกประเภทพืช —</option>
      <?php foreach ($categories as $cat): ?>
        <option value="<?= e($cat['code']) ?>" <?= ($species['category_code'] ?? '') === $cat['code'] ? 'selected' : '' ?>>
          <?= e($cat['name_th']) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <?php
      $subtypesById = array_column($subtypes, null, 'id');
      $selectedSubtypeIds = array_values(array_unique(array_filter(array_merge([(int) ($species['subtype_id'] ?? 0)], $extraSubtypeIds))));
    ?>
    <label for="subtype_picker">ชนิด (เลือกทีละรายการ — เลือกได้มากกว่า 1 ถ้าต้นนี้จัดอยู่ได้หลายชนิด รายการแรกที่เลือกจะเป็นชนิดหลัก)</label>
    <select id="subtype_picker">
      <option value="">— เลือกชนิดเพื่อเพิ่ม —</option>
      <?php foreach ($subtypes as $st): ?>
        <option value="<?= (int) $st['id'] ?>" data-category="<?= e($st['category_code'] ?? '') ?>" data-name="<?= e($st['name_th']) ?>">
          <?= e($st['name_th']) ?><?= $st['category_code'] === null ? ' (ยังไม่กำหนดประเภท)' : '' ?>
        </option>
      <?php endforeach; ?>
    </select>
    <div id="subtype_selected_box" class="chip-box"></div>
    <div id="subtype_hidden_inputs">
      <?php foreach ($selectedSubtypeIds as $sid): if (isset($subtypesById[$sid])): ?>
        <input type="hidden" name="subtype_id[]" value="<?= (int) $sid ?>">
      <?php endif; endforeach; ?>
    </div>
    <p class="field-hint">โครงสร้าง: ประเภท &rarr; ชนิด &rarr; ชื่อต้นไม้ — จัดการรายการได้ที่ <a href="categories.php">ประเภทพืช</a> และ <a href="subtypes.php">ชนิด</a> เลือกชนิดที่ "ยังไม่กำหนดประเภท" ได้เลย ระบบจะผูกเข้ากับประเภทที่เลือกไว้ด้านบนให้อัตโนมัติตอนบันทึก</p>

    <?php // รหัสชนิดพืช / รหัสจำแนกพันธุ์ — รหัสภายในของระบบ ไม่ต้องให้ Admin เห็นหรือกรอกเอง
          // ระบบสร้าง/คงค่าเดิมให้อัตโนมัติผ่านฟิลด์ที่ซ่อนไว้นี้ ?>
    <?php if ($currentCode !== ''): ?>
      <input type="hidden" name="species_code" value="<?= e($currentCode) ?>">
    <?php endif; ?>
    <?php if ($currentClassificationId !== ''): ?>
      <input type="hidden" name="classification_id" value="<?= e($currentClassificationId) ?>">
    <?php endif; ?>

    <label for="name">ชื่อ (ไทย)</label>
    <input type="text" id="name" name="name" value="<?= $v('name') ?>" required>

    <label for="name_common">ชื่อสามัญ</label>
    <input type="text" id="name_common" name="name_common" value="<?= $v('name_common') ?>">

    <label for="name_scientific">ชื่อวิทยาศาสตร์</label>
    <input type="text" id="name_scientific" name="name_scientific" value="<?= $v('name_scientific') ?>" placeholder="เช่น Cassia fistula">

    <label>รูปภาพชนิดพันธุ์</label>
    <?php if (!empty($species['image_path'])): ?>
      <img src="../public/<?= e($species['image_path']) ?>" alt="" class="preview-thumb" id="image-preview">
    <?php endif; ?>
    <div class="field-row" data-photo-capture-for="image">
      <button type="button" class="btn-outline btn-sm" data-photo-action="camera">📷 ถ่ายรูป</button>
      <button type="button" class="btn-outline btn-sm" data-photo-action="gallery">🖼️ เลือกจากคลังภาพ</button>
    </div>
    <input type="file" id="image" name="image" accept="image/jpeg,image/png,image/gif,image/webp" data-preview-target="image-preview" hidden>
    <div data-ai-identify-for="image" data-endpoint="identify_tree.php" data-apply-label="ใช้ชื่อนี้กรอกลงฟอร์ม"<?= AI_ENABLED ? '' : ' data-ai-unavailable="1"' ?>>
      <button type="button" class="btn-outline btn-sm" data-ai-action="identify" disabled>🔍 ให้ AI ช่วยระบุชนิดต้นไม้จากรูปนี้</button>
      <?php if (!AI_ENABLED): ?>
        <p class="field-hint">ต้องตั้งค่า Gemini API key ก่อนจึงจะใช้ได้ — ดูที่หน้า <a href="settings.php#ai-translation">ตั้งค่า</a></p>
      <?php else: ?>
        <p class="field-hint">ไม่รู้ว่าเป็นต้นอะไร? ถ่ายรูปหรือเลือกรูป แล้วให้ AI เดาชื่อให้ (ตรวจสอบก่อนบันทึกเสมอ)</p>
      <?php endif; ?>
      <div data-ai-output aria-live="polite"></div>
    </div>
    <p class="field-hint">
      JPG / PNG / GIF / WEBP ขนาดไม่เกิน 5 MB — แสดงในรายการชนิดพันธุ์ และเป็นรูปสำรองบนหน้าต้นไม้ของผู้เข้าชม
      สำหรับต้นที่ยังไม่มีรูปของตัวเอง
    </p>
    <?php if ($id && !empty($species['image_path'])): ?>
      <label>
        <input type="checkbox" name="remove_image"> ลบรูปภาพชนิดพันธุ์นี้ (ถ้าเลือกรูปใหม่ด้านบน จะถูกแทนที่ด้วยรูปใหม่)
      </label>
    <?php endif; ?>

    <label for="description">คำอธิบาย (ไทย)</label>
    <textarea id="description" name="description" rows="4"><?= $v('description') ?></textarea>

    <?php foreach ($detailSections as $field => $label): ?>
    <label for="<?= $field ?>"><?= e($label) ?> (ไทย)</label>
    <textarea id="<?= $field ?>" name="<?= $field ?>" rows="3"><?= $v($field) ?></textarea>
    <?php endforeach; ?>

    <div class="history-section">
      <h2>เพิ่มต้นไม้ของชนิดพันธุ์นี้ทันที </h2>
      <?php if ($id): ?>
        <?php if ($existingTreesByZone): ?>
          <p class="field-hint">
            ต้นที่มีอยู่แล้วตอนนี้:
            <?= implode(', ', array_map(
              fn($row) => e($row['zone_name']) . ' (' . (int) $row['tree_count'] . ' ต้น)',
              $existingTreesByZone
            )) ?>
          </p>
        <?php else: ?>
          <p class="field-hint">ยังไม่มีต้นไม้ของชนิดพันธุ์นี้ในระบบ</p>
        <?php endif; ?>
      <?php endif; ?>
      <label for="plant_zone_id">โซน</label>
      <select id="plant_zone_id" name="plant_zone_id">
        <option value="">— ไม่เพิ่มต้นไม้ตอนนี้ —</option>
        <?php foreach ($zones as $z): ?>
          <option value="<?= (int) $z['id'] ?>" <?= (int) ($species['plant_zone_id'] ?? 0) === (int) $z['id'] ? 'selected' : '' ?>>
            <?= e($z['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <label for="plant_quantity">เพิ่มจำนวนต้น</label>
      <input type="number" id="plant_quantity" name="plant_quantity" min="0" value="<?= $v('plant_quantity', '0') ?>">
      <p class="field-hint">
        เลือกโซนแล้วใส่จำนวน ระบบจะสร้างต้นไม้ตามจำนวนนั้นให้อัตโนมัติ (แต่ละต้นมี Tree ID/QR ของตัวเอง) —
        ไม่ต้องเพิ่มทีละต้น แก้ไขเป็นรายต้นได้ภายหลังที่หน้าต้นไม้
      </p>

      <label for="plant_status">สถานะต้นไม้ที่เพิ่ม</label>
      <select id="plant_status" name="plant_status">
        <?php foreach ($treeStatuses as $key => $label): ?>
          <option value="<?= e($key) ?>" <?= ($species['plant_status'] ?? 'healthy') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>

      <label for="tree_image">รูปภาพต้นไม้ที่เพิ่ม (ไม่บังคับ)</label>
      <input type="file" id="tree_image" name="tree_image" accept="image/jpeg,image/png,image/gif,image/webp" data-preview-target="tree-image-preview">
      <p class="field-hint">
        ใช้รูปเดียวกันกับทุกต้นที่เพิ่มครั้งนี้ — ถ้าไม่เลือก ต้นไม้จะแสดงรูปภาพชนิดพันธุ์ด้านบนแทน
        ปักหมุดแผนที่และกรอกพิกัด GPS เป็นรายต้นได้ภายหลังที่หน้าแก้ไขต้นไม้
      </p>
    </div>

    <?php if (!$id): ?>
    <div class="history-section">
      <h2>สต็อกเริ่มต้น (ไม่บังคับ)</h2>
      <p class="field-hint">ใส่ข้อมูลตรงนี้ถ้าต้องการบันทึกสต็อกและราคาตั้งแต่ตอนสร้าง — เพิ่มรายการอื่นและบันทึกการขายได้ภายหลังที่หน้าแก้ไขชนิดพันธุ์</p>
      <div class="field-row">
        <label>ขนาด
          <select name="stock_size_id">
            <option value="">— ไม่ระบุ —</option>
            <?php foreach ($stockSizes as $sizeOption): ?>
              <option value="<?= (int) $sizeOption['id'] ?>" <?= (int) ($species['stock_size_id'] ?? 0) === (int) $sizeOption['id'] ? 'selected' : '' ?>><?= e($sizeOption['name_th']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>จำนวน<input type="number" name="stock_quantity" min="0" value="<?= $v('stock_quantity', '0') ?>"></label>
        <label>ราคา (บาท)<input type="text" name="stock_price" inputmode="decimal" placeholder="เช่น 350" value="<?= $v('stock_price') ?>"></label>
        <label>สถานะ
          <select name="stock_status">
            <?php foreach ($saleStatusLabels as $key => $label): ?>
              <option value="<?= e($key) ?>" <?= ($species['stock_status'] ?? 'not_for_sale') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>ช่องทางขาย<input type="text" name="stock_channel" placeholder="เช่น เรือนเพาะชำ, ออนไลน์" value="<?= $v('stock_channel') ?>"></label>
      </div>
    </div>
    <?php endif; ?>

    <p><button class="btn" type="submit">บันทึก</button></p>
  </form>

  <script>
  // "ชนิด" picker: pick one at a time from the dropdown, it becomes a chip
  // in the box below with an X to remove it (e.g. picked the wrong one).
  // The actual submitted values are the hidden subtype_id[] inputs, rebuilt
  // from `selected` on every change — order of `selected` is add-order, and
  // the first one is what species_form.php's save handler treats as the
  // required "primary" subtype.
  var subtypePicker = (function () {
    var picker = document.getElementById('subtype_picker');
    var box = document.getElementById('subtype_selected_box');
    var hiddenContainer = document.getElementById('subtype_hidden_inputs');
    var metaById = {};
    picker.querySelectorAll('option[value]:not([value=""])').forEach(function (opt) {
      metaById[opt.value] = { name: opt.dataset.name, category: opt.dataset.category || '' };
    });

    // Seed `selected` from the hidden inputs PHP already rendered (existing
    // species being edited), so the chip UI reflects it without duplicating
    // that list server- and client-side.
    var selected = Array.prototype.map.call(
      hiddenContainer.querySelectorAll('input[name="subtype_id[]"]'),
      function (input) {
        var meta = metaById[input.value];
        return { id: input.value, name: meta ? meta.name : input.value };
      }
    );

    function render() {
      box.innerHTML = '';
      hiddenContainer.innerHTML = '';
      if (!selected.length) {
        var empty = document.createElement('span');
        empty.className = 'muted-note';
        empty.textContent = 'ยังไม่ได้เลือกชนิด';
        box.appendChild(empty);
      }
      selected.forEach(function (item, idx) {
        var chip = document.createElement('span');
        chip.className = 'subtype-chip';
        chip.textContent = (idx === 0 ? '★ ' : '') + item.name;

        var removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'subtype-chip-remove';
        removeBtn.textContent = '×';
        removeBtn.setAttribute('aria-label', 'ลบ ' + item.name);
        removeBtn.addEventListener('click', function () {
          selected = selected.filter(function (s) { return s.id !== item.id; });
          render();
        });
        chip.appendChild(removeBtn);
        box.appendChild(chip);

        var hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'subtype_id[]';
        hidden.value = item.id;
        hiddenContainer.appendChild(hidden);
      });
    }

    picker.addEventListener('change', function () {
      var id = picker.value;
      if (!id) return;
      if (!selected.some(function (s) { return s.id === id; })) {
        var meta = metaById[id];
        selected.push({ id: id, name: meta ? meta.name : id });
      }
      picker.value = '';
      render();
    });

    function filterByCategory(categoryCode) {
      picker.querySelectorAll('option[value]:not([value=""])').forEach(function (opt) {
        // A subtype with no category yet (empty data-category) stays
        // pickable under any category — picking it links it to whichever
        // category is chosen here (see species_form.php's save handler).
        opt.hidden = !!categoryCode && !!opt.dataset.category && opt.dataset.category !== categoryCode;
      });
      // Changing category invalidates any already-picked chip tied to a
      // different, specific category — drop those, or the primary subtype
      // could point at a category that no longer matches the one chosen
      // above, tripping species_form.php's "mismatch" server check on an
      // entirely ordinary "changed my mind" edit instead of only on actual
      // form tampering.
      var before = selected.length;
      selected = selected.filter(function (item) {
        var cat = metaById[item.id] ? metaById[item.id].category : '';
        return !categoryCode || !cat || cat === categoryCode;
      });
      if (selected.length !== before) {
        render();
      }
    }

    render();
    return { filterByCategory: filterByCategory };
  })();

  function filterSubtypesByCategory(categoryCode) {
    subtypePicker.filterByCategory(categoryCode);
  }
  filterSubtypesByCategory(document.getElementById('category_code').value);
  </script>
  <?php endif; ?>

  <?php if ($id): ?>
  <section id="stock" class="history-section">
    <h2>สต็อกและช่องทางขาย (Nursery Stock)</h2>
    <?php if ($stockRows): ?>
      <div class="table-scroll">
        <table>
          <thead><tr><th>ขนาด</th><th>จำนวน</th><th>ราคา</th><th>สถานะ</th><th>ช่องทางขาย</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($stockRows as $stock): ?>
            <tr>
              <td><?= e($stock['size_name_th'] ?? '') ?></td>
              <td><?= (int) $stock['quantity'] ?></td>
              <td><?= $stock['price'] !== null ? number_format((float) $stock['price'], 2) : '—' ?></td>
              <td><?= e($saleStatusLabels[$stock['sale_status']] ?? $stock['sale_status']) ?></td>
              <td><?= e($stock['sales_channel'] ?? '') ?></td>
              <td>
                <form class="inline" method="post" action="stock_delete.php" data-confirm="ลบรายการนี้?">
                  <?= csrfField() ?>
                  <input type="hidden" name="id" value="<?= (int) $stock['id'] ?>">
                  <input type="hidden" name="species_id" value="<?= (int) $id ?>">
                  <button class="btn btn-sm btn-danger" type="submit">ลบ</button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <p class="muted-note">ยังไม่มีข้อมูลสต็อก</p>
    <?php endif; ?>

    <form method="post" action="stock_add.php" class="inline-add-form">
      <?= csrfField() ?>
      <input type="hidden" name="species_id" value="<?= (int) $id ?>">
      <div class="field-row">
        <label>ขนาด
          <select name="size_id">
            <option value="">— ไม่ระบุ —</option>
            <?php foreach ($stockSizes as $sizeOption): ?>
              <option value="<?= (int) $sizeOption['id'] ?>"><?= e($sizeOption['name_th']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>จำนวน<input type="number" name="quantity" min="0" value="0" required></label>
        <label>ราคา (บาท)<input type="text" name="price" inputmode="decimal" placeholder="เช่น 350"></label>
        <label>สถานะ
          <select name="sale_status">
            <?php foreach ($saleStatusLabels as $key => $label): ?>
              <option value="<?= e($key) ?>"><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>ช่องทางขาย<input type="text" name="sales_channel" placeholder="เช่น เรือนเพาะชำ, ออนไลน์"></label>
      </div>
      <button class="btn btn-sm" type="submit">+ เพิ่มรายการสต็อก</button>
    </form>
  </section>

  <section id="sales" class="history-section">
    <h2>ประวัติการขาย</h2>
    <?php if (isset($_GET['sale_error'])): ?>
      <div class="flash error">ไม่ได้บันทึกการขาย — กรุณาระบุราคา/หน่วยเป็นตัวเลขมากกว่า 0</div>
    <?php endif; ?>
    <p class="field-hint">บันทึกการขายจริง (แยกจากสต็อก/ราคาตั้งไว้ด้านบน) — บันทึกแล้วจะตัดจำนวนออกจากสต็อกที่ตรงกันให้อัตโนมัติ</p>
    <?php if ($salesRows): ?>
      <div class="table-scroll">
        <table>
          <thead><tr><th>วันที่ขาย</th><th>ขนาด</th><th>จำนวน</th><th>ราคา/หน่วย</th><th>รวม</th><th>ผู้บันทึก</th><th>หมายเหตุ</th></tr></thead>
          <tbody>
            <?php foreach ($salesRows as $sale): ?>
            <tr>
              <td><?= e($sale['sold_at']) ?></td>
              <td><?= e($sale['size_name_th'] ?? '—') ?></td>
              <td><?= (int) $sale['quantity'] ?></td>
              <td><?= number_format((float) $sale['unit_price'], 2) ?></td>
              <td><?= number_format((float) $sale['total_price'], 2) ?></td>
              <td><?= e($sale['sold_by'] ?? '') ?></td>
              <td><?= e($sale['notes'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <p class="muted-note">ยังไม่มีประวัติการขาย</p>
    <?php endif; ?>

    <form method="post" action="sale_add.php" class="inline-add-form">
      <?= csrfField() ?>
      <input type="hidden" name="species_id" value="<?= (int) $id ?>">
      <div class="field-row">
        <label>ขนาด
          <select name="size_id">
            <option value="">— ไม่ระบุ —</option>
            <?php foreach ($stockSizes as $sizeOption): ?>
              <option value="<?= (int) $sizeOption['id'] ?>"><?= e($sizeOption['name_th']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>จำนวนที่ขาย<input type="number" name="quantity" min="1" value="1" required></label>
        <label>ราคา/หน่วย (บาท)<input type="text" name="unit_price" inputmode="decimal" placeholder="เช่น 350" required></label>
      </div>
      <label>หมายเหตุ<input type="text" name="notes" placeholder="เช่น ชื่อลูกค้า, ช่องทางขาย"></label>
      <button class="btn btn-sm" type="submit">+ บันทึกการขาย</button>
    </form>
  </section>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/_confirm_modal.php'; ?>
<script src="../public/assets/js/image-preview.js"></script>
<script src="../public/assets/js/photo-capture-buttons.js"></script>
<script src="../public/assets/js/ai-identify-button.js"></script>
<script>
// "ใช้ชื่อนี้กรอกลงฟอร์ม" from the AI-identify panel: fills the name fields
// (overwriting — the admin just asked for this), but only fills the
// description if it's still empty so typed-in text is never clobbered.
var aiIdentify = document.querySelector('[data-ai-identify-for="image"]'); // absent when the form is hidden (no subtypes yet)
if (aiIdentify) aiIdentify.addEventListener('ai-identify:apply', function (e) {
  var r = e.detail.result;
  function setValue(id, value, onlyIfEmpty) {
    var field = document.getElementById(id);
    if (!field || !value) return;
    if (onlyIfEmpty && field.value.trim() !== '') return;
    field.value = value;
  }
  setValue('name', r.name_th || r.name_common, false);
  setValue('name_common', r.name_common, false);
  setValue('name_scientific', r.name_scientific, false);
  setValue('description', r.description_th, true);
  document.getElementById('name').focus();
});
</script>
</body>
</html>
