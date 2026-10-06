<?php
// Shared low-level call to a local Ollama server — used by translation.php
// (text translation, OLLAMA_MODEL) and plant_identify.php (photo
// identification, OLLAMA_VISION_MODEL). Server-side only; no API key and no
// paid service involved.
require_once __DIR__ . '/../config/config.php';

/**
 * One /api/chat request to Ollama. Returns the text of the model's reply, or
 * null on any failure (AI disabled, server not running, model not pulled,
 * bad status, empty reply, out of time). When it fails, $error is set to a
 * short human-readable reason that is safe to show an admin — Ollama's own
 * error text is written to error_log instead. $timedOut is set when
 * $budgetSeconds (a hard cap on the whole call) is what ended it.
 *
 * $imageBase64 attaches one photo — $model must then be a vision model.
 */
function ollamaGenerateText(string $model, string $prompt, float $budgetSeconds, ?string $imageBase64 = null, ?string &$error = null, ?bool &$timedOut = null): ?string
{
    $error = null;
    $timedOut = false;
    if (!OLLAMA_ENABLED || $model === '') {
        $error = 'ปิดการใช้งาน Ollama อยู่ (OLLAMA_MODEL ว่าง)';
        return null;
    }

    $message = ['role' => 'user', 'content' => $prompt];
    if ($imageBase64 !== null) {
        $message['images'] = [$imageBase64];
    }
    $ch = curl_init(OLLAMA_URL . '/api/chat');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'model' => $model,
            'messages' => [$message],
            'stream' => false,
            'format' => 'json',
            // qwen3 is a reasoning model; the thinking pass only adds latency
            // to a translation/identification task (ignored by models without it).
            'think' => false,
            'options' => ['temperature' => 0.2],
        ]),
        CURLOPT_HTTPHEADER => array_merge(
            ['Content-Type: application/json'],
            // Only needed when Ollama sits behind an authenticating proxy (see tools/ollama_auth_proxy.py).
            OLLAMA_API_KEY !== '' ? ['Authorization: Bearer ' . OLLAMA_API_KEY] : []
        ),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT_MS => max(300, (int) round($budgetSeconds * 1000)),
        // Fail fast when Ollama simply isn't running.
        CURLOPT_CONNECTTIMEOUT_MS => 3000,
    ]);
    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = $response === false ? (curl_error($ch) ?: 'unknown error') : '';
    $curlTimedOut = $response === false && curl_errno($ch) === CURLE_OPERATION_TIMEDOUT;
    curl_close($ch);

    if ($curlTimedOut) {
        error_log("Ollama $model timed out after " . round($budgetSeconds, 1) . 's');
        $timedOut = true;
        $error = 'AI ตอบช้าเกินเวลาที่กำหนด (' . rtrim(rtrim(number_format($budgetSeconds, 1), '0'), '.') . ' วินาที) กรุณาลองใหม่อีกครั้ง';
        return null;
    }
    if ($curlError !== '') {
        error_log("Ollama $model connection failed: $curlError");
        $error = 'เชื่อมต่อ Ollama (' . OLLAMA_URL . ') ไม่สำเร็จ — เปิดโปรแกรม Ollama ไว้หรือยัง?';
        return null;
    }

    $decoded = json_decode((string) $response, true);
    if ($status !== 200) {
        error_log("Ollama $model HTTP $status: " . ($decoded['error'] ?? ''));
        $error = $status === 404
            ? "ยังไม่ได้ติดตั้งโมเดล $model — รัน ollama pull $model"
            : "Ollama ตอบกลับผิดพลาด (HTTP $status) กรุณาลองใหม่อีกครั้ง";
        return null;
    }
    $text = $decoded['message']['content'] ?? null;
    if (!is_string($text) || trim($text) === '') {
        $error = 'AI ไม่ส่งผลลัพธ์กลับมา';
        return null;
    }
    return $text;
}
