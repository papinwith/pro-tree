<?php
require_once __DIR__ . '/../includes/auth.php';
requirePermission('species.manage');

$pdo = db();
$speciesList = $pdo->query(
    'SELECT sp.*, st.name_th AS subtype_name, (SELECT COUNT(*) FROM trees t WHERE t.species_id = sp.id) AS tree_count,
            (SELECT GROUP_CONCAT(st2.name_th SEPARATOR ", ")
             FROM species_subtypes ss JOIN subtypes st2 ON st2.id = ss.subtype_id
             WHERE ss.species_id = sp.id) AS extra_subtype_names
     FROM species sp LEFT JOIN subtypes st ON st.id = sp.subtype_id ORDER BY sp.name ASC'
)->fetchAll();
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ผู้ดูแลระบบ — ชนิดพันธุ์</title>
<link rel="stylesheet" href="../public/assets/css/style.css">
</head>
<body>
<div class="admin-wrap">
  <div class="topbar">
    <h1>ชื่อพันธุ์ไม้</h1>
    <?php require __DIR__ . '/_nav.php'; ?>
  </div>

  <div class="btn-row">
    <a class="btn" href="species_form.php">+ เพิ่มชนิดพันธุ์</a>
    <form id="bulkDeleteSpecies" class="inline" method="post" action="species_bulk_delete.php" data-confirm="ลบชนิดพันธุ์ที่เลือกทั้งหมดใช่หรือไม่? (รายการที่ยังมีต้นไม้ผูกอยู่จะถูกข้าม)">
      <?= csrfField() ?>
      <button class="btn btn-sm btn-danger" type="submit" data-bulk-submit="species" disabled>ลบที่เลือก</button>
    </form>
  </div>

  <?php if (isset($_GET['bulk_deleted'])): ?>
    <div class="flash<?= (int) ($_GET['bulk_skipped'] ?? 0) > 0 ? ' warning' : '' ?>">
      ลบชนิดพันธุ์ที่เลือกแล้ว <?= (int) $_GET['bulk_deleted'] ?> รายการ
      <?php if ((int) ($_GET['bulk_skipped'] ?? 0) > 0): ?>
        (ข้าม <?= (int) $_GET['bulk_skipped'] ?> รายการที่ยังมีต้นไม้ผูกอยู่)
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="table-scroll">
    <table>
      <thead>
        <tr><th><input type="checkbox" data-select-all="species" title="เลือกทั้งหมด"></th><th>ชนิด</th><th>รหัสจำแนกพันธุ์</th><th>ชื่อ</th><th>ชื่อวิทยาศาสตร์</th><th>จำนวนต้นไม้</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($speciesList as $sp): ?>
        <tr>
          <td><input type="checkbox" class="row-check" data-group="species" name="ids[]" value="<?= (int) $sp['id'] ?>" form="bulkDeleteSpecies"></td>
          <td>
            <?= e($sp['subtype_name'] ?? '—') ?>
            <?php if ($sp['extra_subtype_names']): ?>
              <br><span class="muted-note">+ <?= e($sp['extra_subtype_names']) ?></span>
            <?php endif; ?>
          </td>
          <td><?= e($sp['classification_id'] ?? '') ?></td>
          <td><?= e($sp['name']) ?></td>
          <td><em><?= e($sp['name_scientific'] ?? '') ?></em></td>
          <td><?= (int) $sp['tree_count'] ?></td>
          <td>
            <div class="btn-row">
              <a class="btn-outline btn-sm" href="species_form.php?id=<?= (int) $sp['id'] ?>">แก้ไข</a>
              <?php if ((int) $sp['tree_count'] === 0): ?>
              <form class="inline" method="post" action="species_delete.php" data-confirm="ลบชนิดพันธุ์นี้ใช่หรือไม่?">
                <?= csrfField() ?>
                <input type="hidden" name="id" value="<?= (int) $sp['id'] ?>">
                <button class="btn btn-sm btn-danger" type="submit">ลบ</button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$speciesList): ?>
        <tr><td colspan="7">ยังไม่มีชื่อต้นไม้</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/_confirm_modal.php'; ?>
<script src="../public/assets/js/bulk-select.js"></script>
</body>
</html>
