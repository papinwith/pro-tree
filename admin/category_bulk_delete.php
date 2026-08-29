<?php
require_once __DIR__ . '/../includes/auth.php';
requirePermission('category.manage');

$deleted = 0;
$skipped = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $codes = array_filter(array_map('trim', $_POST['codes'] ?? []), fn($c) => $c !== '');
    if ($codes) {
        $pdo = db();
        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM species WHERE category_code = :code');
        $deleteStmt = $pdo->prepare('DELETE FROM categories WHERE code = :code');
        foreach ($codes as $code) {
            // Same guard as category_delete.php's no-reassign path: refuse
            // to delete a category that still has species in it — bulk
            // delete doesn't offer the reassign-first flow, so those are
            // just skipped (use category_delete.php on the list page to
            // reassign one at a time).
            $countStmt->execute(['code' => $code]);
            if ((int) $countStmt->fetchColumn() === 0) {
                $deleteStmt->execute(['code' => $code]);
                $deleted++;
            } else {
                $skipped++;
            }
        }
    }
}

header('Location: categories.php?bulk_deleted=' . $deleted . '&bulk_skipped=' . $skipped);
exit;
