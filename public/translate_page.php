<?php
// Called by the browser (public/assets/js/page-translate.js) AFTER a public page has been shown, to translate whatever that
// page still lacks in the visitor's language - in one AI call - and store it. The page is then reloaded once.
// Why here and not while the page is built: see includes/page_translation.php.
//
//   POST type=tree|zone  id=<tree or zone id>  lang=en|zh
//   -> {"status":"complete|translated|partial|failed|busy|disabled","changed":true|false}
//
// Open to visitors, so the cost is bounded: only content that exists and is still untranslated is sent, a successful
// translation is stored (never repeated), only one request per page+language runs at a time, and after a failure the AI
// is not called again for that page+language for a few minutes.
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/page_translation.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function translateJson(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    translateJson(405, ['status' => 'failed', 'changed' => false]);
}
$type = (string) ($_POST['type'] ?? 'tree');
$id = (int) ($_POST['id'] ?? 0);
$lang = (string) ($_POST['lang'] ?? '');
if ($id <= 0 || !in_array($lang, ['en', 'zh'], true) || !in_array($type, ['tree', 'zone'], true)) {
    translateJson(400, ['status' => 'failed', 'changed' => false]);
}
if (!AI_ENABLED) {
    translateJson(200, ['status' => 'disabled', 'changed' => false]);
}

set_time_limit(100);
$pdo = db();
if ($type === 'tree') {
    $tree = getTreeById($pdo, $id);
    if (!$tree) {
        translateJson(404, ['status' => 'failed', 'changed' => false]);
    }
    $result = guardedTranslation("tree_{$id}_$lang", fn() => translateTreePage($pdo, $tree, $lang, 70.0));
} else {
    if (!getZoneById($pdo, $id)) {
        translateJson(404, ['status' => 'failed', 'changed' => false]);
    }
    $result = guardedTranslation("zone_{$id}_$lang", fn() => translateItems($pdo, zonePageMissing($pdo, $id, $lang), $lang, 70.0));
}
translateJson(200, ['status' => $result['status'], 'changed' => $result['changed']]);
