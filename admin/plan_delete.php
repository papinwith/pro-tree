<?php
require_once __DIR__ . '/../includes/auth.php';
requirePermission('plan.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $id = (int) ($_POST['id'] ?? 0);
    if ($id) {
        $pdo = db();
        $plan = getPlantingPlanById($pdo, $id);
        if ($plan) {
            $pdo->prepare('DELETE FROM planting_plans WHERE id = :id')->execute(['id' => $id]);
            deletePublicFile($plan['image_path']);
        }
    }
}

header('Location: plans.php');
exit;
