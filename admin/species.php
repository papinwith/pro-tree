<?php
require_once __DIR__ . '/../includes/auth.php';
requirePermission('species.manage');

$pdo = db();
$speciesList = $pdo->query(
    'SELECT sp.*, st.name_th AS subtype_name, (SELECT COUNT(*) FROM trees t WHERE t.species_id = sp.id) AS tree_count,
            (SELECT COUNT(*) FROM planting_plans pp WHERE pp.species_id = sp.id) AS plan_count,
            (SELECT COUNT(*) FROM sale_transactions sl WHERE sl.species_id = sp.id) AS sale_count,
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
    <form id="bulkDeleteSpecies" class="inline" method="post" action="species_bulk_delete.php" data-confirm="ลบชนิดพันธุ์ที่เลือกทั้งหมดใช่หรือไม่? (รายการที่ยังมีต้นไม้ แผนปลูก หรือประวัติการขายผูกอยู่จะถูกข้าม — ถ้าต้องการลบรายการเหล่านั้น ให้ใช้ปุ่ม ‘ย้ายแล้วลบ’ ที่แถวของแต่ละรายการ)">
      <?= csrfField() ?>
      <button class="btn btn-sm btn-danger" type="submit" data-bulk-submit="species" disabled>ลบที่เลือก</button>
    </form>
  </div>

  <?php if (isset($_GET['reassign_error'])): ?>
    <div class="flash error">
      <?= $_GET['reassign_error'] === 'conflict'
        ? 'ย้ายไม่สำเร็จ — รหัสต้นไม้ซ้ำกับต้นที่มีอยู่ในชนิดพันธุ์ปลายทาง (ไม่มีข้อมูลถูกเปลี่ยนแปลง) กรุณาลองใหม่หรือเลือกชนิดพันธุ์อื่น'
        : 'ย้ายไม่สำเร็จ — ชนิดพันธุ์ที่เลือกไม่ถูกต้อง' ?>
    </div>
  <?php endif; ?>

  <?php if (isset($_GET['bulk_deleted'])): ?>
    <div class="flash<?= (int) ($_GET['bulk_skipped'] ?? 0) > 0 ? ' warning' : '' ?>">
      ลบชนิดพันธุ์ที่เลือกแล้ว <?= (int) $_GET['bulk_deleted'] ?> รายการ
      <?php if ((int) ($_GET['bulk_skipped'] ?? 0) > 0): ?>
        (ข้าม <?= (int) $_GET['bulk_skipped'] ?> รายการที่ยังมีต้นไม้ แผนปลูก หรือประวัติการขายผูกอยู่ — ใช้ปุ่ม ‘ย้ายแล้วลบ’ ที่แถวของรายการนั้น)
      <?php endif; ?>
      <?php if (isset($_GET['moved'])): ?>
        — ย้ายต้นไม้ <?= (int) $_GET['moved'] ?> ต้นไปยังชนิดพันธุ์ที่เลือกแล้ว
        <?php if ((int) ($_GET['recoded'] ?? 0) > 0): ?>
          รหัสต้นไม้ (15 หลัก) เปลี่ยน <?= (int) $_GET['recoded'] ?> ต้น ป้ายรหัสที่พิมพ์ไว้อาจต้องพิมพ์ใหม่ (ลิงก์ QR ยังใช้ได้ตามเดิม)
        <?php endif; ?>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="table-scroll">
    <table>
      <thead>
        <tr><th><input type="checkbox" data-select-all="species" title="เลือกทั้งหมด"></th><th>รูป</th><th>ชนิด</th><th>รหัสจำแนกพันธุ์</th><th>ชื่อ</th><th>ชื่อวิทยาศาสตร์</th><th>จำนวนต้นไม้</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($speciesList as $sp): ?>
        <tr>
          <td><input type="checkbox" class="row-check" data-group="species" name="ids[]" value="<?= (int) $sp['id'] ?>" form="bulkDeleteSpecies"></td>
          <td>
            <?php if (!empty($sp['image_path'])): ?>
              <img src="../public/<?= e($sp['image_path']) ?>" alt="" class="table-thumb" loading="lazy">
            <?php else: ?>
              <span class="muted-note">—</span>
            <?php endif; ?>
          </td>
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
              <?php if ((int) $sp['tree_count'] === 0 && (int) $sp['plan_count'] === 0 && (int) $sp['sale_count'] === 0): ?>
              <form class="inline" method="post" action="species_delete.php" data-confirm="ลบชนิดพันธุ์นี้ใช่หรือไม่?">
                <?= csrfField() ?>
                <input type="hidden" name="id" value="<?= (int) $sp['id'] ?>">
                <button class="btn btn-sm btn-danger" type="submit">ลบ</button>
              </form>
              <?php elseif (count($speciesList) > 1): ?>
              <?php // Still in use — can't be deleted outright; offer "move everything to another species, then delete" (same as the subtype/category lists). ?>
              <form class="inline" method="post" action="species_delete.php" data-confirm="ย้ายต้นไม้ แผนปลูก สต็อก และประวัติการขายทั้งหมดของชนิดพันธุ์นี้ไปยังชนิดพันธุ์ที่เลือก แล้วลบชนิดพันธุ์นี้ใช่หรือไม่? (รหัสต้นไม้ 15 หลักจะเปลี่ยนตามชนิดพันธุ์ใหม่)">
                <?= csrfField() ?>
                <input type="hidden" name="id" value="<?= (int) $sp['id'] ?>">
                <select name="reassign_to" required title="ย้ายต้นไม้ แผนปลูก สต็อก และประวัติการขายไปชนิดพันธุ์นี้ก่อนลบ">
                  <option value="">— ย้ายทั้งหมดไปที่ —</option>
                  <?php foreach ($speciesList as $other): if ((int) $other['id'] === (int) $sp['id']) continue; ?>
                    <option value="<?= (int) $other['id'] ?>"><?= e($other['name']) ?><?= !empty($other['name_scientific']) ? ' (' . e($other['name_scientific']) . ')' : '' ?></option>
                  <?php endforeach; ?>
                </select>
                <button class="btn btn-sm btn-danger" type="submit">ย้ายแล้วลบ</button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$speciesList): ?>
        <tr><td colspan="8">ยังไม่มีชื่อต้นไม้</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/_confirm_modal.php'; ?>
<script src="../public/assets/js/bulk-select.js"></script>
</body>
</html>
