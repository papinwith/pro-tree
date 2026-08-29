<?php
require_once __DIR__ . '/../includes/auth.php';
requirePermission('qrcode.manage');

$pdo = db();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$stmt = $pdo->prepare(
    'SELECT t.*, s.name AS species_name
     FROM trees t JOIN species s ON s.id = t.species_id
     WHERE t.id = :id'
);
$stmt->execute(['id' => $id]);
$tree = $stmt->fetch();
if (!$tree) {
    http_response_code(404);
    exit('ไม่พบต้นไม้นี้');
}

// Trees created before QR generation existed (or whose file was lost) get
// one generated on the fly here, and the path is persisted for next time.
if (empty($tree['qr_code_path']) || !is_file(publicDir() . '/' . $tree['qr_code_path'])) {
    $tree['qr_code_path'] = generateTreeQrCode((int) $tree['id']);
    $pdo->prepare('UPDATE trees SET qr_code_path = :qr WHERE id = :id')
        ->execute(['qr' => $tree['qr_code_path'], 'id' => $tree['id']]);
}

$treeUrl = rtrim(APP_BASE_URL, '/') . '/tree/' . $tree['id'];
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>QR — <?= e($tree['species_name']) ?></title>
<link rel="stylesheet" href="../public/assets/css/style.css">
</head>
<body>
<div class="admin-wrap no-print">
  <p><a class="btn-outline btn-sm" href="dashboard.php">&larr; กลับไปหน้าต้นไม้</a></p>
</div>

<div class="qr-card">
  <img src="../public/<?= e($tree['qr_code_path']) ?>" alt="QR code for <?= e($tree['species_name']) ?>">
  <h2><?= e($tree['species_name']) ?></h2>
  <?php if (!empty($tree['plant_code'])): ?>
    <p class="qr-url"><strong><?= e($tree['plant_code']) ?></strong></p>
  <?php endif; ?>
  <p class="qr-url"><?= e(assetCode($pdo, (int) $tree['id'])) ?></p>
  <p class="qr-url"><?= e($treeUrl) ?></p>
</div>

<div class="qr-actions no-print">
  <a class="btn" href="../public/<?= e($tree['qr_code_path']) ?>" download="<?= e(qrDownloadFilename($tree['plant_code'] ?: ($tree['label'] ?: $tree['species_name']), assetCode($pdo, (int) $tree['id']))) ?>">ดาวน์โหลด PNG</a>
  <button class="btn" type="button" onclick="window.print()">พิมพ์</button>
</div>
</body>
</html>
