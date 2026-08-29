<?php
require_once __DIR__ . '/../includes/auth.php';
requirePermission('species.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id) {
        $pdo = db();
        // Refuse to delete a species that still has trees assigned — reassign them first.
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM trees WHERE species_id = :id');
        $stmt->execute(['id' => $id]);
        if ((int) $stmt->fetchColumn() === 0) {
            $pdo->prepare('DELETE FROM species WHERE id = :id')->execute(['id' => $id]);
        }
    }
}

header('Location: species.php');
exit;
