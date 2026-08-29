<?php
require_once __DIR__ . '/../includes/auth.php';
requirePermission('tree.update');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $pdo = db();
    $id = (int) ($_POST['id'] ?? 0);
    $treeId = (int) ($_POST['tree_id'] ?? 0);
    if ($id) {
        $pdo->prepare('DELETE FROM observations WHERE id = :id')->execute(['id' => $id]);
    }
    header('Location: tree_form.php?id=' . $treeId . '#observations');
    exit;
}

header('Location: dashboard.php');
exit;
