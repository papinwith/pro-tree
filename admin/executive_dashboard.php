<?php
require_once __DIR__ . '/../includes/auth.php';
requirePermission('dashboard.view');

$pdo = db();

$overview = [
    'trees' => (int) $pdo->query('SELECT COUNT(*) FROM trees WHERE is_active = 1')->fetchColumn(),
    'species' => (int) $pdo->query('SELECT COUNT(*) FROM species')->fetchColumn(),
    'categories' => (int) $pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn(),
    'zones' => (int) $pdo->query('SELECT COUNT(*) FROM zones')->fetchColumn(),
    'scans' => (int) $pdo->query('SELECT COUNT(*) FROM tree_scans')->fetchColumn(),
    'unique_visitors' => (int) $pdo->query('SELECT COUNT(DISTINCT visitor_id) FROM tree_scans')->fetchColumn(),
    'interests' => (int) $pdo->query('SELECT COUNT(*) FROM tree_interests')->fetchColumn(),
];
$overview['repeated_scans'] = $overview['scans'] - $overview['unique_visitors'];

$byCategory = $pdo->query(
    "SELECT c.name_th AS category_name,
            COUNT(DISTINCT s.id) AS species_count,
            COUNT(DISTINCT t.id) AS tree_count,
            COUNT(sc.id) AS scan_count
     FROM categories c
     LEFT JOIN species s ON s.category_code = c.code
     LEFT JOIN trees t ON t.species_id = s.id AND t.is_active = 1
     LEFT JOIN tree_scans sc ON sc.tree_id = t.id
     GROUP BY c.code, c.name_th
     ORDER BY scan_count DESC, c.name_th ASC"
)->fetchAll();

$byZone = $pdo->query(
    "SELECT z.name AS zone_name,
            COUNT(DISTINCT t.id) AS tree_count,
            COUNT(sc.id) AS scan_count,
            COUNT(DISTINCT sc.visitor_id) AS unique_visitors
     FROM zones z
     LEFT JOIN trees t ON t.zone_id = z.id AND t.is_active = 1
     LEFT JOIN tree_scans sc ON sc.tree_id = t.id
     GROUP BY z.id, z.name
     ORDER BY scan_count DESC, z.name ASC"
)->fetchAll();

$topTrees = $pdo->query(
    "SELECT t.id, sp.name AS species_name,
            COUNT(sc.id) AS scan_count,
            COUNT(DISTINCT sc.visitor_id) AS unique_visitors
     FROM trees t
     JOIN species sp ON sp.id = t.species_id
     LEFT JOIN tree_scans sc ON sc.tree_id = t.id
     WHERE t.is_active = 1
     GROUP BY t.id, sp.name
     HAVING scan_count > 0
     ORDER BY scan_count DESC
     LIMIT 5"
)->fetchAll();

$trend = $pdo->query(
    "SELECT DATE(scanned_at) AS scan_date, COUNT(*) AS scan_count
     FROM tree_scans
     WHERE scanned_at >= (NOW() - INTERVAL 6 DAY)
     GROUP BY DATE(scanned_at)
     ORDER BY scan_date ASC"
)->fetchAll();
// Fill in every day of the last 7, including days with zero scans, so the
// trend always shows a complete week rather than skipping quiet days.
$trendByDate = array_column($trend, 'scan_count', 'scan_date');
$trendDays = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i day"));
    $trendDays[] = ['date' => $date, 'count' => (int) ($trendByDate[$date] ?? 0)];
}
$trendMax = max(1, max(array_column($trendDays, 'count')));

$stockSummary = $pdo->query(
    "SELECT sale_status, COUNT(*) AS n FROM nursery_stock GROUP BY sale_status"
)->fetchAll(PDO::FETCH_KEY_PAIR);
$saleStatusLabels = ['available' => 'พร้อมขาย', 'reserved' => 'จองแล้ว', 'sold_out' => 'ขายหมด', 'not_for_sale' => 'ไม่ขาย'];
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ผู้ดูแลระบบ — แดชบอร์ดผู้บริหาร</title>
<link rel="stylesheet" href="../public/assets/css/style.css">
</head>
<body>
<div class="admin-wrap">
  <div class="topbar">
    <h1>แดชบอร์ดผู้บริหาร</h1>
    <?php require __DIR__ . '/_nav.php'; ?>
  </div>

  <div class="stat-cards">
    <div class="stat-card"><span class="stat-value"><?= number_format($overview['trees']) ?></span><span class="stat-label">ต้นไม้ทั้งหมด</span></div>
    <div class="stat-card"><span class="stat-value"><?= number_format($overview['species']) ?></span><span class="stat-label">ชนิดพันธุ์</span></div>
    <div class="stat-card"><span class="stat-value"><?= number_format($overview['categories']) ?></span><span class="stat-label">ประเภทพืช</span></div>
    <div class="stat-card"><span class="stat-value"><?= number_format($overview['zones']) ?></span><span class="stat-label">โซน</span></div>
    <div class="stat-card"><span class="stat-value"><?= number_format($overview['unique_visitors']) ?></span><span class="stat-label">ผู้เข้าชมไม่ซ้ำ</span></div>
    <div class="stat-card"><span class="stat-value"><?= number_format($overview['scans']) ?></span><span class="stat-label">สแกนทั้งหมด</span></div>
    <div class="stat-card"><span class="stat-value"><?= number_format($overview['repeated_scans']) ?></span><span class="stat-label">สแกนซ้ำ</span></div>
    <div class="stat-card"><span class="stat-value"><?= number_format($overview['interests']) ?></span><span class="stat-label">ยอดสนใจ</span></div>
  </div>

  <div class="history-section">
    <h2>แนวโน้มการสแกน (7 วันล่าสุด)</h2>
    <?php if ($overview['scans'] === 0): ?>
      <p class="muted-note">ยังไม่มีข้อมูลการสแกน</p>
    <?php else: ?>
      <div class="table-scroll">
        <table>
          <thead><tr><th>วันที่</th><th>จำนวนสแกน</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($trendDays as $day): ?>
            <tr>
              <td><?= e($day['date']) ?></td>
              <td><?= number_format($day['count']) ?></td>
              <td style="width:40%;">
                <div class="completeness-bar"><div class="completeness-bar-fill" style="width:<?= round($day['count'] / $trendMax * 100, 1) ?>%"></div></div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="history-section">
    <h2>ต้นไม้ยอดนิยม (Top 5 จากยอดสแกน)</h2>
    <?php if (!$topTrees): ?>
      <p class="muted-note">ยังไม่มีข้อมูลการสแกน</p>
    <?php else: ?>
      <div class="table-scroll">
        <table>
          <thead><tr><th>Tree ID</th><th>ชนิดพันธุ์</th><th>สแกนทั้งหมด</th><th>ผู้เข้าชมไม่ซ้ำ</th></tr></thead>
          <tbody>
            <?php foreach ($topTrees as $t): ?>
            <tr>
              <td><?= e(assetCode($pdo, (int) $t['id'])) ?></td>
              <td><?= e($t['species_name']) ?></td>
              <td><?= number_format((int) $t['scan_count']) ?></td>
              <td><?= number_format((int) $t['unique_visitors']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="history-section">
    <h2>สถิติตามประเภทพืช</h2>
    <div class="table-scroll">
      <table>
        <thead><tr><th>ประเภทพืช</th><th>ชนิดพันธุ์</th><th>ต้นไม้</th><th>สแกน</th></tr></thead>
        <tbody>
          <?php foreach ($byCategory as $c): ?>
          <tr>
            <td><?= e($c['category_name']) ?></td>
            <td><?= number_format((int) $c['species_count']) ?></td>
            <td><?= number_format((int) $c['tree_count']) ?></td>
            <td><?= number_format((int) $c['scan_count']) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$byCategory): ?><tr><td colspan="4">ยังไม่มีข้อมูล</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="history-section">
    <h2>สถิติตามโซน</h2>
    <div class="table-scroll">
      <table>
        <thead><tr><th>โซน</th><th>ต้นไม้</th><th>สแกน</th><th>ผู้เข้าชมไม่ซ้ำ</th></tr></thead>
        <tbody>
          <?php foreach ($byZone as $z): ?>
          <tr>
            <td><?= e($z['zone_name']) ?></td>
            <td><?= number_format((int) $z['tree_count']) ?></td>
            <td><?= number_format((int) $z['scan_count']) ?></td>
            <td><?= number_format((int) $z['unique_visitors']) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$byZone): ?><tr><td colspan="4">ยังไม่มีข้อมูล</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if (can('revenue.view')): ?>
  <div class="history-section">
    <h2>สต็อกเรือนเพาะชำ</h2>
    <p class="field-hint">สรุปจำนวนรายการสต็อกตามสถานะขาย — ไม่ใช่ยอดขาย/รายได้จริง (ระบบยังไม่มีการบันทึกธุรกรรมขาย)</p>
    <div class="btn-row">
      <?php foreach ($saleStatusLabels as $key => $label): ?>
        <span class="sale-badge sale-badge-<?= e($key) ?>"><?= e($label) ?>: <?= number_format((int) ($stockSummary[$key] ?? 0)) ?></span>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if (can('reports.export')): ?>
    <p><a class="btn-outline btn-sm" href="reports.php">ดูรายงานรายต้น + ส่งออก CSV &rarr;</a></p>
  <?php endif; ?>
</div>
</body>
</html>
