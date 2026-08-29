<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/translation.php';

$pdo = db();

$base = appBasePath();

$treeId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$tree = $treeId ? getTreeById($pdo, $treeId) : null;

if (!$tree) {
    http_response_code(404);
    echo '<h1>Tree not found</h1>';
    exit;
}

// --- Visitor identity + scan logging ---
// Every visit is logged (including repeats), but repeat scans are never
// flagged to the visitor — see docs/visitor-identity.md.
$visitorId = getOrCreateVisitorId($pdo);
$scanId = logScan(
    $pdo,
    $tree['id'],
    $visitorId,
    $tree['zone_id'] !== null ? (int) $tree['zone_id'] : null,
    $tree['latitude'] !== null ? (float) $tree['latitude'] : null,
    $tree['longitude'] !== null ? (float) $tree['longitude'] : null
);
$stats = getScanStats($pdo, $tree['id']);

// --- Prev / Next ---
$prevTree = getPrevTree($pdo, (int) $tree['display_order']);
$nextTree = getNextTree($pdo, (int) $tree['display_order']);

// --- Map banner (per-tree override falls back to global default) ---
$mapImage = $tree['map_image_path'] ?: getSetting($pdo, 'default_map_image', '');
$mapUrl = $tree['map_url'] ?: getSetting($pdo, 'default_map_url', '#');
$siteLogo = getSetting($pdo, 'site_logo', '');

// --- Flash message from interest submission redirect ---
$flash = null;
if (isset($_GET['interest']) && $_GET['interest'] === 'ok') {
    $flash = ['type' => 'ok', 'text' => t('flash_interest_ok')];
} elseif (isset($_GET['interest']) && $_GET['interest'] === 'error') {
    $flash = ['type' => 'error', 'text' => t('flash_interest_error')];
}

$locale = currentLocale();

// Admin only ever enters Thai — EN/ZH are generated on first view in that
// language and cached on the species/zone rows, so this only calls Gemini
// once per species/zone per language, not on every request.
if ($locale !== 'th') {
    $tree = ensureSpeciesTranslated($pdo, $tree, $locale);

    $translatedZone = ensureZoneTranslated($pdo, (int) $tree['zone_id'], $locale);
    $tree['zone_name_' . $locale] = $translatedZone['name_' . $locale] ?? ($tree['zone_name_' . $locale] ?? null);
}

$treeName = localizedTreeField($tree, 'name');
$treeDescription = localizedTreeField($tree, 'description');

// Sourced from species.category_code (always set), not the legacy
// classification_id (optional, not admin-settable anymore) — otherwise
// the category badge silently disappears for every species added after
// classification_id was retired from the admin form.
$category = !empty($tree['category_code']) ? getCategoryByCode($pdo, $tree['category_code']) : null;
if ($category && $locale !== 'th') {
    $category = ensureCategoryTranslated($pdo, $tree['category_code'], $locale);
}
$categoryName = $category ? ($category['name_' . $locale] ?? $category['name_th']) : null;
$saleStatus = speciesSaleStatus($pdo, (int) $tree['species_id']);
$saleStatusLabels = [
    'available' => t('sale_status_available'),
    'reserved' => t('sale_status_reserved'),
    'sold_out' => t('sale_status_sold_out'),
];

// Detail sections rendered below the description, each falling back to
// empty (hidden) when the admin hasn't filled it in for this species.
$detailSections = [
    'care_instructions' => t('care_instructions_label'),
    'characteristics' => t('characteristics_label'),
    'properties' => t('properties_label'),
    'benefits' => t('benefits_label'),
    'cautions' => t('cautions_label'),
    'part_uses' => t('part_uses_label'),
];
?>
<!doctype html>
<html lang="<?= e($locale) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($treeName) ?> <?= e(t('tree_page_title_suffix')) ?></title>
<link rel="stylesheet" href="<?= e($base) ?>/assets/css/style.css">
</head>
<body>
<div class="page">

  <div class="lang-switch">
    <?php foreach (SUPPORTED_LOCALES as $loc): ?>
      <a href="<?= e(localeSwitchUrl($loc)) ?>" class="<?= $loc === $locale ? 'active' : '' ?>"><?= e(strtoupper($loc)) ?></a>
    <?php endforeach; ?>
  </div>

  <?php if ($mapImage): ?>
  <a class="map-banner" href="<?= e($mapUrl) ?>" target="_blank" rel="noopener">
    <img src="<?= e(str_starts_with($mapImage, 'http') ? $mapImage : $base . '/' . ltrim($mapImage, '/')) ?>" alt="Map — click to open full map">
  </a>
  <?php endif; ?>

  <div class="content">

    <?php if ($siteLogo): ?>
      <img class="site-logo" src="<?= e($base . '/' . ltrim($siteLogo, '/')) ?>" alt="">
    <?php endif; ?>

    <?php if ($flash): ?>
      <div class="flash <?= $flash['type'] === 'error' ? 'error' : '' ?>"><?= e($flash['text']) ?></div>
    <?php endif; ?>

    <?php if ($tree['image_path']): ?>
      <img class="tree-image" src="<?= e($base . '/' . ltrim($tree['image_path'], '/')) ?>" alt="<?= e($treeName) ?>">
    <?php endif; ?>

    <h1 class="tree-name"><?= e($treeName) ?></h1>
    <?php if (!empty($tree['name_scientific']) || !empty($tree['name_common'])): ?>
      <p class="tree-subname">
        <?php if (!empty($tree['name_scientific'])): ?><em><?= e($tree['name_scientific']) ?></em><?php endif; ?>
        <?php if (!empty($tree['name_scientific']) && !empty($tree['name_common'])): ?> · <?php endif; ?>
        <?= e($tree['name_common'] ?? '') ?>
      </p>
    <?php endif; ?>

    <?php if (!empty($tree['classification_id']) || !empty($tree['zone_name'])): ?>
      <div class="classification-box">
        <?php if ($categoryName): ?>
          <span class="category-badge"><?= e(t('category_label')) ?>: <?= e($categoryName) ?></span>
        <?php endif; ?>
        <?php if (!empty($tree['classification_id'])): ?>
          <span class="classification-code"><?= e(t('classification_label')) ?>: <?= e($tree['classification_id']) ?></span>
        <?php endif; ?>
        <?php if (!empty($tree['plant_code'])): ?>
          <span class="classification-code"><?= e(t('plant_code_label')) ?>: <?= e($tree['plant_code']) ?></span>
        <?php endif; ?>
        <?php if (!empty($tree['zone_name'])): ?>
          <span class="classification-code"><?= e(t('zone_label')) ?>: <?= e(localizedTreeField(['name' => $tree['zone_name'], 'name_en' => $tree['zone_name_en'], 'name_zh' => $tree['zone_name_zh']], 'name')) ?></span>
        <?php endif; ?>
        <?php if ($saleStatus): ?>
          <span class="sale-badge sale-badge-<?= e($saleStatus) ?>"><?= e($saleStatusLabels[$saleStatus] ?? $saleStatus) ?></span>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <p class="tree-desc"><?= nl2br(e($treeDescription)) ?></p>

    <div class="detail-sections">
      <?php foreach ($detailSections as $field => $label):
        $value = localizedTreeField($tree, $field);
        if ($value === '') continue;
      ?>
        <section class="detail-section">
          <h2><?= e($label) ?></h2>
          <p><?= nl2br(e($value)) ?></p>
        </section>
      <?php endforeach; ?>
    </div>

    <div class="stats-box">
      👁 <strong><?= number_format($stats['unique']) ?></strong> <?= e(t('stats_visitors')) ?>
      (<?= number_format($stats['total']) ?> <?= e(t('stats_total_scans')) ?>)
    </div>

    <div class="interest-section">
      <div class="btn-row">
        <button class="btn" type="button" id="interestBtn"><?= e(t('interested_btn')) ?></button>
        <?php if ($saleStatus === 'available'): ?>
          <button class="btn btn-outline" type="button" id="priceBtn"><?= e(t('price_request_btn')) ?></button>
        <?php endif; ?>
      </div>
      <form class="email-form" id="interestForm" method="post" action="<?= e($base) ?>/interest.php">
        <input type="hidden" name="tree_id" value="<?= (int) $tree['id'] ?>">
        <input type="hidden" name="activity_type" id="activityTypeInput" value="interest_click">
        <input type="email" name="email" placeholder="<?= e(t('email_placeholder')) ?>" required>
        <button class="btn" type="submit"><?= e(t('submit_btn')) ?></button>
      </form>
    </div>

    <div class="nav-buttons">
      <?php if ($prevTree): ?>
        <a href="<?= e($base) ?>/tree.php?id=<?= (int) $prevTree['id'] ?>&lang=<?= e($locale) ?>"><?= e(t('prev_tree')) ?></a>
      <?php else: ?>
        <span class="disabled"><?= e(t('prev_tree')) ?></span>
      <?php endif; ?>

      <?php if ($nextTree): ?>
        <a href="<?= e($base) ?>/tree.php?id=<?= (int) $nextTree['id'] ?>&lang=<?= e($locale) ?>"><?= e(t('next_tree')) ?></a>
      <?php else: ?>
        <span class="disabled"><?= e(t('next_tree')) ?></span>
      <?php endif; ?>
    </div>

  </div>
</div>

<script>
(function () {
  var form = document.getElementById('interestForm');
  var activityInput = document.getElementById('activityTypeInput');
  var priceBtn = document.getElementById('priceBtn');

  document.getElementById('interestBtn').addEventListener('click', function () {
    activityInput.value = 'interest_click';
    form.classList.toggle('open');
  });

  if (priceBtn) {
    priceBtn.addEventListener('click', function () {
      activityInput.value = 'price_request';
      form.classList.add('open');
    });
  }

  // Scan-location capture — best-effort, never blocks the page. The scan
  // itself was already logged server-side before this HTML was sent; this
  // only attaches the visitor's own GPS position to that same scan row,
  // if the browser has geolocation and the visitor grants permission.
  if ('geolocation' in navigator) {
    navigator.geolocation.getCurrentPosition(function (pos) {
      fetch('<?= e($base) ?>/scan_geo.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'scan_id=<?= (int) $scanId ?>'
          + '&lat=' + encodeURIComponent(pos.coords.latitude)
          + '&lng=' + encodeURIComponent(pos.coords.longitude)
          + '&accuracy=' + encodeURIComponent(pos.coords.accuracy),
        keepalive: true
      });
    }, function () {
      // Denied, unavailable, or timed out — nothing to do. The page
      // already rendered normally without waiting on this.
    }, { maximumAge: 60000, timeout: 8000 });
  }
})();
</script>
</body>
</html>
