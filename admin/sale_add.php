<?php
require_once __DIR__ . '/../includes/auth.php';
requirePermission('tree.update');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: species.php');
    exit;
}
requireCsrf();

$pdo = db();
$speciesId = (int) ($_POST['species_id'] ?? 0);
$stmt = $pdo->prepare('SELECT id FROM species WHERE id = :id');
$stmt->execute(['id' => $speciesId]);
if (!$stmt->fetchColumn()) {
    http_response_code(404);
    exit('ไม่พบชนิดพันธุ์นี้');
}

$sizeId = (int) ($_POST['size_id'] ?? 0);
if ($sizeId) {
    $sizeCheck = $pdo->prepare('SELECT 1 FROM stock_sizes WHERE id = :id');
    $sizeCheck->execute(['id' => $sizeId]);
    if (!$sizeCheck->fetchColumn()) {
        $sizeId = 0;
    }
}

$quantity = max(1, (int) ($_POST['quantity'] ?? 1));
$unitPriceRaw = trim($_POST['unit_price'] ?? '');
$unitPrice = is_numeric($unitPriceRaw) ? (float) $unitPriceRaw : 0.0;
$notes = trim($_POST['notes'] ?? '') ?: null;

if ($unitPrice <= 0) {
    header('Location: species_form.php?id=' . $speciesId . '&sale_error=1#sales');
    exit;
}

recordSale($pdo, $speciesId, $sizeId ?: null, $quantity, $unitPrice, $_SESSION['admin_username'] ?? null, $notes);

header('Location: species_form.php?id=' . $speciesId . '#stock');
exit;
