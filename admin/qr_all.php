<?php
require_once __DIR__ . '/../includes/auth.php';
requirePermission('qrcode.manage');

$pdo = db();
$trees = $pdo->query(
    'SELECT t.*, s.name AS species_name, s.category_code, z.name AS zone_name
     FROM trees t
     JOIN species s ON s.id = t.species_id
     JOIN zones z ON z.id = t.zone_id
     ORDER BY t.display_order ASC'
)->fetchAll();

$zones = getAllZones($pdo);
$categories = getAllCategories($pdo);
$statusLabels = ['healthy' => 'สมบูรณ์', 'needs_attention' => 'ต้องดูแล', 'removed' => 'นำออกแล้ว'];

// Backfill QR codes for any tree that doesn't have one on disk yet — over
// the full unfiltered list, so a filtered visit (e.g. ?zone_id=3) doesn't
// skip regenerating a missing QR for a tree the filter happens to exclude.
foreach ($trees as &$tree) {
    if (empty($tree['qr_code_path']) || !is_file(publicDir() . '/' . $tree['qr_code_path'])) {
        $tree['qr_code_path'] = generateTreeQrCode((int) $tree['id']);
        $pdo->prepare('UPDATE trees SET qr_code_path = :qr WHERE id = :id')
            ->execute(['qr' => $tree['qr_code_path'], 'id' => $tree['id']]);
    }
}
unset($tree);

// Same filters as admin/dashboard.php — printing QR labels for the whole
// garden every time is rarely what's wanted; usually it's "just the trees
// I planted in zone X today" or similar.
$zoneFilter = (int) ($_GET['zone_id'] ?? 0);
if ($zoneFilter) {
    $trees = array_values(array_filter($trees, fn($t) => (int) $t['zone_id'] === $zoneFilter));
}
$categoryFilter = trim($_GET['category_code'] ?? '');
if ($categoryFilter !== '') {
    $trees = array_values(array_filter($trees, fn($t) => $t['category_code'] === $categoryFilter));
}
$statusFilter = trim($_GET['status'] ?? '');
if ($statusFilter !== '' && isset($statusLabels[$statusFilter])) {
    $trees = array_values(array_filter($trees, fn($t) => $t['status'] === $statusFilter));
}
$activeFilter = trim($_GET['active'] ?? '');
if ($activeFilter === '1' || $activeFilter === '0') {
    $trees = array_values(array_filter($trees, fn($t) => (string) (int) $t['is_active'] === $activeFilter));
}
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>QR Code ต้นไม้ทั้งหมด</title>
<link rel="stylesheet" href="../public/assets/css/style.css">
</head>
<body>
<div class="admin-wrap no-print">
  <div class="topbar">
    <h1>QR Code ต้นไม้ทั้งหมด</h1>
    <div>
      <a class="btn-outline btn-sm" href="dashboard.php">&larr; กลับไปหน้าต้นไม้</a>
    </div>
  </div>
  <form method="get" class="filter-form">
    <select name="zone_id" aria-label="โซน">
      <option value="">— ทุกโซน —</option>
      <?php foreach ($zones as $z): ?>
        <option value="<?= (int) $z['id'] ?>" <?= $zoneFilter === (int) $z['id'] ? 'selected' : '' ?>><?= e($z['name']) ?></option>
      <?php endforeach; ?>
    </select>

    <select name="category_code" aria-label="ประเภทพืช">
      <option value="">— ทุกประเภทพืช —</option>
      <?php foreach ($categories as $cat): ?>
        <option value="<?= e($cat['code']) ?>" <?= $categoryFilter === $cat['code'] ? 'selected' : '' ?>><?= e($cat['name_th']) ?></option>
      <?php endforeach; ?>
    </select>

    <select name="status" aria-label="สถานะ">
      <option value="">— ทุกสถานะ —</option>
      <?php foreach ($statusLabels as $key => $label): ?>
        <option value="<?= e($key) ?>" <?= $statusFilter === $key ? 'selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>

    <select name="active" aria-label="เปิดใช้งาน">
      <option value="">— เปิด/ปิดใช้งาน —</option>
      <option value="1" <?= $activeFilter === '1' ? 'selected' : '' ?>>เปิดใช้งาน</option>
      <option value="0" <?= $activeFilter === '0' ? 'selected' : '' ?>>ปิดใช้งาน</option>
    </select>

    <button class="btn-outline" type="submit">กรอง</button>
    <?php if ($zoneFilter || $categoryFilter !== '' || $statusFilter !== '' || $activeFilter !== ''): ?>
      <a class="btn-outline" href="qr_all.php">ล้างตัวกรอง</a>
    <?php endif; ?>
  </form>

  <p><button class="btn" type="button" onclick="window.print()">พิมพ์ทั้งหมด (<?= count($trees) ?> ต้น)</button></p>
</div>

<div class="qr-grid">
  <?php foreach ($trees as $tree): ?>
    <?php $treeUrl = rtrim(APP_BASE_URL, '/') . '/tree/' . $tree['id']; ?>
    <div class="qr-cell">
      <img src="../public/<?= e($tree['qr_code_path']) ?>" alt="QR code for <?= e($tree['species_name']) ?>">
      <h3><?= e($tree['species_name']) ?></h3>
      <?php if (!empty($tree['plant_code'])): ?>
        <p class="qr-url"><strong><?= e($tree['plant_code']) ?></strong></p>
      <?php endif; ?>
      <p class="qr-url"><?= e(assetCode($pdo, (int) $tree['id'])) ?></p>
      <p class="qr-url"><?= e($treeUrl) ?></p>
      <p class="no-print"><a class="btn-outline btn-sm" href="../public/<?= e($tree['qr_code_path']) ?>" download="<?= e(qrDownloadFilename($tree['plant_code'] ?: ($tree['label'] ?: $tree['species_name']), assetCode($pdo, (int) $tree['id']))) ?>">ดาวน์โหลด PNG</a></p>
    </div>
  <?php endforeach; ?>
  <?php if (!$trees): ?>
    <p>ยังไม่มีต้นไม้</p>
  <?php endif; ?>
</div>
</body>
</html>
