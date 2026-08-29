<?php
require_once __DIR__ . '/../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$pdo = db();

$treeId = isset($_POST['tree_id']) ? (int) $_POST['tree_id'] : 0;
$email = trim($_POST['email'] ?? '');
$activityType = ($_POST['activity_type'] ?? '') === 'price_request' ? 'price_request' : 'interest_click';

$tree = $treeId ? getTreeById($pdo, $treeId) : null;

if (!$tree || !isValidEmail($email)) {
    $redirect = $tree ? "tree.php?id={$treeId}&interest=error" : 'index.php';
    header('Location: ' . $redirect);
    exit;
}

// Best-effort link back to the visitor's cookie identity, if present.
$visitorId = null;
if (!empty($_COOKIE[VISITOR_COOKIE_NAME])) {
    $stmt = $pdo->prepare('SELECT id FROM visitors WHERE visitor_uuid = :uuid');
    $stmt->execute(['uuid' => $_COOKIE[VISITOR_COOKIE_NAME]]);
    $visitorId = $stmt->fetchColumn() ?: null;
}

$insert = $pdo->prepare(
    'INSERT INTO tree_interests (tree_id, email, visitor_id, activity_type, submitted_at) VALUES (:tid, :email, :vid, :activity, NOW())'
);
$insert->execute([
    'tid' => $treeId,
    'email' => $email,
    'vid' => $visitorId,
    'activity' => $activityType,
]);

header("Location: tree.php?id={$treeId}&interest=ok");
exit;
