<?php
require_once __DIR__ . '/../includes/auth.php';
requirePermission('qrcode.manage');

$pdo = db();
$trees = $pdo->query(
    'SELECT t.*, s.name AS species_name
     FROM trees t JOIN species s ON s.id = t.species_id
     ORDER BY t.display_order ASC'
)->fetchAll();

// Backfill QR codes for any tree that doesn't have one on disk yet.
foreach ($trees as &$tree) {
    if (empty($tree['qr_code_path']) || !is_file(publicDir() . '/' . $tree['qr_code_path'])) {
        $tree['qr_code_path'] = generateTreeQrCode((int) $tree['id']);
        $pdo->prepare('UPDATE trees SET qr_code_path = :qr WHERE id = :id')
            ->execute(['qr' => $tree['qr_code_path'], 'id' => $tree['id']]);
    }
}
unset($tree);
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
  <p><button class="btn" type="button" onclick="window.print()">พิมพ์ทั้งหมด</button></p>
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
