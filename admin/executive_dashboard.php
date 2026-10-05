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

// "สถิติตามประเภทพืช" scan counts are period-scoped (this month / this year)
// — species_count/tree_count stay all-time (current inventory has no
// meaningful "which month" dimension), only scanned_at is date-filtered,
// and only inside the LEFT JOIN's ON clause (not WHERE) so a category with
// zero scans in the period still shows a 0 row instead of disappearing.
$categoryPeriod = ($_GET['category_period'] ?? 'month') === 'year' ? 'year' : 'month';

// Year list for the picker — every year that actually has a scan, plus the
// current year even if it has none yet (so a fresh install still has
// something selectable). Newest first.
$scanYears = $pdo->query('SELECT DISTINCT EXTRACT(YEAR FROM scanned_at)::integer FROM tree_scans ORDER BY 1 DESC')->fetchAll(PDO::FETCH_COLUMN);
// Postgres returns every column through PDO as a string (unlike pdo_mysql's
// native-int mode for a computed integer expression) — cast explicitly so
// the strict in_array() comparison below still matches $currentYear (a real
// PHP int) regardless of which driver ran the query above.
$scanYears = array_map('intval', $scanYears);
$currentYear = (int) date('Y');
if (!in_array($currentYear, $scanYears, true)) {
    array_unshift($scanYears, $currentYear);
    rsort($scanYears);
}
$categoryYear = (int) ($_GET['category_year'] ?? $currentYear);
if (!in_array($categoryYear, $scanYears, true)) {
    $categoryYear = $currentYear;
}
$categoryMonth = (int) ($_GET['category_month'] ?? date('n'));
if ($categoryMonth < 1 || $categoryMonth > 12) {
    $categoryMonth = (int) date('n');
}
$thaiMonths = [1 => 'มกราคม', 2 => 'กุมภาพันธ์', 3 => 'มีนาคม', 4 => 'เมษายน', 5 => 'พฤษภาคม', 6 => 'มิถุนายน',
    7 => 'กรกฎาคม', 8 => 'สิงหาคม', 9 => 'กันยายน', 10 => 'ตุลาคม', 11 => 'พฤศจิกายน', 12 => 'ธันวาคม'];

if ($categoryPeriod === 'year') {
    $categoryPeriodFrom = "$categoryYear-01-01 00:00:00";
    $categoryPeriodTo = ($categoryYear + 1) . '-01-01 00:00:00';
} else {
    $categoryPeriodFrom = sprintf('%04d-%02d-01 00:00:00', $categoryYear, $categoryMonth);
    $categoryPeriodTo = date('Y-m-d H:i:s', strtotime('+1 month', strtotime($categoryPeriodFrom)));
}
$byCategoryStmt = $pdo->prepare(
    "SELECT c.name_th AS category_name,
            COUNT(DISTINCT s.id) AS species_count,
            COUNT(DISTINCT t.id) AS tree_count,
            COUNT(sc.id) AS scan_count
     FROM categories c
     LEFT JOIN species s ON s.category_code = c.code
     LEFT JOIN trees t ON t.species_id = s.id AND t.is_active = 1
     LEFT JOIN tree_scans sc ON sc.tree_id = t.id AND sc.scanned_at >= :from AND sc.scanned_at < :to
     GROUP BY c.code, c.name_th
     ORDER BY scan_count DESC, c.name_th ASC"
);
$byCategoryStmt->execute(['from' => $categoryPeriodFrom, 'to' => $categoryPeriodTo]);
$byCategory = $byCategoryStmt->fetchAll();

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

// Grouped by species (not per-tree) — the same species is usually planted
// as several individual trees, and ranking by specimen just splits one
// species' popularity across duplicate rows instead of showing it as one.
// Two LEFT JOINs off the same trees row (scans, interests) would otherwise
// fan out and inflate both counts — COUNT(DISTINCT ...) on each keeps them
// correct regardless of how many rows the other join produces per tree.
// Defaults to Top 10 (a dashboard should lead with what stands out, not
// every row) with an explicit "ดูทั้งหมด" escape hatch for whoever actually
// wants the full list, rather than always dumping every species here.
$speciesView = ($_GET['species_view'] ?? 'top') === 'all' ? 'all' : 'top';
$topTreesLimitSql = $speciesView === 'all' ? '' : 'LIMIT 10';
$topTrees = $pdo->query(
    "SELECT sp.id, sp.name AS species_name,
            COUNT(DISTINCT sc.id) AS scan_count,
            COUNT(DISTINCT sc.visitor_id) AS unique_visitors,
            COUNT(DISTINCT ti.id) AS interest_count
     FROM species sp
     JOIN trees t ON t.species_id = sp.id AND t.is_active = 1
     LEFT JOIN tree_scans sc ON sc.tree_id = t.id
     LEFT JOIN tree_interests ti ON ti.tree_id = t.id
     GROUP BY sp.id, sp.name
     HAVING COUNT(DISTINCT sc.id) > 0
     ORDER BY scan_count DESC
     $topTreesLimitSql"
)->fetchAll();

$trend = $pdo->query(
    "SELECT DATE(scanned_at) AS scan_date, COUNT(*) AS scan_count
     FROM tree_scans
     WHERE scanned_at >= (NOW() - INTERVAL '6 days')
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

// The actual stock line items behind the badge counts above — "มีอะไรบ้าง".
$stockItems = $pdo->query(
    "SELECT ns.*, sp.name AS species_name, sz.name_th AS size_name_th
     FROM nursery_stock ns
     JOIN species sp ON sp.id = ns.species_id
     LEFT JOIN stock_sizes sz ON sz.id = ns.size_id
     ORDER BY ns.sale_status ASC, sp.name ASC"
)->fetchAll();

// Pie (by category) — categories are genuinely distinct series (identity,
// not magnitude), so this one gets the categorical palette rather than a
// single hue. Validated default order (adjacent-pair CVD-safe up to 8
// slots, which a ring's neighbor-pairs need) — a 9th+ category folds into
// "other" rather than generating a new hue.
$categoryPalette = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#4a3aa7', '#e34948'];
$categoryPieTotal = array_sum(array_column($byCategory, 'scan_count'));
$categoryPieSlices = [];
$categoryOtherCount = 0;
foreach ($byCategory as $c) {
    if ((int) $c['scan_count'] === 0) {
        continue;
    }
    $slotIndex = count($categoryPieSlices);
    if ($slotIndex < count($categoryPalette)) {
        $categoryPieSlices[] = ['label' => $c['category_name'], 'count' => (int) $c['scan_count'], 'color' => $categoryPalette[$slotIndex]];
    } else {
        $categoryOtherCount += (int) $c['scan_count'];
    }
}
if ($categoryOtherCount > 0) {
    $categoryPieSlices[] = ['label' => 'อื่นๆ', 'count' => $categoryOtherCount, 'color' => 'var(--faint)'];
}

// All-time revenue totals + per-species breakdown, only computed when the
// viewer can actually see them — real money, gated the same as the rest of
// this section. Queried directly off sale_transactions/species (NOT
// filtered through $topTrees) — $topTrees only contains species with
// scan_count > 0 and, in the default view, just the top 10 of those, so a
// species that sold without ever being scanned (a walk-in sale, or one not
// yet planted/labeled) would otherwise silently vanish from this revenue
// breakdown even though the total above it already includes it.
$revenueTotals = ['units' => 0, 'revenue' => 0.0];
$topSellers = [];
if (can('revenue.view')) {
    $revenueRow = $pdo->query('SELECT COALESCE(SUM(quantity), 0) AS units, COALESCE(SUM(total_price), 0) AS revenue FROM sale_transactions')->fetch();
    $revenueTotals = ['units' => (int) $revenueRow['units'], 'revenue' => (float) $revenueRow['revenue']];

    $topSellers = $pdo->query(
        "SELECT sp.name AS species_name, SUM(st.quantity) AS units_sold, SUM(st.total_price) AS revenue
         FROM sale_transactions st
         JOIN species sp ON sp.id = st.species_id
         GROUP BY sp.id, sp.name
         ORDER BY revenue DESC"
    )->fetchAll();
}
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

  <!-- ============ กลุ่ม 0: ภาพรวม ============ -->

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

  <!-- ============ กลุ่ม 1: การมีส่วนร่วมของผู้เข้าชม (สแกน/ความสนใจ) ============ -->
  <div class="history-section">
    <div class="section-header-row">
      <h2><?= $speciesView === 'all' ? 'ชนิดพันธุ์ทั้งหมด (จากยอดสแกน)' : 'ต้นไม้ยอดนิยม (Top 10 ชนิดพันธุ์ จากยอดสแกน)' ?></h2>
      <?php if ($speciesView === 'all'): ?>
        <a class="btn-outline btn-sm" href="?species_view=top#top-species">ดูแค่ Top 10</a>
      <?php else: ?>
        <a class="btn-outline btn-sm" href="?species_view=all#top-species">ดูทั้งหมด</a>
      <?php endif; ?>
    </div>
    <a id="top-species"></a>
    <?php if (!$topTrees): ?>
      <p class="muted-note">ยังไม่มีข้อมูลการสแกน</p>
    <?php else: ?>
      <div class="table-scroll">
        <table>
          <thead><tr><th>ชนิดพันธุ์</th><th>สแกนทั้งหมด</th><th>ผู้เข้าชมไม่ซ้ำ</th><th>ความสนใจ/ขอราคา</th></tr></thead>
          <tbody>
            <?php foreach ($topTrees as $t): ?>
            <tr>
              <td><?= e($t['species_name']) ?></td>
              <td><?= number_format((int) $t['scan_count']) ?></td>
              <td><?= number_format((int) $t['unique_visitors']) ?></td>
              <td><?= number_format((int) $t['interest_count']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="field-hint">"ความสนใจ/ขอราคา" คือยอดกดสนใจ/ขอราคารวม (interest_click + price_request) — เป็นสัญญาณความสนใจ ไม่ใช่ยอดขายจริง <?= can('revenue.view') ? '(ดูยอดขายจริงได้ที่หมวด "ยอดขาย" ด้านล่าง)' : '' ?></p>
    <?php endif; ?>
  </div>

  <div class="history-section">
    <div class="section-header-row">
      <h2>สถิติตามประเภทพืช (ตามยอดสแกน)</h2>
      <form method="get" class="btn-row" onchange="this.submit()">
        <select name="category_period">
          <option value="month" <?= $categoryPeriod === 'month' ? 'selected' : '' ?>>รายเดือน</option>
          <option value="year" <?= $categoryPeriod === 'year' ? 'selected' : '' ?>>รายปี (ทั้งปี)</option>
        </select>
        <select name="category_month" <?= $categoryPeriod === 'year' ? 'disabled' : '' ?>>
          <?php foreach ($thaiMonths as $num => $name): ?>
            <option value="<?= $num ?>" <?= $categoryMonth === $num ? 'selected' : '' ?>><?= e($name) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="category_year">
          <?php foreach ($scanYears as $y): ?>
            <option value="<?= (int) $y ?>" <?= $categoryYear === (int) $y ? 'selected' : '' ?>><?= (int) $y ?></option>
          <?php endforeach; ?>
        </select>
        <noscript><button class="btn-outline btn-sm" type="submit">ดู</button></noscript>
      </form>
    </div>
    <a id="category-stats"></a>
    <?php if ($categoryPieSlices): ?>
      <div class="donut-row">
        <?php
          // r=54 + stroke-width=16 (half=8) would put the ring's outer edge
          // at 62 — past the 120x120 viewBox's half-extent of 60, clipping
          // the stroke at the SVG's own boundary. r=50 keeps the outer edge
          // at 58, inside the box with a 2-unit margin.
          $pieR = 50;
          $pieCirc = 2 * M_PI * $pieR;
          $pieGap = count($categoryPieSlices) > 1 ? 3 : 0;
          $pieOffset = 0;
        ?>
        <svg class="donut-chart donut-chart-lg" viewBox="0 0 120 120" role="img" aria-label="สัดส่วนยอดสแกนตามประเภทพืช">
          <?php foreach ($categoryPieSlices as $slice):
            $share = $slice['count'] / $categoryPieTotal;
            $segLen = max(0, $share * $pieCirc - $pieGap);
          ?>
            <circle cx="60" cy="60" r="<?= $pieR ?>" fill="none" stroke="<?= e($slice['color']) ?>"
              stroke-width="16" stroke-dasharray="<?= round($segLen, 1) ?> <?= round($pieCirc - $segLen, 1) ?>"
              stroke-dashoffset="<?= round(-$pieOffset, 1) ?>" transform="rotate(-90 60 60)">
              <title><?= e($slice['label']) ?>: <?= number_format($slice['count']) ?> (<?= round($share * 100) ?>%)</title>
            </circle>
            <?php $pieOffset += $share * $pieCirc; ?>
          <?php endforeach; ?>
        </svg>
        <div class="chart-legend">
          <?php foreach ($categoryPieSlices as $slice): ?>
            <span class="chart-legend-item">
              <span class="chart-legend-swatch" style="background:<?= e($slice['color']) ?>"></span>
              <?= e($slice['label']) ?> — <?= number_format($slice['count']) ?> (<?= round($slice['count'] / $categoryPieTotal * 100) ?>%)
            </span>
          <?php endforeach; ?>
        </div>
      </div>
    <?php else: ?>
      <p class="muted-note">ยังไม่มีข้อมูลการสแกนใน<?= $categoryPeriod === 'year' ? 'ปีนี้' : 'เดือนนี้' ?></p>
    <?php endif; ?>
    <p class="field-hint">สัดส่วนยอดสแกนตามประเภทพืช — ดูจำนวนชนิดพันธุ์/ต้นไม้ (ทั้งหมด ไม่จำกัดช่วงเวลา) ในตารางด้านล่าง</p>
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

  <!-- ============ กลุ่ม 2: ยอดขาย/สต็อก (เห็นเฉพาะ revenue.view) ============ -->

  <?php if (can('revenue.view')): ?>
  <div class="history-section">
    <h2>รายได้รวม (ทั้งหมดตั้งแต่เริ่มระบบ)</h2>
    <div class="stat-cards">
      <div class="stat-card"><span class="stat-value"><?= number_format($revenueTotals['units']) ?></span><span class="stat-label">ต้นที่ขายแล้ว</span></div>
      <div class="stat-card"><span class="stat-value"><?= number_format($revenueTotals['revenue'], 2) ?></span><span class="stat-label">รายได้รวม (บาท)</span></div>
    </div>
    <?php if ($topSellers): ?>
      <div class="table-scroll">
        <table>
          <thead><tr><th>ชนิดพันธุ์</th><th>ขายแล้ว (หน่วย)</th><th>รายได้ (บาท)</th></tr></thead>
          <tbody>
            <?php foreach ($topSellers as $t): ?>
            <tr>
              <td><?= e($t['species_name']) ?></td>
              <td><?= number_format((int) $t['units_sold']) ?></td>
              <td><?= number_format((float) $t['revenue'], 2) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
    <p class="field-hint">มาจากประวัติการขายจริงที่บันทึกไว้ในหน้าแก้ไขชนิดพันธุ์แต่ละรายการ</p>
  </div>

  <div class="history-section">
    <h2>สต็อกเรือนเพาะชำ</h2>
    <p class="field-hint">สรุปจำนวนรายการสต็อกตามสถานะขาย (ของคงเหลือปัจจุบัน — ไม่ใช่ยอดขาย)</p>
    <div class="btn-row">
      <?php foreach ($saleStatusLabels as $key => $label): ?>
        <span class="sale-badge sale-badge-<?= e($key) ?>"><?= e($label) ?>: <?= number_format((int) ($stockSummary[$key] ?? 0)) ?></span>
      <?php endforeach; ?>
    </div>
    <?php if ($stockItems): ?>
      <div class="table-scroll">
        <table>
          <thead><tr><th>ชนิดพันธุ์</th><th>ขนาด</th><th>จำนวน</th><th>ราคา</th><th>สถานะ</th><th>ช่องทางขาย</th></tr></thead>
          <tbody>
            <?php foreach ($stockItems as $item): ?>
            <tr>
              <td><?= e($item['species_name']) ?></td>
              <td><?= e($item['size_name_th'] ?? '—') ?></td>
              <td><?= number_format((int) $item['quantity']) ?></td>
              <td><?= $item['price'] !== null ? number_format((float) $item['price'], 2) : '—' ?></td>
              <td><span class="sale-badge sale-badge-<?= e($item['sale_status']) ?>"><?= e($saleStatusLabels[$item['sale_status']] ?? $item['sale_status']) ?></span></td>
              <td><?= e($item['sales_channel'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <p class="muted-note">ยังไม่มีรายการสต็อก</p>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- ============ ส่งออก/รายงานเพิ่มเติม ============ -->

  <?php if (can('reports.export')): ?>
    <p><a class="btn-outline btn-sm" href="reports.php">ดูรายงานรายต้น + ส่งออก CSV &rarr;</a></p>
  <?php endif; ?>
</div>
</body>
</html>
