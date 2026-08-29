<?php
require_once __DIR__ . '/../includes/auth.php';
requirePermission('tree.view');

$pdo = db();
$trees = $pdo->query(
    'SELECT t.*, s.name AS species_name, s.classification_id, z.name AS zone_name, z.zone_code
     FROM trees t
     JOIN species s ON s.id = t.species_id
     JOIN zones z ON z.id = t.zone_id
     ORDER BY t.display_order ASC'
)->fetchAll();
$siteLogo = getSetting($pdo, 'site_logo', '');
$statusLabels = ['healthy' => 'สมบูรณ์', 'needs_attention' => 'ต้องดูแล', 'removed' => 'นำออกแล้ว'];
$reprintId = isset($_GET['reprint']) ? (int) $_GET['reprint'] : 0;

$q = trim($_GET['q'] ?? '');
if ($q !== '') {
    $needle = mb_strtolower($q);
    $trees = array_values(array_filter($trees, function ($t) use ($needle) {
        return str_contains(mb_strtolower($t['species_name']), $needle)
            || str_contains(mb_strtolower($t['zone_name']), $needle)
            || str_contains(mb_strtolower((string) ($t['label'] ?? '')), $needle)
            || str_contains(mb_strtolower((string) ($t['classification_id'] ?? '')), $needle)
            || str_contains(mb_strtolower((string) ($t['plant_code'] ?? '')), $needle)
            || (string) $t['id'] === $needle;
    }));
}
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ผู้ดูแลระบบ — ต้นไม้</title>
<link rel="stylesheet" href="../public/assets/css/style.css">
</head>
<body>
<div class="admin-wrap">
  <div class="topbar">
    <div class="btn-row">
      <?php if ($siteLogo): ?>
        <img src="../public/<?= e(ltrim($siteLogo, '/')) ?>" alt="" class="topbar-logo">
      <?php endif; ?>
      <h1>ต้นไม้</h1>
    </div>
    <?php require __DIR__ . '/_nav.php'; ?>
  </div>

  <?php if ($reprintId): ?>
    <div class="flash">
      รหัสต้นไม้ของ <?= e(assetCode($pdo, $reprintId)) ?> เปลี่ยนแปลงแล้ว —
      <a href="tree_qr.php?id=<?= $reprintId ?>">พิมพ์ QR/ป้ายใหม่</a>
    </div>
  <?php endif; ?>
  <?php if (isset($_GET['bulk_deleted'])): ?>
    <div class="flash">ลบต้นไม้ที่เลือกแล้ว <?= (int) $_GET['bulk_deleted'] ?> ต้น</div>
  <?php endif; ?>

  <div class="btn-row">
    <a class="btn" href="tree_form.php">+ เพิ่มต้นไม้</a>
    <a class="btn" href="qr_all.php">พิมพ์ QR Code ทั้งหมด</a>
    <form id="bulkDeleteTrees" class="inline" method="post" action="tree_bulk_delete.php" data-confirm="ลบต้นไม้ที่เลือกทั้งหมดใช่หรือไม่?">
      <button class="btn btn-sm btn-danger" type="submit" data-bulk-submit="trees" disabled>ลบที่เลือก</button>
    </form>
  </div>

  <form method="get" class="btn-row search-form">
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="ค้นหาด้วยชนิดพันธุ์ โซน ป้ายชื่อ หรือ Tree ID...">
    <button class="btn-outline btn-sm" type="submit">ค้นหา</button>
  </form>

  <div class="table-scroll">
    <table>
      <thead>
        <tr><th><input type="checkbox" data-select-all="trees" title="เลือกทั้งหมด"></th><th>ลำดับ</th><th>Tree ID</th><th>รหัสต้นไม้ (15 หลัก)</th><th>ชนิดพันธุ์</th><th>โซน</th><th>ป้ายชื่อ</th><th>สถานะ</th><th>เปิดใช้งาน</th><th>URL สาธารณะ</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($trees as $t): ?>
        <tr>
          <td><input type="checkbox" class="row-check" data-group="trees" name="ids[]" value="<?= (int) $t['id'] ?>" form="bulkDeleteTrees"></td>
          <td><?= (int) $t['display_order'] ?></td>
          <td><?= e(assetCode($pdo, (int) $t['id'])) ?></td>
          <td><?= e($t['plant_code'] ?? '—') ?></td>
          <td><?= e($t['species_name']) ?></td>
          <td><?= e($t['zone_name']) ?> (<?= e($t['zone_code']) ?>)</td>
          <td><?= e($t['label'] ?? '') ?></td>
          <td><?= e($statusLabels[$t['status']] ?? $t['status']) ?></td>
          <td><?= $t['is_active'] ? 'ใช่' : 'ไม่' ?></td>
          <td><a href="../public/tree.php?id=<?= (int) $t['id'] ?>" target="_blank">/tree.php?id=<?= (int) $t['id'] ?></a></td>
          <td>
            <div class="btn-row">
              <a class="btn-outline btn-sm" href="tree_form.php?id=<?= (int) $t['id'] ?>">แก้ไข</a>
              <a class="btn-outline btn-sm" href="tree_qr.php?id=<?= (int) $t['id'] ?>">QR</a>
              <form class="inline" method="post" action="tree_delete.php" data-confirm="ลบต้นไม้นี้ใช่หรือไม่?">
                <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                <button class="btn btn-sm btn-danger" type="submit">ลบ</button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$trees): ?>
        <tr><td colspan="11">ไม่พบต้นไม้</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/_confirm_modal.php'; ?>
<script src="../public/assets/js/bulk-select.js"></script>
</body>
</html>
