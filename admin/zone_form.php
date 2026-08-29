<?php
require_once __DIR__ . '/../includes/auth.php';
requirePermission('zone.manage');

$pdo = db();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$zone = $id ? getZoneById($pdo, $id) : null;
if ($id && !$zone) {
    http_response_code(404);
    exit('ไม่พบโซนนี้');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Thai only — EN/ZH are generated automatically on first public view in
    // that language and cached on this same row (see includes/translation.php),
    // so this form must never touch those columns.
    $zoneCode = trim($_POST['zone_code'] ?? '');
    $zoneNumber = trim($_POST['zone_number'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '') ?: null;

    if ($zoneCode === '') {
        $errors[] = 'กรุณาระบุรหัสโซน';
    }
    if ($name === '') {
        $errors[] = 'กรุณาระบุชื่อ';
    }
    if ($zoneCode !== '') {
        $dupStmt = $pdo->prepare('SELECT name FROM zones WHERE zone_code = :code AND id != :id');
        $dupStmt->execute(['code' => $zoneCode, 'id' => $id]);
        $conflict = $dupStmt->fetchColumn();
        if ($conflict !== false) {
            $errors[] = "รหัสโซน \"$zoneCode\" ถูกใช้โดย \"$conflict\" แล้ว";
        }
    }
    if (!preg_match('/^\d{3}$/', $zoneNumber)) {
        $errors[] = 'หมายเลขโซนต้องเป็นตัวเลข 3 หลัก';
    } else {
        $dupNumStmt = $pdo->prepare('SELECT name FROM zones WHERE zone_number = :num AND id != :id');
        $dupNumStmt->execute(['num' => $zoneNumber, 'id' => $id]);
        $numConflict = $dupNumStmt->fetchColumn();
        if ($numConflict !== false) {
            $errors[] = "หมายเลขโซน \"$zoneNumber\" ถูกใช้โดย \"$numConflict\" แล้ว";
        }
    }

    if (!$errors) {
        $params = [
            'zone_code' => $zoneCode, 'zone_number' => $zoneNumber, 'name' => $name, 'description' => $description,
        ];
        try {
            if ($id) {
                $stmt = $pdo->prepare(
                    'UPDATE zones SET zone_code=:zone_code, zone_number=:zone_number, name=:name, description=:description
                     WHERE id=:id'
                );
                $stmt->execute($params + ['id' => $id]);
                $zoneId = $id;
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO zones (zone_code, zone_number, name, description)
                     VALUES (:zone_code, :zone_number, :name, :description)'
                );
                $stmt->execute($params);
                $zoneId = (int) $pdo->lastInsertId();
            }

            // zone_number feeds directly into every tree in this zone's plant_code.
            $affectedTreeIds = $pdo->prepare('SELECT id FROM trees WHERE zone_id = :zid');
            $affectedTreeIds->execute(['zid' => $zoneId]);
            foreach ($affectedTreeIds->fetchAll(PDO::FETCH_COLUMN) as $affectedTreeId) {
                recomputeTreePlantCode($pdo, (int) $affectedTreeId);
            }

            header('Location: zones.php');
            exit;
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $errors[] = 'รหัสโซนนี้เพิ่งถูกใช้โดยการบันทึกอื่น กรุณาตรวจสอบและลองใหม่อีกครั้ง';
            } else {
                throw $e;
            }
        }
    }

    $zone = array_merge($zone ?? [], [
        'zone_code' => $zoneCode, 'zone_number' => $zoneNumber, 'name' => $name, 'description' => $description,
    ]);
}

$v = fn($key, $default = '') => e((string) ($zone[$key] ?? $default));
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $id ? 'แก้ไข' : 'เพิ่ม' ?>โซน</title>
<link rel="stylesheet" href="../public/assets/css/style.css">
</head>
<body>
<div class="admin-wrap admin-wrap-narrow">
  <p><a class="btn-outline btn-sm" href="zones.php">&larr; กลับไปหน้าโซน</a></p>
  <h1><?= $id ? 'แก้ไขโซน' : 'เพิ่มโซน' ?></h1>
  <p class="field-hint">กรอกเป็นภาษาไทยอย่างเดียว — ระบบจะแปลเป็นอังกฤษ/จีนให้อัตโนมัติตอนผู้เข้าชมเปิดหน้าต้นไม้ด้วยภาษานั้น<?= AI_ENABLED ? '' : ' (ต้องตั้งค่า Gemini API key ก่อน — ดูที่หน้า <a href="settings.php#ai-translation">ตั้งค่า</a>)' ?></p>

  <?php foreach ($errors as $err): ?>
    <div class="flash error"><?= e($err) ?></div>
  <?php endforeach; ?>

  <form method="post">
    <label for="zone_code">รหัสโซน</label>
    <input type="text" id="zone_code" name="zone_code" value="<?= $v('zone_code') ?>" required placeholder="เช่น Z1">

    <label for="zone_number">หมายเลขโซน (ตัวเลข 3 หลัก สำหรับรหัสต้นไม้ 15 หลัก)</label>
    <input type="text" id="zone_number" name="zone_number" value="<?= $v('zone_number') ?>"
           pattern="\d{3}" maxlength="3" placeholder="เช่น 001" required>

    <label for="name">ชื่อ (ไทย)</label>
    <input type="text" id="name" name="name" value="<?= $v('name') ?>" required>

    <label for="description">คำอธิบาย (ไทย)</label>
    <textarea id="description" name="description" rows="3"><?= $v('description') ?></textarea>

    <p><button class="btn" type="submit">บันทึก</button></p>
  </form>
</div>
</body>
</html>
