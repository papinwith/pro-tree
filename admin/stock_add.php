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
if (!getSpeciesById($pdo, $speciesId)) {
    http_response_code(404);
    exit('ไม่พบชนิดพันธุ์นี้');
}

// A stale/tampered size_id would otherwise hit the fk_stock_size foreign
// key and crash — validate against the real table first, silently
// dropping to "no size" rather than failing the whole save over an
// invalid selection.
$sizeId = validateStockSizeId($pdo, (int) ($_POST['size_id'] ?? 0));
$quantity = max(0, (int) ($_POST['quantity'] ?? 0));
$priceRaw = trim($_POST['price'] ?? '');
$price = $priceRaw !== '' ? (float) $priceRaw : null;
$saleStatus = $_POST['sale_status'] ?? 'not_for_sale';
if (!in_array($saleStatus, ['available', 'reserved', 'sold_out', 'not_for_sale'], true)) {
    $saleStatus = 'not_for_sale';
}
$salesChannel = trim($_POST['sales_channel'] ?? '') ?: null;

$pdo->prepare(
    'INSERT INTO nursery_stock (species_id, size_id, quantity, price, sale_status, sales_channel)
     VALUES (:sid, :size, :qty, :price, :status, :channel)'
)->execute([
    'sid' => $speciesId, 'size' => $sizeId ?: null, 'qty' => $quantity,
    'price' => $price, 'status' => $saleStatus, 'channel' => $salesChannel,
]);

header('Location: species_form.php?id=' . $speciesId . '#stock');
exit;
