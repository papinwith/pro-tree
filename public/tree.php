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

// --- Latest size measurement, if staff have ever recorded one ---
$observations = getObservationsForTree($pdo, $tree['id']);
$latestObservation = $observations[0] ?? null;

// --- Nursery stock currently for sale, with price — visitors should be
// able to see the price up front, not only after clicking "request price". ---
$stockForSale = array_values(array_filter(
    getStockForSpecies($pdo, (int) $tree['species_id']),
    fn($s) => $s['sale_status'] === 'available'
));

// --- Map banner (per-tree override falls back to global default) ---
$mapImage = $tree['map_image_path'] ?: getSetting($pdo, 'default_map_image', '');
$siteLogo = getSetting($pdo, 'site_logo', '');

// --- Zone pins overlaid on the map banner — too many trees to pin
// individually, so pins are placed per zone in admin/zone_map.php. ---
$mapZonePins = array_values(array_filter(
    getAllZones($pdo),
    fn($z) => $z['map_pin_x'] !== null && $z['map_pin_y'] !== null
));

// --- Flash message from interest submission redirect ---
$flash = null;
if (isset($_GET['interest']) && $_GET['interest'] === 'ok') {
    $flash = ['type' => 'ok', 'text' => t('flash_interest_ok')];
} elseif (isset($_GET['interest']) && $_GET['interest'] === 'error') {
    $flash = ['type' => 'error', 'text' => t('flash_interest_error')];
}

$locale = currentLocale();

// --- Contact info / hours, shown near the bottom if the admin filled any in ---
// Phone/LINE are identifiers, not language content — left as-is. Address
// and hours are free text, so they go through the same lazy AI-translate-
// and-cache pattern as species/zone/category content.
$contactPhone = getSetting($pdo, 'contact_phone', '');
$contactLine = getSetting($pdo, 'contact_line', '');
$contactAddress = getSetting($pdo, 'contact_address', '');
$openingHours = getSetting($pdo, 'opening_hours', '');
if ($locale !== 'th') {
    // Up to 5 sequential Gemini calls can land below (contact address,
    // opening hours, species, zone, category), each allowed up to 45s on a
    // cache miss — comfortably past PHP's default 30s max_execution_time.
    // Only raised on this non-Thai, cache-miss-possible path; the Thai path
    // above never calls out to Gemini at all.
    set_time_limit(240);
    $contactAddress = ensureSettingTranslated($pdo, 'contact_address', $contactAddress, $locale);
    $openingHours = ensureSettingTranslated($pdo, 'opening_hours', $openingHours, $locale);
}

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
  <div class="map-banner-wrap">
    <img class="map-banner" id="mapBannerImage" src="<?= e(resolveAssetUrl($mapImage, $base)) ?>" alt="<?= e(t('map_label')) ?>" tabindex="0" role="button" aria-label="<?= e(t('expand_image_label')) ?>">
    <?php foreach ($mapZonePins as $z): ?>
      <button type="button" class="map-pin<?= (int) $z['id'] === (int) $tree['zone_id'] ? ' current' : '' ?>"
              style="left:<?= e((string) $z['map_pin_x']) ?>%; top:<?= e((string) $z['map_pin_y']) ?>%"
              aria-label="<?= e(localizedTreeField($z, 'name')) ?>">
        <span class="map-pin-label"><?= e(localizedTreeField($z, 'name')) ?></span>
      </button>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="content">

    <?php if ($siteLogo): ?>
      <img class="site-logo" src="<?= e($base . '/' . ltrim($siteLogo, '/')) ?>" alt="">
    <?php endif; ?>

    <?php if ($flash): ?>
      <div class="flash <?= $flash['type'] === 'error' ? 'error' : '' ?>"><?= e($flash['text']) ?></div>
    <?php endif; ?>

    <?php if ($tree['image_path']): ?>
      <img class="tree-image" id="treeImage" src="<?= e($base . '/' . ltrim($tree['image_path'], '/')) ?>" alt="<?= e($treeName) ?>" tabindex="0" role="button" aria-label="<?= e(t('expand_image_label')) ?>">
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
        <?php if (!empty($tree['zone_name'])): ?>
          <span class="classification-code"><?= e(t('zone_label')) ?>: <?= e(localizedTreeField(['name' => $tree['zone_name'], 'name_en' => $tree['zone_name_en'], 'name_zh' => $tree['zone_name_zh']], 'name')) ?></span>
        <?php endif; ?>
        <?php if ($saleStatus): ?>
          <span class="sale-badge sale-badge-<?= e($saleStatus) ?>"><?= e($saleStatusLabels[$saleStatus] ?? $saleStatus) ?></span>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($latestObservation && ($latestObservation['height_cm'] !== null || $latestObservation['canopy_cm'] !== null)): ?>
      <div class="measurement-box">
        <strong><?= e(t('measurement_label')) ?>:</strong>
        <?php if ($latestObservation['height_cm'] !== null): ?>
          <?= e(t('height_label')) ?> <?= e(number_format((float) $latestObservation['height_cm'], 0)) ?> ซม.
        <?php endif; ?>
        <?php if ($latestObservation['height_cm'] !== null && $latestObservation['canopy_cm'] !== null): ?> · <?php endif; ?>
        <?php if ($latestObservation['canopy_cm'] !== null): ?>
          <?= e(t('canopy_label')) ?> <?= e(number_format((float) $latestObservation['canopy_cm'], 0)) ?> ซม.
        <?php endif; ?>
        <span class="muted-note">(<?= e(t('measured_at_label')) ?> <?= e($latestObservation['observed_at']) ?>)</span>
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

    <?php if ($stockForSale): ?>
      <div class="price-box">
        <strong><?= e(t('price_label')) ?>:</strong>
        <ul class="price-list">
          <?php foreach ($stockForSale as $stock): ?>
            <li>
              <?php if (!empty($stock['size_name_th'])): ?><?= e(localizedTreeField($stock, 'size_name')) ?> — <?php endif; ?>
              <?php if ($stock['price'] !== null): ?>
                <?= number_format((float) $stock['price'], 0) ?> ฿
              <?php else: ?>
                <?= e(t('price_request_btn')) ?>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

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

    <?php if ($contactPhone || $contactLine || $contactAddress || $openingHours): ?>
      <div class="contact-box">
        <strong><?= e(t('contact_label')) ?></strong>
        <?php if ($contactPhone): ?><p><?= e($contactPhone) ?></p><?php endif; ?>
        <?php if ($contactLine): ?><p>LINE: <?= e($contactLine) ?></p><?php endif; ?>
        <?php if ($contactAddress): ?><p><?= e(t('address_label')) ?>: <?= nl2br(e($contactAddress)) ?></p><?php endif; ?>
        <?php if ($openingHours): ?><p><?= e(t('opening_hours_label')) ?>: <?= e($openingHours) ?></p><?php endif; ?>
      </div>
    <?php endif; ?>

  </div>
</div>

<?php if ($tree['image_path'] || $mapImage): ?>
<div class="lightbox-overlay" id="imageLightbox" hidden>
  <button type="button" class="lightbox-close" id="imageLightboxClose" aria-label="Close">×</button>
  <img class="lightbox-img" id="imageLightboxImg" src="" alt="<?= e($treeName) ?>">
</div>
<?php endif; ?>

<script>
(function () {
  var form = document.getElementById('interestForm');
  var activityInput = document.getElementById('activityTypeInput');
  var priceBtn = document.getElementById('priceBtn');

  document.getElementById('interestBtn').addEventListener('click', function () {
    activityInput.value = 'interest_click';
    form.classList.toggle('open');
  });

  // Zone pins on the map banner — tap to show/hide the zone name label;
  // must not also trigger the banner image's own tap-to-zoom lightbox.
  document.querySelectorAll('.map-pin').forEach(function (pin) {
    pin.addEventListener('click', function (e) {
      e.stopPropagation();
      var wasOpen = pin.classList.contains('open');
      document.querySelectorAll('.map-pin.open').forEach(function (p) { p.classList.remove('open'); });
      if (!wasOpen) pin.classList.add('open');
    });
  });

  // Tap the tree photo, or the map banner, to view it full-size in the same popup.
  var lightboxTriggers = [document.getElementById('treeImage'), document.getElementById('mapBannerImage')].filter(Boolean);
  var lightbox = document.getElementById('imageLightbox');
  if (lightboxTriggers.length && lightbox) {
    var lightboxImg = document.getElementById('imageLightboxImg');
    var closeLightbox = function () {
      lightbox.hidden = true;
      lightboxImg.src = '';
    };
    var openLightbox = function (img) {
      lightboxImg.src = img.src;
      lightbox.hidden = false;
    };
    lightboxTriggers.forEach(function (img) {
      img.addEventListener('click', function () { openLightbox(img); });
      img.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openLightbox(img); }
      });
    });
    lightbox.addEventListener('click', closeLightbox);
    document.getElementById('imageLightboxClose').addEventListener('click', function (e) {
      e.stopPropagation();
      closeLightbox();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !lightbox.hidden) closeLightbox();
    });
  }

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
