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
$submittedToken = $_POST['csrf_token'] ?? '';
if (!is_string($submittedToken) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $submittedToken)) {
    identifyJson(400, ['ok' => false, 'error' => 'คำขอไม่ถูกต้องหรือหมดอายุ กรุณารีเฟรชหน้าแล้วลองใหม่']);
}
if (!AI_ENABLED) {
    identifyJson(503, ['ok' => false, 'error' => 'ยังไม่ได้ตั้งค่า Gemini API key — ดูวิธีตั้งค่าที่หน้า "ตั้งค่า"']);
}

$file = $_FILES['image'] ?? null;
if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    identifyJson(400, ['ok' => false, 'error' => 'กรุณาเลือกหรือถ่ายรูปต้นไม้ก่อน']);
}
if ($file['size'] > 5 * 1024 * 1024) {
    identifyJson(400, ['ok' => false, 'error' => 'ไฟล์รูปภาพมีขนาดใหญ่เกินไป (สูงสุด 5 MB)']);
}
$imageInfo = @getimagesize($file['tmp_name']);
$allowedMimes = [IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_PNG => 'image/png', IMAGETYPE_GIF => 'image/gif', IMAGETYPE_WEBP => 'image/webp'];
if ($imageInfo === false || !isset($allowedMimes[$imageInfo[2]])) {
    identifyJson(400, ['ok' => false, 'error' => 'ไฟล์นี้ไม่ใช่รูปภาพที่รองรับ (JPG, PNG, GIF, WEBP)']);
}

// Release the session lock before the slow (up to 60s) Gemini call —
// otherwise every other admin request from this browser (opening another
// tab, saving the form) queues behind it.
session_write_close();

$outcome = identifyPlantFromImage((string) file_get_contents($file['tmp_name']), $allowedMimes[$imageInfo[2]]);
if (!$outcome['ok']) {
    identifyJson(502, ['ok' => false, 'error' => $outcome['error']]);
}

$result = $outcome['result'];
$matched = $result['is_plant'] ? findMatchingSpecies(db(), $result) : null;
$result['matched_species_id'] = $matched ? (int) $matched['id'] : null;
$result['matched_species_name'] = $matched ? (string) $matched['name'] : null;
identifyJson(200, ['ok' => true, 'result' => $result]);
