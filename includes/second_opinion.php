<?php
// Second opinion for photo identification: the same prompt and photo, sent to
// Google Gemini, used only when the local model is not confident (see
// applySecondOpinion() in plant_identify.php). Server-side only; the API key
// never reaches the browser.
require_once __DIR__ . '/../config/config.php';

function secondOpinionAvailable(): bool
{
    return GEMINI_API_KEY !== '' || PLANTNET_API_KEY !== '';
}

/**
 * Returns the model's text reply, or null on any failure. $error is a short
 * Thai reason that is safe to show an admin (the provider's own message goes
 * to error_log, never to the browser).
 */
function geminiSecondOpinionText(string $prompt, ?string $imageBytes, string $mimeType, float $budgetSeconds, ?string &$error = null): ?string
{
    $error = null;
    if (!secondOpinionAvailable()) {
        $error = 'ยังไม่ได้ตั้งค่า GEMINI_API_KEY';
        return null;
    }
    $ch = curl_init(GEMINI_API_BASE . '/v1beta/models/' . rawurlencode(GEMINI_MODEL) . ':generateContent');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            // $imageBytes null = a text-only request (translation)
            'contents' => [['parts' => array_values(array_filter([
                ['text' => $prompt],
                $imageBytes !== null ? ['inline_data' => ['mime_type' => $mimeType, 'data' => base64_encode($imageBytes)]] : null,
            ]))]],
            'generationConfig' => ['response_mime_type' => 'application/json'],
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-goog-api-key: ' . GEMINI_API_KEY],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT_MS => max(500, (int) round($budgetSeconds * 1000)),
        CURLOPT_CONNECTTIMEOUT_MS => 3000,
    ]);
    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $failed = $response === false ? (curl_error($ch) ?: 'unknown error') : '';
    curl_close($ch);

    if ($failed !== '') {
        error_log('Gemini second opinion failed: ' . $failed);
        $error = 'เชื่อมต่อ Gemini ไม่สำเร็จ';
        return null;
    }
    $decoded = json_decode((string) $response, true);
    if ($status !== 200) {
        error_log("Gemini second opinion HTTP $status: " . ($decoded['error']['message'] ?? ''));
        $error = "Gemini ตอบกลับผิดพลาด (HTTP $status)";
        return null;
    }
    $text = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? null;
    if (!is_string($text) || trim($text) === '') {
        $error = 'Gemini ไม่ส่งผลลัพธ์กลับมา';
        return null;
    }
    return $text;
}
