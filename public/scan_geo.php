<?php
// Records the visitor's own GPS position against a scan already logged by
// tree.php. Fire-and-forget from the client (sendBeacon-style fetch with
// keepalive) — always responds 200 regardless of outcome, since a rejected
// update must never surface as a visible error to the visitor.
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false]);
    exit;
}

$pdo = db();

$scanId = (int) ($_POST['scan_id'] ?? 0);
$lat = isset($_POST['lat']) ? (float) $_POST['lat'] : null;
$lng = isset($_POST['lng']) ? (float) $_POST['lng'] : null;
$accuracy = isset($_POST['accuracy']) ? (float) $_POST['accuracy'] : null;

// Coordinates must be within valid WGS84 ranges — reject obvious garbage
// without erroring out to the client.
$valid = $scanId > 0
    && $lat !== null && $lat >= -90 && $lat <= 90
    && $lng !== null && $lng >= -180 && $lng <= 180;

if ($valid) {
    // Visitor identity is re-derived from the cookie, never trusted from
    // the request body — updateScanGps() only touches a row that already
    // belongs to this same visitor.
    $visitorId = getOrCreateVisitorId($pdo);
    updateScanGps($pdo, $scanId, $visitorId, $lat, $lng, $accuracy);
}

echo json_encode(['ok' => true]);
