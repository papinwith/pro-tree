<?php
require_once __DIR__ . '/../includes/auth.php';
requirePermission('zone.manage');

$blocked = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $id = (int) ($_POST['id'] ?? 0);
    if ($id) {
        $pdo = db();
        // Refuse to delete a zone that still has trees or planting plans
        // assigned — reassign them first. The count is only a fast path: a
        // plan created between the check and the DELETE still hits the
        // foreign key, which is treated as "still in use" too.
        if (zoneUsageCount($pdo, $id) === 0) {
            try {
                $pdo->prepare('DELETE FROM zones WHERE id = :id')->execute(['id' => $id]);
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
                $blocked = true;
            }
        } else {
            $blocked = true;
        }
    }
}

// Reuses the list page's "deleted N / skipped M" notice so a refused delete
// says so instead of silently doing nothing.
header('Location: zones.php' . ($blocked ? '?bulk_deleted=0&bulk_skipped=1' : ''));
exit;
