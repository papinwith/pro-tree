<?php
require_once __DIR__ . '/../includes/auth.php';
requirePermission('species.manage');

$query = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $id = (int) ($_POST['id'] ?? 0);
    $reassignTo = (int) ($_POST['reassign_to'] ?? 0);
    if ($id) {
        $pdo = db();
        $species = getSpeciesById($pdo, $id);
        if ($species && $reassignTo) {
            // "Move everything to another species, then delete this one" —
            // same idea as the reassign option on category/subtype delete.
            try {
                $result = reassignSpeciesAndDelete($pdo, $id, $reassignTo);
                if ($result === null) {
                    $query = ['reassign_error' => 'invalid'];
                } else {
                    $query = ['bulk_deleted' => 1, 'bulk_skipped' => 0, 'moved' => $result['moved'], 'recoded' => $result['recoded']];
                }
            } catch (PDOException $e) {
                // Rolled back — nothing changed. Most likely two trees ended
                // up with the same plant_code in the target species.
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
                $query = ['reassign_error' => 'conflict'];
            }
        } elseif ($species) {
            // Plain delete: refuse while trees, planting plans or sales still
            // depend on it — reassign them (above) or remove them first. The
            // count is only a fast path: a plan created between the check and
            // the DELETE still hits the foreign key, treated as "in use" too.
            $blocked = false;
            if (speciesUsageCount($pdo, $id) === 0) {
                try {
                    $pdo->prepare('DELETE FROM species WHERE id = :id')->execute(['id' => $id]);
                    deletePublicFile($species['image_path'] ?? null);
                } catch (PDOException $e) {
                    if ($e->getCode() !== '23000') {
                        throw $e;
                    }
                    $blocked = true;
                }
            } else {
                $blocked = true;
            }
            // Reuses the list page's "deleted N / skipped M" notice so a
            // refused delete says so instead of silently doing nothing.
            if ($blocked) {
                $query = ['bulk_deleted' => 0, 'bulk_skipped' => 1];
            }
        }
    }
}

header('Location: species.php' . ($query ? '?' . http_build_query($query) : ''));
exit;
