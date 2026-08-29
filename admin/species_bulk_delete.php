<?php
require_once __DIR__ . '/../includes/auth.php';
requirePermission('species.manage');

$deleted = 0;
$skipped = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ids = array_filter(array_map('intval', $_POST['ids'] ?? []));
    if ($ids) {
        $pdo = db();
        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM trees WHERE species_id = :id');
        $deleteStmt = $pdo->prepare('DELETE FROM species WHERE id = :id');
        foreach ($ids as $id) {
            // Same guard as species_delete.php: refuse to delete a species
            // that still has trees assigned — reassign them first.
            $countStmt->execute(['id' => $id]);
            if ((int) $countStmt->fetchColumn() === 0) {
                $deleteStmt->execute(['id' => $id]);
                $deleted++;
            } else {
                $skipped++;
            }
        }
    }
}

header('Location: species.php?bulk_deleted=' . $deleted . '&bulk_skipped=' . $skipped);
exit;
