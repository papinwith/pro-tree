<?php
// Shared low-level Google Gemini call — used by translation.php (text
// translation) and plant_identify.php (photo identification). Server-side
// only; the API key never reaches the browser.
require_once __DIR__ . '/../config/config.php';

/**
 * POSTs $body (a generateContent request) to Gemini and returns the text of
 * the first candidate, or null on any failure (AI disabled, network error,
 * non-200, blocked/empty response). When it fails, $error is set to a short
 * human-readable reason that is safe to show an admin — it never contains the
 * API key or the provider's own error text (that is written to error_log).
 */
function geminiGenerateText(array $body, int $timeoutSeconds, ?string &$error = null): ?string
{
    $error = null;
    if (!AI_ENABLED) {
        $error = 'ยังไม่ได้ตั้งค่า Gemini API key';
        return null;
    }

    $url = GEMINI_API_BASE . '/v1beta/models/' . rawurlencode(GEMINI_MODEL)
        . ':generateContent';

    $ch = curl_init($url);
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
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curlError) {
        $error = 'เชื่อมต่อบริการ AI ไม่สำเร็จ' . ($curlError ? " ($curlError)" : '');
        return null;
    }
    if ($status !== 200) {
        // The provider's own message (quota/billing/project details) can be
        // internal detail — it goes to the server log, never to the browser.
        $apiMessage = json_decode((string) $response, true)['error']['message'] ?? '';
        error_log("Gemini API HTTP $status: " . $apiMessage);
        $error = $status === 429
            ? 'บริการ AI ถูกใช้งานเกินโควตาชั่วคราว กรุณารอสักครู่แล้วลองใหม่'
            : "บริการ AI ตอบกลับผิดพลาด (HTTP $status) กรุณาลองใหม่อีกครั้ง";
        return null;
    }

    $decoded = json_decode($response, true);
    $innerText = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? null;
    if (!is_string($innerText)) {
        $error = 'บริการ AI ไม่ส่งผลลัพธ์กลับมา (อาจถูกบล็อกโดยตัวกรองความปลอดภัย)';
        return null;
    }
    return $innerText;
}
