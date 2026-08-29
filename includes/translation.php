<?php
// Google Gemini AI translation — server-side only. Admin only ever enters
// Thai content (species_form.php); English/Chinese are generated
// automatically the first time a visitor views a tree page in that
// language, then cached on the species row so later visitors reuse it
// instead of calling Gemini again. Falls back to Thai silently if AI is
// disabled or the call fails — nothing on the public page ever errors out
// over a translation problem.
require_once __DIR__ . '/functions.php';

/** Species fields eligible for AI translation, in display order. Thai is
 *  always the source; name_scientific is deliberately excluded — Latin
 *  binomials are never translated, so they're simply never sent. */
function translatableSpeciesFields(): array
{
    return [
        'name' => 'ชื่อต้นไม้',
        'description' => 'คำอธิบาย',
        'care_instructions' => 'วิธีดูแล',
        'characteristics' => 'ลักษณะ',
        'properties' => 'คุณสมบัติ',
        'benefits' => 'ประโยชน์',
        'cautions' => 'ข้อควรระวัง',
        'part_uses' => 'การใช้ประโยชน์แต่ละส่วน',
    ];
}

/**
 * Calls Gemini once with every non-empty Thai field and asks for a single
 * structured JSON response covering all of them, for only the requested
 * target language(s) — so viewing a tree in English never pays to also
 * translate Chinese nobody asked for yet.
 * Returns ['en' => ['field' => text, ...]] (and/or 'zh') or null on any
 * failure (network error, bad status, malformed response).
 */
function geminiTranslateFields(array $thaiFieldsByKey, array $targetLangs): ?array
{
    if (!AI_ENABLED || !$thaiFieldsByKey || !$targetLangs) {
        return null;
    }

    $fieldList = [];
    foreach ($thaiFieldsByKey as $key => $text) {
        $fieldList[] = "- {$key}: " . $text;
    }
    $langNames = ['en' => 'English', 'zh' => 'Chinese'];
    $wantedLangs = array_values(array_intersect($targetLangs, ['en', 'zh']));
    if (!$wantedLangs) {
        return null;
    }
    $langLabel = implode(' and ', array_map(fn($l) => $langNames[$l], $wantedLangs));

    $shape = [];
    foreach ($wantedLangs as $lang) {
        $shape[] = '"' . $lang . '": {"<field_key>": "...", ...}';
    }

    $prompt = "You are translating Thai plant/tree catalog content into {$langLabel} for a public information page.\n"
        . "Translate EVERY field below from Thai into {$langLabel}.\n"
        . "Preserve scientific names, proper nouns, and botanical terminology exactly where they appear — do not attempt to translate Latin binomials.\n"
        . "Keep the tone factual and concise, matching the source.\n\n"
        . "Fields:\n" . implode("\n", $fieldList) . "\n\n"
        . "Respond with ONLY a JSON object of this exact shape (no markdown fences, no commentary):\n"
        . '{' . implode(', ', $shape) . '}' . "\n"
        . "Include every field key listed above.";

    $body = json_encode([
        'contents' => [['parts' => [['text' => $prompt]]]],
        'generationConfig' => ['response_mime_type' => 'application/json'],
    ]);

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode(GEMINI_MODEL)
        . ':generateContent?key=' . rawurlencode(GEMINI_API_KEY);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curlError || $status !== 200) {
        return null;
    }

    $decoded = json_decode($response, true);
    $innerText = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? null;
    if (!is_string($innerText)) {
        return null;
    }

    $result = json_decode($innerText, true);
    if (!is_array($result)) {
        return null;
    }

    return $result;
}

/**
 * Ensures every translatable field on this species has cached text for
 * $lang ('en'|'zh'). Only fields still empty for that language are sent
 * to Gemini — already-cached values are left untouched, so this is cheap
 * on every request after the first. Persists results onto the species row
 * (species.{field}_{lang}) so future visitors reuse the cache, and returns
 * the (possibly updated) $species/$tree array with translations merged in
 * so THIS request also renders them immediately.
 *
 * $species may be a plain species row or a joined tree row (as returned by
 * getTreeById()) — either way it must contain the translatable Thai fields
 * plus a `species_id` key identifying which species row to update.
 */
function ensureSpeciesTranslated(PDO $pdo, array $species, string $lang): array
{
    if (!AI_ENABLED || !in_array($lang, ['en', 'zh'], true) || empty($species['species_id'])) {
        return $species;
    }

    $missing = [];
    foreach (array_keys(translatableSpeciesFields()) as $field) {
        $thaiValue = trim((string) ($species[$field] ?? ''));
        $existing = trim((string) ($species[$field . '_' . $lang] ?? ''));
        if ($thaiValue !== '' && $existing === '') {
            $missing[$field] = $thaiValue;
        }
    }
    if (!$missing) {
        return $species; // fully cached already, or nothing to translate
    }

    $translated = geminiTranslateFields($missing, [$lang]);
    if ($translated === null || !isset($translated[$lang]) || !is_array($translated[$lang])) {
        return $species; // Gemini unavailable — caller renders the Thai fallback as-is
    }

    $updates = [];
    foreach ($missing as $field => $thaiValue) {
        $text = $translated[$lang][$field] ?? null;
        if (is_string($text) && trim($text) !== '') {
            $updates[$field . '_' . $lang] = trim($text);
        }
    }
    if (!$updates) {
        return $species;
    }

    $setSql = [];
    $params = ['id' => (int) $species['species_id']];
    foreach ($updates as $column => $text) {
        $setSql[] = "`$column` = :$column";
        $params[$column] = $text;
    }
    $pdo->prepare('UPDATE species SET ' . implode(', ', $setSql) . ' WHERE id = :id')->execute($params);

    foreach ($updates as $column => $text) {
        $species[$column] = $text;
    }
    return $species;
}

/** Zone fields eligible for on-demand AI translation, same lazy-cache
 *  pattern as ensureSpeciesTranslated(). */
function translatableZoneFields(): array
{
    return [
        'name' => 'ชื่อโซน',
        'description' => 'คำอธิบายโซน',
    ];
}

/**
 * Same lazy-translate-and-cache behavior as ensureSpeciesTranslated(), for
 * a zone. Admin only ever enters the Thai name/description (zone_form.php);
 * EN/ZH are generated on first view in that language and cached on the
 * zone row. Returns the zone row (from getZoneById()) with translations
 * merged in, or the untranslated row if AI is disabled/unavailable/the
 * zone doesn't exist.
 */
function ensureZoneTranslated(PDO $pdo, int $zoneId, string $lang): array
{
    $zone = getZoneById($pdo, $zoneId);
    if (!$zone) {
        return [];
    }
    if (!AI_ENABLED || !in_array($lang, ['en', 'zh'], true)) {
        return $zone;
    }

    $missing = [];
    foreach (array_keys(translatableZoneFields()) as $field) {
        $thaiValue = trim((string) ($zone[$field] ?? ''));
        $existing = trim((string) ($zone[$field . '_' . $lang] ?? ''));
        if ($thaiValue !== '' && $existing === '') {
            $missing[$field] = $thaiValue;
        }
    }
    if (!$missing) {
        return $zone;
    }

    $translated = geminiTranslateFields($missing, [$lang]);
    if ($translated === null || !isset($translated[$lang]) || !is_array($translated[$lang])) {
        return $zone;
    }

    $updates = [];
    foreach ($missing as $field => $thaiValue) {
        $text = $translated[$lang][$field] ?? null;
        if (is_string($text) && trim($text) !== '') {
            $updates[$field . '_' . $lang] = trim($text);
        }
    }
    if (!$updates) {
        return $zone;
    }

    $setSql = [];
    $params = ['id' => $zoneId];
    foreach ($updates as $column => $text) {
        $setSql[] = "`$column` = :$column";
        $params[$column] = $text;
    }
    $pdo->prepare('UPDATE zones SET ' . implode(', ', $setSql) . ' WHERE id = :id')->execute($params);

    foreach ($updates as $column => $text) {
        $zone[$column] = $text;
    }
    return $zone;
}

/**
 * Same lazy-translate-and-cache behavior as ensureZoneTranslated(), for a
 * category. Admin only ever enters the Thai name (admin/category_form.php);
 * EN/ZH are generated on first public view in that language and cached on
 * the category row. Returns the category row, translated if possible.
 */
function ensureCategoryTranslated(PDO $pdo, string $categoryCode, string $lang): array
{
    $category = getCategoryByCode($pdo, $categoryCode);
    if (!$category) {
        return [];
    }
    if (!AI_ENABLED || !in_array($lang, ['en', 'zh'], true)) {
        return $category;
    }

    $thaiValue = trim((string) ($category['name_th'] ?? ''));
    $existing = trim((string) ($category['name_' . $lang] ?? ''));
    if ($thaiValue === '' || $existing !== '') {
        return $category; // nothing to translate, or already cached
    }

    $translated = geminiTranslateFields(['name' => $thaiValue], [$lang]);
    $text = $translated[$lang]['name'] ?? null;
    if (!is_string($text) || trim($text) === '') {
        return $category;
    }

    $column = 'name_' . $lang;
    $pdo->prepare("UPDATE categories SET `$column` = :val WHERE code = :code")
        ->execute(['val' => trim($text), 'code' => $categoryCode]);
    $category[$column] = trim($text);
    return $category;
}
