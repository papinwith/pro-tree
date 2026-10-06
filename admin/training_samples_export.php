<?php
// Download of the approved training photos for ml/pull_feedback.py - NOT a browser page and not behind the admin login:
// it is protected by the secret TRAINING_EXPORT_TOKEN (environment variable on the host), sent as
// "Authorization: Bearer <token>". With no token configured the whole thing is switched off.
//
//   GET training_samples_export.php?since=<last id already downloaded>&limit=<1..100>
//   -> {"ok":true,"samples":[{"id":..,"name_scientific":"..","mime":"image/jpeg","image_b64":".."}, ...]}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/training_samples.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function exportJson(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (TRAINING_EXPORT_TOKEN === '' || strlen(TRAINING_EXPORT_TOKEN) < 24) {
    exportJson(404, ['ok' => false, 'error' => 'disabled']); // looks like a missing page, not a locked door
}
$given = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if ($given === '' && function_exists('getallheaders')) { // Apache + mod_php does not always put it in $_SERVER
    foreach (getallheaders() as $name => $value) {
        if (strcasecmp($name, 'Authorization') === 0) {
            $given = (string) $value;
        }
    }
}
if (!hash_equals('Bearer ' . TRAINING_EXPORT_TOKEN, $given)) {
    exportJson(401, ['ok' => false, 'error' => 'unauthorized']);
}
$since = max(0, (int) ($_GET['since'] ?? 0));
$limit = min(100, max(1, (int) ($_GET['limit'] ?? 50)));
try {
    exportJson(200, ['ok' => true, 'samples' => approvedTrainingSamplesSince(db(), $since, $limit)]);
} catch (Throwable $e) {
    error_log('training export failed: ' . $e->getMessage());
    exportJson(500, ['ok' => false, 'error' => 'failed']);
}
