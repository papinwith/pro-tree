<?php
require_once __DIR__ . '/../includes/auth.php';
requirePermission('category.manage');

$pdo = db();
$errors = [];

$code = trim($_GET['code'] ?? '');
$category = $code !== '' ? getCategoryByCode($pdo, $code) : null;
if ($code !== '' && !$category) {
    http_response_code(404);
    exit('ไม่พบประเภทพืชนี้');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $nameTh = trim($_POST['name_th'] ?? '');
    if ($nameTh === '') {
        $errors[] = 'กรุณาระบุชื่อ';
    }

    if (!$errors) {
        if ($category) {
            $pdo->prepare('UPDATE categories SET name_th = :name_th WHERE code = :code')
                ->execute(['name_th' => $nameTh, 'code' => $category['code']]);
        } else {
            $newCode = nextCategoryCode($pdo);
            $pdo->prepare('INSERT INTO categories (code, name_th) VALUES (:code, :name_th)')
                ->execute(['code' => $newCode, 'name_th' => $nameTh]);
        }
        header('Location: categories.php');
        exit;
    }
    $category = array_merge($category ?? [], ['name_th' => $nameTh]);
}
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $category ? 'แก้ไข' : 'เพิ่ม' ?>ประเภทพืช</title>
<link rel="stylesheet" href="../public/assets/css/style.css">
</head>
<body>
<div class="admin-wrap admin-wrap-narrow">
  <p><a class="btn-outline btn-sm" href="categories.php">&larr; กลับไปหน้าประเภทพืช</a></p>
  <h1><?= $category ? 'แก้ไข' : 'เพิ่ม' ?>ประเภทพืช</h1>
  <p class="field-hint">กรอกเป็นภาษาไทยอย่างเดียว — ระบบจะแปลเป็นอังกฤษ/จีนให้อัตโนมัติตอนผู้เข้าชมเปิดหน้าต้นไม้ด้วยภาษานั้น<?= AI_ENABLED ? '' : ' (ต้องตั้งค่า Gemini API key ก่อน — ดูที่หน้า <a href="settings.php#ai-translation">ตั้งค่า</a>)' ?></p>

  <?php foreach ($errors as $err): ?>
    <div class="flash error"><?= e($err) ?></div>
  <?php endforeach; ?>

  <form method="post">
    <?= csrfField() ?>
    <label for="name_th">ชื่อ (ไทย)</label>
    <input type="text" id="name_th" name="name_th" value="<?= e($category['name_th'] ?? ($_POST['name_th'] ?? '')) ?>" required autofocus>

    <p><button class="btn" type="submit">บันทึก</button></p>
  </form>
</div>
</body>
</html>
