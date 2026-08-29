<?php
require_once __DIR__ . '/../includes/auth.php';
requirePermission('tree.update');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit;
}

$pdo = db();
$treeId = (int) ($_POST['tree_id'] ?? 0);
$stmt = $pdo->prepare('SELECT id FROM trees WHERE id = :id');
$stmt->execute(['id' => $treeId]);
if (!$stmt->fetchColumn()) {
    http_response_code(404);
    exit('ไม่พบต้นไม้นี้');
}

$observedAt = trim($_POST['observed_at'] ?? '') ?: date('Y-m-d');
$heightCm = trim($_POST['height_cm'] ?? '') !== '' ? (float) $_POST['height_cm'] : null;
$canopyCm = trim($_POST['canopy_cm'] ?? '') !== '' ? (float) $_POST['canopy_cm'] : null;
$health = $_POST['health'] ?? 'good';
if (!in_array($health, ['good', 'fair', 'poor'], true)) {
    $health = 'good';
}
$notes = trim($_POST['notes'] ?? '') ?: null;
$recordedBy = trim($_POST['recorded_by'] ?? '') ?: null;

$pdo->prepare(
    'INSERT INTO observations (tree_id, observed_at, height_cm, canopy_cm, health, notes, recorded_by)
     VALUES (:tid, :date, :h, :c, :health, :notes, :by)'
)->execute([
    'tid' => $treeId, 'date' => $observedAt, 'h' => $heightCm, 'c' => $canopyCm,
    'health' => $health, 'notes' => $notes, 'by' => $recordedBy,
]);

header('Location: tree_form.php?id=' . $treeId . '#observations');
exit;
