<?php
require_once __DIR__ . '/../includes/auth.php';
requirePermission('category.manage');

$pdo = db();
$errors = [];
$categories = getAllCategories($pdo);

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$subtype = $id ? getSubtypeById($pdo, $id) : null;
if ($id && !$subtype) {
    http_response_code(404);
    exit('ไม่พบชนิดนี้');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $nameTh = trim($_POST['name_th'] ?? '');
    if ($nameTh === '') {
        $errors[] = 'กรุณาระบุชื่อ';
    }

    // Category is only editable once a subtype already exists — "เพิ่มชนิด"
    // (create) asks for just the name; the category link gets made later,
    // either here on edit or automatically the first time this subtype is
    // picked for a species (see species_form.php).
    $categoryCode = null;
    if ($subtype) {
        $categoryCode = trim($_POST['category_code'] ?? '') ?: null;
        if ($categoryCode !== null && !getCategoryByCode($pdo, $categoryCode)) {
            $errors[] = 'กรุณาเลือกประเภทพืชให้ถูกต้อง';
        }
    }

    if (!$errors) {
        if ($subtype) {
            $categoryChanged = $categoryCode !== $subtype['category_code'];
            $pdo->prepare('UPDATE subtypes SET category_code = :cc, name_th = :name_th WHERE id = :id')
                ->execute(['cc' => $categoryCode, 'name_th' => $nameTh, 'id' => $subtype['id']]);

            // The subtype's category changed — every species under it (and
            // therefore every one of their trees) needs to follow, since
            // species.category_code must always match its subtype's
            // category (that's what still feeds trees.plant_code). If the
            // category was cleared (set back to none), leave existing
            // species' category_code alone — they keep the last known-good
            // value rather than being left without one.
            if ($categoryChanged && $categoryCode !== null) {
                $speciesStmt = $pdo->prepare('SELECT id FROM species WHERE subtype_id = :sid');
                $speciesStmt->execute(['sid' => $subtype['id']]);
                foreach ($speciesStmt->fetchAll(PDO::FETCH_COLUMN) as $speciesId) {
                    $newSpeciesCode = nextSpeciesCode($pdo, $categoryCode);
                    $pdo->prepare('UPDATE species SET category_code = :cc, species_code = :sc WHERE id = :id')
                        ->execute(['cc' => $categoryCode, 'sc' => $newSpeciesCode, 'id' => $speciesId]);
                    $treeStmt = $pdo->prepare('SELECT id FROM trees WHERE species_id = :sid');
                    $treeStmt->execute(['sid' => (int) $speciesId]);
                    foreach ($treeStmt->fetchAll(PDO::FETCH_COLUMN) as $treeId) {
                        recomputeTreePlantCode($pdo, (int) $treeId);
                    }
                }
            }
        } else {
            $pdo->prepare('INSERT INTO subtypes (category_code, name_th) VALUES (NULL, :name_th)')
                ->execute(['name_th' => $nameTh]);
        }
        header('Location: subtypes.php');
        exit;
    }
    $subtype = array_merge($subtype ?? [], ['category_code' => $categoryCode, 'name_th' => $nameTh]);
}
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $subtype ? 'แก้ไข' : 'เพิ่ม' ?>ชนิด</title>
<link rel="stylesheet" href="../public/assets/css/style.css">
</head>
<body>
<div class="admin-wrap admin-wrap-narrow">
  <p><a class="btn-outline btn-sm" href="subtypes.php">&larr; กลับไปหน้าชนิด</a></p>
  <h1><?= $subtype ? 'แก้ไข' : 'เพิ่ม' ?>ชนิด</h1>
  <p class="field-hint">กรอกเป็นภาษาไทยอย่างเดียว — ระบบจะแปลเป็นอังกฤษ/จีนให้อัตโนมัติตอนผู้เข้าชมเปิดหน้าต้นไม้ด้วยภาษานั้น<?= AI_ENABLED ? '' : ' (ต้องตั้งค่า Gemini API key ก่อน — ดูที่หน้า <a href="settings.php#ai-translation">ตั้งค่า</a>)' ?></p>

  <?php foreach ($errors as $err): ?>
    <div class="flash error"><?= e($err) ?></div>
  <?php endforeach; ?>

  <form method="post">
    <?= csrfField() ?>
    <label for="name_th">ชื่อชนิด (ไทย)</label>
    <input type="text" id="name_th" name="name_th" value="<?= e($subtype['name_th'] ?? '') ?>" required autofocus placeholder="เช่น ไม้ผล, ไม้ดอก, ไม้ประดับ">

    <?php if ($subtype): ?>
    <label for="category_code">ประเภทพืช (ไม่บังคับ — กำหนดตอนนี้หรือปล่อยว่างแล้วระบบจะผูกให้อัตโนมัติตอนเลือกชนิดนี้ไปใช้กับชื่อต้นไม้)</label>
    <select id="category_code" name="category_code">
      <option value="">— ยังไม่กำหนด —</option>
      <?php foreach ($categories as $cat): ?>
        <option value="<?= e($cat['code']) ?>" <?= ($subtype['category_code'] ?? '') === $cat['code'] ? 'selected' : '' ?>>
          <?= e($cat['name_th']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <?php else: ?>
    <p class="field-hint">ไม่ต้องเลือกประเภทพืชตอนนี้ — ระบบจะผูกให้อัตโนมัติครั้งแรกที่เลือกชนิดนี้ไปใช้กับชื่อต้นไม้ หรือมากำหนดทีหลังได้ที่หน้าแก้ไข</p>
    <?php endif; ?>

    <p><button class="btn" type="submit">บันทึก</button></p>
  </form>
</div>
</body>
</html>
