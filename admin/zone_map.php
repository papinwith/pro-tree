<?php
require_once __DIR__ . '/../includes/auth.php';
requirePermission('zone.manage');

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $zoneId = (int) ($_POST['zone_id'] ?? 0);
    if (!getZoneById($pdo, $zoneId)) {
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
$mapImageUrl = $mapImage ? resolveAssetUrl($mapImage, '../public') : '';
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ผู้ดูแลระบบ — ปักหมุดโซนบนแผนที่</title>
<link rel="stylesheet" href="../public/assets/css/style.css">
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

  <div class="pin-picker-wrap" id="zoneMapWrap">
    <img src="<?= e($mapImageUrl) ?>" id="zoneMapImage" alt="แผนที่">
    <?php foreach ($zones as $z): if ($z['map_pin_x'] === null || $z['map_pin_y'] === null) continue; ?>
      <div class="pin-picker-pin" data-zone-id="<?= (int) $z['id'] ?>"
           style="left:<?= e((string) $z['map_pin_x']) ?>%; top:<?= e((string) $z['map_pin_y']) ?>%"
           title="<?= e($z['name']) ?>">
        <span class="pin-picker-pin-label"><?= e($z['name']) ?></span>
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

    wrap.querySelectorAll('.pin-picker-pin').forEach(function (pin) {
      pin.addEventListener('click', function (e) {
        e.stopPropagation();
        select.value = pin.dataset.zoneId;
        highlightSelected();
      });
    });

    function highlightSelected() {
      wrap.querySelectorAll('.pin-picker-pin').forEach(function (pin) {
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
