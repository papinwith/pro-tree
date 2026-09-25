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
    // "Unknown plant" / "unidentified" is the model saying it can't tell, not a name.
    if (preg_match('/^\s*(unknown|unidentified|n\/a|none)\b/i', $nameScientific)) {
        $nameScientific = '';
    }
    if (preg_match('/^\s*(unknown|unidentified|n\/a|none|ไม่ทราบ|ไม่ระบุ)/iu', $nameTh)) {
        $nameTh = '';
    }
    // Nothing usable to suggest, whatever `is_plant` claims. The model
    // sometimes answers "true"/1 instead of a JSON boolean, so accept those
    // too (an unrecognized value, e.g. "banana" or an array, counts as false).
    $claimsPlant = is_scalar($raw['is_plant'] ?? null) && filter_var($raw['is_plant'], FILTER_VALIDATE_BOOLEAN);
    $isPlant = $claimsPlant && ($nameTh !== '' || $nameScientific !== '');

    $detail = [];
    foreach (plantDetailFields() as $field) {
        $detail[$field] = $isPlant ? $str($raw[$field] ?? null, 1500) : '';
    }

    // Only ever meaningful when the caller offered a catalogue to choose from
    // (see constrainToCatalogue(), which validates these against it).
    $subtypeIds = [];
    foreach (is_array($raw['subtype_ids'] ?? null) ? $raw['subtype_ids'] : [] as $sid) {
        if ((is_int($sid) || (is_string($sid) && ctype_digit($sid))) && (int) $sid > 0) {
            $subtypeIds[(int) $sid] = (int) $sid;
        }
    }

    return [
        'is_plant' => $isPlant,
        'name_th' => $isPlant ? $nameTh : '',
        'name_common' => $isPlant ? $str($raw['name_common'] ?? null, 120) : '',
        'name_scientific' => $isPlant ? $nameScientific : '',
        'confidence' => $confidence,
        'description_th' => $isPlant ? $str($raw['description_th'] ?? null, 1200) : '',
        'notes_th' => $str($raw['notes_th'] ?? null, 600),
        'alternatives' => $isPlant ? $alternatives : [],
        'category_code' => $isPlant ? ($str($raw['category_code'] ?? null, 20) ?: null) : null,
        'subtype_ids' => $isPlant ? array_slice(array_values($subtypeIds), 0, 3) : [],
    ] + $detail;
}

/** The long-form species fields the AI can draft, same keys as the `species` columns. */
function plantDetailFields(): array
{
    return ['care_instructions', 'characteristics', 'properties', 'benefits', 'cautions', 'part_uses'];
}

/**
 * Restricts the AI's category/subtype picks to ones that really exist (it
 * was only offered the real lists, but nothing guarantees it stuck to them)
 * and adds display names. A subtype tied to a different category than the
 * chosen one is dropped, matching what species_form.php would reject on save.
 * $categories: rows with code, name_th. $subtypes: rows with id, name_th,
 * category_code (null = not yet assigned to a category).
 */
function constrainToCatalogue(array $result, array $categories, array $subtypes): array
{
    $categoryNames = array_column($categories, 'name_th', 'code');
    $categoryCode = $result['category_code'] ?? null;
    if ($categoryCode === null || !isset($categoryNames[$categoryCode])) {
        $categoryCode = null;
    }

    $subtypesById = array_column($subtypes, null, 'id');
    $subtypeIds = [];
    $subtypeNames = [];
    foreach ($result['subtype_ids'] ?? [] as $sid) {
        $st = $subtypesById[$sid] ?? null;
        if ($st === null) {
            continue;
        }
        if ($st['category_code'] !== null && $categoryCode !== null && $st['category_code'] !== $categoryCode) {
            continue;
        }
        $subtypeIds[] = (int) $sid;
        $subtypeNames[] = (string) $st['name_th'];
    }

    $result['category_code'] = $categoryCode;
    $result['category_name'] = $categoryCode !== null ? (string) $categoryNames[$categoryCode] : null;
    $result['subtype_ids'] = $subtypeIds;
    $result['subtype_names'] = $subtypeNames;
    return $result;
}

/**
 * The instruction sent alongside the photo. With $catalogue (['categories' =>
 * rows with code+name_th, 'subtypes' => rows with id+name_th+category_code])
 * it asks for the full species write-up too — care, characteristics,
 * properties, benefits, cautions, per-part uses — and a category/subtype
 * picked from the catalogue's own lists; without it, just the identification.
 */
function buildPlantIdentifyPrompt(?array $catalogue = null, ?array $knownSpecies = null): string
{
    $prompt = "You are an expert tropical botanist helping a plant nursery in Thailand catalogue its plants (ornamental trees and shrubs, palms, fruit trees, herbs, water plants). "
        . "Identify the plant in the attached photo.\n\n"
        . "Rules:\n"
        . "- Base the identification only on what is actually visible (leaves, flowers, fruit, bark, growth habit). Do not guess wildly; if unsure, say so via a lower confidence.\n"
        . "- confidence: \"high\" only if the plant is clearly and distinctively identifiable, \"medium\" if likely but similar species exist, \"low\" if it is a best guess.\n"
        . "- If the photo does not show a plant, or the plant cannot be identified at all, set is_plant to false and leave the name fields empty.\n"
        . "- Consider species commonly grown in Thailand first, but name what you actually see; look for the features that separate look-alikes. If you are only sure of the genus, give the genus with your most likely species and set confidence to low.\n"
        . "- name_th is the common Thai name of THAT species — use an empty string if you do not know a real Thai name, never invent one; name_common is the common English name; name_scientific is the Latin binomial (genus + species, no author).\n"
        . "- description_th: 1-3 Thai sentences describing this plant. notes_th: Thai; what you based the identification on, and if confidence is not high, what extra photo (e.g. close-up of flower, leaf, fruit) would help.\n"
        . "- alternatives: up to 3 other plausible species when confidence is not high, otherwise an empty array.\n";

    // The nursery's own species, as candidates. On 10 real photos this lifted
    // correct species from 7 to 9 (it settles look-alikes such as Plumeria rubra
    // vs alba in favour of the one actually stocked) and did not cause a single
    // false match when the plant was NOT on the list — because the wording tells
    // the model to ignore the list unless the photo clearly matches.
    $candidates = knownSpeciesForPrompt($knownSpecies);
    if ($candidates) {
        $prompt .= "- The nursery already catalogues these species: " . implode('; ', $candidates) . ". If the photo clearly shows one of them, answer with exactly that scientific name. If it does not clearly match any of them, ignore this list and name what you actually see — the plant may be new to the nursery.
";
    }
    $prompt .= "- Never answer \"Unknown\": if you cannot name the species give the genus with sp., and if you cannot even do that, set is_plant to false.
";

    $shape = '"is_plant": true, "name_th": "...", "name_common": "...", "name_scientific": "...", "confidence": "high|medium|low", '
        . '"description_th": "...", "notes_th": "...", "alternatives": [{"name_th": "...", "name_scientific": "..."}]';

    if ($catalogue !== null) {
        $categoryLines = [];
        foreach ($catalogue['categories'] as $cat) {
            $categoryLines[] = '  ' . $cat['code'] . ' = ' . $cat['name_th'];
        }
        $subtypeLines = [];
        foreach ($catalogue['subtypes'] as $st) {
            $subtypeLines[] = '  ' . $st['id'] . ' = ' . $st['name_th'];
        }
        $prompt .= "- All of these are written in Thai, factual and concise, for a public plant information page, about the identified species in general (not just this one plant): "
            . "care_instructions (how to grow and look after it: light, water, soil, pruning), characteristics (appearance and growth: height, leaves, flowers, fruit), "
            . "properties (notable botanical/chemical/practical properties), benefits (uses and benefits), cautions (toxicity, allergens, who should avoid it, pests/invasiveness), "
            . "part_uses (uses of each part, formatted like \"ดอก: ...; ผล: ...; ลำต้น: ...\"). Use an empty string for anything you do not reliably know — do not invent facts.\n"
            . "- category_code: pick exactly ONE code from this list that best fits, or null if none fits:\n" . implode("\n", $categoryLines) . "\n"
            . "- subtype_ids: pick 1-3 ids from this list that fit (an empty array if none fit). Never invent ids or codes outside the lists:\n" . implode("\n", $subtypeLines) . "\n";
        $shape .= ', "care_instructions": "...", "characteristics": "...", "properties": "...", "benefits": "...", "cautions": "...", "part_uses": "...", '
            . '"category_code": "code or null", "subtype_ids": [1]';
    }

    return $prompt . "\nRespond with ONLY a JSON object of this exact shape (no markdown fences, no commentary):\n{" . $shape . '}';
}

/**
 * Sends the photo to Gemini. Returns ['ok' => true, 'result' => <normalized>]
 * or ['ok' => false, 'error' => <Thai message>, 'timed_out' => bool]. Pass
 * $catalogue (see buildPlantIdentifyPrompt()) for the full write-up; the
 * category/subtype it picks are then validated against that same catalogue.
 * $budgetSeconds is a hard cap on the whole AI call (see geminiGenerateText()).
 * $knownSpecies (rows with name_scientific) are offered to the model as candidates.
 */
function identifyPlantFromImage(string $imageBytes, string $mimeType, ?array $catalogue = null, float $budgetSeconds = 60.0, ?array $knownSpecies = null): array
{
    $error = null;
    $timedOut = false;
    $innerText = geminiGenerateText([
        'contents' => [[
            'parts' => [
                ['text' => buildPlantIdentifyPrompt($catalogue, $knownSpecies)],
                ['inline_data' => ['mime_type' => $mimeType, 'data' => base64_encode($imageBytes)]],
            ],
        ]],
        'generationConfig' => ['response_mime_type' => 'application/json'],
    ], $budgetSeconds, $error, $timedOut);

    if ($innerText === null) {
        return ['ok' => false, 'error' => $error ?? 'เรียกใช้บริการ AI ไม่สำเร็จ', 'timed_out' => $timedOut];
    }

    $raw = json_decode($innerText, true);
    if (!is_array($raw)) {
        return ['ok' => false, 'error' => 'อ่านผลลัพธ์จาก AI ไม่ได้ กรุณาลองใหม่อีกครั้ง'];
    }
    $result = normalizePlantIdentification($raw);
    if ($catalogue !== null) {
        $result = constrainToCatalogue($result, $catalogue['categories'], $catalogue['subtypes']);
    }
    return ['ok' => true, 'result' => $result];
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
 * row (id, name, ...) or null. Pass $speciesRows (e.g. from
 * cachedIdentifyLists()) to skip the database read.
 */
function findMatchingSpecies(?PDO $pdo, array $identification, ?array $speciesRows = null): ?array
{
    $sciKey = scientificNameKey((string) ($identification['name_scientific'] ?? ''));
    $nameTh = trim((string) ($identification['name_th'] ?? ''));

    $byName = null;
    foreach ($speciesRows ?? getAllSpecies($pdo ?? db()) as $species) {
        if ($sciKey !== '' && scientificNameKey((string) ($species['name_scientific'] ?? '')) === $sciKey) {
            return $species;
        }
        if ($byName === null && $nameTh !== '' && trim((string) $species['name']) === $nameTh) {
            $byName = $species;
        }
    }
    return $byName;
}

/**
 * Per-admin hourly quota for the paid identify call (AI_IDENTIFY_MAX_PER_HOUR).
 * Counts one use and returns true if the admin is still under the limit, or
 * returns false (without counting) if they've used it up. State is a small
 * timestamp list in the system temp dir, flock()ed so concurrent requests
 * can't both slip under the limit — no schema change needed. It's per
 * server instance and resets if the host wipes its temp dir (fine: it only
 * guards against a stuck or abused button, not a hard billing guarantee).
 * If the file can't be opened at all it fails OPEN, so a broken temp dir
 * doesn't take the whole feature down.
 */
function consumeIdentifyQuota(int $adminId, ?int $maxPerHour = null, ?int $now = null): bool
{
    $max = $maxPerHour ?? AI_IDENTIFY_MAX_PER_HOUR;
    $now = $now ?? time();
    $windowStart = $now - 3600;

    $handle = @fopen(sys_get_temp_dir() . '/tree_ai_identify_' . $adminId . '.json', 'c+');
    if (!$handle) {
        return true;
    }
    try {
        flock($handle, LOCK_EX);
        $stored = json_decode((string) stream_get_contents($handle), true);
        $recent = array_values(array_filter(
            is_array($stored) ? $stored : [],
            static fn($t) => is_int($t) && $t > $windowStart
        ));
        if (count($recent) >= $max) {
            return false;
        }
        $recent[] = $now;
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($recent));
        return true;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

/**
 * Returns $load()'s result, reusing a copy kept in the system temp dir for up
 * to $ttlSeconds. For data that changes rarely but costs a slow remote query
 * to read (categories, subtypes, the species list) on a request with a tight
 * time budget. Stale by at most $ttlSeconds; the callers only use it to steer
 * and cross-check the AI's answer, and everything derived from it is
 * re-validated by the form/server, so brief staleness is harmless. A cache
 * that can't be read or written silently falls back to $load().
 */
function identifyCached(string $name, int $ttlSeconds, callable $load): array
{
    if ($ttlSeconds <= 0) {
        return $load();
    }
    $path = sys_get_temp_dir() . '/tree_ai_cache_' . preg_replace('/[^a-z0-9_]/i', '', $name) . '.json';
    clearstatcache(true, $path); // a long-lived process (or a test) must not see a stale mtime
    if (is_file($path) && time() - (int) @filemtime($path) < $ttlSeconds) {
        $cached = json_decode((string) @file_get_contents($path), true);
        if (is_array($cached)) {
            return $cached;
        }
    }
    $fresh = $load();
    $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($tmp, json_encode($fresh)) !== false) {
        @rename($tmp, $path);
        @unlink($tmp);
    }
    return $fresh;
}

/**
 * The identify endpoint has only a few seconds in total, and every database
 * round trip costs ~0.3 s (plus ~0.8 s to connect) when the database is
 * remote — so the forms that offer the button hand it what they have already
 * loaded to render: the category/subtype/species lists (into the list cache)
 * and a short-lived "this admin was just verified" marker in their own
 * server-side session, so the click itself needs no database access at all.
 * Call it only from a page that has already passed its permission check;
 * $canManageSpecies says whether that check was species.manage (which is what
 * the full write-up needs) or only a tree permission.
 */
function warmIdentifyRequest(bool $canManageSpecies, ?array $categories, ?array $subtypes, ?array $species): void
{
    $ttl = AI_IDENTIFY_CACHE_SECONDS;
    if ($ttl <= 0) {
        return;
    }
    $until = time() + min($ttl, 120);
    $_SESSION['identify_permitted_until'] = $until;
    $_SESSION['identify_species_until'] = $canManageSpecies ? $until : 0;
    if ($categories !== null) {
        identifyRefresh('categories', $categories);
    }
    if ($subtypes !== null) {
        identifyRefresh('subtypes', $subtypes);
    }
    if ($species !== null) {
        identifyRefresh('species', array_map(
            static fn($s) => ['id' => $s['id'], 'name' => $s['name'], 'name_scientific' => $s['name_scientific'] ?? null],
            $species
        ));
    }
}

/** Overwrites the cache entry that identifyCached() reads for $name. */
function identifyRefresh(string $name, array $data): void
{
    $path = sys_get_temp_dir() . '/tree_ai_cache_' . preg_replace('/[^a-z0-9_]/i', '', $name) . '.json';
    $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($tmp, json_encode($data)) !== false) {
        @rename($tmp, $path);
        @unlink($tmp);
    }
}

/** Seconds since the list cache for $name was written, or null if there is none. */
function identifyCacheAge(string $name): ?int
{
    $path = sys_get_temp_dir() . '/tree_ai_cache_' . preg_replace('/[^a-z0-9_]/i', '', $name) . '.json';
    clearstatcache(true, $path);
    return is_file($path) ? max(0, time() - (int) @filemtime($path)) : null;
}

/**
 * Scientific names (genus + species, deduplicated, max 150 so the prompt stays
 * small) of the nursery's own species, for buildPlantIdentifyPrompt().
 */
function knownSpeciesForPrompt(?array $speciesRows): array
{
    $names = [];
    foreach ($speciesRows ?? [] as $row) {
        $sci = trim((string) ($row['name_scientific'] ?? ''));
        $key = scientificNameKey($sci);
        if ($key !== '' && str_contains($key, ' ') && !isset($names[$key])) {
            [$genus, $species] = explode(' ', $key, 2);
            $names[$key] = ucfirst($genus) . ' ' . $species; // "Cassia fistula", whatever the stored casing
        }
    }
    return array_slice(array_values($names), 0, 150);
}

/**
 * Like identifyCached() but never loads: returns the cached list if a fresh copy
 * exists, else null. For data that improves the answer but is not worth a
 * ~1 s database read while the request's time budget is running.
 */
function identifyCachedIfFresh(string $name, int $ttlSeconds): ?array
{
    if ($ttlSeconds <= 0) {
        return null;
    }
    $age = identifyCacheAge($name);
    if ($age === null || $age >= $ttlSeconds) {
        return null;
    }
    $data = json_decode((string) @file_get_contents(sys_get_temp_dir() . '/tree_ai_cache_' . preg_replace('/[^a-z0-9_]/i', '', $name) . '.json'), true);
    return is_array($data) ? $data : null;
}
