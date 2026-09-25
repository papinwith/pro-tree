<?php
// JSON endpoint behind the "ให้ AI ช่วยระบุชนิดต้นไม้" button on the species
// and tree forms (public/assets/js/ai-identify-button.js). Takes one photo,
// returns Gemini's best-guess identification plus the id of a matching
// already-catalogued species, if any. Never writes to the database — the
// admin reviews the suggestion and applies it (or not) in the form.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/plant_identify.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function identifyJson(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    identifyJson(405, ['ok' => false, 'error' => 'ต้องส่งด้วย POST']);
}
if (!adminLoggedIn()) {
    identifyJson(401, ['ok' => false, 'error' => 'กรุณาเข้าสู่ระบบใหม่']);
}
// Same people who can add/edit a species or a tree — the two forms this
// button lives on.
if (!can('species.manage') && !can('tree.create') && !can('tree.update')) {
    identifyJson(403, ['ok' => false, 'error' => 'บทบาทของคุณไม่มีสิทธิ์ใช้งานส่วนนี้']);
}
// A body over post_max_size makes PHP drop $_POST and $_FILES entirely, which
// would otherwise surface as a misleading "CSRF expired" below.
if (empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    identifyJson(413, ['ok' => false, 'error' => 'ไฟล์รูปภาพมีขนาดใหญ่เกินไป (สูงสุด 5 MB)']);
}
$submittedToken = $_POST['csrf_token'] ?? '';
if (!is_string($submittedToken) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $submittedToken)) {
    identifyJson(400, ['ok' => false, 'error' => 'คำขอไม่ถูกต้องหรือหมดอายุ กรุณารีเฟรชหน้าแล้วลองใหม่']);
}
if (!AI_ENABLED) {
    identifyJson(503, ['ok' => false, 'error' => 'ยังไม่ได้ตั้งค่า Gemini API key — ดูวิธีตั้งค่าที่หน้า "ตั้งค่า"']);
}

try {
    $imageType = inspectUploadedImage($_FILES['image'] ?? []);
} catch (RuntimeException $e) {
    identifyJson(400, ['ok' => false, 'error' => $e->getMessage()]);
}
if ($imageType === null) {
    identifyJson(400, ['ok' => false, 'error' => 'กรุณาเลือกหรือถ่ายรูปต้นไม้ก่อน']);
}

// Only requests that would actually cost a Gemini call count against the
// quota (everything rejected above is free).
if (!consumeIdentifyQuota((int) $_SESSION['admin_id'])) {
    identifyJson(429, ['ok' => false, 'error' => 'ใช้ฟีเจอร์ระบุชนิดด้วย AI ครบโควตาต่อชั่วโมงแล้ว (' . AI_IDENTIFY_MAX_PER_HOUR . ' ครั้ง) กรุณารอสักครู่แล้วลองใหม่']);
}

// Release the session lock before the slow (up to 60s) Gemini call —
// otherwise every other admin request from this browser (opening another
// tab, saving the form) queues behind it.
session_write_close();

$outcome = identifyPlantFromImage((string) file_get_contents($_FILES['image']['tmp_name']), $imageType['mime']);
if (!$outcome['ok']) {
    identifyJson(502, ['ok' => false, 'error' => $outcome['error']]);
}

$result = $outcome['result'];
$matched = $result['is_plant'] ? findMatchingSpecies(db(), $result) : null;
$result['matched_species_id'] = $matched ? (int) $matched['id'] : null;
$result['matched_species_name'] = $matched ? (string) $matched['name'] : null;
identifyJson(200, ['ok' => true, 'result' => $result]);
