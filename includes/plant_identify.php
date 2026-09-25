<?php
// "What tree is this?" — sends a photo to Gemini's vision model and returns
// a best-guess identification, for when an admin is cataloguing a tree they
// don't recognize. This is only ever a SUGGESTION shown to the admin to
// review — nothing here writes to the database.
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/gemini.php';

/**
 * Coerces whatever JSON the model returned into a predictable, bounded
 * shape (the model is instructed on the format, but nothing guarantees it
 * followed it — wrong types, missing keys, absurdly long strings). Pure
 * function, no I/O.
 */
function normalizePlantIdentification(array $raw): array
{
    $str = static function ($v, int $max): string {
        return is_string($v) ? mb_substr(trim($v), 0, $max) : '';
    };

    $alternatives = [];
    foreach (is_array($raw['alternatives'] ?? null) ? $raw['alternatives'] : [] as $alt) {
        if (!is_array($alt)) {
            continue;
        }
        $altTh = $str($alt['name_th'] ?? null, 120);
        $altSci = $str($alt['name_scientific'] ?? null, 160);
        if ($altTh !== '' || $altSci !== '') {
            $alternatives[] = ['name_th' => $altTh, 'name_scientific' => $altSci];
        }
        if (count($alternatives) >= 3) {
            break;
        }
    }

    $confidence = $raw['confidence'] ?? '';
    if (!in_array($confidence, ['high', 'medium', 'low'], true)) {
        $confidence = 'low';
    }

    $nameTh = $str($raw['name_th'] ?? null, 120);
    $nameScientific = $str($raw['name_scientific'] ?? null, 160);
    // Nothing usable to suggest, whatever `is_plant` claims.
    $isPlant = ($raw['is_plant'] ?? false) === true && ($nameTh !== '' || $nameScientific !== '');

    return [
        'is_plant' => $isPlant,
        'name_th' => $isPlant ? $nameTh : '',
        'name_common' => $isPlant ? $str($raw['name_common'] ?? null, 120) : '',
        'name_scientific' => $isPlant ? $nameScientific : '',
        'confidence' => $confidence,
        'description_th' => $isPlant ? $str($raw['description_th'] ?? null, 1200) : '',
        'notes_th' => $str($raw['notes_th'] ?? null, 600),
        'alternatives' => $isPlant ? $alternatives : [],
    ];
}

/**
 * Sends the photo to Gemini. Returns ['ok' => true, 'result' => <normalized>]
 * or ['ok' => false, 'error' => <Thai message>].
 */
function identifyPlantFromImage(string $imageBytes, string $mimeType): array
{
    $prompt = "You are a botanist helping a Thai plant nursery catalogue its trees. "
        . "Identify the plant in the attached photo.\n\n"
        . "Rules:\n"
        . "- Base the answer only on what is actually visible (leaves, flowers, fruit, bark, growth habit). Do not guess wildly; if unsure, say so via a lower confidence.\n"
        . "- confidence: \"high\" only if the plant is clearly and distinctively identifiable, \"medium\" if likely but similar species exist, \"low\" if it is a best guess.\n"
        . "- If the photo does not show a plant, or the plant cannot be identified at all, set is_plant to false and leave the name fields empty.\n"
        . "- name_th is the common Thai name; name_common is the common English name; name_scientific is the Latin binomial (genus + species, no author).\n"
        . "- description_th: 1-3 Thai sentences describing this plant. notes_th: Thai; what you based the identification on, and if confidence is not high, what extra photo (e.g. close-up of flower, leaf, fruit) would help.\n"
        . "- alternatives: up to 3 other plausible species when confidence is not high, otherwise an empty array.\n\n"
        . "Respond with ONLY a JSON object of this exact shape (no markdown fences, no commentary):\n"
        . '{"is_plant": true, "name_th": "...", "name_common": "...", "name_scientific": "...", "confidence": "high|medium|low", '
        . '"description_th": "...", "notes_th": "...", "alternatives": [{"name_th": "...", "name_scientific": "..."}]}';

    $error = null;
    $innerText = geminiGenerateText([
        'contents' => [[
            'parts' => [
                ['text' => $prompt],
                ['inline_data' => ['mime_type' => $mimeType, 'data' => base64_encode($imageBytes)]],
            ],
        ]],
        'generationConfig' => ['response_mime_type' => 'application/json'],
    ], 60, $error);

    if ($innerText === null) {
        return ['ok' => false, 'error' => $error ?? 'เรียกใช้บริการ AI ไม่สำเร็จ'];
    }

    $raw = json_decode($innerText, true);
    if (!is_array($raw)) {
        return ['ok' => false, 'error' => 'อ่านผลลัพธ์จาก AI ไม่ได้ กรุณาลองใหม่อีกครั้ง'];
    }
    return ['ok' => true, 'result' => normalizePlantIdentification($raw)];
}

/** "Cassia fistula L. 'Alba'" -> "cassia fistula" (genus + species only). */
function scientificNameKey(string $name): string
{
    $words = preg_split('/\s+/', mb_strtolower(trim($name)), -1, PREG_SPLIT_NO_EMPTY);
    return implode(' ', array_slice($words, 0, 2));
}

/**
 * Finds an already-catalogued species matching an identification — by
 * scientific name (genus + species, so author/cultivar suffixes don't
 * matter), falling back to an exact Thai-name match. Returns that species
 * row (id, name, ...) or null.
 */
function findMatchingSpecies(PDO $pdo, array $identification): ?array
{
    $sciKey = scientificNameKey((string) ($identification['name_scientific'] ?? ''));
    $nameTh = trim((string) ($identification['name_th'] ?? ''));

    $byName = null;
    foreach (getAllSpecies($pdo) as $species) {
        if ($sciKey !== '' && scientificNameKey((string) ($species['name_scientific'] ?? '')) === $sciKey) {
            return $species;
        }
        if ($byName === null && $nameTh !== '' && trim((string) $species['name']) === $nameTh) {
            $byName = $species;
        }
    }
    return $byName;
}
