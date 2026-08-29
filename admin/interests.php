<?php
require_once __DIR__ . '/../includes/auth.php';
requirePermission('interest.view');

$pdo = db();

$statusLabels = ['new' => 'ใหม่', 'contacted' => 'ติดต่อแล้ว', 'converted' => 'ปิดการขายได้', 'closed' => 'ปิดเคส'];
$activityLabels = ['interest_click' => 'กดสนใจ', 'price_request' => 'ขอราคา'];

$filter = $_GET['status'] ?? '';
if (!in_array($filter, ['new', 'contacted', 'converted', 'closed'], true)) {
    $filter = '';
}

$sql = "SELECT i.*, sp.name AS species_name
        FROM tree_interests i
        JOIN trees t ON t.id = i.tree_id
        JOIN species sp ON sp.id = t.species_id";
$params = [];
if ($filter !== '') {
    $sql .= ' WHERE i.lead_status = :status';
    $params['status'] = $filter;
}
$sql .= ' ORDER BY i.submitted_at DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$summary = $pdo->query('SELECT lead_status, COUNT(*) c FROM tree_interests GROUP BY lead_status')->fetchAll(PDO::FETCH_KEY_PAIR);

$topSpecies = $pdo->query(
    "SELECT sp.name, COUNT(*) c FROM tree_interests i
     JOIN trees t ON t.id = i.tree_id JOIN species sp ON sp.id = t.species_id
     GROUP BY sp.name ORDER BY c DESC LIMIT 5"
)->fetchAll();
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ผู้ดูแลระบบ — ความสนใจ (Leads)</title>
<link rel="stylesheet" href="../public/assets/css/style.css">
</head>
<body>
<div class="admin-wrap">
  <div class="topbar">
    <h1>ความสนใจของผู้เข้าชม</h1>
    <a class="btn-outline btn-sm" href="<?= e(adminHomeUrl()) ?>">&larr; กลับไปหน้าแรก</a>
  </div>

  <div class="stat-cards">
    <?php foreach ($statusLabels as $key => $label): ?>
      <a class="stat-card <?= $filter === $key ? 'active' : '' ?>" href="interests.php?status=<?= e($key) ?>">
        <span class="stat-value"><?= (int) ($summary[$key] ?? 0) ?></span>
        <span class="stat-label"><?= e($label) ?></span>
      </a>
    <?php endforeach; ?>
    <a class="stat-card <?= $filter === '' ? 'active' : '' ?>" href="interests.php">
      <span class="stat-value"><?= array_sum($summary) ?></span>
      <span class="stat-label">ทั้งหมด</span>
    </a>
  </div>

  <?php if ($topSpecies): ?>
  <div class="table-scroll mb-lg">
    <table>
      <thead><tr><th colspan="2">ชนิดพันธุ์ที่ได้รับความสนใจสูงสุด</th></tr></thead>
      <tbody>
        <?php foreach ($topSpecies as $ts): ?>
          <tr><td><?= e($ts['name']) ?></td><td><?= (int) $ts['c'] ?> ครั้ง</td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <div class="table-scroll">
    <table>
      <thead>
        <tr><th>เวลา</th><th>ต้นไม้</th><th>อีเมล</th><th>ประเภท</th><th>ช่องทางติดต่อ</th><th>สถานะ Lead</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= e($r['submitted_at']) ?></td>
          <td><?= e($r['species_name']) ?> (<?= e(assetCode($pdo, (int) $r['tree_id'])) ?>)</td>
          <td><?= e($r['email']) ?></td>
          <td><?= e($activityLabels[$r['activity_type']] ?? $r['activity_type']) ?></td>
          <td><?= e($r['contact_channel'] ?? '') ?></td>
          <td>
            <form class="inline-lead-form" method="post" action="interest_update.php">
              <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <input type="hidden" name="filter" value="<?= e($filter) ?>">
              <select name="lead_status" onchange="this.form.querySelector('input[name=contact_channel]').focus()">
                <?php foreach ($statusLabels as $key => $label): ?>
                  <option value="<?= e($key) ?>" <?= $r['lead_status'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
              </select>
              <input type="text" name="contact_channel" value="<?= e($r['contact_channel'] ?? '') ?>" placeholder="ช่องทางติดต่อ" class="input-narrow">
              <button class="btn-outline btn-sm" type="submit">บันทึก</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
        <tr><td colspan="7">ยังไม่มีข้อมูลความสนใจ</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
</body>
</html>
