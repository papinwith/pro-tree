<?php
require_once __DIR__ . '/../includes/auth.php';
requirePermission('admin.manage');

$pdo = db();
$admins = $pdo->query(
    'SELECT a.id, a.username, a.created_at, r.role_key, r.name_th AS role_name
     FROM admins a JOIN roles r ON r.id = a.role_id
     ORDER BY a.username ASC'
)->fetchAll();
$myId = (int) ($_SESSION['admin_id'] ?? 0);
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ผู้ดูแลระบบ — บัญชีผู้ใช้งาน</title>
<link rel="stylesheet" href="../public/assets/css/style.css">
</head>
<body>
<div class="admin-wrap">
  <div class="topbar">
    <h1>บัญชีผู้ใช้งาน</h1>
    <?php require __DIR__ . '/_nav.php'; ?>
  </div>

  <p><a class="btn" href="user_form.php">+ เพิ่มผู้ใช้งาน</a></p>

  <div class="table-scroll">
    <table>
      <thead>
        <tr><th>ชื่อผู้ใช้</th><th>บทบาท</th><th>สร้างเมื่อ</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($admins as $a): ?>
        <tr>
          <td><?= e($a['username']) ?><?= (int) $a['id'] === $myId ? ' <span class="muted-note">(คุณ)</span>' : '' ?></td>
          <td><?= e($a['role_name']) ?></td>
          <td><?= e($a['created_at']) ?></td>
          <td>
            <div class="btn-row">
              <a class="btn-outline btn-sm" href="user_form.php?id=<?= (int) $a['id'] ?>">แก้ไข</a>
              <?php if ((int) $a['id'] !== $myId): ?>
              <form class="inline" method="post" action="user_delete.php" data-confirm="ลบผู้ใช้งานนี้ใช่หรือไม่?">
                <?= csrfField() ?>
                <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                <button class="btn btn-sm btn-danger" type="submit">ลบ</button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$admins): ?>
        <tr><td colspan="4">ยังไม่มีผู้ใช้งาน</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <p class="field-hint mb-lg">ลบบัญชีตัวเองไม่ได้ และระบบจะไม่ให้ลบ "โปรแกรมเมอร์" คนสุดท้าย เพื่อไม่ให้ไม่มีใครเข้าระบบได้เลย</p>
</div>
<?php require __DIR__ . '/_confirm_modal.php'; ?>
</body>
</html>
