<?php
require_once __DIR__ . '/../includes/auth.php';
requirePermission('zone.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $id = (int) ($_POST['id'] ?? 0);
    if ($id) {
        $pdo = db();
        // Refuse to delete a zone that still has trees assigned — reassign them first.
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM trees WHERE zone_id = :id');
        $stmt->execute(['id' => $id]);
        if ((int) $stmt->fetchColumn() === 0) {
            $pdo->prepare('DELETE FROM zones WHERE id = :id')->execute(['id' => $id]);
        }
    }
}

header('Location: zones.php');
exit;
