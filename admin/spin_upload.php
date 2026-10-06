<?php
// JSON endpoint behind the "360-degree spin" section of the tree form (public/assets/js/spin-capture.js).
// A spin is uploaded in small batches and only becomes the tree's spin when it is complete:
//   POST action=start   tree_id                         -> {"ok":true,"token":"..."}
//   POST action=frames  tree_id token idx[] frames[]    -> {"ok":true,"saved":n}      (a few frames per request)
//   POST action=commit  tree_id token                   -> {"ok":true,"frames":n}
//   POST action=delete  tree_id                         -> {"ok":true}
// Same people who can edit a tree (tree.update), CSRF-protected like every admin POST.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/spin.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function spinJson(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    spinJson(405, ['ok' => false, 'error' => 'ต้องส่งด้วย POST']);
}
if (!adminLoggedIn()) {
    spinJson(401, ['ok' => false, 'error' => 'กรุณาเข้าสู่ระบบใหม่']);
}
if (!can('tree.update')) {
    spinJson(403, ['ok' => false, 'error' => 'บทบาทของคุณไม่มีสิทธิ์แก้ไขต้นไม้']);
}
if (postBodyExceededLimit()) {
    spinJson(413, ['ok' => false, 'error' => 'ข้อมูลที่ส่งมีขนาดใหญ่เกินไป ลองลดจำนวนเฟรมหรือความละเอียด']);
}
$token = $_POST['csrf_token'] ?? '';
if (!is_string($token) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
    spinJson(400, ['ok' => false, 'error' => 'คำขอไม่ถูกต้องหรือหมดอายุ กรุณารีเฟรชหน้า']);
}
session_write_close();

$pdo = db();
$treeId = (int) ($_POST['tree_id'] ?? 0);
if ($treeId <= 0 || !getTreeById($pdo, $treeId)) {
    spinJson(404, ['ok' => false, 'error' => 'ไม่พบต้นไม้']);
}

try {
    switch ((string) ($_POST['action'] ?? '')) {
        case 'start':
            ensureSpinTables($pdo);
            spinJson(200, ['ok' => true, 'token' => newSpinToken(), 'max_frames' => SPIN_MAX_FRAMES, 'min_frames' => SPIN_MIN_FRAMES]);

        case 'frames':
            $spinToken = (string) ($_POST['token'] ?? '');
            $indexes = $_POST['idx'] ?? [];
            $files = $_FILES['frames'] ?? null;
            if (!validSpinToken($spinToken) || !is_array($indexes) || !$files || !is_array($files['tmp_name'] ?? null)) {
                spinJson(400, ['ok' => false, 'error' => 'ข้อมูลเฟรมไม่ครบ']);
            }
            $saved = 0;
            foreach ($files['tmp_name'] as $k => $tmp) {
                if (($files['error'][$k] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($tmp) || !isset($indexes[$k])) {
                    spinJson(400, ['ok' => false, 'error' => 'อัปโหลดเฟรมที่ ' . ((int) ($indexes[$k] ?? $k) + 1) . ' ไม่สำเร็จ', 'saved' => $saved]);
                }
                if (!saveSpinFrame($pdo, $treeId, $spinToken, (int) $indexes[$k], (string) file_get_contents($tmp))) {
                    spinJson(400, ['ok' => false, 'error' => 'เฟรมที่ ' . ((int) $indexes[$k] + 1) . ' ไม่ใช่รูปที่ใช้ได้หรือใหญ่เกินไป', 'saved' => $saved]);
                }
                $saved++;
            }
            spinJson(200, ['ok' => true, 'saved' => $saved]);

        case 'commit':
            $frames = commitSpin($pdo, $treeId, (string) ($_POST['token'] ?? ''));
            if ($frames === null) {
                spinJson(400, ['ok' => false, 'error' => 'เฟรมไม่ครบ (ต้องมีอย่างน้อย ' . SPIN_MIN_FRAMES . ' เฟรมและเรียงต่อกันไม่ขาด) กรุณาอัปโหลดใหม่']);
            }
            spinJson(200, ['ok' => true, 'frames' => $frames]);

        case 'delete':
            deleteTreeSpin($pdo, $treeId);
            spinJson(200, ['ok' => true]);

        default:
            spinJson(400, ['ok' => false, 'error' => 'คำสั่งไม่ถูกต้อง']);
    }
} catch (Throwable $e) {
    error_log('spin upload failed: ' . $e->getMessage());
    spinJson(500, ['ok' => false, 'error' => 'บันทึกภาพหมุนไม่สำเร็จ กรุณาลองใหม่']);
}
