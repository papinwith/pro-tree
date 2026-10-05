<?php
require_once __DIR__ . '/../includes/auth.php';
requirePermission('zone.manage');

$deleted = 0;
$skipped = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $ids = array_filter(array_map('intval', $_POST['ids'] ?? []));
    if ($ids) {
        $pdo = db();
        $deleteStmt = $pdo->prepare('DELETE FROM zones WHERE id = :id');
        foreach ($ids as $id) {
            // Same guard as zone_delete.php: refuse to delete a zone that
            // still has trees or planting plans assigned — reassign them
            // first. A foreign-key refusal that slips past the count is
            // skipped too, rather than aborting the rest of the batch.
            if (zoneUsageCount($pdo, $id) !== 0) {
                $skipped++;
                continue;
            }
            try {
                $deleteStmt->execute(['id' => $id]);
                $deleted++;
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
                $skipped++;
            }
        }
    }
}

header('Location: zones.php?bulk_deleted=' . $deleted . '&bulk_skipped=' . $skipped);
exit;
