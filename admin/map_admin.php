<?php
require_once __DIR__ . '/../includes/auth.php';
requirePermission('zone.manage');

$pdo = db();

$zones = getAllZones($pdo);

$trees = $pdo->query(
    "SELECT t.id, t.map_pin_x, t.map_pin_y, t.status, t.label, s.name AS species_name
     FROM trees t
     JOIN species s ON s.id = t.species_id
     WHERE t.map_pin_x IS NOT NULL AND t.map_pin_y IS NOT NULL"
)->fetchAll();

$plans = $pdo->query(
    "SELECT pp.id, pp.map_pin_x, pp.map_pin_y, pp.status, z.name AS zone_name, sp.name AS species_name
     FROM planting_plans pp
     JOIN zones z ON z.id = pp.zone_id
     LEFT JOIN species sp ON sp.id = pp.species_id
     WHERE pp.map_pin_x IS NOT NULL AND pp.map_pin_y IS NOT NULL"
)->fetchAll();

// Real-map (OpenStreetMap) markers: everything that has GPS coordinates.
$gpsTrees = $pdo->query(
    "SELECT t.id, t.latitude, t.longitude, t.status, t.label, s.name AS species_name
     FROM trees t
     JOIN species s ON s.id = t.species_id
     WHERE t.latitude IS NOT NULL AND t.longitude IS NOT NULL"
)->fetchAll();
$gpsPlans = $pdo->query(
    "SELECT pp.id, pp.latitude, pp.longitude, z.name AS zone_name, sp.name AS species_name
     FROM planting_plans pp
     JOIN zones z ON z.id = pp.zone_id
     LEFT JOIN species sp ON sp.id = pp.species_id
     WHERE pp.latitude IS NOT NULL AND pp.longitude IS NOT NULL"
)->fetchAll();
$gpsMarkers = array_merge(
    array_map(fn($t) => [
        'lat' => (float) $t['latitude'], 'lng' => (float) $t['longitude'],
        'label' => 'ต้นไม้: ' . ($t['label'] ?: $t['species_name']), 'href' => 'tree_form.php?id=' . (int) $t['id'],
        'color' => $t['status'] === 'needs_attention' ? '#d97706' : ($t['status'] === 'removed' ? '#dc2626' : '#16a34a'),
    ], $gpsTrees),
    array_map(fn($p) => [
        'lat' => (float) $p['latitude'], 'lng' => (float) $p['longitude'],
        'label' => 'แผน: ' . ($p['species_name'] ?? 'ยังไม่ระบุชนิด') . ' — ' . $p['zone_name'], 'href' => 'plan_form.php?id=' . (int) $p['id'],
        'color' => '#2563eb',
    ], $gpsPlans)
);

$mapImage = getSetting($pdo, 'default_map_image', '');
$mapImageUrl = $mapImage ? resolveAssetUrl($mapImage, '../public') : '';
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ผู้ดูแลระบบ — แผนที่รวม</title>
<link rel="stylesheet" href="../public/assets/css/style.css">
<?= gpsMapAssets() ?>
</head>
<body>
<div class="admin-wrap">
  <div class="topbar">
    <h1>แผนที่รวม — โซน / ต้นไม้ / แผนการปลูก</h1>
    <?php require __DIR__ . '/_nav.php'; ?>
  </div>

  <p class="field-hint mb-lg">
    มุมมองรวมของหมุดทั้งหมดบนแผนที่เดียวกัน — ดูอย่างเดียว จะย้าย/ปักหมุดใหม่ให้ไปที่
    <a href="zone_map.php">ปักหมุดโซน</a>, หน้าแก้ไขต้นไม้แต่ละต้น หรือหน้าแก้ไขแผนการปลูกแต่ละแผน
  </p>

  <h2>แผนที่จริง (ตามพิกัด GPS)</h2>
  <p class="field-hint">
    ต้นไม้ <?= count($gpsTrees) ?> ต้น และแผนการปลูก <?= count($gpsPlans) ?> แผนที่มีพิกัด —
    สีเขียว = สมบูรณ์, ส้ม = ต้องดูแล, แดง = นำออกแล้ว, น้ำเงิน = แผนการปลูก แตะหมุดเพื่อเปิดหน้าแก้ไข
  </p>
  <div id="gpsOverview" class="gps-map gps-map-tall"></div>
  <script>
  (function () {
    var el = document.getElementById('gpsOverview');
    if (typeof L === 'undefined') { el.hidden = true; return; }
    var markers = <?= json_encode($gpsMarkers, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var map = L.map(el).setView([13.0, 101.0], 6);
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
    }).addTo(map);
    var bounds = [];
    markers.forEach(function (m) {
      var link = document.createElement('a');
      link.href = m.href;
      link.textContent = m.label;
      L.circleMarker([m.lat, m.lng], { radius: 7, color: '#fff', weight: 2, fillColor: m.color, fillOpacity: 0.9 })
        .bindPopup(link).addTo(map);
      bounds.push([m.lat, m.lng]);
    });
    if (bounds.length) map.fitBounds(bounds, { padding: [30, 30], maxZoom: 18 });
  })();
  </script>

  <h2>รูปแผนที่ (หมุดโซน / หมุดเดิม)</h2>
  <?php if (!$mapImageUrl): ?>
    <div class="flash error">ยังไม่ได้ตั้งค่ารูปแผนที่เริ่มต้น — ไปที่หน้า <a href="settings.php">ตั้งค่า</a> ก่อน</div>
  <?php else: ?>

  <div class="btn-row mb-lg">
    <label><input type="checkbox" id="toggleZones" checked> โซน (<?= count(array_filter($zones, fn($z) => $z['map_pin_x'] !== null)) ?>)</label>
    <label><input type="checkbox" id="toggleTrees" checked> ต้นไม้ (<?= count($trees) ?>)</label>
    <label><input type="checkbox" id="togglePlans" checked> แผนการปลูก (<?= count($plans) ?>)</label>
  </div>

  <div class="pin-picker-wrap">
    <img src="<?= e($mapImageUrl) ?>" alt="แผนที่">

    <div id="layerZones">
      <?php foreach ($zones as $z): if ($z['map_pin_x'] === null || $z['map_pin_y'] === null) continue; ?>
        <div class="pin-picker-pin" style="left:<?= e((string) $z['map_pin_x']) ?>%; top:<?= e((string) $z['map_pin_y']) ?>%" title="โซน: <?= e($z['name']) ?>">
          <span class="pin-picker-pin-label"><?= e($z['name']) ?></span>
        </div>
      <?php endforeach; ?>
    </div>

    <div id="layerTrees">
      <?php foreach ($trees as $t):
        $label = $t['label'] ?: $t['species_name'];
        $title = 'ต้นไม้: ' . $label . ' (' . $t['status'] . ')';
      ?>
        <a class="pin-picker-pin" href="tree_form.php?id=<?= (int) $t['id'] ?>"
           style="left:<?= e((string) $t['map_pin_x']) ?>%; top:<?= e((string) $t['map_pin_y']) ?>%; background:<?= $t['status'] === 'needs_attention' ? 'var(--amber-600)' : ($t['status'] === 'removed' ? 'var(--red-600)' : 'var(--green-500)') ?>"
           title="<?= e($title) ?>">
          <span class="pin-picker-pin-label"><?= e($label) ?></span>
        </a>
      <?php endforeach; ?>
    </div>

    <div id="layerPlans">
      <?php foreach ($plans as $p):
        $label = 'แผน: ' . ($p['species_name'] ?? 'ยังไม่ระบุชนิด') . ' — ' . $p['zone_name'];
      ?>
        <a class="pin-picker-pin pin-picker-pin-plan" href="plan_form.php?id=<?= (int) $p['id'] ?>"
           style="left:<?= e((string) $p['map_pin_x']) ?>%; top:<?= e((string) $p['map_pin_y']) ?>%"
           title="<?= e($label) ?>">
          <span class="pin-picker-pin-label"><?= e($label) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>

  <script>
  document.getElementById('toggleZones').addEventListener('change', function (e) {
    document.getElementById('layerZones').hidden = !e.target.checked;
  });
  document.getElementById('toggleTrees').addEventListener('change', function (e) {
    document.getElementById('layerTrees').hidden = !e.target.checked;
  });
  document.getElementById('togglePlans').addEventListener('change', function (e) {
    document.getElementById('layerPlans').hidden = !e.target.checked;
  });
  </script>

  <?php endif; ?>
</div>
</body>
</html>
