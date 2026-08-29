<?php
require_once __DIR__ . '/../includes/auth.php';
requirePermission('category.manage');

$pdo = db();
$categories = getAllCategories($pdo);
$categoriesByCode = array_column($categories, null, 'code');
$subtypes = $pdo->query(
    'SELECT st.*, (SELECT COUNT(*) FROM species s WHERE s.subtype_id = st.id) AS species_count
     FROM subtypes st ORDER BY st.category_code ASC, st.name_th ASC'
)->fetchAll();
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ผู้ดูแลระบบ — ชนิด</title>
<link rel="stylesheet" href="../public/assets/css/style.css">
</head>
<body>
<div class="admin-wrap">
  <div class="topbar">
    <h1>ชนิด</h1>
    <?php require __DIR__ . '/_nav.php'; ?>
  </div>

  <?php if (!$categories): ?>
    <div class="flash error">ยังไม่มีประเภทพืชในระบบ กรุณา<a href="category_form.php">เพิ่มประเภทพืช</a>ก่อน</div>
  <?php endif; ?>

  <div class="btn-row">
    <button class="btn" type="button" onclick="document.getElementById('addSubtypeForm').hidden = false; document.getElementById('subtype_name_th').focus();">+ เพิ่มชนิด</button>
    <form id="bulkDeleteSubtypes" class="inline" method="post" action="subtype_bulk_delete.php" data-confirm="ลบชนิดที่เลือกทั้งหมดใช่หรือไม่? (รายการที่ยังมีชื่อต้นไม้ผูกอยู่จะถูกข้าม)">
      <button class="btn btn-sm btn-danger" type="submit" data-bulk-submit="subtypes" disabled>ลบที่เลือก</button>
    </form>
  </div>

  <form id="addSubtypeForm" method="post" action="subtype_form.php" class="inline-add-form" hidden>
    <label for="subtype_name_th">ชื่อชนิด (ไทย)</label>
    <div class="field-row">
      <input type="text" id="subtype_name_th" name="name_th" required placeholder="เช่น ไม้ผล, ไม้ดอก, ไม้ประดับ">
      <button class="btn btn-sm" type="submit">บันทึก</button>
    </div>
  </form>

  <?php if (isset($_GET['bulk_deleted'])): ?>
    <div class="flash">
      ลบชนิดที่เลือกแล้ว <?= (int) $_GET['bulk_deleted'] ?> รายการ
      <?php if ((int) ($_GET['bulk_skipped'] ?? 0) > 0): ?>
        (ข้าม <?= (int) $_GET['bulk_skipped'] ?> รายการที่ยังมีชื่อต้นไม้ผูกอยู่ — ใช้ปุ่ม "ลบ" รายแถวเพื่อย้ายชื่อต้นไม้ก่อนลบ)
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="table-scroll">
    <table>
      <thead>
        <tr><th><input type="checkbox" data-select-all="subtypes" title="เลือกทั้งหมด"></th><th>ชนิด</th><th>จำนวนชื่อต้นไม้</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($subtypes as $st): ?>
        <tr>
          <td><input type="checkbox" class="row-check" data-group="subtypes" name="ids[]" value="<?= (int) $st['id'] ?>" form="bulkDeleteSubtypes"></td>
          <td><?= e($st['name_th']) ?></td>
          <td><?= (int) $st['species_count'] ?></td>
          <td>
            <a class="btn-outline btn-sm" href="subtype_form.php?id=<?= (int) $st['id'] ?>">แก้ไข</a>
            <?php if ((int) $st['species_count'] === 0): ?>
            <form class="inline" method="post" action="subtype_delete.php" data-confirm="ลบชนิดนี้ใช่หรือไม่?">
              <input type="hidden" name="id" value="<?= (int) $st['id'] ?>">
              <button class="btn btn-sm btn-danger" type="submit">ลบ</button>
            </form>
            <?php else: ?>
            <form class="inline" method="post" action="subtype_delete.php" data-confirm="ย้ายชื่อต้นไม้ทั้งหมดไปชนิดที่เลือก แล้วลบชนิดนี้ใช่หรือไม่?">
              <input type="hidden" name="id" value="<?= (int) $st['id'] ?>">
              <select name="reassign_to" required title="ย้ายชื่อต้นไม้ไปชนิดนี้ก่อนลบ">
                <option value="">— ย้ายชื่อต้นไม้ไปที่ —</option>
                <?php foreach ($subtypes as $other): if ((int) $other['id'] === (int) $st['id']) continue; ?>
                  <option value="<?= (int) $other['id'] ?>"><?= $other['category_code'] !== null ? e($categoriesByCode[$other['category_code']]['name_th'] ?? $other['category_code']) . ' — ' : '' ?><?= e($other['name_th']) ?></option>
                <?php endforeach; ?>
              </select>
              <button class="btn btn-sm btn-danger" type="submit">ลบ</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$subtypes): ?>
        <tr><td colspan="4">ยังไม่มีชนิด</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <p class="field-hint mb-lg">ชนิดที่เพิ่มใหม่จะไปแสดงในตัวเลือก "ชนิด" ตอนเพิ่ม/แก้ไขชื่อต้นไม้โดยอัตโนมัติ — โครงสร้าง: ประเภท &rarr; ชนิด &rarr; ชื่อต้นไม้</p>
</div>
<?php require __DIR__ . '/_confirm_modal.php'; ?>
<script src="../public/assets/js/bulk-select.js"></script>
</body>
</html>
