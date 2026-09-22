<?php
require_once __DIR__ . '/../includes/auth.php';
requirePermission('settings.manage');

$pdo = db();

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
foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
    $tableCounts[$table] = (int) $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
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
    ดาวน์โหลดไฟล์ SQL ที่มีทั้งโครงสร้างตารางและข้อมูลทั้งหมดในระบบ ณ ขณะนี้
    เก็บไฟล์นี้ไว้นอกเซิร์ฟเวอร์ (เช่น Google Drive, external drive) ตามรอบที่กำหนด
    เพื่อป้องกันข้อมูลสูญหายและลดการผูกติดกับผู้พัฒนาระบบ
  </p>

  <p><a class="btn" href="backup.php?download=1">⬇ ดาวน์โหลดไฟล์สำรอง (.sql)</a></p>

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

  <p class="field-hint">
    ไฟล์ SQL นี้กู้คืนได้ด้วยคำสั่ง <code>mysql -u root -p tree_qr_system &lt; ไฟล์.sql</code> —
    ดูรายละเอียดเพิ่มเติมใน <code>SETUP.md</code>
  </p>
</div>
</body>
</html>
