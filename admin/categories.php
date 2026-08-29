<?php
require_once __DIR__ . '/../includes/auth.php';
requirePermission('category.manage');

$pdo = db();
$categories = $pdo->query(
    'SELECT c.*, (SELECT COUNT(*) FROM species s WHERE s.category_code = c.code) AS species_count
     FROM categories c ORDER BY c.code ASC'
)->fetchAll();
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ผู้ดูแลระบบ — ประเภทพืช</title>
<link rel="stylesheet" href="../public/assets/css/style.css">
</head>
<body>
<div class="admin-wrap">
  <div class="topbar">
    <h1>ประเภทพืช</h1>
    <?php require __DIR__ . '/_nav.php'; ?>
  </div>

  <div class="btn-row">
    <a class="btn" href="category_form.php">+ เพิ่มประเภทพืช</a>
    <form id="bulkDeleteCategories" class="inline" method="post" action="category_bulk_delete.php" data-confirm="ลบประเภทพืชที่เลือกทั้งหมดใช่หรือไม่? (รายการที่ยังมีชนิดพันธุ์ผูกอยู่จะถูกข้าม)">
      <button class="btn btn-sm btn-danger" type="submit" data-bulk-submit="categories" disabled>ลบที่เลือก</button>
    </form>
  </div>

  <?php if (isset($_GET['bulk_deleted'])): ?>
    <div class="flash">
      ลบประเภทพืชที่เลือกแล้ว <?= (int) $_GET['bulk_deleted'] ?> รายการ
      <?php if ((int) ($_GET['bulk_skipped'] ?? 0) > 0): ?>
        (ข้าม <?= (int) $_GET['bulk_skipped'] ?> รายการที่ยังมีชนิดพันธุ์ผูกอยู่ — ใช้ปุ่ม "ลบ" รายแถวเพื่อย้ายชนิดพันธุ์ก่อนลบ)
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="table-scroll">
    <table>
      <thead>
        <tr><th><input type="checkbox" data-select-all="categories" title="เลือกทั้งหมด"></th><th>ชื่อ</th><th>จำนวนชนิดพันธุ์</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($categories as $c): ?>
        <tr>
          <td><input type="checkbox" class="row-check" data-group="categories" name="codes[]" value="<?= e($c['code']) ?>" form="bulkDeleteCategories"></td>
          <td><?= e($c['name_th']) ?></td>
          <td><?= (int) $c['species_count'] ?></td>
          <td>
            <a class="btn-outline btn-sm" href="category_form.php?code=<?= urlencode($c['code']) ?>">แก้ไข</a>
            <?php if ((int) $c['species_count'] === 0): ?>
            <form class="inline" method="post" action="category_delete.php" data-confirm="ลบประเภทพืชนี้ใช่หรือไม่?">
              <input type="hidden" name="code" value="<?= e($c['code']) ?>">
              <button class="btn btn-sm btn-danger" type="submit">ลบ</button>
            </form>
            <?php else: ?>
            <form class="inline" method="post" action="category_delete.php" data-confirm="ย้ายชนิดพันธุ์ทั้งหมดไปประเภทที่เลือก แล้วลบประเภทนี้ใช่หรือไม่?">
              <input type="hidden" name="code" value="<?= e($c['code']) ?>">
              <select name="reassign_to" required title="ย้ายชนิดพันธุ์ไปประเภทนี้ก่อนลบ">
                <option value="">— ย้ายชนิดพันธุ์ไปที่ —</option>
                <?php foreach ($categories as $other): if ($other['code'] === $c['code']) continue; ?>
                  <option value="<?= e($other['code']) ?>"><?= e($other['name_th']) ?></option>
                <?php endforeach; ?>
              </select>
              <button class="btn btn-sm btn-danger" type="submit">ลบ</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$categories): ?>
        <tr><td colspan="4">ยังไม่มีประเภทพืช</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <p class="field-hint mb-lg">ประเภทพืชที่เพิ่มใหม่จะไปแสดงในตัวเลือก "ประเภทพืช" ตอนเพิ่ม/แก้ไขชนิดพันธุ์โดยอัตโนมัติ</p>
</div>
<?php require __DIR__ . '/_confirm_modal.php'; ?>
<script src="../public/assets/js/bulk-select.js"></script>
</body>
</html>
