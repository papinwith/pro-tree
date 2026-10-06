<?php
// The "details" of an identification - description, care, characteristics, properties, benefits, cautions and the uses of
// each part - are filled in EVERY time, whatever engine named the plant (tree in the browser, Qwen, Pl@ntNet, Gemini).
// The engines only decide the NAME; this module then looks for the write-up in this order:
//   1. the species already catalogued in the system (the text people wrote and approved),
//   2. the cache of what the AI wrote for that scientific name before (no new AI call),
//   3. a fresh AI write-up (Gemini if configured - fast; else the local text model), which is then cached.
// A write-up is about the species in general, so it can be reused for every photo of it. It is AI-written text and the
// page says so: it must be read before saving. If nothing can write it (no engine reachable) the answer still goes
// out, with details_status = 'failed' so the page can say the details could not be produced right now.
require_once __DIR__ . '/training_samples.php'; // canonicalSpeciesName() and plant_identify.php, ollama.php, second_opinion.php

function ensureSpeciesDetailsTable(PDO $pdo): void
{
    static $done = null; // keyed by the PDO object itself - object ids are reused once a PDO is freed
    $done ??= new WeakMap();
    if (!isset($done[$pdo])) {
        $ts = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'DATETIME DEFAULT CURRENT_TIMESTAMP' : 'TIMESTAMP NOT NULL DEFAULT now()';
        $pdo->exec("CREATE TABLE IF NOT EXISTS species_details_cache (
            name_scientific VARCHAR(160) NOT NULL PRIMARY KEY,
            details_json TEXT NOT NULL,
            source VARCHAR(20) NOT NULL,
            created_at $ts
        )");
        $done[$pdo] = true;
    }
}

/** The result keys that make up the write-up (the first is species.description). */
function speciesDetailKeys(): array
{
    return array_merge(['description_th'], plantDetailFields());
}

/** The long write-up (care, characteristics, ...) - the one-line description a photo answer already carries does not count. */
function resultHasLongDetails(array $result): bool
{
    foreach (plantDetailFields() as $k) {
        if (trim((string) ($result[$k] ?? '')) !== '') {
            return true;
        }
    }
    return false;
}

function resultHasDetails(array $result): bool
{
    foreach (speciesDetailKeys() as $k) {
        if (trim((string) ($result[$k] ?? '')) !== '') {
            return true;
        }
    }
    return false;
}

/** Text-only instruction asking for the write-up of a species that is already named. */
function buildSpeciesDetailPrompt(string $scientific, string $thaiName, ?array $catalogue = null): string
{
    $who = $scientific . ($thaiName !== '' ? " (Thai name: $thaiName)" : '');
    $prompt = "You are an expert tropical botanist writing for a public plant information page in Thailand. The plant is: $who.\n"
        . "Write the following IN THAI, factual and concise, about this SPECIES in general: description_th (1-3 sentences), "
        . "care_instructions (how to grow and look after it: light, water, soil, pruning), characteristics (appearance and growth: height, leaves, flowers, fruit), "
        . "properties (notable botanical/chemical/practical properties), benefits (uses and benefits), cautions (toxicity, allergens, who should avoid it, pests/invasiveness), "
        . "part_uses (uses of each part, formatted like \"ดอก: ...; ผล: ...; ลำต้น: ...\"). Use an empty string for anything you do not reliably know - do not invent facts.\n";
    $shape = '"description_th": "...", "care_instructions": "...", "characteristics": "...", "properties": "...", "benefits": "...", "cautions": "...", "part_uses": "..."';
    if ($catalogue !== null) {
        $cats = array_map(static fn($c) => '  ' . $c['code'] . ' = ' . $c['name_th'], $catalogue['categories']);
        $subs = array_map(static fn($s) => '  ' . $s['id'] . ' = ' . $s['name_th'], $catalogue['subtypes']);
        $prompt .= "- category_code: pick exactly ONE code from this list that best fits, or null if none fits:\n" . implode("\n", $cats) . "\n"
            . "- subtype_ids: pick 1-3 ids from this list that fit (an empty array if none fit). Never invent ids or codes outside the lists:\n" . implode("\n", $subs) . "\n";
        $shape .= ', "category_code": "code or null", "subtype_ids": [1]';
    }
    return $prompt . "\nRespond with ONLY a JSON object of this exact shape (no markdown fences, no commentary):\n{" . $shape . '}';
}

/** Bounded, typed details out of whatever JSON the model returned (or null if it gave nothing usable). */
function normalizeSpeciesDetails(?array $raw): ?array
{
    if ($raw === null) {
        return null;
    }
    $n = normalizePlantIdentification(['is_plant' => true, 'name_th' => 'x', 'name_scientific' => 'x'] + $raw);
    $out = [];
    foreach (speciesDetailKeys() as $k) {
        $out[$k] = (string) ($n[$k] ?? '');
    }
    $out['category_code'] = $n['category_code'] ?? null;
    $out['subtype_ids'] = $n['subtype_ids'] ?? [];
    return resultHasLongDetails($out) ? $out : null;
}

/** Default writer: Gemini when configured (about 5 s), else the local text model (slow on a small GPU). */
function defaultDetailGenerator(string $prompt, float $budgetSeconds, ?string &$source = null): ?string
{
    $err = null;
    if (GEMINI_API_KEY !== '' && $budgetSeconds >= 4.0) {
        $text = geminiSecondOpinionText($prompt, null, '', min(40.0, $budgetSeconds), $err);
        if ($text !== null) {
            $source = 'gemini';
            return $text;
        }
    }
    if (OLLAMA_ENABLED && $budgetSeconds >= 10.0) {
        $timedOut = false;
        $text = ollamaGenerateText(OLLAMA_MODEL, $prompt, $budgetSeconds - 1.0, null, $err, $timedOut);
        if ($text !== null) {
            $source = 'ollama';
            return $text;
        }
    }
    return null;
}

/** True when some engine could write details at all (used to reserve time for it). */
function canGenerateDetails(): bool
{
    return GEMINI_API_KEY !== '' || OLLAMA_ENABLED;
}

/** The write-up of a catalogued species as result keys, or null if the row has none of it. */
function catalogueDetailsFor(PDO $pdo, int $speciesId): ?array
{
    $stmt = $pdo->prepare('SELECT description, care_instructions, characteristics, properties, benefits, cautions, part_uses FROM species WHERE id = :id');
    $stmt->execute(['id' => $speciesId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $out = ['description_th' => (string) ($row['description'] ?? '')];
    foreach (plantDetailFields() as $k) {
        $out[$k] = (string) ($row[$k] ?? '');
    }
    return resultHasLongDetails($out) ? $out : null;
}

/**
 * Fills the details of an identification result (see the top of this file for the order). $matchedId is the catalogued
 * species the answer matched, if any. $generate (prompt, budget, &source) => json text is injectable for tests.
 * Adds details_status ('present' | 'catalogue' | 'cache' | 'generated' | 'failed' | 'not_applicable') and details_source.
 * Never throws and never removes an answer: at worst the result comes back without details.
 */
function completeWithDetails(PDO $pdo, array $result, ?array $catalogue, float $budgetSeconds, ?callable $generate = null, ?int $matchedId = null): array
{
    $result['details_status'] = 'not_applicable';
    $result['details_source'] = null;
    $name = canonicalSpeciesName((string) ($result['name_scientific'] ?? ''));
    if (empty($result['is_plant']) || $name === '') {
        return $result;
    }
    $apply = static function (array $result, array $details, string $status, string $source): array {
        foreach (speciesDetailKeys() as $k) {
            // the engine's own one-line description of THIS photo is kept; the catalogue's approved text replaces it
            if ($k === 'description_th' && $status !== 'catalogue' && trim((string) ($result[$k] ?? '')) !== '') {
                continue;
            }
            $result[$k] = $details[$k] ?? '';
        }
        if (array_key_exists('category_code', $details) && empty($result['category_code'])) {
            $result['category_code'] = $details['category_code'];
            $result['subtype_ids'] = $details['subtype_ids'] ?? [];
        }
        $result['details_status'] = $status;
        $result['details_source'] = $source;
        return $result;
    };
    $finish = static function (array $result) use ($catalogue): array {
        return $catalogue !== null ? constrainToCatalogue($result, $catalogue['categories'], $catalogue['subtypes']) : $result;
    };

    try {
        if (resultHasLongDetails($result)) {
            $result['details_status'] = 'present';
            $result['details_source'] = (string) ($result['main_source'] ?? 'ai');
            return $finish($result);
        }
        if ($matchedId !== null && ($d = catalogueDetailsFor($pdo, $matchedId)) !== null) {
            return $finish($apply($result, $d, 'catalogue', 'catalogue'));
        }

        ensureSpeciesDetailsTable($pdo);
        $stmt = $pdo->prepare('SELECT details_json FROM species_details_cache WHERE name_scientific = :n');
        $stmt->execute(['n' => $name]);
        $cached = $stmt->fetchColumn();
        if ($cached !== false && ($d = normalizeSpeciesDetails(json_decode((string) $cached, true) ?: null)) !== null) {
            return $finish($apply($result, $d, 'cache', 'cache'));
        }

        $source = null;
        $generate = $generate ?? 'defaultDetailGenerator';
        $text = $budgetSeconds >= 4.0 ? $generate(buildSpeciesDetailPrompt($name, (string) ($result['name_th'] ?? ''), $catalogue), $budgetSeconds, $source) : null;
        $d = $text === null ? null : normalizeSpeciesDetails(json_decode($text, true) ?: null);
        if ($d === null) {
            $result['details_status'] = 'failed';
            return $finish($result);
        }
        $pdo->prepare('INSERT INTO species_details_cache (name_scientific, details_json, source) VALUES (:n, :j, :s) ON CONFLICT (name_scientific) DO NOTHING')
            ->execute(['n' => $name, 'j' => json_encode($d, JSON_UNESCAPED_UNICODE), 's' => (string) $source]);
        return $finish($apply($result, $d, 'generated', (string) $source));
    } catch (Throwable $e) {
        error_log('species details failed: ' . $e->getMessage());
        $result['details_status'] = 'failed';
        return $result;
    }
}
