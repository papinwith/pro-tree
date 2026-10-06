<?php
// JSON endpoint behind the "ให้ AI ช่วยระบุชนิดต้นไม้" button on the species
// and tree forms (public/assets/js/ai-identify-button.js). Takes one photo,
// returns the AI's best-guess identification plus the id of a matching
// already-catalogued species, if any. Never writes to the database — the
// admin reviews the suggestion and applies it (or not) in the form.
// The time cap below is measured from here: PHP has already received the whole
// upload by the time this line runs, so a slow phone connection sending the
// photo doesn't eat the budget the AI needs (that part is out of the server's hands).
$requestStartedAt = microtime(true);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/plant_identify.php';
require_once __DIR__ . '/../includes/training_samples.php';
require_once __DIR__ . '/../includes/species_details.php';

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
// button lives on. Checked against the database on every click, so a role
// that has just been revoked stops working immediately (the AI call itself
// takes far longer than this lookup).
if (!canAny(['species.manage', 'tree.create', 'tree.update'])) {
    identifyJson(403, ['ok' => false, 'error' => 'บทบาทของคุณไม่มีสิทธิ์ใช้งานส่วนนี้']);
}
$speciesOk = can('species.manage');
// A body over post_max_size makes PHP drop $_POST and $_FILES entirely, which
// would otherwise surface as a misleading "CSRF expired" below.
if (postBodyExceededLimit()) {
    identifyJson(413, ['ok' => false, 'error' => 'ไฟล์รูปภาพมีขนาดใหญ่เกินไป (สูงสุด 10 MB)']);
}
$submittedToken = $_POST['csrf_token'] ?? '';
if (!is_string($submittedToken) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $submittedToken)) {
    identifyJson(400, ['ok' => false, 'error' => 'คำขอไม่ถูกต้องหรือหมดอายุ กรุณารีเฟรชหน้าแล้วลองใหม่']);
}
if (!AI_ENABLED) {
    identifyJson(503, ['ok' => false, 'error' => 'ปิดการใช้งาน AI อยู่ — ดูวิธีตั้งค่าที่หน้า "ตั้งค่า"']);
}

try {
    $imageType = inspectUploadedImage($_FILES['image'] ?? []);
} catch (RuntimeException $e) {
    identifyJson(400, ['ok' => false, 'error' => $e->getMessage()]);
}
if ($imageType === null) {
    identifyJson(400, ['ok' => false, 'error' => 'กรุณาเลือกหรือถ่ายรูปต้นไม้ก่อน']);
}

// Only requests that would actually cost an AI call count against the
// quota (everything rejected above is free).
if (!consumeIdentifyQuota((int) $_SESSION['admin_id'])) {
    identifyJson(429, ['ok' => false, 'error' => 'ใช้ฟีเจอร์ระบุชนิดด้วย AI ครบโควตาต่อชั่วโมงแล้ว (' . AI_IDENTIFY_MAX_PER_HOUR . ' ครั้ง) กรุณารอสักครู่แล้วลองใหม่']);
}

// Release the session lock before the slow (up to AI_IDENTIFY_MAX_SECONDS) AI call —
// otherwise every other admin request from this browser (opening another
// tab, saving the form) queues behind it.
session_write_close();

// The species form asks for the full write-up (care, characteristics, ...
// plus a category/subtype picked from the real lists) — only for someone who
// can actually create/edit species; the tree form just wants the name.
$catalogue = null;
if (($_POST['detail'] ?? '') === 'full' && $speciesOk) {
    $catalogue = [
        'categories' => identifyCached('categories', AI_IDENTIFY_CACHE_SECONDS, fn() => getAllCategories(db())),
        'subtypes' => identifyCached('subtypes', AI_IDENTIFY_CACHE_SECONDS, fn() => getAllSubtypes(db())),
    ];
}

// Hard cap: the server's whole handling of the request (this script's own work
// included, measured from when the upload had arrived) must finish within
// AI_IDENTIFY_MAX_SECONDS. What's left after
// what has already been spent — minus a little for matching the answer against
// the catalogue afterwards — is the AI's budget.
$budget = max(1.5, AI_IDENTIFY_MAX_SECONDS - (microtime(true) - $requestStartedAt) - 0.4);
// The engines only have to NAME the plant (a short, quick answer); the details - care, characteristics, benefits, cautions...
// - are then filled in every time by completeWithDetails(). Time is kept back for that step.
$detailsReserve = canGenerateDetails() ? min(30.0, AI_IDENTIFY_MAX_SECONDS * 0.3) : 0.0;
$budget = max(1.5, $budget - $detailsReserve);
// The nursery's own species help the model settle look-alikes — but only if the
// list is already cached: reading it from the database here would spend ~1 s of
// the AI's time.
$imageBytes = (string) file_get_contents($_FILES['image']['tmp_name']);
$outcome = identifyPlantFromImage($imageBytes, $imageType['mime'], null, $budget, identifyCachedIfFresh('species', AI_IDENTIFY_CACHE_SECONDS));
if (!$outcome['ok']) {
    identifyJson(!empty($outcome['timed_out']) ? 504 : 502, ['ok' => false, 'timed_out' => !empty($outcome['timed_out']), 'error' => $outcome['error']]);
}

$result = $outcome['result'];
// Learning for the local "tree" model: two teachers agreeing -> kept as an approved example; disagreeing -> queued for a
// person (admin/training_samples.php). Never affects the answer returned below.
recordTrainingSampleFromResult(db(), $imageBytes, $imageType['mime'], $result, isset($_SESSION['admin_id']) ? (int) $_SESSION['admin_id'] : null);
$matched = $result['is_plant']
    ? findMatchingSpecies(null, $result, identifyCached('species', AI_IDENTIFY_CACHE_SECONDS, fn() => db()->query('SELECT id, name, name_scientific FROM species ORDER BY name')->fetchAll()))
    : null;
$result['matched_species_id'] = $matched ? (int) $matched['id'] : null;
$result['matched_species_name'] = $matched ? (string) $matched['name'] : null;
// Details every time: the catalogued text, else what the AI wrote before, else a fresh AI write-up (see species_details.php).
$detailsBudget = max(4.0, AI_IDENTIFY_MAX_SECONDS - (microtime(true) - $requestStartedAt) - 0.5);
$result = completeWithDetails(db(), $result, $catalogue, $detailsBudget, null, $matched ? (int) $matched['id'] : null);
identifyJson(200, ['ok' => true, 'result' => $result]);
