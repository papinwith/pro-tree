<?php
// Shared admin navigation — one button per group; clicking it reveals the
// items inside (no page jump to get an overview). Each item is still
// gated by can(), same as the page it links to, so a group with nothing
// the current admin can reach simply doesn't render. Included from the
// topbar of the "hub" list pages (dashboard/zones/species/categories/
// users); other pages just keep their single "&larr; back" link since
// they're not part of this menu.
$masterDataLinks = [];
if (can('zone.manage')) $masterDataLinks[] = ['zones.php', 'โซน'];
if (can('zone.manage')) $masterDataLinks[] = ['zone_map.php', 'ปักหมุดโซนบนแผนที่'];
if (can('zone.manage')) $masterDataLinks[] = ['map_admin.php', 'แผนที่รวม'];
if (can('category.manage')) $masterDataLinks[] = ['categories.php', 'ประเภทพืช'];
if (can('category.manage')) $masterDataLinks[] = ['subtypes.php', 'ชนิด'];
if (can('species.manage')) $masterDataLinks[] = ['species.php', 'ชื่อต้นไม้'];
if (can('plan.manage')) $masterDataLinks[] = ['plans.php', 'แผนการปลูก'];

$reportLinks = [];
if (can('dashboard.view')) $reportLinks[] = ['executive_dashboard.php', 'แดชบอร์ดผู้บริหาร'];
if (can('interest.view')) $reportLinks[] = ['interests.php', 'ความสนใจ'];
if (can('reports.export')) $reportLinks[] = ['reports.php', 'รายงาน'];

$systemLinks = [];
if (can('settings.manage')) $systemLinks[] = ['settings.php', 'ตั้งค่า'];
if (can('settings.manage')) $systemLinks[] = ['backup.php', 'สำรองข้อมูล'];
if (can('admin.manage')) $systemLinks[] = ['users.php', 'ผู้ใช้งาน'];

$navGroups = [
    'ข้อมูลหลัก' => $masterDataLinks,
    'รายงาน' => $reportLinks,
    'ระบบ' => $systemLinks,
];
?>
<nav class="admin-nav">
  <a class="btn-outline btn-sm" href="<?= e(adminHomeUrl()) ?>">หน้าแรก</a>

  <?php foreach ($navGroups as $groupLabel => $links): if (!$links) continue; ?>
    <details class="admin-nav-group">
      <summary class="btn-outline btn-sm"><?= e($groupLabel) ?></summary>
      <div class="admin-nav-menu">
        <?php foreach ($links as [$href, $label]): ?>
          <a href="<?= e($href) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
      </div>
    </details>
  <?php endforeach; ?>

  <a class="btn-outline btn-sm" href="logout.php">ออกจากระบบ (<?= e($_SESSION['admin_username'] ?? '') ?>)</a>
</nav>
<script>
document.addEventListener('click', function (e) {
  document.querySelectorAll('.admin-nav-group[open]').forEach(function (group) {
    if (!group.contains(e.target)) group.removeAttribute('open');
  });
});
</script>
