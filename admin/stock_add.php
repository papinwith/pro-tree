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

$sizeLabel = trim($_POST['size_label'] ?? '') ?: null;
$quantity = max(0, (int) ($_POST['quantity'] ?? 0));
$priceRaw = trim($_POST['price'] ?? '');
$price = $priceRaw !== '' ? (float) $priceRaw : null;
$saleStatus = $_POST['sale_status'] ?? 'not_for_sale';
if (!in_array($saleStatus, ['available', 'reserved', 'sold_out', 'not_for_sale'], true)) {
    $saleStatus = 'not_for_sale';
}
$salesChannel = trim($_POST['sales_channel'] ?? '') ?: null;

$pdo->prepare(
    'INSERT INTO nursery_stock (species_id, size_label, quantity, price, sale_status, sales_channel)
     VALUES (:sid, :size, :qty, :price, :status, :channel)'
)->execute([
    'sid' => $speciesId, 'size' => $sizeLabel, 'qty' => $quantity,
    'price' => $price, 'status' => $saleStatus, 'channel' => $salesChannel,
]);

header('Location: species_form.php?id=' . $speciesId . '#stock');
exit;
