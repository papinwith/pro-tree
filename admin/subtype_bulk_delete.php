<?php
require_once __DIR__ . '/../includes/auth.php';
requirePermission('category.manage');

$deleted = 0;
$skipped = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $ids = array_filter(array_map('intval', $_POST['ids'] ?? []));
    if ($ids) {
        $pdo = db();
        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM species WHERE subtype_id = :id');
        $deleteStmt = $pdo->prepare('DELETE FROM subtypes WHERE id = :id');
        foreach ($ids as $id) {
            // Same guard as subtype_delete.php's no-reassign path: refuse to
            // delete a subtype that still has species in it — bulk delete
            // doesn't offer the reassign-first flow, so those are just
            // skipped (use subtype_delete.php on the list page to reassign
            // one at a time).
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

header('Location: subtypes.php?bulk_deleted=' . $deleted . '&bulk_skipped=' . $skipped);
exit;
