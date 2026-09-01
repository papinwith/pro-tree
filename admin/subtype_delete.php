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
        if ($target && (int) $target['id'] === $id) {
            $target = null;
        }
        if ($speciesCount > 0 && $target) {
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
            // Species that had this subtype only as an *extra* tag (not
            // their primary) never went through the loop above — without
            // this, ON DELETE CASCADE on species_subtypes would silently
            // drop those tags the moment the subtype row is deleted below,
            // with no equivalent "migrate to $target" treatment the primary
            // gets. Only applies when a real reassign target was chosen;
            // a plain delete (no target) has nowhere to move the tag to.
            if ($target) {
                // A species already tagging $target too would collide on
                // (species_id, subtype_id) once retargeted — drop the
                // now-redundant old-subtype row for those first.
                $pdo->prepare(
                    'DELETE ss FROM species_subtypes ss
                     JOIN species_subtypes existing
                       ON existing.species_id = ss.species_id AND existing.subtype_id = :target_dup
                     WHERE ss.subtype_id = :id'
                )->execute(['target_dup' => $target['id'], 'id' => $id]);
                $pdo->prepare('UPDATE species_subtypes SET subtype_id = :target WHERE subtype_id = :id')
                    ->execute(['target' => $target['id'], 'id' => $id]);
            }

            $pdo->prepare('DELETE FROM subtypes WHERE id = :id')->execute(['id' => $id]);
        }
    }
}

header('Location: subtypes.php');
exit;
