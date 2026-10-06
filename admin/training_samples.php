<?php
// Review of training examples for the local "tree" model: photos on which the two teachers (Qwen and the second opinion)
// disagreed. A person approves (optionally correcting the name) or rejects each one; only approved photos are ever
// downloaded for retraining (ml/pull_feedback.py).
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/training_samples.php';
requirePermission('species.manage');

$pdo = db();
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $id = (int) ($_POST['id'] ?? 0);
    $decision = (string) ($_POST['decision'] ?? '');
    $name = isset($_POST['name_scientific']) ? (string) $_POST['name_scientific'] : null;
    $ok = $id > 0 && reviewTrainingSample($pdo, $id, $decision, $name, (int) ($_SESSION['admin_id'] ?? 0));
    header('Location: training_samples.php?' . ($ok ? 'done=' . urlencode($decision) : 'error=1'));
    exit;
}
if (isset($_GET['done'])) {
    $flash = ['ok', $_GET['done'] === 'approve' ? 'อนุมัติแล้ว — จะถูกนำไปสอน tree ในรอบเทรนถัดไป' : 'ปฏิเสธแล้ว — จะไม่ถูกนำไปสอน'];
} elseif (isset($_GET['error'])) {
    $flash = ['error', 'ทำรายการไม่สำเร็จ (ชื่อวิทยาศาสตร์ไม่ถูกต้อง หรือมีผู้ตรวจไปแล้ว)'];
}
$counts = trainingSampleCounts($pdo);
$samples = pendingTrainingSamples($pdo, 50);
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ผู้ดูแลระบบ — ตัวอย่างสอน AI</title>
<link rel="stylesheet" href="../public/assets/css/style.css">
</head>
<body>
<div class="admin-wrap">
  <div class="topbar">
    <h1>ตัวอย่างสอน AI</h1>
    <?php require __DIR__ . '/_nav.php'; ?>
  </div>

  <?php if ($flash): ?><div class="flash<?= $flash[0] === 'error' ? ' error' : '' ?>"><?= e($flash[1]) ?></div><?php endif; ?>

  <p class="field-hint">
    โมเดล <strong>tree</strong> เรียนจากภาพที่ถูกยืนยันแล้วเท่านั้น — ภาพจะถูกยืนยันอัตโนมัติเมื่อครูสองตัวตอบชนิดเดียวกัน หรือเมื่อแอดมินกด "ใช้ผลนี้"
    ส่วนภาพที่ครูสองตัว<strong>ตอบไม่ตรงกัน</strong>จะมาอยู่ที่นี่เพื่อให้คนตัดสิน ตอนนี้:
    รอตรวจ <?= (int) $counts['pending'] ?> · อนุมัติแล้ว <?= (int) $counts['approved'] ?> · ปฏิเสธ <?= (int) $counts['rejected'] ?>
  </p>
  <p class="field-hint muted-note">ถ้าไม่แน่ใจว่าชื่อถูกหรือไม่ ให้กด "ปฏิเสธ" — ภาพที่ติดป้ายผิดจะทำให้ tree แย่ลง การปฏิเสธไม่เสียหายอะไร</p>

  <?php if (!$samples): ?>
    <div class="flash">ไม่มีภาพที่รอตรวจ</div>
  <?php endif; ?>

  <?php foreach ($samples as $s): ?>
    <div class="card" style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-start;margin-bottom:12px">
      <img src="data:<?= e($s['mime']) ?>;base64,<?= e($s['image_b64']) ?>" alt="" style="width:200px;max-width:100%;border-radius:8px">
      <form method="post" style="flex:1;min-width:240px">
        <?= csrfField() ?>
        <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
        <p>ครูที่ถูกถาม: <code><?= e((string) $s['teachers']) ?></code> — คำตอบที่มั่นใจกว่าคือ</p>
        <label for="n<?= (int) $s['id'] ?>">ชื่อวิทยาศาสตร์ (แก้ได้ก่อนอนุมัติ)</label>
        <input type="text" id="n<?= (int) $s['id'] ?>" name="name_scientific" value="<?= e($s['name_scientific']) ?>">
        <p class="btn-row">
          <button class="btn btn-sm" type="submit" name="decision" value="approve">อนุมัติ — ให้ tree เรียน</button>
          <button class="btn btn-sm btn-danger" type="submit" name="decision" value="reject">ปฏิเสธ</button>
        </p>
      </form>
    </div>
  <?php endforeach; ?>
</div>
</body>
</html>
