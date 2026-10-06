<?php
// Pl@ntNet (my.plantnet.org) - a plant-identification service, used as the
// "teacher" when the local model is not confident (see identifyPlantFromImage()).
// Server-side only: the API key never reaches the browser, and it is never put
// in a log line (the key travels in the request URL, so error output here
// deliberately contains no URL).
require_once __DIR__ . '/../config/config.php';

function plantnetAvailable(): bool
{
    return PLANTNET_API_KEY !== '';
}

/**
 * Identifies the plant in $imageBytes. Returns the best match as
 * ['name_scientific' => ..., 'name_common' => ..., 'confidence_pct' => 0-100,
 * 'alternatives' => [[name_scientific, name_common], ...]] or null when it could
 * not (no key, network error, quota used up, no plant found). $error is a short
 * Thai reason that is safe to show an admin.
 */
function plantnetIdentify(string $imageBytes, string $mimeType, float $budgetSeconds, ?string &$error = null): ?array
{
    $error = null;
    if (!plantnetAvailable()) {
        $error = 'ยังไม่ได้ตั้งค่า PLANTNET_API_KEY';
        return null;
    }
    $tmp = tempnam(sys_get_temp_dir(), 'pn');
    if ($tmp === false || file_put_contents($tmp, $imageBytes) === false) {
        $error = 'เขียนไฟล์ชั่วคราวไม่ได้';
        return null;
    }
    try {
        $ch = curl_init(PLANTNET_API_BASE . '/v2/identify/all?' . http_build_query(['api-key' => PLANTNET_API_KEY, 'lang' => 'en', 'nb-results' => 4]));
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => ['images' => new CURLFile($tmp, $mimeType, 'photo'), 'organs' => 'auto'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => max(500, (int) round($budgetSeconds * 1000)),
            CURLOPT_CONNECTTIMEOUT_MS => 3000,
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $failed = $response === false ? (curl_error($ch) ?: 'unknown error') : '';
        curl_close($ch);
    } finally {
        @unlink($tmp);
    }

    if ($failed !== '') {
        error_log('Pl@ntNet request failed: ' . preg_replace('/api-key=[^&\s]+/', 'api-key=***', $failed));
        $error = 'เชื่อมต่อ Pl@ntNet ไม่สำเร็จ';
        return null;
    }
    if ($status === 404) {
        $error = 'Pl@ntNet ไม่พบพืชในรูป';
        return null;
    }
    if ($status === 429) {
        $error = 'โควตา Pl@ntNet วันนี้หมดแล้ว';
        return null;
    }
    $decoded = json_decode((string) $response, true);
    if ($status !== 200 || !is_array($decoded)) {
        error_log("Pl@ntNet HTTP $status");
        $error = "Pl@ntNet ตอบกลับผิดพลาด (HTTP $status)";
        return null;
    }
    return parsePlantnetResults($decoded);
}

/** Pure: turns a Pl@ntNet /identify response into the shape plantnetIdentify() returns (or null). */
function parsePlantnetResults(array $decoded): ?array
{
    $results = is_array($decoded['results'] ?? null) ? $decoded['results'] : [];
    $parsed = [];
    foreach ($results as $r) {
        $sci = trim((string) ($r['species']['scientificNameWithoutAuthor'] ?? ''));
        if ($sci === '' || !is_numeric($r['score'] ?? null)) {
            continue;
        }
        $common = $r['species']['commonNames'][0] ?? '';
        $parsed[] = ['name_scientific' => $sci, 'name_common' => is_string($common) ? trim($common) : '',
                     'confidence_pct' => (int) max(0, min(100, round((float) $r['score'] * 100)))];
    }
    if (!$parsed) {
        return null;
    }
    $best = array_shift($parsed);
    $best['alternatives'] = array_map(
        static fn($p) => ['name_th' => '', 'name_scientific' => $p['name_scientific']],
        array_slice($parsed, 0, 3)
    );
    return $best;
}
