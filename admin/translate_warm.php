<?php
// JSON endpoint behind the "translate everything in advance" button on the settings page: translates the public
// content into English and Chinese ahead of time (before an exhibition), one small step per request, so visitors never
// have to wait for an AI. Steps are driven by the page's JavaScript.
//
//   POST action=plan                              -> {"ok":true,"species":[{"id":1,"name":"..."}],"langs":["en","zh"]}
//   POST action=run task=species id=N lang=en|zh  -> {"ok":true,"status":"translated|complete|partial|failed","stored":n,"wanted":n}
//   POST action=run task=shared lang=en|zh        -> the same, for every zone, every category and the contact texts
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/page_translation.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function warmJson(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    warmJson(405, ['ok' => false, 'error' => 'ต้องส่งด้วย POST']);
}
if (!adminLoggedIn()) {
    warmJson(401, ['ok' => false, 'error' => 'กรุณาเข้าสู่ระบบใหม่']);
}
if (!can('settings.manage')) {
    warmJson(403, ['ok' => false, 'error' => 'บทบาทของคุณไม่มีสิทธิ์ใช้งานส่วนนี้']);
}
$token = $_POST['csrf_token'] ?? '';
if (!is_string($token) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
    warmJson(400, ['ok' => false, 'error' => 'คำขอไม่ถูกต้องหรือหมดอายุ กรุณารีเฟรชหน้า']);
}
if (!AI_ENABLED) {
    warmJson(503, ['ok' => false, 'error' => 'ปิดการใช้งาน AI อยู่']);
}
session_write_close();
set_time_limit(100);
$pdo = db();

if (($_POST['action'] ?? '') === 'plan') {
    $rows = $pdo->query('SELECT id, name FROM species ORDER BY id')->fetchAll();
    warmJson(200, ['ok' => true, 'species' => array_map(fn($r) => ['id' => (int) $r['id'], 'name' => (string) $r['name']], $rows), 'langs' => ['en', 'zh']]);
}
if (($_POST['action'] ?? '') === 'run') {
    $lang = (string) ($_POST['lang'] ?? '');
    if (!in_array($lang, ['en', 'zh'], true)) {
        warmJson(400, ['ok' => false, 'error' => 'ภาษาไม่ถูกต้อง']);
    }
    if (($_POST['task'] ?? '') === 'species') {
        $stmt = $pdo->prepare('SELECT * FROM species WHERE id = :id');
        $stmt->execute(['id' => (int) ($_POST['id'] ?? 0)]);
        $species = $stmt->fetch();
        if (!$species) {
            warmJson(404, ['ok' => false, 'error' => 'ไม่พบชนิดพันธุ์']);
        }
        $items = speciesMissingItems($species, $lang);
    } elseif (($_POST['task'] ?? '') === 'shared') {
        $items = sharedMissingItems($pdo, $lang);
    } else {
        warmJson(400, ['ok' => false, 'error' => 'งานไม่ถูกต้อง']);
    }
    $r = translateItems($pdo, $items, $lang, 80.0);
    warmJson(200, ['ok' => true] + array_intersect_key($r, array_flip(['status', 'stored', 'wanted'])));
}
warmJson(400, ['ok' => false, 'error' => 'คำสั่งไม่ถูกต้อง']);
