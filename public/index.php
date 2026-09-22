<?php
require_once __DIR__ . '/../includes/functions.php';

$locale = currentLocale();

// Client-side dictionary for every supported locale, so switching languages
// can rewrite the page's text in place via JS instead of navigating —
// a full page reload would tear down the active camera stream, which
// violates "changing the language must not close the camera".
$scannerKeys = [
    'scan_title', 'scan_heading', 'scan_prompt', 'scan_open_camera', 'scan_close_camera',
    'scan_aim', 'scan_found', 'scan_camera_denied', 'scan_unsupported',
];
$localeStrings = [];
foreach (SUPPORTED_LOCALES as $loc) {
    $translations = loadTranslations($loc);
    $fallback = loadTranslations('th');
    foreach ($scannerKeys as $key) {
        $localeStrings[$loc][$key] = $translations[$key] ?? $fallback[$key] ?? $key;
    }
}
?>
<!doctype html>
<html lang="<?= e($locale) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title id="pageTitle"><?= e(t('scan_title')) ?></title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="page">
  <div class="lang-switch" id="langSwitch">
    <?php foreach (SUPPORTED_LOCALES as $loc): ?>
      <a href="<?= e(localeSwitchUrl($loc)) ?>" data-lang="<?= e($loc) ?>" class="<?= $loc === $locale ? 'active' : '' ?>"><?= e(strtoupper($loc)) ?></a>
    <?php endforeach; ?>
  </div>

  <div class="content scan-wait">

    <div class="scanner" id="scanner">
      <video id="scannerVideo" playsinline muted></video>
      <div class="scanner-frame"></div>
    </div>
    <canvas id="scannerCanvas" hidden></canvas>

    <h1 class="tree-name" id="scanHeading"><?= e(t('scan_heading')) ?></h1>
    <p class="tree-desc" id="scannerStatus"><?= e(t('scan_prompt')) ?></p>

    <button class="btn" type="button" id="toggleCameraBtn"><?= e(t('scan_open_camera')) ?></button>
  </div>
</div>

<script src="assets/js/jsQR.js"></script>
<script>
(function () {
  var video = document.getElementById('scannerVideo');
  var canvas = document.getElementById('scannerCanvas');
  var ctx = canvas.getContext('2d', { willReadFrequently: true });
  var status = document.getElementById('scannerStatus');
  var heading = document.getElementById('scanHeading');
  var pageTitle = document.getElementById('pageTitle');
  var toggleBtn = document.getElementById('toggleCameraBtn');
  var scannerBox = document.getElementById('scanner');
  var langSwitch = document.getElementById('langSwitch');
  var stream = null;
  var scanning = false;

  // How often to attempt a decode, in ms. Decoding every animation frame
  // (~60/sec) burns CPU/battery for no real benefit on a static QR target,
  // so this throttles to once every 3 seconds.
  var SCAN_INTERVAL_MS = 3000;
  var lastAttemptAt = 0;

  var LOCALES = <?= json_encode($localeStrings) ?>;
  var locale = <?= json_encode($locale) ?>;
  var i18n = LOCALES[locale];

  // Tracks which i18n string is currently shown as the status line, so a
  // language switch can re-render it in the new language without disturbing
  // camera state (found/aim/prompt/denied/unsupported).
  var statusKey = 'scan_prompt';

  function setStatus(key) {
    statusKey = key;
    status.textContent = i18n[key];
  }

  function applyLocale(newLocale) {
    locale = newLocale;
    i18n = LOCALES[locale];

    document.documentElement.lang = locale;
    pageTitle.textContent = i18n.scan_title;
    heading.textContent = i18n.scan_heading;
    status.textContent = i18n[statusKey];
    toggleBtn.textContent = stream ? i18n.scan_close_camera : i18n.scan_open_camera;

    var links = langSwitch.querySelectorAll('a');
    for (var i = 0; i < links.length; i++) {
      links[i].classList.toggle('active', links[i].getAttribute('data-lang') === locale);
    }

    document.cookie = 'tree_lang=' + encodeURIComponent(locale) + '; path=/; max-age=' + (60 * 60 * 24 * 365) + '; samesite=Lax';
  }

  langSwitch.addEventListener('click', function (e) {
    var link = e.target.closest('a[data-lang]');
    if (!link) return;
    e.preventDefault();
    applyLocale(link.getAttribute('data-lang'));
  });

  function resolveDestination(text) {
    try {
      var url = new URL(text, window.location.href);
      if (url.origin === window.location.origin) {
        return url.href;
      }
    } catch (e) {
      // not a URL — ignore
    }
    return null;
  }

  function tick(timestamp) {
    if (!scanning) return;
    if (timestamp - lastAttemptAt >= SCAN_INTERVAL_MS && video.readyState === video.HAVE_ENOUGH_DATA) {
      lastAttemptAt = timestamp;
      canvas.width = video.videoWidth;
      canvas.height = video.videoHeight;
      ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
      var imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
      var code = window.jsQR(imageData.data, imageData.width, imageData.height, {
        inversionAttempts: 'dontInvert',
      });
      if (code && code.data) {
        var dest = resolveDestination(code.data);
        if (dest) {
          scanning = false;
          setStatus('scan_found');
          stopCamera();
          window.location.href = dest;
          return;
        }
      }
    }
    requestAnimationFrame(tick);
  }

  function stopCamera() {
    scanning = false;
    if (stream) {
      stream.getTracks().forEach(function (track) { track.stop(); });
      stream = null;
    }
    video.srcObject = null;
    scannerBox.classList.remove('active');
    toggleBtn.textContent = i18n.scan_open_camera;
    setStatus('scan_prompt');
  }

  function startCamera() {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      setStatus('scan_unsupported');
      return;
    }
    toggleBtn.disabled = true;
    navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
      .then(function (s) {
        stream = s;
        video.srcObject = stream;
        video.setAttribute('playsinline', true);
        video.play();
        scannerBox.classList.add('active');
        toggleBtn.disabled = false;
        toggleBtn.textContent = i18n.scan_close_camera;
        setStatus('scan_aim');
        scanning = true;
        lastAttemptAt = 0;
        requestAnimationFrame(tick);
      })
      .catch(function () {
        toggleBtn.disabled = false;
        setStatus('scan_camera_denied');
      });
  }

  toggleBtn.addEventListener('click', function () {
    if (stream) {
      stopCamera();
    } else {
      startCamera();
    }
  });
})();
</script>
</body>
</html>
