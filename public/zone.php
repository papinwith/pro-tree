<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/translation.php';
require_once __DIR__ . '/../includes/page_translation.php';

$pdo = db();

$base = appBasePath();

$zoneId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$zone = $zoneId ? getZoneById($pdo, $zoneId) : null;

if (!$zone) {
    http_response_code(404);
    echo '<h1>Zone not found</h1>';
    exit;
}

$locale = currentLocale();

// Built from what is cached now (Thai where untranslated); the missing part is translated after the page has shown.
$needsTranslation = $locale !== 'th' && AI_ENABLED && zonePageMissing($pdo, $zoneId, $locale) !== [];

$zoneName = localizedTreeField($zone, 'name');
$zoneDescription = localizedTreeField($zone, 'description');

$trees = getTreesByZone($pdo, $zoneId);
?>
<!doctype html>
<html lang="<?= e($locale) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($zoneName) ?> <?= e(t('zone_page_title_suffix')) ?></title>
<link rel="stylesheet" href="<?= e($base) ?>/assets/css/style.css">
</head>
<body<?php if ($needsTranslation): ?> data-translate-url="translate_page.php" data-translate-type="zone" data-translate-id="<?= (int) $zoneId ?>" data-translate-lang="<?= e($locale) ?>" data-translate-notice="<?= e(t('translating_notice')) ?>"<?php endif; ?>>
<div class="page">

  <div class="lang-switch">
    <?php foreach (SUPPORTED_LOCALES as $loc): ?>
      <a href="<?= e(localeSwitchUrl($loc)) ?>" class="<?= $loc === $locale ? 'active' : '' ?>"><?= e(strtoupper($loc)) ?></a>
    <?php endforeach; ?>
  </div>

  <div class="content">

    <h1 class="tree-name"><?= e($zoneName) ?></h1>

    <?php if ($zoneDescription !== ''): ?>
      <p class="tree-desc"><?= nl2br(e($zoneDescription)) ?></p>
    <?php endif; ?>

    <p class="muted-note"><?= e(sprintf(t('tree_count_label'), count($trees))) ?></p>

    <?php if ($trees): ?>
      <div class="tree-grid">
        <?php foreach ($trees as $t): ?>
          <a class="tree-card" href="<?= e($base) ?>/tree.php?id=<?= (int) $t['id'] ?>">
            <?php if ($t['image_path']): ?>
              <img src="<?= e($base . '/' . ltrim($t['image_path'], '/')) ?>" alt="<?= e(localizedTreeField($t, 'name')) ?>">
            <?php else: ?>
              <div class="tree-card-placeholder"></div>
            <?php endif; ?>
            <h3><?= e(localizedTreeField($t, 'name')) ?></h3>
          </a>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p class="muted-note"><?= e(t('zone_no_trees_label')) ?></p>
    <?php endif; ?>

  </div>
</div>
<?php if ($needsTranslation): ?><script src="<?= e($base) ?>/assets/js/page-translate.js"></script><?php endif; ?>
</body>
</html>
