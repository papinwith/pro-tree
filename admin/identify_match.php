<?php
// JSON endpoint used after the in-browser "tree" model (public/assets/js/tree-model.js) has named a plant:
// looks the scientific name up among the already-catalogued species. No AI call, no photo - just a database
// match, so it is cheap. Same access rules as identify_tree.php.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/plant_identify.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function matchJson(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    matchJson(405, ['ok' => false, 'error' => 'ต้องส่งด้วย POST']);
}
if (!adminLoggedIn()) {
    matchJson(401, ['ok' => false, 'error' => 'กรุณาเข้าสู่ระบบใหม่']);
}
if (!canAny(['species.manage', 'tree.create', 'tree.update'])) {
    matchJson(403, ['ok' => false, 'error' => 'บทบาทของคุณไม่มีสิทธิ์ใช้งานส่วนนี้']);
}
$submittedToken = $_POST['csrf_token'] ?? '';
if (!is_string($submittedToken) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $submittedToken)) {
    matchJson(400, ['ok' => false, 'error' => 'คำขอไม่ถูกต้องหรือหมดอายุ กรุณารีเฟรชหน้าแล้วลองใหม่']);
}

$name = trim((string) ($_POST['name_scientific'] ?? ''));
// A Latin binomial: letters, spaces, dots, hyphens, apostrophes and the hybrid sign - nothing else gets near a query.
if ($name === '' || mb_strlen($name) > 160 || !preg_match('/^[\p{L} .\'×-]+$/u', $name)) {
    matchJson(400, ['ok' => false, 'error' => 'ชื่อวิทยาศาสตร์ไม่ถูกต้อง']);
}

session_write_close();
$matched = findMatchingSpecies(
    null,
    ['name_scientific' => $name, 'name_th' => ''],
    identifyCached('species', AI_IDENTIFY_CACHE_SECONDS, fn() => db()->query('SELECT id, name, name_scientific FROM species ORDER BY name')->fetchAll())
);
matchJson(200, [
    'ok' => true,
    'matched_species_id' => $matched ? (int) $matched['id'] : null,
    'matched_species_name' => $matched ? (string) $matched['name'] : null,
]);
