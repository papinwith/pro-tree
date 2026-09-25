<?php
// Shared low-level Google Gemini call — used by translation.php (text
// translation) and plant_identify.php (photo identification). Server-side
// only; the API key never reaches the browser.
require_once __DIR__ . '/../config/config.php';

/** Statuses meaning "this model can't answer right now, another one might":
 *  quota used up (429), overloaded (503), a transient server error (500/502/504),
 *  or the model has been retired (404). Anything else (400 bad request, 401/403
 *  bad key) would fail the same way on every model, so it is not retried. */
const GEMINI_FALLBACK_STATUSES = [404, 429, 500, 502, 503, 504];

/**
 * One generateContent call to one model. Returns ['status' => HTTP status
 * (0 if none was received), 'body' => response body, 'curl_error' => string].
 */
function geminiAttempt(string $model, array $body, int $timeoutSeconds): array
{
    $ch = curl_init(GEMINI_API_BASE . '/v1beta/models/' . rawurlencode($model) . ':generateContent');
    $curlOpts = [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        // Newer Gemini API keys (the "AQ...." format issued by current AI
        // Studio) are rejected with 401 ACCESS_TOKEN_TYPE_UNSUPPORTED when
        // sent as the old `?key=` query param — Google's own current docs
        // send it as this header instead. The header form also works fine
        // with the older AIzaSy... key format, so this isn't a breaking
        // change for anyone already using that.
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-goog-api-key: ' . GEMINI_API_KEY],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeoutSeconds,
    ];
    // generativelanguage.googleapis.com resolves IPv6-first; on a host/
    // network where outbound IPv6 is misconfigured or blackholed, curl
    // silently hangs the full timeout trying that address before ever
    // reaching Google, and every call quietly fails. Forcing IPv4 sidesteps
    // that, but only opt-in (GEMINI_FORCE_IPV4) — unconditionally forcing it
    // would be actively worse on a host where IPv4 is the restricted/slower
    // path.
    if (GEMINI_FORCE_IPV4) {
        $curlOpts[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
    }
    curl_setopt_array($ch, $curlOpts);
    $response = curl_exec($ch);
    $result = [
        'status' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
        'body' => $response === false ? '' : (string) $response,
        'curl_error' => $response === false ? (curl_error($ch) ?: 'unknown error') : '',
    ];
    curl_close($ch);
    return $result;
}

/**
 * POSTs $body (a generateContent request) to Gemini and returns the text of
 * the first candidate, or null on any failure (AI disabled, network error,
 * non-200, blocked/empty response). When it fails, $error is set to a short
 * human-readable reason that is safe to show an admin — it never contains the
 * API key or the provider's own error text (that is written to error_log).
 *
 * Tries GEMINI_MODEL first, then each of GEMINI_FALLBACK_MODELS in order,
 * moving on whenever a model can't answer right now (see
 * GEMINI_FALLBACK_STATUSES). Free-tier quotas are per model per day, and the
 * newest models are often overloaded, so a chain both stretches the quota and
 * keeps the feature answering instead of failing on the first "high demand".
 * A network error/timeout is NOT retried on another model — the connection
 * itself is the problem, and stacking timeouts would just make the admin wait.
 */
function geminiGenerateText(array $body, int $timeoutSeconds, ?string &$error = null): ?string
{
    $error = null;
    if (!AI_ENABLED) {
        $error = 'ยังไม่ได้ตั้งค่า Gemini API key';
        return null;
    }

    $models = array_values(array_unique(array_merge([GEMINI_MODEL], GEMINI_FALLBACK_MODELS)));
    $attempt = ['status' => 0, 'body' => '', 'curl_error' => ''];
    // A second pass (after a short pause) only if every model was merely busy
    // (503) — a burst of "high demand" usually clears within a couple of
    // seconds. Quota exhaustion (429) or a retired model (404) won't clear.
    for ($pass = 1; $pass <= 2; $pass++) {
        $allBusy = true;
        foreach ($models as $model) {
            $attempt = geminiAttempt($model, $body, $timeoutSeconds);
            if ($attempt['curl_error'] !== '') {
                $error = 'เชื่อมต่อบริการ AI ไม่สำเร็จ (' . $attempt['curl_error'] . ')';
                return null;
            }
            if ($attempt['status'] === 200) {
                $decoded = json_decode($attempt['body'], true);
                $innerText = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? null;
                if (is_string($innerText)) {
                    return $innerText;
                }
                // 200 but nothing usable (e.g. blocked by a safety filter): the
                // same prompt/photo would very likely be blocked on any model.
                $error = 'บริการ AI ไม่ส่งผลลัพธ์กลับมา (อาจถูกบล็อกโดยตัวกรองความปลอดภัย)';
                return null;
            }
            // The provider's own message (quota/billing/project details) can
            // be internal detail — it goes to the server log, never to the
            // browser.
            $apiMessage = json_decode($attempt['body'], true)['error']['message'] ?? '';
            error_log("Gemini API $model HTTP {$attempt['status']}: " . $apiMessage);
            if (!in_array($attempt['status'], GEMINI_FALLBACK_STATUSES, true)) {
                break 2;
            }
            $allBusy = $allBusy && $attempt['status'] === 503;
        }
        if (!$allBusy || $pass === 2) {
            break;
        }
        sleep(2);
    }

    $status = $attempt['status'];
    $error = $status === 429
        ? 'บริการ AI ถูกใช้งานเกินโควตาชั่วคราว กรุณารอสักครู่แล้วลองใหม่'
        : "บริการ AI ตอบกลับผิดพลาด (HTTP $status) กรุณาลองใหม่อีกครั้ง";
    return null;
}
