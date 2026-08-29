<?php
require_once __DIR__ . '/../includes/auth.php';

startAdminSession();
$pdo = db();

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['dev_bypass_admin_id'])) {
        // Only ever does anything if devLoginBypassAllowed() is true —
        // see includes/auth.php. A forged POST from anywhere else just
        // falls through to the normal "invalid" error below.
        if (devBypassLogin($pdo, (int) $_POST['dev_bypass_admin_id'])) {
            header('Location: ' . adminHomeUrl());
            exit;
        }
        $error = 'เข้าสู่ระบบไม่สำเร็จ';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        if (attemptAdminLogin($pdo, $username, $password)) {
            header('Location: ' . adminHomeUrl());
            exit;
        }
        $error = 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง';
    }
}

$devAdmins = [];
if (devLoginBypassAllowed()) {
    $devAdmins = $pdo->query(
        'SELECT a.id, a.username, r.name_th AS role_name
         FROM admins a JOIN roles r ON r.id = a.role_id
         ORDER BY a.username ASC'
    )->fetchAll();
}
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>เข้าสู่ระบบผู้ดูแล</title>
<link rel="stylesheet" href="../public/assets/css/style.css">
</head>
<body>
<div class="admin-wrap admin-wrap-login">
  <h1>เข้าสู่ระบบผู้ดูแล</h1>
  <?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <label for="username">ชื่อผู้ใช้</label>
    <input type="text" id="username" name="username" required autofocus>
    <label for="password">รหัสผ่าน</label>
    <input type="password" id="password" name="password" required>
    <p><button class="btn" type="submit">เข้าสู่ระบบ</button></p>
  </form>

  <?php if ($devAdmins): ?>
  <div class="history-section">
    <div class="flash error" style="margin-bottom:12px;">⚠ โหมดพัฒนา (DEV_LOGIN_BYPASS) เปิดอยู่ — ห้ามเปิดค่านี้บนเซิร์ฟเวอร์จริง</div>
    <h2>เข้าสู่ระบบทันที (ไม่ใช้รหัสผ่าน)</h2>
    <div class="btn-row">
      <?php foreach ($devAdmins as $a): ?>
        <form method="post" class="inline">
          <input type="hidden" name="dev_bypass_admin_id" value="<?= (int) $a['id'] ?>">
          <button class="btn-outline btn-sm" type="submit"><?= e($a['username']) ?> (<?= e($a['role_name']) ?>)</button>
        </form>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
</div>
</body>
</html>
