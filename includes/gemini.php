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
 * (0 if none was received), 'body' => response body, 'curl_error' => string,
 * 'timed_out' => bool].
 */
function geminiAttempt(string $model, array $body, float $timeoutSeconds): array
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
        // Millisecond precision: the whole call may have only a few seconds in total.
        CURLOPT_TIMEOUT_MS => max(300, (int) round($timeoutSeconds * 1000)),
        CURLOPT_CONNECTTIMEOUT_MS => max(300, min(2000, (int) round($timeoutSeconds * 1000))),
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
        'timed_out' => $response === false && curl_errno($ch) === CURLE_OPERATION_TIMEDOUT,
    ];
    curl_close($ch);
    return $result;
}

/**
 * POSTs $body (a generateContent request) to Gemini and returns the text of
 * the first candidate, or null on any failure (AI disabled, network error,
 * non-200, blocked/empty response, out of time). When it fails, $error is set
 * to a short human-readable reason that is safe to show an admin — it never
 * contains the API key or the provider's own error text (that is written to
 * error_log).
 *
 * $budgetSeconds is a HARD cap on the whole call, fallbacks included: every
 * attempt gets only the time still left, and nothing new is started once
 * there isn't enough left to be useful. $timedOut is set when that cap (or a
 * single attempt's share of it) is what ended the call.
 *
 * Tries GEMINI_MODEL first, then each of GEMINI_FALLBACK_MODELS in order,
 * moving on whenever a model can't answer right now (see
 * GEMINI_FALLBACK_STATUSES). Free-tier quotas are per model per day, and the
 * newest models are often overloaded, so a chain both stretches the quota and
 * keeps the feature answering instead of failing on the first "high demand".
 * Those failures come back within a second or so, so they leave time for the
 * next model. A network error is NOT retried on another model — the
 * connection itself is the problem.
 */
function geminiGenerateText(array $body, float $budgetSeconds, ?string &$error = null, ?bool &$timedOut = null): ?string
{
    $error = null;
    $timedOut = false;
    if (!AI_ENABLED) {
        $error = 'ยังไม่ได้ตั้งค่า Gemini API key';
        return null;
    }

    $deadline = microtime(true) + $budgetSeconds;
    $models = array_values(array_unique(array_merge([GEMINI_MODEL], GEMINI_FALLBACK_MODELS)));
    $attempt = ['status' => 0, 'body' => '', 'curl_error' => '', 'timed_out' => false];
    $tried = 0;
    foreach ($models as $model) {
        $remaining = $deadline - microtime(true);
        // Not worth starting another model with under ~1s left (the first one
        // always gets a go, however small the budget).
        if ($tried > 0 && $remaining < 1.0) {
            break;
        }
        $tried++;
        $attempt = geminiAttempt($model, $body, max(0.3, $remaining));
        if ($attempt['timed_out']) {
            error_log("Gemini API $model timed out after " . round($budgetSeconds - ($deadline - microtime(true)), 1) . 's');
            $timedOut = true;
            $error = 'AI ตอบช้าเกินเวลาที่กำหนด (' . rtrim(rtrim(number_format($budgetSeconds, 1), '0'), '.') . ' วินาที) กรุณาลองใหม่อีกครั้ง';
            return null;
        }
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
        // The provider's own message (quota/billing/project details) can be
        // internal detail — it goes to the server log, never to the browser.
        $apiMessage = json_decode($attempt['body'], true)['error']['message'] ?? '';
        error_log("Gemini API $model HTTP {$attempt['status']}: " . $apiMessage);
        if (!in_array($attempt['status'], GEMINI_FALLBACK_STATUSES, true)) {
            break;
        }
    }

    $status = $attempt['status'];
    $error = $status === 429
        ? 'บริการ AI ถูกใช้งานเกินโควตาชั่วคราว กรุณารอสักครู่แล้วลองใหม่'
        : "บริการ AI ตอบกลับผิดพลาด (HTTP $status) กรุณาลองใหม่อีกครั้ง";
    return null;
}
