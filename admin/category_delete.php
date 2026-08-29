<?php
require_once __DIR__ . '/../includes/auth.php';
requirePermission('category.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $code = trim($_POST['code'] ?? '');
    $reassignTo = trim($_POST['reassign_to'] ?? '');
    if ($code !== '') {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM species WHERE category_code = :code');
        $stmt->execute(['code' => $code]);
        $speciesCount = (int) $stmt->fetchColumn();

        $canDelete = $speciesCount === 0;

        if ($speciesCount > 0 && $reassignTo !== '' && $reassignTo !== $code && getCategoryByCode($pdo, $reassignTo)) {
            // Move every species in this category into the target category
            // first (the FK on species.category_code would block the delete
            // otherwise). species_code is re-assigned fresh within the
            // target category to dodge a (category_code, species_code)
            // collision, and every affected tree's plant_code is recomputed
            // since its category segment just changed.
            $speciesStmt = $pdo->prepare('SELECT id FROM species WHERE category_code = :code');
            $speciesStmt->execute(['code' => $code]);
            foreach ($speciesStmt->fetchAll(PDO::FETCH_COLUMN) as $speciesId) {
                $newSpeciesCode = nextSpeciesCode($pdo, $reassignTo);
                $pdo->prepare('UPDATE species SET category_code = :cc, species_code = :sc WHERE id = :id')
                    ->execute(['cc' => $reassignTo, 'sc' => $newSpeciesCode, 'id' => $speciesId]);

                $treeStmt = $pdo->prepare('SELECT id FROM trees WHERE species_id = :sid');
                $treeStmt->execute(['sid' => (int) $speciesId]);
                foreach ($treeStmt->fetchAll(PDO::FETCH_COLUMN) as $treeId) {
                    recomputeTreePlantCode($pdo, (int) $treeId);
                }
            }
            $canDelete = true;
        }

        if ($canDelete) {
            $pdo->prepare('DELETE FROM categories WHERE code = :code')->execute(['code' => $code]);
        }
    }
}

header('Location: categories.php');
exit;
