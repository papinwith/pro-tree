<?php
/**
 * Regression test for a bug found in production: tree.php's stylesheet link,
 * interest form action, and prev/next nav links used plain relative URLs
 * (e.g. href="assets/css/style.css"). Those resolve correctly when tree.php
 * is hit directly (tree.php?id=2, living in public/), but BREAK under the
 * .htaccess-rewritten pretty URL (/tree/2) because the browser sees an extra
 * "/tree/" path segment that isn't really there on disk — so the CSS (and
 * everything else relative) 404s silently, i.e. "this page has no CSS".
 *
 * This can only be caught by hitting the REAL Apache server, since PHP's
 * built-in dev server (used by the other tests here) doesn't process
 * .htaccess/mod_rewrite at all — that's exactly how this slipped through
 * the rest of the suite. So unlike the other tests, this one talks to
 * whatever Apache is actually running locally (http://localhost/pro%20tree)
 * instead of spinning up its own `php -S` instance, and skips (exit 0) if
 * that's not reachable rather than failing the whole suite.
 *
 * Run: php tests/pretty_url_asset_test.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';

$base = 'http://localhost/pro%20tree/public'; // matches config/config.php's APP_BASE_URL for this install

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "PASS  $label\n";
    } else {
        $fail++;
        echo "FAIL  $label" . ($detail !== '' ? "\n      $detail" : '') . "\n";
    }
}

function httpGet(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => (string) $body];
}

$probe = httpGet("$base/tree.php?id=1");
if ($probe['status'] === 0) {
    echo "SKIP  Apache isn't reachable at $base — start XAMPP Apache to run this test.\n";
    echo "\n0 passed, 0 failed (skipped)\n";
    exit(0);
}

$pdo = db();
$firstTreeId = (int) $pdo->query('SELECT id FROM trees ORDER BY display_order ASC LIMIT 1')->fetchColumn();
if (!$firstTreeId) {
    echo "SKIP  no trees in the DB to test against.\n";
    exit(0);
}

try {
    $r = httpGet("$base/tree/$firstTreeId"); // the pretty, rewritten route — the one that broke
    check('pretty /tree/{id} URL returns 200', $r['status'] === 200, "got {$r['status']}");

    check(
        'stylesheet link is base-path-absolute, not bare-relative',
        (bool) preg_match('#href="/pro%20tree/public/assets/css/style\.css"#', $r['body']),
        'looking for an absolute href in: ' . (preg_match('/<link rel="stylesheet"[^>]*>/', $r['body'], $m) ? $m[0] : '(not found)')
    );
    check(
        'interest form action is base-path-absolute, not bare-relative',
        (bool) preg_match('#action="/pro%20tree/public/interest\.php"#', $r['body'])
    );

    // The actual, most important check: the asset the page LINKS to must be
    // fetchable AS SEEN FROM the pretty URL's own address bar — i.e. resolve
    // any relative href in the response against $base/tree/$firstTreeId,
    // exactly like a real browser would, and confirm it's not a 404.
    if (preg_match('/<link rel="stylesheet" href="([^"]+)"/', $r['body'], $m)) {
        $cssHref = html_entity_decode($m[1]);
        if (str_starts_with($cssHref, 'http')) {
            $resolvedCssUrl = $cssHref;
        } elseif (str_starts_with($cssHref, '/')) {
            $resolvedCssUrl = 'http://localhost' . $cssHref;
        } else {
            // Naive relative resolution against the pretty URL's own address
            // bar, exactly like a real browser would do.
            $resolvedCssUrl = "$base/tree/$firstTreeId/../$cssHref";
        }
        $cssResult = httpGet($resolvedCssUrl);
        check('the CSS the pretty-URL page actually links to is fetchable (200)', $cssResult['status'] === 200, "resolved to $resolvedCssUrl, got {$cssResult['status']}");
    } else {
        check('found a stylesheet link to check', false);
    }

    // Direct tree.php?id= access must keep working exactly as before (no regression).
    $rDirect = httpGet("$base/tree.php?id=$firstTreeId");
    check('direct tree.php?id= form still returns 200', $rDirect['status'] === 200);
    check(
        'direct form also gets the base-path-absolute stylesheet link',
        (bool) preg_match('#href="/pro%20tree/public/assets/css/style\.css"#', $rDirect['body'])
    );
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    $fail++;
}

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
