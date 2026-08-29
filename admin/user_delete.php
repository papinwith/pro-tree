<?php
require_once __DIR__ . '/../includes/auth.php';
requirePermission('admin.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $myId = (int) ($_SESSION['admin_id'] ?? 0);

    if ($id && $id !== $myId) {
        $pdo = db();

        $stmt = $pdo->prepare('SELECT r.role_key FROM admins a JOIN roles r ON r.id = a.role_id WHERE a.id = :id');
        $stmt->execute(['id' => $id]);
        $roleKey = $stmt->fetchColumn();

        $blocked = false;
        if ($roleKey === 'programmer') {
            $programmerCount = (int) $pdo->query(
                "SELECT COUNT(*) FROM admins a JOIN roles r ON r.id = a.role_id WHERE r.role_key = 'programmer'"
            )->fetchColumn();
            $blocked = $programmerCount <= 1; // never delete the last Programmer
        }

        if (!$blocked) {
            $pdo->prepare('DELETE FROM admins WHERE id = :id')->execute(['id' => $id]);
        }
    }
}

header('Location: users.php');
exit;
