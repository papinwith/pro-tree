<?php
require_once __DIR__ . '/../includes/auth.php';
requirePermission('zone.manage');

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $zoneId = (int) ($_POST['zone_id'] ?? 0);
    $zoneCheck = $pdo->prepare('SELECT id FROM zones WHERE id = :id');
    $zoneCheck->execute(['id' => $zoneId]);
    if (!$zoneCheck->fetchColumn()) {
        http_response_code(404);
        exit('ไม่พบโซนนี้');
    }

    if (($_POST['remove'] ?? '') === '1') {
        $pinX = null;
        $pinY = null;
    } else {
        $pinX = max(0, min(100, (float) ($_POST['pin_x'] ?? 0)));
        $pinY = max(0, min(100, (float) ($_POST['pin_y'] ?? 0)));
    }

    $stmt = $pdo->prepare('UPDATE zones SET map_pin_x = :x, map_pin_y = :y WHERE id = :id');
    $stmt->execute(['x' => $pinX, 'y' => $pinY, 'id' => $zoneId]);

    header('Location: zone_map.php');
    exit;
}

$zones = getAllZones($pdo);
$mapImage = getSetting($pdo, 'default_map_image', '');
$mapImageUrl = $mapImage ? (str_starts_with($mapImage, 'http') ? $mapImage : '../public/' . ltrim($mapImage, '/')) : '';
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ผู้ดูแลระบบ — ปักหมุดโซนบนแผนที่</title>
<link rel="stylesheet" href="../public/assets/css/style.css">
<style>
  .zone-map-wrap { position: relative; display: inline-block; max-width: 100%; }
  .zone-map-wrap img { display: block; max-width: 100%; border: 1px solid var(--border); border-radius: var(--radius-md); }
  .zone-map-pin {
    position: absolute; transform: translate(-50%, -100%);
    width: 22px; height: 22px; border-radius: 50% 50% 50% 0;
    background: var(--green-500); border: 2px solid #fff; box-shadow: var(--shadow-sm);
    cursor: pointer; rotate: -45deg;
  }
  .zone-map-pin.selected { background: var(--red-600); }
  .zone-map-pin-label {
    position: absolute; bottom: 26px; left: 50%; transform: translateX(-50%);
    background: var(--text); color: #fff; font-size: 0.75rem; padding: 2px 7px;
    border-radius: var(--radius-sm); white-space: nowrap; pointer-events: none;
  }
</style>
</head>
<body>
<div class="admin-wrap">
  <div class="topbar">
    <h1>ปักหมุดโซนบนแผนที่</h1>
    <?php require __DIR__ . '/_nav.php'; ?>
  </div>

  <p class="field-hint mb-lg">
    เลือกโซนจากรายการด้านล่าง แล้วคลิกบนแผนที่เพื่อปักหมุดตำแหน่งของโซนนั้น
    (คลิกที่หมุดที่มีอยู่แล้วเพื่อเลือกโซนนั้นแล้วคลิกตำแหน่งใหม่เพื่อย้ายหมุด)
  </p>

  <?php if (!$mapImageUrl): ?>
    <div class="flash error">ยังไม่ได้ตั้งค่ารูปแผนที่เริ่มต้น — ไปที่หน้า <a href="settings.php">ตั้งค่า</a> ก่อน</div>
  <?php else: ?>

  <label for="zoneSelect">โซนที่จะปักหมุด</label>
  <select id="zoneSelect">
    <?php foreach ($zones as $z): ?>
      <option value="<?= (int) $z['id'] ?>"><?= e($z['name']) ?><?= $z['map_pin_x'] !== null ? ' (ปักหมุดแล้ว)' : '' ?></option>
    <?php endforeach; ?>
  </select>

  <p class="mb-lg">
    <button class="btn btn-sm btn-danger" type="button" id="removePinBtn">ลบหมุดของโซนที่เลือก</button>
  </p>

  <div class="zone-map-wrap" id="zoneMapWrap">
    <img src="<?= e($mapImageUrl) ?>" id="zoneMapImage" alt="แผนที่">
    <?php foreach ($zones as $z): if ($z['map_pin_x'] === null || $z['map_pin_y'] === null) continue; ?>
      <div class="zone-map-pin" data-zone-id="<?= (int) $z['id'] ?>"
           style="left:<?= e((string) $z['map_pin_x']) ?>%; top:<?= e((string) $z['map_pin_y']) ?>%"
           title="<?= e($z['name']) ?>">
        <span class="zone-map-pin-label"><?= e($z['name']) ?></span>
      </div>
    <?php endforeach; ?>
  </div>

  <form method="post" id="pinForm">
    <?= csrfField() ?>
    <input type="hidden" name="zone_id" id="pinZoneId">
    <input type="hidden" name="pin_x" id="pinX">
    <input type="hidden" name="pin_y" id="pinY">
    <input type="hidden" name="remove" id="pinRemove" value="">
  </form>

  <script>
  (function () {
    var select = document.getElementById('zoneSelect');
    var wrap = document.getElementById('zoneMapWrap');
    var img = document.getElementById('zoneMapImage');
    var form = document.getElementById('pinForm');

    wrap.querySelectorAll('.zone-map-pin').forEach(function (pin) {
      pin.addEventListener('click', function (e) {
        e.stopPropagation();
        select.value = pin.dataset.zoneId;
        highlightSelected();
      });
    });

    function highlightSelected() {
      wrap.querySelectorAll('.zone-map-pin').forEach(function (pin) {
        pin.classList.toggle('selected', pin.dataset.zoneId === select.value);
      });
    }
    select.addEventListener('change', highlightSelected);
    highlightSelected();

    img.addEventListener('click', function (e) {
      var rect = img.getBoundingClientRect();
      var x = ((e.clientX - rect.left) / rect.width) * 100;
      var y = ((e.clientY - rect.top) / rect.height) * 100;
      document.getElementById('pinZoneId').value = select.value;
      document.getElementById('pinX').value = x.toFixed(2);
      document.getElementById('pinY').value = y.toFixed(2);
      document.getElementById('pinRemove').value = '';
      form.submit();
    });

    document.getElementById('removePinBtn').addEventListener('click', function () {
      document.getElementById('pinZoneId').value = select.value;
      document.getElementById('pinRemove').value = '1';
      form.submit();
    });
  })();
  </script>

  <?php endif; ?>
</div>
</body>
</html>
