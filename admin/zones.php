<?php
require_once __DIR__ . '/../includes/auth.php';
requirePermission('zone.manage');

$pdo = db();
$zones = $pdo->query(
    'SELECT z.*, (SELECT COUNT(*) FROM trees t WHERE t.zone_id = z.id) AS tree_count,
            (SELECT COUNT(*) FROM planting_plans pp WHERE pp.zone_id = z.id) AS plan_count
     FROM zones z ORDER BY z.name ASC'
)->fetchAll();
$completeness = getDataCompletenessStats($pdo);
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ผู้ดูแลระบบ — โซน</title>
<link rel="stylesheet" href="../public/assets/css/style.css">
</head>
<body>
<div class="admin-wrap">
  <div class="topbar">
    <h1>โซน</h1>
    <?php require __DIR__ . '/_nav.php'; ?>
  </div>

  <div class="btn-row">
    <a class="btn" href="zone_form.php">+ เพิ่มโซน</a>
    <form id="bulkDeleteZones" class="inline" method="post" action="zone_bulk_delete.php" data-confirm="ลบโซนที่เลือกทั้งหมดใช่หรือไม่? (รายการที่ยังมีต้นไม้หรือแผนปลูกผูกอยู่จะถูกข้าม)">
      <?= csrfField() ?>
      <button class="btn btn-sm btn-danger" type="submit" data-bulk-submit="zones" disabled>ลบที่เลือก</button>
    </form>
  </div>

  <div class="stat-cards">
    <div class="stat-card active">
      <span class="stat-value"><?= $completeness['percent'] ?>%</span>
      <span class="stat-label">ความครบถ้วนของข้อมูลโดยรวม</span>
    </div>
    <div class="stat-card">
      <span class="stat-value"><?= $completeness['complete'] ?> / <?= $completeness['total'] ?></span>
      <span class="stat-label">ต้นที่มีรูป+พิกัด+ประวัติสำรวจครบ</span>
    </div>
  </div>
  <p class="field-hint mb-lg">
    "ครบ" หมายถึงต้นไม้ที่มีรูปภาพ, พิกัดตำแหน่ง (ละติจูด/ลองจิจูด) และมีประวัติการสำรวจ (Observation) อย่างน้อย 1 ครั้ง — เป้าหมายโครงการคือ ≥95%
  </p>

  <?php if (isset($_GET['bulk_deleted'])): ?>
    <div class="flash<?= (int) ($_GET['bulk_skipped'] ?? 0) > 0 ? ' warning' : '' ?>">
      ลบโซนที่เลือกแล้ว <?= (int) $_GET['bulk_deleted'] ?> รายการ
      <?php if ((int) ($_GET['bulk_skipped'] ?? 0) > 0): ?>
        (ข้าม <?= (int) $_GET['bulk_skipped'] ?> รายการที่ยังมีต้นไม้หรือแผนปลูกผูกอยู่)
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="table-scroll">
    <table>
      <thead>
        <tr><th><input type="checkbox" data-select-all="zones" title="เลือกทั้งหมด"></th><th>รหัส</th><th>ชื่อ</th><th>จำนวนต้นไม้</th><th>ความครบถ้วนข้อมูล</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($zones as $z):
          $zc = $completeness['by_zone'][$z['id']] ?? ['complete' => 0, 'total' => 0, 'percent' => 0.0];
        ?>
        <tr>
          <td><input type="checkbox" class="row-check" data-group="zones" name="ids[]" value="<?= (int) $z['id'] ?>" form="bulkDeleteZones"></td>
          <td><?= e($z['zone_code']) ?></td>
          <td><?= e($z['name']) ?></td>
          <td><?= (int) $z['tree_count'] ?></td>
          <td>
            <?php if ($zc['total'] > 0): ?>
              <div class="completeness-cell">
                <div class="completeness-bar"><div class="completeness-bar-fill <?= $zc['percent'] < 95 ? 'low' : '' ?>" style="width:<?= $zc['percent'] ?>%"></div></div>
                <span><?= $zc['percent'] ?>% (<?= $zc['complete'] ?>/<?= $zc['total'] ?>)</span>
              </div>
            <?php else: ?>
              <span class="muted-note">—</span>
            <?php endif; ?>
          </td>
          <td>
            <div class="btn-row">
              <a class="btn-outline btn-sm" href="zone_form.php?id=<?= (int) $z['id'] ?>">แก้ไข</a>
              <?php if ((int) $z['tree_count'] === 0 && (int) $z['plan_count'] === 0): ?>
              <form class="inline" method="post" action="zone_delete.php" data-confirm="ลบโซนนี้ใช่หรือไม่?">
                <?= csrfField() ?>
                <input type="hidden" name="id" value="<?= (int) $z['id'] ?>">
                <button class="btn btn-sm btn-danger" type="submit">ลบ</button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$zones): ?>
        <tr><td colspan="6">ยังไม่มีโซน</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/_confirm_modal.php'; ?>
<script src="../public/assets/js/bulk-select.js"></script>
</body>
</html>
