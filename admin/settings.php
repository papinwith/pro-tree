<?php
require_once __DIR__ . '/../includes/auth.php';
requirePermission('settings.manage');

$pdo = db();
$saved = false;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $previousImage = getSetting($pdo, 'default_map_image', '');
    $previousLogo = getSetting($pdo, 'site_logo', '');
    $newImage = null;
    $newLogo = null;

    try {
        $newImage = saveUploadedImage($_FILES['default_map_image_file'] ?? [], 'maps');
        $newLogo = saveUploadedImage($_FILES['site_logo_file'] ?? [], 'logo');
    } catch (RuntimeException $e) {
        $errors[] = $e->getMessage();
    }

    $mapUrlInput = trim($_POST['default_map_url'] ?? '');
    $mapUrl = validatePublicUrl($mapUrlInput);
    if ($mapUrlInput !== '' && $mapUrl === null) {
        $errors[] = 'URL แผนที่ไม่ถูกต้อง (ต้องขึ้นต้นด้วย http:// หรือ https://)';
    }

    // Shown to visitors on the public tree page — free text, so staff can
    // include a phone number, LINE ID, and hours however reads naturally
    // rather than forcing separate fields per contact channel.
    $contactPhone = trim($_POST['contact_phone'] ?? '');
    $contactLine = trim($_POST['contact_line'] ?? '');
    $contactAddress = trim($_POST['contact_address'] ?? '');
    $openingHours = trim($_POST['opening_hours'] ?? '');

    if (!$errors) {
        // An uploaded file wins; otherwise keep whatever the text field has
        // (typed URL/path, or the existing value if left untouched).
        $imageValue = $newImage ?? trim($_POST['default_map_image'] ?? '');

        setSetting($pdo, 'default_map_image', $imageValue);
        setSetting($pdo, 'default_map_url', $mapUrl ?? '');
        setSetting($pdo, 'contact_phone', $contactPhone);
        setSetting($pdo, 'contact_line', $contactLine);
        setSetting($pdo, 'contact_address', $contactAddress);
        setSetting($pdo, 'opening_hours', $openingHours);
        if ($newLogo !== null) {
            setSetting($pdo, 'site_logo', $newLogo);
        }

        if ($newImage !== null && $previousImage !== '' && str_starts_with($previousImage, 'assets/uploads/maps/')) {
            deletePublicFile($previousImage);
        }
        if ($newLogo !== null && $previousLogo !== '' && str_starts_with($previousLogo, 'assets/uploads/logo/')) {
            deletePublicFile($previousLogo);
        }

        $saved = true;
    }
}

$defaultMapImage = getSetting($pdo, 'default_map_image', '');
$defaultMapUrl = $errors ? ($mapUrlInput ?? '') : getSetting($pdo, 'default_map_url', '');
$siteLogo = getSetting($pdo, 'site_logo', '');
$contactPhone = $errors ? ($contactPhone ?? '') : getSetting($pdo, 'contact_phone', '');
$contactLine = $errors ? ($contactLine ?? '') : getSetting($pdo, 'contact_line', '');
$contactAddress = $errors ? ($contactAddress ?? '') : getSetting($pdo, 'contact_address', '');
$openingHours = $errors ? ($openingHours ?? '') : getSetting($pdo, 'opening_hours', '');
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ผู้ดูแลระบบ — ตั้งค่า</title>
<link rel="stylesheet" href="../public/assets/css/style.css">
</head>
<body>
<div class="admin-wrap admin-wrap-narrow">
  <p><a class="btn-outline btn-sm" href="dashboard.php">&larr; กลับไปหน้าต้นไม้</a></p>
  <h1>ตั้งค่า</h1>

  <?php if ($saved): ?><div class="flash">บันทึกการตั้งค่าแล้ว</div><?php endif; ?>
  <?php foreach ($errors as $err): ?>
    <div class="flash error"><?= e($err) ?></div>
  <?php endforeach; ?>

  <form method="post" enctype="multipart/form-data">
    <?= csrfField() ?>
    <label for="site_logo_file">โลโก้เว็บไซต์ / งาน</label>
    <?php if ($siteLogo): ?>
      <p><img src="../public/<?= e(ltrim($siteLogo, '/')) ?>" alt="" class="preview-thumb"></p>
    <?php endif; ?>
    <input type="file" id="site_logo_file" name="site_logo_file" accept="image/jpeg,image/png,image/gif,image/webp">

    <label for="default_map_image_file">รูปแบนเนอร์แผนที่เริ่มต้น</label>
    <?php if ($defaultMapImage): ?>
      <p><img src="../public/<?= e(ltrim($defaultMapImage, '/')) ?>" alt="" class="preview-wide"></p>
    <?php endif; ?>
    <input type="file" id="default_map_image_file" name="default_map_image_file" accept="image/jpeg,image/png,image/gif,image/webp">

    <label for="default_map_image">…หรือระบุ URL/พาธแทนการอัปโหลด</label>
    <input type="text" id="default_map_image" name="default_map_image" value="<?= e($defaultMapImage) ?>">

    <label for="default_map_url">URL ปลายทางเมื่อคลิกแผนที่</label>
    <input type="url" id="default_map_url" name="default_map_url" value="<?= e($defaultMapUrl) ?>">
    <p class="field-hint">ยังไม่ถูกใช้งานในหน้าเว็บปัจจุบัน — ตอนนี้แผนที่แสดงเป็น popup ขยายภาพเมื่อแตะ ไม่ได้ลิงก์ออกไปยัง URL นี้</p>

    <label for="contact_phone">เบอร์โทรติดต่อ</label>
    <input type="text" id="contact_phone" name="contact_phone" value="<?= e($contactPhone) ?>" placeholder="เช่น 081-234-5678">

    <label for="contact_line">LINE ID</label>
    <input type="text" id="contact_line" name="contact_line" value="<?= e($contactLine) ?>" placeholder="เช่น @treeshop">

    <label for="contact_address">ที่อยู่ร้าน/สวน</label>
    <textarea id="contact_address" name="contact_address" rows="2"><?= e($contactAddress) ?></textarea>

    <label for="opening_hours">เวลาทำการ</label>
    <input type="text" id="opening_hours" name="opening_hours" value="<?= e($openingHours) ?>" placeholder="เช่น ทุกวัน 08:00-17:00">

    <p><button class="btn" type="submit">บันทึก</button></p>
  </form>

  <p class="field-hint">ต้นไม้แต่ละต้นสามารถกำหนดค่าเฉพาะของตัวเองแทนค่านี้ได้ในแบบฟอร์มแก้ไขต้นไม้ — ข้อมูลติดต่อ/เวลาทำการด้านบนจะแสดงในหน้าต้นไม้ที่ลูกค้าเห็นตอนสแกน QR</p>

  <section id="ai-translation" class="history-section">
    <h2>AI (Gemini) — คำแปล และระบุชนิดต้นไม้จากรูป</h2>
    <?php if (AI_ENABLED): ?>
      <div class="flash">✓ ตั้งค่า Gemini API key แล้ว — ใช้โมเดล <code><?= e(GEMINI_MODEL) ?></code></div>
    <?php else: ?>
      <div class="flash error">✗ ยังไม่ได้ตั้งค่า Gemini API key</div>
    <?php endif; ?>
    <p class="field-hint">
      คีย์นี้ต้องตั้งค่าในไฟล์เท่านั้น (ไม่มีช่องกรอกในหน้าเว็บ และไม่เก็บลงฐานข้อมูล เพื่อไม่ให้หลุดปนไปกับข้อมูลสำรอง/export):
    </p>
    <ol class="field-hint">
      <li>คัดลอกไฟล์ <code>config/local.example.php</code> เป็น <code>config/local.php</code></li>
      <li>เปิด <code>config/local.php</code> แล้วแทนที่ <code>paste-your-gemini-api-key-here</code> ด้วยคีย์จริง</li>
      <li>บันทึกไฟล์ — ไม่ต้องรีสตาร์ท Apache ก็ใช้ได้ทันที</li>
    </ol>
    <p class="field-hint">คีย์เดียวกันนี้ใช้กับปุ่ม "ให้ AI ช่วยระบุชนิดต้นไม้จากรูป" ในหน้าเพิ่ม/แก้ไขชนิดพันธุ์และต้นไม้ด้วย (บนโฮสต์อย่าง Railway ให้ตั้งเป็น environment variable ชื่อ <code>GEMINI_API_KEY</code> แทน)</p>
    <p class="field-hint muted-note"><code>config/local.php</code> อยู่ใน <code>.gitignore</code> แล้ว จะไม่ถูกคอมมิตเข้า git โดยไม่ตั้งใจ</p>
  </section>
</div>
</body>
</html>
