<?php
// One frame of a tree's 360-degree spin, served from the database (see includes/spin.php).
//   GET spin_frame.php?tree=<id>&t=<token>&i=<frame number>
// The token is part of the URL and changes whenever the spin is replaced, so a frame never changes under its URL and
// browsers and CDNs may keep it for a year.
require_once __DIR__ . '/../includes/spin.php';

$treeId = (int) ($_GET['tree'] ?? 0);
$token = (string) ($_GET['t'] ?? '');
$idx = (int) ($_GET['i'] ?? -1);

$frame = $treeId > 0 ? getSpinFrame(db(), $treeId, $token, $idx) : null;
if ($frame === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'not found';
    exit;
}

$etag = '"' . $token . '-' . $idx . '"';
header('ETag: ' . $etag);
header('Cache-Control: public, max-age=31536000, immutable');
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}
header('Content-Type: ' . $frame['mime']);
header('Content-Length: ' . strlen($frame['bytes']));
header('X-Content-Type-Options: nosniff');
echo $frame['bytes'];
