<?php
require_once __DIR__ . '/../includes/auth.php';
requirePermission('category.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $id = (int) ($_POST['id'] ?? 0);
    $reassignTo = (int) ($_POST['reassign_to'] ?? 0);
    if ($id) {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM species WHERE subtype_id = :id');
        $stmt->execute(['id' => $id]);
        $speciesCount = (int) $stmt->fetchColumn();

        $canDelete = $speciesCount === 0;

        $target = $reassignTo ? getSubtypeById($pdo, $reassignTo) : null;
        if ($speciesCount > 0 && $target && $target['id'] !== $id) {
            // Move every species under this subtype into the target subtype
            // first (the FK on species.subtype_id would block the delete
            // otherwise). If the target belongs to a different category,
            // species.category_code/species_code follow it too — that's
            // still what feeds trees.plant_code — and every affected tree's
            // plant_code is recomputed.
            $speciesStmt = $pdo->prepare('SELECT id, category_code FROM species WHERE subtype_id = :id');
            $speciesStmt->execute(['id' => $id]);
            foreach ($speciesStmt->fetchAll() as $species) {
                $speciesId = (int) $species['id'];
                if ($species['category_code'] !== $target['category_code']) {
                    $newSpeciesCode = nextSpeciesCode($pdo, $target['category_code']);
                    $pdo->prepare('UPDATE species SET subtype_id = :sid, category_code = :cc, species_code = :sc WHERE id = :id')
                        ->execute(['sid' => $target['id'], 'cc' => $target['category_code'], 'sc' => $newSpeciesCode, 'id' => $speciesId]);

                    $treeStmt = $pdo->prepare('SELECT id FROM trees WHERE species_id = :sid');
                    $treeStmt->execute(['sid' => $speciesId]);
                    foreach ($treeStmt->fetchAll(PDO::FETCH_COLUMN) as $treeId) {
                        recomputeTreePlantCode($pdo, (int) $treeId);
                    }
                } else {
                    $pdo->prepare('UPDATE species SET subtype_id = :sid WHERE id = :id')
                        ->execute(['sid' => $target['id'], 'id' => $speciesId]);
                }
            }
            $canDelete = true;
        }

        if ($canDelete) {
            $pdo->prepare('DELETE FROM subtypes WHERE id = :id')->execute(['id' => $id]);
        }
    }
}

header('Location: subtypes.php');
exit;
