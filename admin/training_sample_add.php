<?php
// JSON endpoint: an admin pressed "use this result" on the identify panel, so that photo + name become an approved
// training example for the local "tree" model (see includes/training_samples.php). Best effort - the page never waits on it.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/training_samples.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function sampleJson(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sampleJson(405, ['ok' => false, 'error' => 'ต้องส่งด้วย POST']);
}
if (!adminLoggedIn()) {
    sampleJson(401, ['ok' => false, 'error' => 'กรุณาเข้าสู่ระบบใหม่']);
}
if (!canAny(['species.manage', 'tree.create', 'tree.update'])) {
    sampleJson(403, ['ok' => false, 'error' => 'บทบาทของคุณไม่มีสิทธิ์ใช้งานส่วนนี้']);
}
if (postBodyExceededLimit()) {
    sampleJson(413, ['ok' => false, 'error' => 'ไฟล์รูปภาพมีขนาดใหญ่เกินไป']);
}
$submittedToken = $_POST['csrf_token'] ?? '';
if (!is_string($submittedToken) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $submittedToken)) {
    sampleJson(400, ['ok' => false, 'error' => 'คำขอไม่ถูกต้องหรือหมดอายุ']);
}
try {
    $imageType = inspectUploadedImage($_FILES['image'] ?? []);
} catch (RuntimeException $e) {
    sampleJson(400, ['ok' => false, 'error' => $e->getMessage()]);
}
if ($imageType === null) {
    sampleJson(400, ['ok' => false, 'error' => 'ไม่พบรูป']);
}

$adminId = (int) ($_SESSION['admin_id'] ?? 0);
session_write_close();
try {
    $id = confirmTrainingSample(db(), (string) file_get_contents($_FILES['image']['tmp_name']), $imageType['mime'], (string) ($_POST['name_scientific'] ?? ''), $adminId);
} catch (Throwable $e) {
    error_log('training sample confirm failed: ' . $e->getMessage());
    sampleJson(500, ['ok' => false, 'error' => 'บันทึกตัวอย่างไม่สำเร็จ']);
}
sampleJson(200, ['ok' => $id !== null, 'id' => $id]);
