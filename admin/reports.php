<?php
require_once __DIR__ . '/../includes/auth.php';
requirePermission('reports.export');

$pdo = db();

$rows = $pdo->query(
    "SELECT
        t.id, sp.name AS species_name, t.display_order,
        COUNT(sc.id) AS total_scans,
        COUNT(DISTINCT sc.visitor_id) AS unique_visitors,
        (SELECT COUNT(*) FROM tree_interests i WHERE i.tree_id = t.id) AS interests
     FROM trees t
     JOIN species sp ON sp.id = t.species_id
     LEFT JOIN tree_scans sc ON sc.tree_id = t.id
     GROUP BY t.id, sp.name, t.display_order
     ORDER BY t.display_order ASC"
)->fetchAll();

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="tree-report.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Tree ID', 'ชนิดพันธุ์', 'ลำดับการแสดงผล', 'จำนวนสแกนทั้งหมด', 'ผู้เข้าชมไม่ซ้ำ', 'สแกนซ้ำ', 'ยอดสนใจ']);
    foreach ($rows as $r) {
        fputcsv($out, [
            assetCode($pdo, (int) $r['id']), $r['species_name'], $r['display_order'],
            $r['total_scans'], $r['unique_visitors'],
            $r['total_scans'] - $r['unique_visitors'], $r['interests'],
        ]);
    }
    fclose($out);
    exit;
}
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ผู้ดูแลระบบ — รายงาน</title>
<link rel="stylesheet" href="../public/assets/css/style.css">
</head>
<body>
<div class="admin-wrap">
  <p><a href="<?= e(adminHomeUrl()) ?>">&larr; กลับไปหน้าแรก</a><?php if (can('interest.view')): ?> · <a href="interests.php">ความสนใจ (Leads)</a><?php endif; ?></p>
  <div class="topbar">
    <h1>รายงาน</h1>
    <a class="btn" href="reports.php?export=csv">ส่งออก CSV</a>
  </div>

  <div class="table-scroll">
    <table>
      <thead>
        <tr>
          <th>#</th><th>Tree ID</th><th>ชนิดพันธุ์</th><th>สแกนทั้งหมด</th><th>ผู้เข้าชมไม่ซ้ำ</th><th>สแกนซ้ำ</th><th>ยอดสนใจ</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= (int) $r['display_order'] ?></td>
          <td><?= e(assetCode($pdo, (int) $r['id'])) ?></td>
          <td><?= e($r['species_name']) ?></td>
          <td><?= (int) $r['total_scans'] ?></td>
          <td><?= (int) $r['unique_visitors'] ?></td>
          <td><?= (int) $r['total_scans'] - (int) $r['unique_visitors'] ?></td>
          <td><?= (int) $r['interests'] ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
        <tr><td colspan="7">ยังไม่มีข้อมูล</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
</body>
</html>
