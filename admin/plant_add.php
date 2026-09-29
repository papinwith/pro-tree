<?php
// One page, one save: pick an existing species or write up a new one, and
// plant its trees — instead of adding the species on species_form.php first
// and then the trees on tree_form.php. Create-only; editing an existing
// species or tree still happens on those two pages (?id=...).
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
if (!canAny(['tree.create', 'species.manage'])) {
    requirePermission('tree.create'); // denies with the usual message
}
$canCreateTrees = can('tree.create');
$canManageSpecies = can('species.manage');

$pdo = db();
$speciesList = getAllSpecies($pdo);
$categories = getAllCategories($pdo);
$categoriesByCode = array_column($categories, null, 'code');
$subtypes = getAllSubtypes($pdo);
$subtypesById = array_column($subtypes, null, 'id');
$zones = getAllZones($pdo);
require_once __DIR__ . '/../includes/plant_identify.php';
warmIdentifyRequest($canManageSpecies, $canManageSpecies ? $categories : null, $canManageSpecies ? $subtypes : null, $speciesList);
$mapImage = getSetting($pdo, 'default_map_image', '');
$mapImageUrl = $mapImage ? resolveAssetUrl($mapImage, '../public') : '';
$treeStatuses = ['healthy' => 'สมบูรณ์', 'needs_attention' => 'ต้องดูแล', 'removed' => 'นำออกแล้ว'];
// Thai-only, same as species_form.php — English/Chinese are generated later.
$detailSections = [
    'care_instructions' => 'วิธีดูแล',
    'characteristics' => 'ลักษณะ',
    'properties' => 'คุณสมบัติ',
    'benefits' => 'ประโยชน์',
    'cautions' => 'ข้อควรระวัง (ผลเสีย / ผู้ที่ควรหลีกเลี่ยง)',
    'part_uses' => 'การใช้ประโยชน์แต่ละส่วน (เช่น ดอก: ..., ผล: ..., ลำต้น: ...)',
];

$errors = [];
$form = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    // --- Species: an existing one, or a new one written up here ---
    // Without tree.create the page can only add a species, so it is always "new".
    $newSpeciesMode = $canManageSpecies && (($_POST['species_mode'] ?? '') === 'new' || !$speciesList || !$canCreateTrees);
    $speciesId = 0;
    $categoryCode = $name = '';
    $nameCommon = $nameScientific = $description = null;
    $subtypeId = 0;
    $selectedSubtypeIds = [];
    $subtype = null;
    $detailValues = [];
    if ($newSpeciesMode) {
        $categoryCode = trim($_POST['category_code'] ?? '');
        // First chosen subtype is the primary one, the rest are extra tags
        // (same rule as species_form.php).
        $selectedSubtypeIds = array_values(array_intersect(
            array_unique(array_filter(array_map('intval', $_POST['subtype_id'] ?? []))),
            array_map('intval', array_column($subtypes, 'id'))
        ));
        $subtypeId = $selectedSubtypeIds[0] ?? 0;
        $subtype = $subtypeId ? getSubtypeById($pdo, $subtypeId) : null;
        $name = trim($_POST['name'] ?? '');
        $nameCommon = trim($_POST['name_common'] ?? '') ?: null;
        $nameScientific = trim($_POST['name_scientific'] ?? '') ?: null;
        $description = trim($_POST['description'] ?? '') ?: null;
        foreach (array_keys($detailSections) as $field) {
            $detailValues[$field] = trim($_POST[$field] ?? '') ?: null;
        }

        if ($name === '') {
            $errors[] = 'กรุณาระบุชื่อชนิดพันธุ์';
        }
        if (!getCategoryByCode($pdo, $categoryCode)) {
            $errors[] = 'กรุณาเลือกประเภทพืชให้ถูกต้อง';
        }
        if (!$subtype) {
            $errors[] = 'กรุณาเลือกชนิดให้ถูกต้อง';
        } elseif ($subtype['category_code'] !== null && $subtype['category_code'] !== $categoryCode) {
            $errors[] = 'ชนิดที่เลือกไม่ตรงกับประเภทพืชที่เลือก';
        }
    } else {
        $speciesId = (int) ($_POST['species_id'] ?? 0);
        if (!$speciesId || !getSpeciesById($pdo, $speciesId)) {
            $errors[] = 'กรุณาเลือกชนิดพันธุ์ให้ถูกต้อง';
        }
    }

    // --- Trees ---
    $zoneId = (int) ($_POST['zone_id'] ?? 0);
    $quantity = $canCreateTrees ? max(0, min(200, (int) ($_POST['quantity'] ?? 0))) : 0;
    $status = $_POST['status'] ?? 'healthy';
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $latInput = trim($_POST['latitude'] ?? '');
    $lngInput = trim($_POST['longitude'] ?? '');
    $latitude = $latInput !== '' ? (float) $latInput : null;
    $longitude = $lngInput !== '' ? (float) $lngInput : null;
    $pinXInput = trim($_POST['map_pin_x'] ?? '');
    $pinYInput = trim($_POST['map_pin_y'] ?? '');
    $mapPinX = $pinXInput !== '' ? max(0, min(100, (float) $pinXInput)) : null;
    $mapPinY = $pinYInput !== '' ? max(0, min(100, (float) $pinYInput)) : null;

    if ($quantity > 0) {
        if (!$zoneId || !getZoneById($pdo, $zoneId)) {
            $errors[] = 'กรุณาเลือกโซนให้ถูกต้อง';
        }
        if (!isset($treeStatuses[$status])) {
            $errors[] = 'สถานะต้นไม้ไม่ถูกต้อง';
        }
        if ($latInput !== '' && (!is_numeric($latInput) || $latitude < -90 || $latitude > 90)) {
            $errors[] = 'ละติจูดต้องอยู่ระหว่าง -90 ถึง 90';
        }
        if ($lngInput !== '' && (!is_numeric($lngInput) || $longitude < -180 || $longitude > 180)) {
            $errors[] = 'ลองจิจูดต้องอยู่ระหว่าง -180 ถึง 180';
        }
    } elseif (!$newSpeciesMode) {
        // An existing species with no trees would save nothing at all.
        $errors[] = 'กรุณาระบุจำนวนต้นอย่างน้อย 1 ต้น';
    }

    // One photo: for a new species it becomes the species photo (its new trees
    // show it automatically as their fallback); otherwise it is the trees' photo.
    $photoSubdir = $newSpeciesMode ? 'species' : 'tree';
    $photoPath = null;
    if (!$errors) {
        try {
            $photoPath = saveUploadedImage($_FILES['image'] ?? [], $photoSubdir);
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
    }

    if (!$errors) {
        $createdQrPaths = [];
        $treeIds = [];
        // Species row and every tree land together or not at all.
        $pdo->beginTransaction();
        try {
            if ($newSpeciesMode) {
                $params = [
                    'category_code' => $categoryCode, 'subtype_id' => $subtypeId,
                    'species_code' => nextSpeciesCode($pdo, $categoryCode),
                    'name' => $name, 'name_common' => $nameCommon, 'name_scientific' => $nameScientific,
                    'image_path' => $photoPath, 'description' => $description,
                ] + $detailValues;
                $columns = array_keys($params);
                $pdo->prepare('INSERT INTO species (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_map(fn($c) => ":$c", $columns)) . ')')
                    ->execute($params);
                $speciesId = (int) $pdo->lastInsertId();
                setExtraSubtypesForSpecies($pdo, $speciesId, array_slice($selectedSubtypeIds, 1), $subtypeId);
                // First use of a subtype that has no category yet links it for good.
                if ($subtype['category_code'] === null) {
                    $pdo->prepare('UPDATE subtypes SET category_code = :cc WHERE id = :id')
                        ->execute(['cc' => $categoryCode, 'id' => $subtypeId]);
                }
            }

            if ($quantity > 0) {
                $maxOrder = (int) $pdo->query('SELECT COALESCE(MAX(display_order), 0) FROM trees')->fetchColumn();
                $insertTree = $pdo->prepare(
                    'INSERT INTO trees (species_id, zone_id, area_code, status, image_path, latitude, longitude, location_updated_at,
                     map_pin_x, map_pin_y, display_order, is_active)
                     VALUES (:species_id, :zone_id, :area_code, :status, :image_path, :latitude, :longitude, :location_updated_at,
                     :map_pin_x, :map_pin_y, :display_order, :is_active)'
                );
                // plant_code is recomputed right after each insert so later rows
                // in this batch get their own sequence (see tree_form.php).
                for ($i = 1; $i <= $quantity; $i++) {
                    $insertTree->execute([
                        'species_id' => $speciesId, 'zone_id' => $zoneId, 'area_code' => '01', 'status' => $status,
                        'image_path' => $newSpeciesMode ? null : $photoPath,
                        'latitude' => $latitude, 'longitude' => $longitude,
                        'location_updated_at' => ($latitude !== null || $longitude !== null) ? date('Y-m-d H:i:s') : null,
                        'map_pin_x' => $mapPinX, 'map_pin_y' => $mapPinY,
                        'display_order' => $maxOrder + $i, 'is_active' => $isActive,
                    ]);
                    $newTreeId = (int) $pdo->lastInsertId();
                    $treeIds[] = $newTreeId;
                    $qrPath = generateTreeQrCode($newTreeId);
                    $createdQrPaths[] = $qrPath;
                    $pdo->prepare('UPDATE trees SET qr_code_path = :qr WHERE id = :id')->execute(['qr' => $qrPath, 'id' => $newTreeId]);
                    recomputeTreePlantCode($pdo, $newTreeId);
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            deletePublicFile($photoPath);
            foreach ($createdQrPaths as $orphanQr) {
                deletePublicFile($orphanQr);
            }
            if ($e instanceof PDOException && $e->getCode() === '23000') {
                $errors[] = 'ข้อมูลนี้เพิ่งถูกใช้โดยการบันทึกอื่น กรุณาลองใหม่อีกครั้ง';
            } else {
                throw $e;
            }
        }

        if (!$errors) {
            header('Location: ' . ($treeIds ? 'dashboard.php' : 'species.php'));
            exit;
        }
    }

    // Re-show what was entered.
    $form = [
        'species_mode' => $newSpeciesMode ? 'new' : 'existing', 'species_id' => $speciesId,
        'category_code' => $categoryCode, 'subtype_ids' => $selectedSubtypeIds,
        'name' => $name, 'name_common' => $nameCommon, 'name_scientific' => $nameScientific, 'description' => $description,
        'zone_id' => $zoneId, 'quantity' => $quantity, 'status' => $status, 'is_active' => $isActive,
        'latitude' => $latInput, 'longitude' => $lngInput, 'map_pin_x' => $mapPinX, 'map_pin_y' => $mapPinY,
    ] + $detailValues;
}

$v = fn($key, $default = '') => e((string) ($form[$key] ?? $default));
// ?mode=new (the "+ เพิ่มชนิดพันธุ์" button on species.php) starts on "new species".
$speciesMode = $form['species_mode']
    ?? (($canManageSpecies && (!$speciesList || !$canCreateTrees || ($_GET['mode'] ?? '') === 'new')) ? 'new' : 'existing');
// Both choices only make sense when there is something to pick and trees can be planted.
$offerSpeciesChoice = $canManageSpecies && $canCreateTrees && $speciesList;
$selectedSubtypeIds = $form['subtype_ids'] ?? [];
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>เพิ่มต้นไม้ / ชนิดพันธุ์</title>
<link rel="stylesheet" href="../public/assets/css/style.css">
</head>
<body>
<div class="admin-wrap admin-wrap-narrow">
  <p><a class="btn-outline btn-sm" href="<?= $canCreateTrees ? 'dashboard.php' : 'species.php' ?>">&larr; กลับ</a></p>
  <h1>เพิ่มต้นไม้ / ชนิดพันธุ์</h1>
  <p class="field-hint">กรอกทุกอย่างในหน้าเดียวแล้วกดบันทึกครั้งเดียว — แก้ไขรายละเอียดทีหลังได้ที่หน้าต้นไม้หรือหน้าชื่อต้นไม้ตามปกติ</p>

  <?php foreach ($errors as $err): ?>
    <div class="flash error"><?= e($err) ?></div>
  <?php endforeach; ?>

  <?php if (!$speciesList && !$canManageSpecies): ?>
    <div class="flash error">ยังไม่มีชื่อต้นไม้ในระบบ กรุณาติดต่อผู้ดูแลที่มีสิทธิ์เพิ่มชนิดพันธุ์</div>
  <?php elseif ($canCreateTrees && !$zones): ?>
    <div class="flash error">ยังไม่มีโซนในระบบ กรุณา<a href="zone_form.php">เพิ่มโซน</a>ก่อน</div>
  <?php elseif ($canManageSpecies && !$subtypes && !$speciesList): ?>
    <div class="flash error">ยังไม่มีชนิดในระบบ กรุณา<a href="subtype_form.php">เพิ่มชนิด</a>ก่อน</div>
  <?php else: ?>

  <form method="post" enctype="multipart/form-data">
    <?= csrfField() ?>

    <label>รูปภาพ</label>
    <div class="field-row" data-photo-capture-for="image">
      <button type="button" class="btn-outline btn-sm" data-photo-action="camera">📷 ถ่ายรูป</button>
      <button type="button" class="btn-outline btn-sm" data-photo-action="gallery">🖼️ เลือกจากคลังภาพ</button>
    </div>
    <input type="file" id="image" name="image" accept="image/jpeg,image/png,image/gif,image/webp" data-preview-target="image-preview" hidden>
    <div data-ai-identify-for="image" data-endpoint="identify_tree.php"
         <?php if ($canManageSpecies && !$canCreateTrees): ?>
           data-apply-label="ใช้ข้อมูลนี้กรอกลงฟอร์ม" data-detail="full"
         <?php elseif ($canManageSpecies): ?>
           data-apply-label="เลือกชื่อต้นไม้นี้ให้" data-require-match="1" data-detail="full" data-no-match-create-label="ใช้ข้อมูลนี้สร้างชนิดพันธุ์ใหม่"
         <?php else: ?>
           data-apply-label="เลือกชื่อต้นไม้นี้ให้" data-require-match="1"
         <?php endif; ?>
         <?= AI_ENABLED ? '' : ' data-ai-unavailable="1"' ?>>
      <button type="button" class="btn-outline btn-sm" data-ai-action="identify" disabled>🔍 ให้ AI ช่วยระบุชนิดต้นไม้จากรูปนี้</button>
      <div data-ai-output aria-live="polite"></div>
    </div>
    <p class="field-hint">
      ไม่บังคับ — JPG / PNG / GIF / WEBP ไม่เกิน 10 MB ถ้าเพิ่มชนิดพันธุ์ใหม่ รูปนี้จะเป็นรูปของชนิดพันธุ์และต้นที่เพิ่มครั้งนี้จะแสดงรูปนี้ด้วย
      <?= AI_ENABLED ? 'ไม่รู้ว่าเป็นต้นอะไร? ถ่ายรูปแล้วให้ AI ช่วยเลือกหรือกรอกข้อมูลให้' : '' ?>
    </p>

    <div class="history-section">
      <h2>1. ชนิดพันธุ์</h2>
      <?php if ($offerSpeciesChoice): ?>
        <label><input type="radio" name="species_mode" value="existing" <?= $speciesMode === 'existing' ? 'checked' : '' ?> onchange="applySpeciesMode()"> เลือกชื่อต้นไม้ที่มีในระบบแล้ว</label>
        <label><input type="radio" name="species_mode" value="new" <?= $speciesMode === 'new' ? 'checked' : '' ?> onchange="applySpeciesMode()"> เพิ่มชนิดพันธุ์ใหม่</label>
      <?php else: ?>
        <input type="hidden" name="species_mode" value="<?= e($speciesMode) ?>">
      <?php endif; ?>

      <?php if ($speciesList && $canCreateTrees): ?>
      <div id="existing-species-panel">
        <label for="species_search">ค้นหาชื่อต้นไม้</label>
        <input type="search" id="species_search" placeholder="พิมพ์ค้นหาชื่อต้นไม้..." oninput="filterSpecies()">
        <label for="species_id">ชื่อต้นไม้</label>
        <select id="species_id" name="species_id">
          <option value="">— เลือกชื่อต้นไม้ —</option>
          <?php foreach ($speciesList as $sp): ?>
            <option value="<?= (int) $sp['id'] ?>" <?= (int) ($form['species_id'] ?? 0) === (int) $sp['id'] ? 'selected' : '' ?>>
              <?= e($sp['name']) ?><?= $sp['name_scientific'] ? ' (' . e($sp['name_scientific']) . ')' : '' ?>
              — <?= e($categoriesByCode[$sp['category_code']]['name_th'] ?? '') ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>

      <?php if ($canManageSpecies): ?>
      <div id="new-species-panel">
        <label for="category_code">ประเภทพืช</label>
        <select id="category_code" name="category_code" onchange="subtypePicker.filterByCategory(this.value)">
          <option value="">— เลือกประเภทพืช —</option>
          <?php foreach ($categories as $cat): ?>
            <option value="<?= e($cat['code']) ?>" <?= ($form['category_code'] ?? '') === $cat['code'] ? 'selected' : '' ?>><?= e($cat['name_th']) ?></option>
          <?php endforeach; ?>
        </select>

        <label for="subtype_picker">ชนิด (เลือกได้มากกว่า 1 — รายการแรกเป็นชนิดหลัก)</label>
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

        <label for="name">ชื่อ (ไทย)</label>
        <input type="text" id="name" name="name" value="<?= $v('name') ?>">
        <label for="name_common">ชื่อสามัญ</label>
        <input type="text" id="name_common" name="name_common" value="<?= $v('name_common') ?>">
        <label for="name_scientific">ชื่อวิทยาศาสตร์</label>
        <input type="text" id="name_scientific" name="name_scientific" value="<?= $v('name_scientific') ?>" placeholder="เช่น Cassia fistula">
        <label for="description">คำอธิบาย (ไทย)</label>
        <textarea id="description" name="description" rows="3"><?= $v('description') ?></textarea>

        <details class="mb-lg"<?= array_filter(array_intersect_key($form, $detailSections)) ? ' open' : '' ?>>
          <summary>รายละเอียดเพิ่มเติม (วิธีดูแล ลักษณะ คุณสมบัติ ประโยชน์ ฯลฯ — ไม่บังคับ)</summary>
          <?php foreach ($detailSections as $field => $label): ?>
            <label for="<?= $field ?>"><?= e($label) ?> (ไทย)</label>
            <textarea id="<?= $field ?>" name="<?= $field ?>" rows="3"><?= $v($field) ?></textarea>
          <?php endforeach; ?>
        </details>
        <p class="field-hint">กรอกภาษาไทยอย่างเดียว ระบบจะแปลอังกฤษ/จีนให้อัตโนมัติ</p>
      </div>
      <?php endif; ?>
    </div>

    <?php if ($canCreateTrees): ?>
    <div class="history-section">
      <h2>2. ต้นไม้</h2>
      <label for="zone_id">โซน</label>
      <select id="zone_id" name="zone_id">
        <option value="">— เลือกโซน —</option>
        <?php foreach ($zones as $z): ?>
          <option value="<?= (int) $z['id'] ?>" <?= (int) ($form['zone_id'] ?? 0) === (int) $z['id'] ? 'selected' : '' ?>><?= e($z['name']) ?> (<?= e($z['zone_code']) ?>)</option>
        <?php endforeach; ?>
      </select>

      <label for="quantity">จำนวนต้น</label>
      <input type="number" id="quantity" name="quantity" min="0" max="200" value="<?= $v('quantity', '1') ?>">
      <p class="field-hint">แต่ละต้นได้ Tree ID / QR ของตัวเอง <?= $canManageSpecies ? '— ใส่ 0 ถ้าต้องการเพิ่มแค่ชนิดพันธุ์ใหม่ยังไม่ปลูก' : '' ?></p>

      <label for="status">สถานะ</label>
      <select id="status" name="status">
        <?php foreach ($treeStatuses as $key => $label): ?>
          <option value="<?= e($key) ?>" <?= ($form['status'] ?? 'healthy') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>

      <details class="mb-lg"<?= ($form['latitude'] ?? '') !== '' || ($form['map_pin_x'] ?? null) !== null ? ' open' : '' ?>>
        <summary>ตำแหน่ง (GPS / ปักหมุดบนแผนที่ — ไม่บังคับ)</summary>
        <label for="latitude">พิกัด GPS</label>
        <div class="field-row">
          <input type="text" id="latitude" name="latitude" inputmode="decimal" placeholder="ละติจูด เช่น 13.7563" value="<?= $v('latitude') ?>">
          <input type="text" id="longitude" name="longitude" inputmode="decimal" placeholder="ลองจิจูด เช่น 100.5018" value="<?= $v('longitude') ?>">
          <button type="button" class="btn-outline btn-sm" data-geolocate
                  data-lat-target="latitude" data-lng-target="longitude" data-status-target="location-status">📍 ใช้ตำแหน่งปัจจุบัน</button>
        </div>
        <p class="field-hint" id="location-status" data-geolocate-status hidden></p>

        <?php if ($mapImageUrl): ?>
          <label>ปักหมุดบนแผนที่</label>
          <div data-pin-field>
            <div class="pin-picker-wrap" data-pin-image-wrap>
              <img src="<?= e($mapImageUrl) ?>" alt="แผนที่">
              <?php if (($form['map_pin_x'] ?? null) !== null && ($form['map_pin_y'] ?? null) !== null): ?>
                <div class="pin-picker-pin" data-pin-marker style="left:<?= e((string) $form['map_pin_x']) ?>%; top:<?= e((string) $form['map_pin_y']) ?>%"></div>
              <?php endif; ?>
            </div>
            <input type="hidden" name="map_pin_x" data-pin-x value="<?= $v('map_pin_x') ?>">
            <input type="hidden" name="map_pin_y" data-pin-y value="<?= $v('map_pin_y') ?>">
            <p><button type="button" class="btn-outline btn-sm" data-pin-remove>ลบหมุด</button></p>
          </div>
        <?php endif; ?>
        <p class="field-hint">ใช้ตำแหน่งเดียวกันกับทุกต้นที่เพิ่มครั้งนี้ — ปรับเป็นรายต้นได้ภายหลังที่หน้าแก้ไขต้นไม้</p>
      </details>

      <label>
        <input type="checkbox" name="is_active" <?= (!isset($form['is_active']) || $form['is_active']) ? 'checked' : '' ?>>
        เปิดใช้งาน (แสดงต่อผู้เข้าชม)
      </label>
    </div>
    <?php endif; ?>

    <p><button class="btn" type="submit">บันทึก</button></p>
  </form>

  <script>
  var existingPanel = document.getElementById('existing-species-panel');
  var newPanel = document.getElementById('new-species-panel');

  function currentSpeciesMode() {
    var checked = document.querySelector('input[name="species_mode"]:checked');
    return checked ? checked.value : document.querySelector('input[name="species_mode"]').value;
  }

  // Show only the chosen species panel, and make only its fields required.
  function applySpeciesMode() {
    var isNew = currentSpeciesMode() === 'new';
    if (existingPanel) existingPanel.hidden = isNew;
    if (newPanel) newPanel.hidden = !isNew;
    var speciesSelect = document.getElementById('species_id');
    if (speciesSelect) speciesSelect.required = !isNew;
    ['category_code', 'name'].forEach(function (id) {
      var field = document.getElementById(id);
      if (field) field.required = isNew;
    });
  }

  function setSpeciesMode(mode) {
    var radio = document.querySelector('input[name="species_mode"][value="' + mode + '"]');
    if (radio) radio.checked = true;
    applySpeciesMode();
  }

  function filterSpecies() {
    var term = document.getElementById('species_search').value.trim().toLowerCase();
    var select = document.getElementById('species_id');
    select.querySelectorAll('option[value]:not([value=""])').forEach(function (opt) {
      opt.hidden = !!term && opt.textContent.toLowerCase().indexOf(term) === -1;
    });
    var current = select.options[select.selectedIndex];
    if (current && current.hidden) select.value = '';
  }

  // "ชนิด" chips — same behaviour as species_form.php: pick one at a time,
  // the first becomes the primary subtype, × removes one.
  var subtypePicker = (function () {
    var picker = document.getElementById('subtype_picker');
    if (!picker) return { filterByCategory: function () {}, hasSelection: function () { return false; }, setIds: function () {} };
    var box = document.getElementById('subtype_selected_box');
    var hiddenContainer = document.getElementById('subtype_hidden_inputs');
    var metaById = {};
    picker.querySelectorAll('option[value]:not([value=""])').forEach(function (opt) {
      metaById[opt.value] = { name: opt.dataset.name, category: opt.dataset.category || '' };
    });
    var selected = Array.prototype.map.call(hiddenContainer.querySelectorAll('input'), function (input) {
      return { id: input.value, name: metaById[input.value] ? metaById[input.value].name : input.value };
    });

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
        selected.push({ id: id, name: metaById[id] ? metaById[id].name : id });
      }
      picker.value = '';
      render();
    });

    function filterByCategory(categoryCode) {
      picker.querySelectorAll('option[value]:not([value=""])').forEach(function (opt) {
        opt.hidden = !!categoryCode && !!opt.dataset.category && opt.dataset.category !== categoryCode;
      });
      var before = selected.length;
      selected = selected.filter(function (item) {
        var cat = metaById[item.id] ? metaById[item.id].category : '';
        return !categoryCode || !cat || cat === categoryCode;
      });
      if (selected.length !== before) render();
    }

    function setIds(ids, categoryCode) {
      selected = ids.filter(function (id) {
        var meta = metaById[String(id)];
        return meta && (!categoryCode || !meta.category || meta.category === categoryCode);
      }).map(function (id) {
        return { id: String(id), name: metaById[String(id)].name };
      });
      render();
    }

    render();
    return { filterByCategory: filterByCategory, hasSelection: function () { return selected.length > 0; }, setIds: setIds };
  })();

  if (document.getElementById('category_code')) {
    subtypePicker.filterByCategory(document.getElementById('category_code').value);
  }
  applySpeciesMode();

  // AI result: a species already in the system gets selected; otherwise
  // (admins who can add species) switch to "new species" with it filled in.
  var aiIdentify = document.querySelector('[data-ai-identify-for="image"]');
  if (aiIdentify) aiIdentify.addEventListener('ai-identify:apply', function (e) {
    var r = e.detail.result;
    if (r.matched_species_id && document.getElementById('species_id')) {
      setSpeciesMode('existing');
      document.getElementById('species_search').value = '';
      filterSpecies();
      document.getElementById('species_id').value = String(r.matched_species_id);
      document.getElementById('species_id').scrollIntoView({ behavior: 'smooth', block: 'center' });
      return;
    }
    if (!newPanel) return;
    setSpeciesMode('new');
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
    ['care_instructions', 'characteristics', 'properties', 'benefits', 'cautions', 'part_uses'].forEach(function (field) {
      setValue(field, r[field], true);
    });
    var category = document.getElementById('category_code');
    if (r.category_code && category.value === '') {
      category.value = r.category_code;
      subtypePicker.filterByCategory(r.category_code);
    }
    if (r.subtype_ids && r.subtype_ids.length && !subtypePicker.hasSelection()) {
      subtypePicker.setIds(r.subtype_ids, category.value);
    }
    document.getElementById('name').scrollIntoView({ behavior: 'smooth', block: 'center' });
  });
  </script>
  <?php endif; ?>
</div>
<script src="../public/assets/js/map-pin-picker.js"></script>
<script src="../public/assets/js/image-preview.js"></script>
<script src="../public/assets/js/ai-identify-button.js"></script>
<script src="../public/assets/js/geolocate-button.js"></script>
<script src="../public/assets/js/photo-capture-buttons.js"></script>
</body>
</html>
