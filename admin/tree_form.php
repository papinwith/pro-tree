<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$pdo = db();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
requirePermission($id ? 'tree.update' : 'tree.create');
$tree = null;
if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM trees WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $tree = $stmt->fetch();
    if (!$tree) {
        http_response_code(404);
        exit('ไม่พบต้นไม้นี้');
    }
}

$errors = [];

$speciesList = getAllSpecies($pdo);
$categoriesByCode = array_column(getAllCategories($pdo), null, 'code');
$subtypes = getAllSubtypes($pdo);
$subtypesById = array_column($subtypes, null, 'id');
$zones = getAllZones($pdo);
$statuses = ['healthy' => 'สมบูรณ์', 'needs_attention' => 'ต้องดูแล', 'removed' => 'นำออกแล้ว'];
$healthLabels = ['good' => 'ดี', 'fair' => 'พอใช้', 'poor' => 'ทรุดโทรม'];
$observations = $id ? getObservationsForTree($pdo, $id) : [];
$maintenanceLogs = $id ? getMaintenanceLogsForTree($pdo, $id) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $speciesId = (int) ($_POST['species_id'] ?? 0);
    $zoneId = (int) ($_POST['zone_id'] ?? 0);
    $status = $_POST['status'] ?? 'healthy';
    $mapUrlInput = trim($_POST['map_url'] ?? '');
    $mapUrl = validatePublicUrl($mapUrlInput);
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    // Garden area/nameplate/URL slug/coordinates/display order are no
    // longer admin-entered — the system assigns them (area_code defaults
    // to '01', display_order continues the existing sequence, the rest
    // stay null/unset) so staff only deal with the fields that matter for
    // day-to-day planting.
    $areaCode = $tree['area_code'] ?? '01';
    $label = $tree['label'] ?? null;
    $slug = $tree['slug'] ?? null;
    // GPS coordinates ARE admin-entered (unlike area/label/slug above) —
    // optional, since not every tree has been surveyed yet. Kept as the
    // existing value when the field is left blank on an edit, so re-saving
    // the form without touching these never wipes a coordinate someone
    // already recorded.
    $latInput = trim($_POST['latitude'] ?? '');
    $lngInput = trim($_POST['longitude'] ?? '');
    $latitude = $latInput !== '' ? (float) $latInput : (isset($tree['latitude']) ? (float) $tree['latitude'] : null);
    $longitude = $lngInput !== '' ? (float) $lngInput : (isset($tree['longitude']) ? (float) $tree['longitude'] : null);
    if ($latInput !== '' && ($latitude < -90 || $latitude > 90)) {
        $errors[] = 'ละติจูดต้องอยู่ระหว่าง -90 ถึง 90';
    }
    if ($lngInput !== '' && ($longitude < -180 || $longitude > 180)) {
        $errors[] = 'ลองจิจูดต้องอยู่ระหว่าง -180 ถึง 180';
    }

    // Only present (and only meaningful) when creating — lets staff plant
    // several identical trees of the same species/zone in one submit,
    // same as the bulk-add feature on the species form.
    $quantity = $id ? 1 : max(1, min(200, (int) ($_POST['quantity'] ?? 1)));

    if (!$speciesId || !getSpeciesById($pdo, $speciesId)) {
        $errors[] = 'กรุณาเลือกชนิดพันธุ์ให้ถูกต้อง';
    }
    if (!$zoneId || !getZoneById($pdo, $zoneId)) {
        $errors[] = 'กรุณาเลือกโซนให้ถูกต้อง';
    }
    if (!isset($statuses[$status])) {
        $errors[] = 'สถานะไม่ถูกต้อง';
    }
    if ($mapUrlInput !== '' && $mapUrl === null) {
        $errors[] = 'URL แผนที่ไม่ถูกต้อง (ต้องขึ้นต้นด้วย http:// หรือ https://)';
    }

    // Uploaded files replace the existing image only if a new one was chosen;
    // otherwise the tree keeps whatever it already had (null on create).
    // When planting several at once, the same image/map are used for all of them.
    $imagePath = $tree['image_path'] ?? null;
    $mapImagePath = $tree['map_image_path'] ?? null;
    $newImagePath = null;
    $newMapImagePath = null;

    if (!$errors) {
        try {
            $newImagePath = saveUploadedImage($_FILES['image'] ?? [], 'tree');
            $newMapImagePath = saveUploadedImage($_FILES['map_image'] ?? [], 'maps');
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
    }

    if (!$errors) {
        if ($newImagePath !== null) {
            $imagePath = $newImagePath;
        }
        if ($newMapImagePath !== null) {
            $mapImagePath = $newMapImagePath;
        }

        // Only bump the timestamp when a coordinate was actually typed this
        // submit — re-saving the rest of the form (a new photo, a status
        // change) shouldn't make an untouched location look freshly surveyed.
        $locationUpdatedAt = ($latInput !== '' || $lngInput !== '')
            ? date('Y-m-d H:i:s')
            : ($tree['location_updated_at'] ?? null);

        $params = [
            'species_id' => $speciesId, 'zone_id' => $zoneId, 'area_code' => $areaCode, 'label' => $label, 'status' => $status,
            'slug' => $slug,
            'image_path' => $imagePath, 'map_image_path' => $mapImagePath, 'map_url' => $mapUrl,
            'latitude' => $latitude, 'longitude' => $longitude, 'location_updated_at' => $locationUpdatedAt,
            'is_active' => $isActive,
        ];

        $treeIds = [];
        $codeChanged = false;
        try {
            if ($id) {
                $stmt = $pdo->prepare(
                    'UPDATE trees SET species_id=:species_id, zone_id=:zone_id, area_code=:area_code, label=:label, status=:status,
                     slug=:slug, image_path=:image_path, map_image_path=:map_image_path, map_url=:map_url,
                     latitude=:latitude, longitude=:longitude, location_updated_at=:location_updated_at,
                     is_active=:is_active
                     WHERE id=:id'
                );
                $stmt->execute($params + ['id' => $id]);
                $treeIds = [$id];
            } else {
                $maxOrder = (int) $pdo->query('SELECT COALESCE(MAX(display_order), 0) FROM trees')->fetchColumn();
                $insertStmt = $pdo->prepare(
                    'INSERT INTO trees (species_id, zone_id, area_code, label, status, slug, image_path, map_image_path, map_url,
                     latitude, longitude, location_updated_at, display_order, is_active)
                     VALUES (:species_id, :zone_id, :area_code, :label, :status, :slug, :image_path, :map_image_path, :map_url,
                     :latitude, :longitude, :location_updated_at, :display_order, :is_active)'
                );
                // Recompute each tree's plant_code right after inserting it
                // (not in a separate pass afterwards) — recomputeTreePlantCode()
                // assigns the next sequence by counting sibling rows sharing
                // the same species/zone/area, so a later row in this same
                // batch needs the earlier ones already committed to land on a
                // distinct sequence instead of colliding with them.
                for ($i = 1; $i <= $quantity; $i++) {
                    $insertStmt->execute($params + ['display_order' => $maxOrder + $i]);
                    $newTreeId = (int) $pdo->lastInsertId();
                    $treeIds[] = $newTreeId;

                    $qrCodePath = generateTreeQrCode($newTreeId);
                    $pdo->prepare('UPDATE trees SET qr_code_path = :qr WHERE id = :id')
                        ->execute(['qr' => $qrCodePath, 'id' => $newTreeId]);
                    $codeChanged = recomputeTreePlantCode($pdo, $newTreeId) || $codeChanged;
                }
            }
        } catch (PDOException $e) {
            // Someone else (or another tab) saved a conflicting row between
            // our validation check above and this write — don't crash,
            // discard whatever we just uploaded, and ask the admin to retry.
            deletePublicFile($newImagePath);
            deletePublicFile($newMapImagePath);
            if ($e->getCode() === '23000') {
                $errors[] = 'ข้อมูลนี้เพิ่งถูกใช้โดยการบันทึกอื่น กรุณาตรวจสอบและลองใหม่อีกครั้ง';
            } else {
                throw $e;
            }
        }
    }

    if (!$errors) {
        if ($id) {
            // Editing an existing tree — species/zone/area may have just
            // changed, so (re)generate its QR and recompute its plant_code
            // (a bulk create already did both per-row above).
            $qrCodePath = generateTreeQrCode($id);
            $pdo->prepare('UPDATE trees SET qr_code_path = :qr WHERE id = :id')
                ->execute(['qr' => $qrCodePath, 'id' => $id]);
            $codeChanged = recomputeTreePlantCode($pdo, $id);
        }

        // Clean up the files that got replaced, now that the DB row points elsewhere.
        if ($newImagePath !== null && !empty($tree['image_path'])) {
            deletePublicFile($tree['image_path']);
        }
        if ($newMapImagePath !== null && !empty($tree['map_image_path'])) {
            deletePublicFile($tree['map_image_path']);
        }

        $lastTreeId = end($treeIds);
        header('Location: dashboard.php' . ($codeChanged ? '?reprint=' . $lastTreeId : ''));
        exit;
    }

    // keep entered values on validation error
    $tree = array_merge($tree ?? [], [
        'species_id' => $speciesId, 'zone_id' => $zoneId, 'area_code' => $areaCode, 'label' => $label, 'status' => $status,
        'slug' => $slug, 'map_url' => $mapUrlInput, 'is_active' => $isActive,
        'latitude' => $latitude, 'longitude' => $longitude, 'quantity' => $quantity,
    ]);
}

$v = fn($key, $default = '') => e((string) ($tree[$key] ?? $default));

// Which category/subtype the currently-selected species belongs to, so the
// two filters above the species dropdown start on the right value (both
// when editing an existing tree and when re-showing the form after a
// validation error).
$speciesById = array_column($speciesList, null, 'id');
$initialCategoryCode = '';
$initialSubtypeId = '';
if (!empty($tree['species_id']) && isset($speciesById[(int) $tree['species_id']])) {
    $initialCategoryCode = $speciesById[(int) $tree['species_id']]['category_code'];
    $initialSubtypeId = $speciesById[(int) $tree['species_id']]['subtype_id'] ?? '';
}
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $id ? 'แก้ไข' : 'เพิ่ม' ?>ต้นไม้</title>
<link rel="stylesheet" href="../public/assets/css/style.css">
</head>
<body>
<div class="admin-wrap admin-wrap-narrow">
  <p><a class="btn-outline btn-sm" href="dashboard.php">&larr; กลับไปหน้าต้นไม้</a></p>
  <h1><?= $id ? 'แก้ไขต้นไม้ — ' . e(assetCode($pdo, $id)) : 'เพิ่มต้นไม้' ?></h1>

  <?php foreach ($errors as $err): ?>
    <div class="flash error"><?= e($err) ?></div>
  <?php endforeach; ?>

  <?php if (!$speciesList): ?>
    <div class="flash error">ยังไม่มีชื่อต้นไม้ในระบบ กรุณา<a href="species_form.php">เพิ่มชื่อต้นไม้</a>ก่อน</div>
  <?php elseif (!$zones): ?>
    <div class="flash error">ยังไม่มีโซนในระบบ กรุณา<a href="zone_form.php">เพิ่มโซน</a>ก่อน</div>
  <?php else: ?>

  <form method="post" enctype="multipart/form-data">
    <?= csrfField() ?>
    <label for="category_code">ประเภทพืช</label>
    <select id="category_code" onchange="filterSubtypesByCategory(this.value); filterSpecies();">
      <option value="">— ทั้งหมด —</option>
      <?php foreach ($categoriesByCode as $cat): ?>
        <option value="<?= e($cat['code']) ?>" <?= $initialCategoryCode === $cat['code'] ? 'selected' : '' ?>><?= e($cat['name_th']) ?></option>
      <?php endforeach; ?>
    </select>

    <label for="subtype_filter">ชนิด</label>
    <select id="subtype_filter" onchange="filterSpecies()">
      <option value="">— ทั้งหมด —</option>
      <?php foreach ($subtypes as $st): ?>
        <option value="<?= (int) $st['id'] ?>" data-category="<?= e($st['category_code'] ?? '') ?>"
          <?= (string) $initialSubtypeId === (string) $st['id'] ? 'selected' : '' ?>><?= e($st['name_th']) ?></option>
      <?php endforeach; ?>
    </select>
    <p class="field-hint">เลือกประเภทพืช/ชนิดก่อน เพื่อกรองรายการชื่อต้นไม้ด้านล่างให้เลือกง่ายขึ้น</p>

    <label for="species_id">ชื่อต้นไม้</label>
    <select id="species_id" name="species_id" required onchange="updateSpeciesDetails(this.value)">
      <option value="">— เลือกชื่อต้นไม้ —</option>
      <?php foreach ($speciesList as $sp): ?>
        <option value="<?= (int) $sp['id'] ?>" <?= (int) ($tree['species_id'] ?? 0) === (int) $sp['id'] ? 'selected' : '' ?>
          data-category="<?= e($sp['category_code']) ?>"
          data-subtype="<?= (int) ($sp['subtype_id'] ?? 0) ?>"
          data-scientific="<?= e($sp['name_scientific'] ?? '') ?>"
          data-type="<?= e($categoriesByCode[$sp['category_code']]['name_th'] ?? '') ?>"
          data-subtype-name="<?= e($subtypesById[$sp['subtype_id'] ?? 0]['name_th'] ?? '') ?>"
          data-description="<?= e($sp['description'] ?? '') ?>"
          data-properties="<?= e($sp['properties'] ?? '') ?>"
          data-benefits="<?= e($sp['benefits'] ?? '') ?>"
          data-cautions="<?= e($sp['cautions'] ?? '') ?>">
          <?= e($sp['name']) ?><?= $sp['name_scientific'] ? ' (' . e($sp['name_scientific']) . ')' : '' ?>
        </option>
      <?php endforeach; ?>
    </select>

    <div id="species-details" class="field-hint" style="display:none">
      <p><strong>ชื่อวิทยาศาสตร์:</strong> <span data-out="scientific"></span></p>
      <p><strong>ประเภทพืช:</strong> <span data-out="type"></span></p>
      <p><strong>ชนิด:</strong> <span data-out="subtype-name"></span></p>
      <p><strong>คำอธิบาย:</strong> <span data-out="description"></span></p>
      <p><strong>คุณสมบัติ/สรรพคุณ:</strong> <span data-out="properties"></span></p>
      <p><strong>ประโยชน์:</strong> <span data-out="benefits"></span></p>
      <p><strong>ข้อควรระวัง:</strong> <span data-out="cautions"></span></p>
    </div>
    <p class="field-hint">แก้ไขรายละเอียดได้ที่ — <a href="species.php">จัดการชื่อต้นไม้</a></p>

    <label for="zone_id">โซน</label>
    <select id="zone_id" name="zone_id" required>
      <option value="">— เลือกโซน —</option>
      <?php foreach ($zones as $z): ?>
        <option value="<?= (int) $z['id'] ?>" <?= (int) ($tree['zone_id'] ?? 0) === (int) $z['id'] ? 'selected' : '' ?>>
          <?= e($z['name']) ?> (<?= e($z['zone_code']) ?>)
        </option>
      <?php endforeach; ?>
    </select>

    <label for="status">สถานะ</label>
    <select id="status" name="status">
      <?php foreach ($statuses as $key => $label): ?>
        <option value="<?= e($key) ?>" <?= ($tree['status'] ?? 'healthy') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>

    <label for="latitude">พิกัด GPS (ไม่บังคับ)</label>
    <div class="field-row">
      <input type="text" id="latitude" name="latitude" inputmode="decimal" placeholder="ละติจูด เช่น 13.7563" value="<?= $v('latitude') ?>">
      <input type="text" id="longitude" name="longitude" inputmode="decimal" placeholder="ลองจิจูด เช่น 100.5018" value="<?= $v('longitude') ?>">
    </div>
    <p class="field-hint">
      ปล่อยว่างไว้ถ้ายังไม่ได้สำรวจตำแหน่ง — เปิดแอปแผนที่บนมือถือแล้วคัดลอกพิกัดจากตำแหน่งปัจจุบันมาวางได้เลย
      <?php if (!empty($tree['location_updated_at'])): ?>
        (บันทึกพิกัดล่าสุดเมื่อ <?= e($tree['location_updated_at']) ?>)
      <?php endif; ?>
    </p>

    <label for="image">รูปภาพต้นไม้</label>
    <?php if (!empty($tree['image_path'])): ?>
      <p><img src="../public/<?= e($tree['image_path']) ?>" alt="" class="preview-thumb"></p>
    <?php endif; ?>
    <input type="file" id="image" name="image" accept="image/jpeg,image/png,image/gif,image/webp">

    <?php if (!$id): ?>
    <label for="quantity">จำนวนต้น</label>
    <input type="number" id="quantity" name="quantity" min="1" max="200" value="<?= $v('quantity', '1') ?>">
    <p class="field-hint">สร้างต้นไม้หลายต้นพร้อมกัน (ชนิดพันธุ์/โซน/รูปภาพเดียวกัน) แต่ละต้นได้ Tree ID/QR/ลำดับการแสดงผลของตัวเอง</p>
    <?php endif; ?>

    <p class="field-hint">
      Tree ID ภายใน (<?= $id ? e(assetCode($pdo, $id)) : 'NN-UD-xxxxxx' ?>) และลิงก์ QR จะคงเดิมแม้ย้ายต้นไม้ —
      รหัสต้นไม้ 15 หลักที่พิมพ์บนป้ายจะถูกกำหนดให้อัตโนมัติ
    </p>
    <?php if (!empty($tree['plant_code'])): ?>
      <p class="field-hint">
        รหัสต้นไม้ปัจจุบัน: <strong><?= e($tree['plant_code']) ?></strong>
        <?php if (!empty($tree['plant_code_updated_at'])): ?>
          (อัปเดตล่าสุด <?= e($tree['plant_code_updated_at']) ?>)
        <?php endif; ?>
      </p>
    <?php endif; ?>

    <label>
      <input type="checkbox" name="is_active" <?= (!isset($tree['is_active']) || $tree['is_active']) ? 'checked' : '' ?>>
      เปิดใช้งาน (แสดงต่อผู้เข้าชม)
    </label>

    <p><button class="btn" type="submit">บันทึก</button></p>
  </form>

  <script>
  function updateSpeciesDetails(id) {
    var panel = document.getElementById('species-details');
    if (!id) { panel.style.display = 'none'; return; }
    var opt = document.querySelector('#species_id option[value="' + id + '"]');
    if (!opt) { panel.style.display = 'none'; return; }
    ['scientific', 'type', 'subtype-name', 'description', 'properties', 'benefits', 'cautions'].forEach(function (key) {
      var val = opt.getAttribute('data-' + key) || '—';
      panel.querySelector('[data-out="' + key + '"]').textContent = val;
    });
    panel.style.display = '';
  }

  // Category narrows which subtypes show; category+subtype together narrow
  // which species show. Neither filter is submitted (not real <select
  // name>s) — a tree's actual category/subtype come from whichever species
  // ends up chosen in #species_id.
  function filterSubtypesByCategory(categoryCode) {
    var select = document.getElementById('subtype_filter');
    var options = select.querySelectorAll('option[value]:not([value=""])');
    options.forEach(function (opt) {
      opt.hidden = !!categoryCode && opt.dataset.category !== categoryCode;
    });
    var current = select.options[select.selectedIndex];
    if (current && current.hidden) {
      select.value = '';
    }
  }

  function filterSpecies() {
    var categoryCode = document.getElementById('category_code').value;
    var subtypeId = document.getElementById('subtype_filter').value;
    var select = document.getElementById('species_id');
    var options = select.querySelectorAll('option[value]:not([value=""])');
    options.forEach(function (opt) {
      var match = (!categoryCode || opt.dataset.category === categoryCode)
        && (!subtypeId || opt.dataset.subtype === subtypeId);
      opt.hidden = !match;
    });
    var current = select.options[select.selectedIndex];
    if (current && current.hidden) {
      select.value = '';
      updateSpeciesDetails('');
    }
  }

  filterSubtypesByCategory(document.getElementById('category_code').value);
  filterSpecies();
  updateSpeciesDetails(document.getElementById('species_id').value);
  </script>
  <?php endif; ?>

  <?php if ($id): ?>
  <section id="observations" class="history-section">
    <h2>ประวัติการสำรวจ (Observation)</h2>
    <?php if ($observations): ?>
      <div class="table-scroll">
        <table>
          <thead><tr><th>วันที่</th><th>ความสูง (ซม.)</th><th>ทรงพุ่ม (ซม.)</th><th>สุขภาพ</th><th>ผู้บันทึก</th><th>หมายเหตุ</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($observations as $ob): ?>
            <tr>
              <td><?= e($ob['observed_at']) ?></td>
              <td><?= e($ob['height_cm'] !== null ? $ob['height_cm'] : '—') ?></td>
              <td><?= e($ob['canopy_cm'] !== null ? $ob['canopy_cm'] : '—') ?></td>
              <td><?= e($healthLabels[$ob['health']] ?? $ob['health']) ?></td>
              <td><?= e($ob['recorded_by'] ?? '') ?></td>
              <td><?= e($ob['notes'] ?? '') ?></td>
              <td>
                <form class="inline" method="post" action="observation_delete.php" data-confirm="ลบรายการนี้?">
                  <?= csrfField() ?>
                  <input type="hidden" name="id" value="<?= (int) $ob['id'] ?>">
                  <input type="hidden" name="tree_id" value="<?= (int) $id ?>">
                  <button class="btn btn-sm btn-danger" type="submit">ลบ</button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <p class="muted-note">ยังไม่มีประวัติการสำรวจ</p>
    <?php endif; ?>

    <form method="post" action="observation_add.php" class="inline-add-form">
      <?= csrfField() ?>
      <input type="hidden" name="tree_id" value="<?= (int) $id ?>">
      <div class="field-row">
        <label>วันที่<input type="date" name="observed_at" value="<?= e(date('Y-m-d')) ?>" required></label>
        <label>ความสูง (ซม.)<input type="text" name="height_cm" inputmode="decimal" placeholder="เช่น 250"></label>
        <label>ทรงพุ่ม (ซม.)<input type="text" name="canopy_cm" inputmode="decimal" placeholder="เช่น 180"></label>
        <label>สุขภาพ
          <select name="health">
            <?php foreach ($healthLabels as $key => $label): ?>
              <option value="<?= e($key) ?>"><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>ผู้บันทึก<input type="text" name="recorded_by" placeholder="ชื่อเจ้าหน้าที่"></label>
      </div>
      <label>หมายเหตุ<input type="text" name="notes" placeholder="ข้อสังเกตเพิ่มเติม"></label>
      <button class="btn btn-sm" type="submit">+ บันทึกการสำรวจ</button>
    </form>
  </section>

  <section id="maintenance" class="history-section">
    <h2>ประวัติการดูแล (Maintenance Log)</h2>
    <?php if ($maintenanceLogs): ?>
      <div class="table-scroll">
        <table>
          <thead><tr><th>วันที่</th><th>กิจกรรม</th><th>ผู้ปฏิบัติ</th><th>หมายเหตุ</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($maintenanceLogs as $log): ?>
            <tr>
              <td><?= e($log['performed_at']) ?></td>
              <td><?= e($log['activity']) ?></td>
              <td><?= e($log['performed_by'] ?? '') ?></td>
              <td><?= e($log['notes'] ?? '') ?></td>
              <td>
                <form class="inline" method="post" action="maintenance_delete.php" data-confirm="ลบรายการนี้?">
                  <?= csrfField() ?>
                  <input type="hidden" name="id" value="<?= (int) $log['id'] ?>">
                  <input type="hidden" name="tree_id" value="<?= (int) $id ?>">
                  <button class="btn btn-sm btn-danger" type="submit">ลบ</button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <p class="muted-note">ยังไม่มีประวัติการดูแล</p>
    <?php endif; ?>

    <form method="post" action="maintenance_add.php" class="inline-add-form">
      <?= csrfField() ?>
      <input type="hidden" name="tree_id" value="<?= (int) $id ?>">
      <div class="field-row">
        <label>วันที่<input type="date" name="performed_at" value="<?= e(date('Y-m-d')) ?>" required></label>
        <label>กิจกรรม<input type="text" name="activity" placeholder="เช่น รดน้ำ, ตัดแต่ง, ใส่ปุ๋ย" required></label>
        <label>ผู้ปฏิบัติ<input type="text" name="performed_by" placeholder="ชื่อเจ้าหน้าที่"></label>
      </div>
      <label>หมายเหตุ<input type="text" name="notes" placeholder="รายละเอียดเพิ่มเติม"></label>
      <button class="btn btn-sm" type="submit">+ บันทึกการดูแล</button>
    </form>
  </section>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/_confirm_modal.php'; ?>
</body>
</html>
