<?php
require_once __DIR__ . '/../includes/auth.php';
requirePermission('tree.status.manage');

$deleted = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $ids = array_filter(array_map('intval', $_POST['ids'] ?? []));
    if ($ids) {
        $pdo = db();
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT id, image_path, map_image_path, qr_code_path FROM trees WHERE id IN ($in)");
        $stmt->execute($ids);
        $rows = $stmt->fetchAll();

        $pdo->prepare("DELETE FROM trees WHERE id IN ($in)")->execute($ids);
        $deleted = count($rows);

        foreach ($rows as $tree) {
            deletePublicFile($tree['image_path']);
            deletePublicFile($tree['map_image_path']);
            deletePublicFile($tree['qr_code_path']);
        }
    }
}

header('Location: dashboard.php?bulk_deleted=' . $deleted);
exit;
