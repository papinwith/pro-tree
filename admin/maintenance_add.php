<?php
require_once __DIR__ . '/../includes/auth.php';
requirePermission('tree.update');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit;
}
requireCsrf();

$pdo = db();
$treeId = (int) ($_POST['tree_id'] ?? 0);
$stmt = $pdo->prepare('SELECT id FROM trees WHERE id = :id');
$stmt->execute(['id' => $treeId]);
if (!$stmt->fetchColumn()) {
    http_response_code(404);
    exit('ไม่พบต้นไม้นี้');
}

$activity = trim($_POST['activity'] ?? '');
$performedAt = trim($_POST['performed_at'] ?? '') ?: date('Y-m-d');
$performedBy = trim($_POST['performed_by'] ?? '') ?: null;
$notes = trim($_POST['notes'] ?? '') ?: null;

if ($activity !== '') {
    $pdo->prepare(
        'INSERT INTO maintenance_logs (tree_id, activity, performed_at, performed_by, notes)
         VALUES (:tid, :activity, :date, :by, :notes)'
    )->execute([
        'tid' => $treeId, 'activity' => $activity, 'date' => $performedAt,
        'by' => $performedBy, 'notes' => $notes,
    ]);
}

header('Location: tree_form.php?id=' . $treeId . '#maintenance');
exit;
