<?php
require_once __DIR__ . '/../includes/auth.php';
requirePermission('admin.manage');

$pdo = db();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$admin = null;
if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM admins WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $admin = $stmt->fetch();
    if (!$admin) {
        http_response_code(404);
        exit('ไม่พบผู้ใช้งานนี้');
    }
}

$roles = $pdo->query('SELECT id, role_key, name_th FROM roles ORDER BY id ASC')->fetchAll();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $roleId = (int) ($_POST['role_id'] ?? 0);

    if ($username === '') {
        $errors[] = 'กรุณาระบุชื่อผู้ใช้';
    }
    if (!$id && $password === '') {
        $errors[] = 'กรุณาตั้งรหัสผ่านสำหรับผู้ใช้งานใหม่';
    }
    if ($password !== '' && strlen($password) < 8) {
        $errors[] = 'รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร';
    }
    if (!in_array($roleId, array_column($roles, 'id'), true)) {
        $errors[] = 'กรุณาเลือกบทบาทให้ถูกต้อง';
    }
    if ($username !== '') {
        $dupStmt = $pdo->prepare('SELECT id FROM admins WHERE username = :u AND id != :id');
        $dupStmt->execute(['u' => $username, 'id' => $id]);
        if ($dupStmt->fetchColumn() !== false) {
            $errors[] = "ชื่อผู้ใช้ \"$username\" ถูกใช้แล้ว";
        }
    }
    // Changing your own role away from Programmer would lock you out of
    // this very page — block it here rather than let it happen silently.
    if ($id && $id === (int) ($_SESSION['admin_id'] ?? 0)) {
        $currentRoleKey = $pdo->prepare('SELECT role_key FROM roles WHERE id = :id');
        $currentRoleKey->execute(['id' => $roleId]);
        if ($currentRoleKey->fetchColumn() !== 'programmer') {
            $errors[] = 'เปลี่ยนบทบาทของบัญชีตัวเองออกจาก "โปรแกรมเมอร์" ไม่ได้ (จะทำให้เข้าหน้านี้ไม่ได้อีก)';
        }
    }

    if (!$errors) {
        try {
            if ($id) {
                if ($password !== '') {
                    $stmt = $pdo->prepare('UPDATE admins SET username=:u, password_hash=:p, role_id=:r WHERE id=:id');
                    $stmt->execute(['u' => $username, 'p' => password_hash($password, PASSWORD_BCRYPT), 'r' => $roleId, 'id' => $id]);
                } else {
                    $stmt = $pdo->prepare('UPDATE admins SET username=:u, role_id=:r WHERE id=:id');
                    $stmt->execute(['u' => $username, 'r' => $roleId, 'id' => $id]);
                }
            } else {
                $stmt = $pdo->prepare('INSERT INTO admins (username, password_hash, role_id) VALUES (:u, :p, :r)');
                $stmt->execute(['u' => $username, 'p' => password_hash($password, PASSWORD_BCRYPT), 'r' => $roleId]);
            }
            header('Location: users.php');
            exit;
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $errors[] = 'ชื่อผู้ใช้นี้เพิ่งถูกใช้โดยการบันทึกอื่น กรุณาลองใหม่อีกครั้ง';
            } else {
                throw $e;
            }
        }
    }

    $admin = array_merge($admin ?? [], ['username' => $username, 'role_id' => $roleId]);
}

$v = fn($key, $default = '') => e((string) ($admin[$key] ?? $default));
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $id ? 'แก้ไข' : 'เพิ่ม' ?>ผู้ใช้งาน</title>
<link rel="stylesheet" href="../public/assets/css/style.css">
</head>
<body>
<div class="admin-wrap admin-wrap-narrow">
  <p><a class="btn-outline btn-sm" href="users.php">&larr; กลับไปหน้าบัญชีผู้ใช้งาน</a></p>
  <h1><?= $id ? 'แก้ไขผู้ใช้งาน' : 'เพิ่มผู้ใช้งาน' ?></h1>

  <?php foreach ($errors as $err): ?>
    <div class="flash error"><?= e($err) ?></div>
  <?php endforeach; ?>

  <form method="post">
    <label for="username">ชื่อผู้ใช้</label>
    <input type="text" id="username" name="username" value="<?= $v('username') ?>" required>

    <label for="password"><?= $id ? 'รหัสผ่านใหม่ (เว้นว่างถ้าไม่ต้องการเปลี่ยน)' : 'รหัสผ่าน' ?></label>
    <input type="password" id="password" name="password" <?= $id ? '' : 'required' ?> minlength="8">

    <label for="role_id">บทบาท</label>
    <select id="role_id" name="role_id" required>
      <?php foreach ($roles as $r): ?>
        <option value="<?= (int) $r['id'] ?>" <?= (int) ($admin['role_id'] ?? 0) === (int) $r['id'] ? 'selected' : '' ?>>
          <?= e($r['name_th']) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <p><button class="btn" type="submit">บันทึก</button></p>
  </form>
</div>
</body>
</html>
