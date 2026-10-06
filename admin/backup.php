<?php
require_once __DIR__ . '/../includes/auth.php';
requirePermission('settings.manage');
require_once __DIR__ . '/../includes/full_backup.php';

$pdo = db();
$restoreResult = null;
$restoreError = null;

if (($_GET['download'] ?? '') === 'full') {
    // The whole site: database + photos in one ZIP, streamed so a big backup never sits in memory.
    session_write_close();
    @set_time_limit(0);
    ignore_user_abort(false);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $filename = 'tree_qr_system-full-backup-' . date('Y-m-d_His') . '.zip';
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    header('X-Accel-Buffering: no');
    try {
        streamFullBackupZip($pdo, fopen('php://output', 'wb'));
    } catch (Throwable $e) {
        error_log('full backup failed: ' . $e->getMessage());   // the download is cut short; the ZIP then has no end and will not open
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $file = $_FILES['backup_zip'] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        $restoreError = 'อัปโหลดไฟล์ไม่สำเร็จ — ไฟล์อาจใหญ่เกินที่เซิร์ฟเวอร์รับได้ (ขณะนี้รับได้ไม่เกิน ' . (string) ini_get('upload_max_filesize') . ')';
    } else {
        try {
            $restoreResult = restorePhotosFromZip($file['tmp_name']);
        } catch (RuntimeException $e) {
            $restoreError = 'อ่านไฟล์ ZIP ไม่ได้: ' . $e->getMessage();
        }
    }
}

if (isset($_GET['download'])) {
    $sql = generateDatabaseBackupSql($pdo);
    $filename = 'tree_qr_system-backup-' . date('Y-m-d_His') . '.sql';
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($sql));
    echo $sql;
    exit;
}

$tableCounts = [];
$tableNames = $pdo->query(
    "SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename"
)->fetchAll(PDO::FETCH_COLUMN);
foreach ($tableNames as $table) {
    $tableCounts[$table] = (int) $pdo->query('SELECT COUNT(*) FROM "' . $table . '"')->fetchColumn();
}
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ผู้ดูแลระบบ — สำรองข้อมูล</title>
<link rel="stylesheet" href="../public/assets/css/style.css">
</head>
<body>
<div class="admin-wrap admin-wrap-narrow">
  <p><a class="btn-outline btn-sm" href="dashboard.php">&larr; กลับไปหน้าต้นไม้</a></p>
  <h1>สำรองข้อมูล (Backup)</h1>
  <p class="field-hint">
    ดาวน์โหลดข้อมูลทั้งเว็บ ณ ขณะนี้ แล้วเก็บไว้นอกเซิร์ฟเวอร์ (เช่น Google Drive, external drive) ตามรอบที่กำหนด
    เพื่อป้องกันข้อมูลสูญหาย
  </p>

  <p><a class="btn" href="backup.php?download=full">⬇ ดาวน์โหลดสำรองทั้งเว็บ (ฐานข้อมูล + รูป .zip)</a></p>
  <p class="field-hint">
    ไฟล์ ZIP มีฐานข้อมูลทั้งหมด (รวมภาพหมุน 360° และข้อมูลฝึกโมเดล) และรูปที่อัปโหลดไว้ (ต้นไม้ พันธุ์ไม้ แผนที่ โลโก้)
    ถ้ามีรูปมาก การเตรียมไฟล์อาจใช้เวลาครู่หนึ่ง — รอจนเบราว์เซอร์ดาวน์โหลดเสร็จ
  </p>
  <p><a class="btn-outline btn-sm" href="backup.php?download=1">⬇ เฉพาะฐานข้อมูล (.sql — ไม่มีรูป)</a></p>

  <div class="table-scroll mb-lg">
    <table>
      <thead><tr><th>ตาราง</th><th>จำนวนแถว</th></tr></thead>
      <tbody>
        <?php foreach ($tableCounts as $table => $count): ?>
        <tr><td><?= e($table) ?></td><td><?= number_format($count) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <h2>กู้คืนรูปจากไฟล์สำรอง</h2>
  <?php if ($restoreError): ?><div class="flash error"><?= e($restoreError) ?></div><?php endif; ?>
  <?php if ($restoreResult): ?>
    <div class="flash">
      กู้คืนรูปแล้ว <?= (int) $restoreResult['restored'] ?> ไฟล์
      (มีอยู่แล้ว ข้ามไป <?= (int) $restoreResult['skipped'] ?>, ไม่รับ <?= (int) $restoreResult['rejected'] ?>)
      <?php foreach ($restoreResult['errors'] as $err): ?><br><?= e($err) ?><?php endforeach; ?>
    </div>
  <?php endif; ?>
  <form method="post" enctype="multipart/form-data" class="mb-lg">
    <?= csrfField() ?>
    <input type="file" name="backup_zip" accept=".zip,application/zip" required>
    <button class="btn-outline btn-sm" type="submit">กู้คืนรูป</button>
    <p class="field-hint">
      เลือกไฟล์ ZIP ที่ดาวน์โหลดจากหน้านี้ — ระบบจะใส่เฉพาะรูปกลับเข้าไป (ไฟล์ที่มีอยู่แล้วไม่ถูกทับ) ส่วนฐานข้อมูลให้กู้คืนด้วย psql ตามไฟล์ README.txt ในนั้น
    </p>
  </form>

  <p class="field-hint">
    ไฟล์ .sql นี้มีเฉพาะ "ข้อมูล" (ไม่มีโครงสร้างตาราง) — กู้คืนโดยรัน
    <code>docs/install.postgres.sql</code> ก่อน (โครงสร้าง) แล้วค่อยโหลดไฟล์นี้ด้วยคำสั่ง
    <code>psql "$DATABASE_URL" -f ไฟล์.sql</code> — ดูรายละเอียดเพิ่มเติมใน <code>SETUP.md</code>
  </p>
</div>
</body>
</html>
