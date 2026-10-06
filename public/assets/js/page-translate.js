// Asks the server to translate what this page still lacks in the visitor's language, AFTER the page has been shown, then
// reloads it once so the translated text appears. The page itself never waits for the AI.
//
//   <body data-translate-url="translate_page.php" data-translate-type="tree" data-translate-id="12"
//         data-translate-lang="en" data-translate-notice="Translating this page...">
(function () {
  var body = document.body;
  var url = body.dataset.translateUrl;
  if (!url) return;
  var form = 'type=' + encodeURIComponent(body.dataset.translateType || 'tree') +
    '&id=' + encodeURIComponent(body.dataset.translateId || '') +
    '&lang=' + encodeURIComponent(body.dataset.translateLang || '');

  // One attempt per page and language per browser session, so a failing translation can never turn into a reload loop.
  var key = 'page-translate:' + location.pathname + '?' + form;
  try { if (sessionStorage.getItem(key)) return; } catch (e) { /* private mode: fall through, the server also limits repeats */ }
  function remember() { try { sessionStorage.setItem(key, '1'); } catch (e) { /* ignore */ } }

  var banner = document.createElement('div');
  banner.setAttribute('role', 'status');
  banner.textContent = body.dataset.translateNotice || '...';
  banner.style.cssText = 'position:fixed;left:50%;bottom:16px;transform:translateX(-50%);z-index:50;max-width:92%;padding:8px 16px;' +
    'border-radius:999px;background:#1f2937;color:#fff;font:14px/1.4 system-ui,sans-serif;box-shadow:0 2px 10px rgba(0,0,0,.3)';
  document.body.appendChild(banner);

  var tries = 0;
  function finish(reload) {
    remember();
    if (reload) { location.reload(); return; }
    banner.remove();
  }
  function run() {
    fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: form })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        // another visitor's request is already translating this page: wait a little and ask again
        if (j.status === 'busy' && tries++ < 8) { setTimeout(run, 4000); return; }
        finish(!!j.changed);
      })
      .catch(function () { finish(false); });
  }
  run();
})();
