<?php
require_once __DIR__ . '/../includes/auth.php';
requirePermission('tree.update');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo = db();
    $id = (int) ($_POST['id'] ?? 0);
    $speciesId = (int) ($_POST['species_id'] ?? 0);
    if ($id) {
        $pdo->prepare('DELETE FROM nursery_stock WHERE id = :id')->execute(['id' => $id]);
    }
    header('Location: species_form.php?id=' . $speciesId . '#stock');
    exit;
}

header('Location: species.php');
exit;
