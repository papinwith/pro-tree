<?php
require_once __DIR__ . '/../includes/auth.php';
requirePermission('plan.manage');

$pdo = db();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$plan = null;
if ($id) {
    $plan = getPlantingPlanById($pdo, $id);
    if (!$plan) {
        http_response_code(404);
        exit('ไม่พบแผนการปลูกนี้');
    }
}

$errors = [];
$zones = getAllZones($pdo);
$speciesList = getAllSpecies($pdo);
$mapImage = getSetting($pdo, 'default_map_image', '');
$mapImageUrl = $mapImage ? resolveAssetUrl($mapImage, '../public') : '';
$statusLabels = ['pending' => 'รอดำเนินการ', 'in_progress' => 'กำลังดำเนินการ', 'completed' => 'ปลูกแล้ว', 'cancelled' => 'ยกเลิก'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $zoneId = (int) ($_POST['zone_id'] ?? 0);
    $speciesIdInput = trim($_POST['species_id'] ?? '');
    $speciesId = $speciesIdInput !== '' ? (int) $speciesIdInput : null;
    $plannedQuantity = max(1, (int) ($_POST['planned_quantity'] ?? 1));
    $targetDateInput = trim($_POST['target_date'] ?? '');
    $targetDate = $targetDateInput !== '' ? $targetDateInput : null;
    $status = $_POST['status'] ?? 'pending';
    $notes = trim($_POST['notes'] ?? '') ?: null;
    $createdBy = trim($_POST['created_by'] ?? '') ?: null;

    $latInput = trim($_POST['latitude'] ?? '');
    $lngInput = trim($_POST['longitude'] ?? '');
    $latitude = $latInput !== '' ? (float) $latInput : (isset($plan['latitude']) ? (float) $plan['latitude'] : null);
    $longitude = $lngInput !== '' ? (float) $lngInput : (isset($plan['longitude']) ? (float) $plan['longitude'] : null);
    if ($latInput !== '' && (!is_numeric($latInput) || $latitude < -90 || $latitude > 90)) {
        $errors[] = 'ละติจูดต้องอยู่ระหว่าง -90 ถึง 90';
    }
    if ($lngInput !== '' && (!is_numeric($lngInput) || $longitude < -180 || $longitude > 180)) {
        $errors[] = 'ลองจิจูดต้องอยู่ระหว่าง -180 ถึง 180';
    }

    // Click-to-place % pin on the map banner image — a purely visual
    // position, independent of the GPS lat/lng above (see tree_form.php).
    $pinXInput = trim($_POST['map_pin_x'] ?? '');
    $pinYInput = trim($_POST['map_pin_y'] ?? '');
    $mapPinX = $pinXInput !== '' ? max(0, min(100, (float) $pinXInput)) : null;
    $mapPinY = $pinYInput !== '' ? max(0, min(100, (float) $pinYInput)) : null;

    if (!$zoneId || !getZoneById($pdo, $zoneId)) {
        $errors[] = 'กรุณาเลือกโซนให้ถูกต้อง';
    }
    if ($speciesId !== null && !getSpeciesById($pdo, $speciesId)) {
        $errors[] = 'กรุณาเลือกชนิดพันธุ์ให้ถูกต้อง';
    }
    if (!isset($statusLabels[$status])) {
        $errors[] = 'สถานะไม่ถูกต้อง';
    }
    if ($targetDateInput !== '' && !DateTime::createFromFormat('Y-m-d', $targetDateInput)) {
        $errors[] = 'วันที่ตั้งเป้าไม่ถูกต้อง';
    }

    $imagePath = $plan['image_path'] ?? null;
    $newImagePath = null;
    if (!$errors) {
        try {
            $newImagePath = saveUploadedImage($_FILES['image'] ?? [], 'plan');
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
    }

    if (!$errors) {
        if ($newImagePath !== null) {
            $imagePath = $newImagePath;
        }

        $params = [
            'zone_id' => $zoneId, 'species_id' => $speciesId, 'planned_quantity' => $plannedQuantity,
            'target_date' => $targetDate, 'latitude' => $latitude, 'longitude' => $longitude,
            'map_pin_x' => $mapPinX, 'map_pin_y' => $mapPinY,
            'image_path' => $imagePath, 'status' => $status, 'notes' => $notes, 'created_by' => $createdBy,
        ];

        if ($id) {
            $stmt = $pdo->prepare(
                'UPDATE planting_plans SET zone_id=:zone_id, species_id=:species_id, planned_quantity=:planned_quantity,
                 target_date=:target_date, latitude=:latitude, longitude=:longitude, map_pin_x=:map_pin_x, map_pin_y=:map_pin_y,
                 image_path=:image_path, status=:status, notes=:notes, created_by=:created_by
                 WHERE id=:id'
            );
            $stmt->execute($params + ['id' => $id]);
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO planting_plans (zone_id, species_id, planned_quantity, target_date, latitude, longitude,
                 map_pin_x, map_pin_y, image_path, status, notes, created_by)
                 VALUES (:zone_id, :species_id, :planned_quantity, :target_date, :latitude, :longitude,
                 :map_pin_x, :map_pin_y, :image_path, :status, :notes, :created_by)'
            );
            $stmt->execute($params);
        }

        if ($newImagePath !== null && !empty($plan['image_path'])) {
            deletePublicFile($plan['image_path']);
        }

        header('Location: plans.php');
        exit;
    }

    $plan = array_merge($plan ?? [], [
        'zone_id' => $zoneId, 'species_id' => $speciesId, 'planned_quantity' => $plannedQuantity,
        'target_date' => $targetDate, 'latitude' => $latitude, 'longitude' => $longitude,
        'map_pin_x' => $mapPinX, 'map_pin_y' => $mapPinY,
        'status' => $status, 'notes' => $notes, 'created_by' => $createdBy,
    ]);
}

$v = fn($key, $default = '') => e((string) ($plan[$key] ?? $default));
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $id ? 'แก้ไข' : 'เพิ่ม' ?>แผนการปลูก</title>
<link rel="stylesheet" href="../public/assets/css/style.css">
</head>
<body>
<div class="admin-wrap admin-wrap-narrow">
  <p><a class="btn-outline btn-sm" href="plans.php">&larr; กลับไปหน้าแผนการปลูก</a></p>
  <h1><?= $id ? 'แก้ไขแผนการปลูก' : 'เพิ่มแผนการปลูก' ?></h1>

  <?php foreach ($errors as $err): ?>
    <div class="flash error"><?= e($err) ?></div>
  <?php endforeach; ?>

  <?php if (!$zones): ?>
    <div class="flash error">ยังไม่มีโซนในระบบ กรุณา<a href="zone_form.php">เพิ่มโซน</a>ก่อน</div>
  <?php else: ?>

  <form method="post" enctype="multipart/form-data">
    <?= csrfField() ?>
    <label for="zone_id">โซน</label>
    <select id="zone_id" name="zone_id" required>
      <option value="">— เลือกโซน —</option>
      <?php foreach ($zones as $z): ?>
        <option value="<?= (int) $z['id'] ?>" <?= (int) ($plan['zone_id'] ?? 0) === (int) $z['id'] ? 'selected' : '' ?>>
          <?= e($z['name']) ?> (<?= e($z['zone_code']) ?>)
        </option>
      <?php endforeach; ?>
    </select>

    <label for="species_search">ค้นหาชนิดพันธุ์</label>
    <input type="search" id="species_search" placeholder="พิมพ์ค้นหาชื่อต้นไม้..." oninput="filterSpecies()">

    <label for="species_id">ชนิดพันธุ์ (ไม่บังคับ — ถ้ายังไม่ตัดสินใจ)</label>
    <select id="species_id" name="species_id">
      <option value="">— ยังไม่ระบุ —</option>
      <?php foreach ($speciesList as $sp): ?>
        <option value="<?= (int) $sp['id'] ?>" <?= (int) ($plan['species_id'] ?? 0) === (int) $sp['id'] ? 'selected' : '' ?>>
          <?= e($sp['name']) ?><?= $sp['name_scientific'] ? ' (' . e($sp['name_scientific']) . ')' : '' ?>
        </option>
      <?php endforeach; ?>
    </select>

    <label for="planned_quantity">จำนวนต้นที่วางแผนปลูก</label>
    <input type="number" id="planned_quantity" name="planned_quantity" min="1" value="<?= $v('planned_quantity', '1') ?>">

    <label for="target_date">วันที่ตั้งเป้าปลูก (ไม่บังคับ)</label>
    <input type="date" id="target_date" name="target_date" value="<?= $v('target_date') ?>">

    <label for="latitude">พิกัด GPS จุดที่จะปลูก (ไม่บังคับ)</label>
    <div class="field-row">
      <input type="text" id="latitude" name="latitude" inputmode="decimal" placeholder="ละติจูด เช่น 13.7563" value="<?= $v('latitude') ?>">
      <input type="text" id="longitude" name="longitude" inputmode="decimal" placeholder="ลองจิจูด เช่น 100.5018" value="<?= $v('longitude') ?>">
    </div>
    <p class="field-hint">ปล่อยว่างไว้ถ้ายังไม่ได้สำรวจตำแหน่ง — เปิดแอปแผนที่บนมือถือแล้วคัดลอกพิกัดจากตำแหน่งปัจจุบันมาวางได้เลย</p>

    <label>ตำแหน่งบนแผนที่ (คลิกปักหมุด, ไม่บังคับ)</label>
    <?php if (!$mapImageUrl): ?>
      <p class="field-hint">ยังไม่ได้ตั้งค่ารูปแผนที่เริ่มต้น — ไปที่หน้า <a href="settings.php">ตั้งค่า</a> ก่อนถึงจะปักหมุดได้</p>
    <?php else: ?>
      <div data-pin-field>
        <div class="pin-picker-wrap" data-pin-image-wrap>
          <img src="<?= e($mapImageUrl) ?>" alt="แผนที่">
          <?php if (($plan['map_pin_x'] ?? null) !== null && ($plan['map_pin_y'] ?? null) !== null): ?>
            <div class="pin-picker-pin pin-picker-pin-plan" data-pin-marker style="left:<?= e((string) $plan['map_pin_x']) ?>%; top:<?= e((string) $plan['map_pin_y']) ?>%"></div>
          <?php endif; ?>
        </div>
        <input type="hidden" name="map_pin_x" data-pin-x value="<?= $v('map_pin_x') ?>">
        <input type="hidden" name="map_pin_y" data-pin-y value="<?= $v('map_pin_y') ?>">
        <p><button type="button" class="btn-outline btn-sm" data-pin-remove>ลบหมุด</button></p>
      </div>
      <p class="field-hint">คลิกบนรูปแผนที่เพื่อปักตำแหน่งคร่าวๆ — เป็นคนละค่ากับพิกัด GPS ด้านบน</p>
    <?php endif; ?>

    <label for="image">รูปภาพ/สเก็ตช์จุดที่จะปลูก (ไม่บังคับ)</label>
    <?php if (!empty($plan['image_path'])): ?>
      <img src="../public/<?= e($plan['image_path']) ?>" alt="" class="preview-thumb" id="image-preview">
    <?php endif; ?>
    <input type="file" id="image" name="image" accept="image/jpeg,image/png,image/gif,image/webp" data-preview-target="image-preview">

    <label for="status">สถานะ</label>
    <select id="status" name="status">
      <?php foreach ($statusLabels as $key => $label): ?>
        <option value="<?= e($key) ?>" <?= ($plan['status'] ?? 'pending') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>

    <label for="created_by">ผู้วางแผน (ไม่บังคับ)</label>
    <input type="text" id="created_by" name="created_by" placeholder="ชื่อเจ้าหน้าที่" value="<?= $v('created_by') ?>">

    <label for="notes">หมายเหตุ</label>
    <textarea id="notes" name="notes" rows="3"><?= $v('notes') ?></textarea>

    <p><button class="btn" type="submit">บันทึก</button></p>
  </form>

  <script>
  function filterSpecies() {
    var searchTerm = document.getElementById('species_search').value.trim().toLowerCase();
    var select = document.getElementById('species_id');
    var options = select.querySelectorAll('option[value]:not([value=""])');
    options.forEach(function (opt) {
      opt.hidden = !!searchTerm && opt.textContent.toLowerCase().indexOf(searchTerm) === -1;
    });
    var current = select.options[select.selectedIndex];
    if (current && current.hidden) {
      select.value = '';
    }
  }
  </script>
  <?php endif; ?>
</div>
<script src="../public/assets/js/map-pin-picker.js"></script>
<script src="../public/assets/js/image-preview.js"></script>
</body>
</html>
