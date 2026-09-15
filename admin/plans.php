<?php
require_once __DIR__ . '/../includes/auth.php';
requirePermission('plan.manage');

$pdo = db();
$plans = getAllPlantingPlans($pdo);
$statusLabels = ['pending' => 'รอดำเนินการ', 'in_progress' => 'กำลังดำเนินการ', 'completed' => 'ปลูกแล้ว', 'cancelled' => 'ยกเลิก'];
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ผู้ดูแลระบบ — แผนการปลูก</title>
<link rel="stylesheet" href="../public/assets/css/style.css">
</head>
<body>
<div class="admin-wrap">
  <div class="topbar">
    <h1>แผนการปลูกต้นไม้</h1>
    <?php require __DIR__ . '/_nav.php'; ?>
  </div>

  <p class="field-hint mb-lg">บันทึกจุด/โซนที่ตั้งใจจะปลูกในอนาคต ก่อนที่จะลงต้นไม้จริง — เมื่อปลูกจริงแล้วให้ไปเพิ่มต้นไม้ตามปกติที่หน้า <a href="tree_form.php">เพิ่มต้นไม้</a> แล้วค่อยมาปิดแผนนี้</p>

  <div class="btn-row mb-lg">
    <a class="btn" href="plan_form.php">+ เพิ่มแผนการปลูก</a>
  </div>

  <div class="table-scroll">
    <table>
      <thead>
        <tr><th>โซน</th><th>ชนิดพันธุ์</th><th>จำนวน</th><th>วันที่ตั้งเป้า</th><th>พิกัด</th><th>สถานะ</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($plans as $p): ?>
        <tr>
          <td><?= e($p['zone_name']) ?></td>
          <td><?= e($p['species_name'] ?? '— ยังไม่ระบุ —') ?></td>
          <td><?= (int) $p['planned_quantity'] ?></td>
          <td><?= e($p['target_date'] ?? '—') ?></td>
          <td><?= ($p['latitude'] !== null && $p['longitude'] !== null) ? e($p['latitude'] . ', ' . $p['longitude']) : '—' ?></td>
          <td><?= e($statusLabels[$p['status']] ?? $p['status']) ?></td>
          <td>
            <div class="btn-row">
              <a class="btn-outline btn-sm" href="plan_form.php?id=<?= (int) $p['id'] ?>">แก้ไข</a>
              <form class="inline" method="post" action="plan_delete.php" data-confirm="ลบแผนการปลูกนี้ใช่หรือไม่?">
                <?= csrfField() ?>
                <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                <button class="btn btn-sm btn-danger" type="submit">ลบ</button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$plans): ?>
        <tr><td colspan="7">ยังไม่มีแผนการปลูก</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/_confirm_modal.php'; ?>
</body>
</html>
