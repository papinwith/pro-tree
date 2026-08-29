<?php
require_once __DIR__ . '/../includes/auth.php';
requirePermission('interest.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: interests.php');
    exit;
}

$pdo = db();
$id = (int) ($_POST['id'] ?? 0);
$leadStatus = $_POST['lead_status'] ?? 'new';
$contactChannel = trim($_POST['contact_channel'] ?? '') ?: null;

if (!in_array($leadStatus, ['new', 'contacted', 'converted', 'closed'], true)) {
    $leadStatus = 'new';
}

if ($id) {
    $pdo->prepare(
        'UPDATE tree_interests SET lead_status = :status, contact_channel = :channel, lead_updated_at = NOW() WHERE id = :id'
    )->execute(['status' => $leadStatus, 'channel' => $contactChannel, 'id' => $id]);
}

// Only forward a plain lead_status filter value back (never a raw query
// string) so this can't be abused to inject arbitrary redirect targets.
$filter = $_POST['filter'] ?? '';
$redirect = 'interests.php';
if (in_array($filter, ['new', 'contacted', 'converted', 'closed'], true)) {
    $redirect .= '?status=' . $filter;
}
header('Location: ' . $redirect);
exit;
