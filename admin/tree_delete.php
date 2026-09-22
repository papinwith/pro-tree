<?php
require_once __DIR__ . '/../includes/auth.php';
requirePermission('tree.status.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $id = (int) ($_POST['id'] ?? 0);
    if ($id) {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT image_path, map_image_path, qr_code_path FROM trees WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $tree = $stmt->fetch();

        $pdo->prepare('DELETE FROM trees WHERE id = :id')->execute(['id' => $id]);

        if ($tree) {
            // Photo/map files can be shared with sibling trees created in the
            // same batch — only unlink them once nothing else uses them.
            deletePublicFileIfUnreferenced($pdo, $tree['image_path']);
            deletePublicFileIfUnreferenced($pdo, $tree['map_image_path']);
            deletePublicFile($tree['qr_code_path']);
        }
    }
}

header('Location: dashboard.php');
exit;
