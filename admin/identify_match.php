<?php
// JSON endpoint used after the in-browser "tree" model (public/assets/js/tree-model.js) has named a plant:
// looks the scientific name up among the already-catalogued species. No AI call, no photo - just a database
// match, so it is cheap. Same access rules as identify_tree.php.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/plant_identify.php';
require_once __DIR__ . '/../includes/species_details.php';

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
$payload = [
    'ok' => true,
    'matched_species_id' => $matched ? (int) $matched['id'] : null,
    'matched_species_name' => $matched ? (string) $matched['name'] : null,
];
// The browser model only knows the name; the details (care, characteristics, ...) are filled in here, every time:
// catalogued text, else the AI's earlier write-up for this species, else a fresh one.
if (($_POST['want_details'] ?? '') === '1') {
    $catalogue = null;
    if (($_POST['detail'] ?? '') === 'full' && can('species.manage')) {
        $catalogue = [
            'categories' => identifyCached('categories', AI_IDENTIFY_CACHE_SECONDS, fn() => getAllCategories(db())),
            'subtypes' => identifyCached('subtypes', AI_IDENTIFY_CACHE_SECONDS, fn() => getAllSubtypes(db())),
        ];
    }
    $seed = normalizePlantIdentification(['is_plant' => true, 'name_scientific' => $name, 'name_th' => $payload['matched_species_name'] ?? '', 'confidence_pct' => 90]);
    $done = completeWithDetails(db(), $seed, $catalogue, 45.0, null, $matched ? (int) $matched['id'] : null);
    foreach (array_merge(speciesDetailKeys(), ['category_code', 'category_name', 'subtype_ids', 'subtype_names', 'details_status', 'details_source']) as $k) {
        $payload[$k] = $done[$k] ?? null;
    }
}
matchJson(200, $payload);
