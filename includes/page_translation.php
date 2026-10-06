<?php
// Translating the public pages WITHOUT making the visitor wait.
//
// Admins only write Thai; English/Chinese are produced by AI and cached in the database. Doing that while the page
// is being built made the first visitor of every language wait for up to five AI calls in a row. Instead:
//   - the page is built at once from whatever is cached (Thai where there is no translation yet),
//   - everything still missing - species, zone, category and the contact texts - goes to the AI in ONE call,
//     asked for by the browser after the page has shown (public/translate_page.php), and the page reloads
//     once when the translation is ready;
//   - admin/translate_warm.php can do all of it in advance (before an exhibition), so nobody ever waits.
// The helpers below find what is missing (no AI), translate it in one call and store it.
require_once __DIR__ . '/translation.php';

const PAGE_TRANSLATION_FAIL_PAUSE = 300; // after a failed attempt, do not call the AI again for this page/language for 5 minutes

/** Plain-text settings (contact_address, opening_hours) that are translated and cached as "{key}_{lang}" settings. */
function translatableSettingKeys(): array
{
    return ['contact_address', 'opening_hours'];
}

/**
 * What the tree page still lacks in $lang: a list of items ['key' => unique key, 'text' => Thai, 'kind' => ..., 'id' => ..., 'field' => ...].
 * Reads only - no AI. Species fields come from the joined tree row; zone, category and settings from their own tables.
 */
function treePageMissing(PDO $pdo, array $tree, string $lang): array
{
    if (!in_array($lang, ['en', 'zh'], true)) {
        return [];
    }
    $items = speciesMissingItems($tree, $lang);
    if (!empty($tree['zone_id']) && ($zone = getZoneById($pdo, (int) $tree['zone_id']))) {
        $items = array_merge($items, zoneMissingItems($zone, $lang));
    }
    if (!empty($tree['category_code']) && ($category = getCategoryByCode($pdo, (string) $tree['category_code']))) {
        $items = array_merge($items, categoryMissingItems($category, $lang));
    }
    return array_merge($items, settingMissingItems($pdo, $lang));
}

function speciesMissingItems(array $species, string $lang): array
{
    $id = (int) ($species['species_id'] ?? $species['id'] ?? 0);
    $items = [];
    if ($id > 0) {
        foreach (array_keys(translatableSpeciesFields()) as $f) {
            $thai = trim((string) ($species[$f] ?? ''));
            if ($thai !== '' && trim((string) ($species[$f . '_' . $lang] ?? '')) === '') {
                $items[] = ['key' => "species.$id.$f", 'text' => $thai, 'kind' => 'species', 'id' => $id, 'field' => $f];
            }
        }
    }
    return $items;
}

function zoneMissingItems(array $zone, string $lang): array
{
    $items = [];
    foreach (array_keys(translatableZoneFields()) as $f) {
        $thai = trim((string) ($zone[$f] ?? ''));
        if ($thai !== '' && trim((string) ($zone[$f . '_' . $lang] ?? '')) === '') {
            $items[] = ['key' => "zone.{$zone['id']}.$f", 'text' => $thai, 'kind' => 'zone', 'id' => (int) $zone['id'], 'field' => $f];
        }
    }
    return $items;
}

function categoryMissingItems(array $category, string $lang): array
{
    $thai = trim((string) ($category['name_th'] ?? ''));
    if ($thai !== '' && trim((string) ($category['name_' . $lang] ?? '')) === '') {
        return [['key' => "category.{$category['code']}", 'text' => $thai, 'kind' => 'category', 'id' => (string) $category['code'], 'field' => 'name']];
    }
    return [];
}

function settingMissingItems(PDO $pdo, string $lang): array
{
    $items = [];
    foreach (translatableSettingKeys() as $k) {
        $thai = trim((string) getSetting($pdo, $k, ''));
        if ($thai !== '' && trim((string) getSetting($pdo, $k . '_' . $lang, '')) === '') {
            $items[] = ['key' => "setting.$k", 'text' => $thai, 'kind' => 'setting', 'id' => $k, 'field' => 'text'];
        }
    }
    return $items;
}

/** The zone page lacks only the zone's own name and description. */
function zonePageMissing(PDO $pdo, int $zoneId, string $lang): array
{
    $zone = in_array($lang, ['en', 'zh'], true) ? getZoneById($pdo, $zoneId) : null;
    return $zone ? zoneMissingItems($zone, $lang) : [];
}

/**
 * Translates every item in ONE AI call and stores each result where the page reads it. Returns
 * ['status' => 'complete' | 'translated' | 'partial' | 'failed', 'changed' => bool, 'stored' => n, 'wanted' => n].
 * Never throws. $engine (prompt, budget) => text is injectable for tests.
 */
function translateItems(PDO $pdo, array $items, string $lang, float $budgetSeconds = 60.0, ?callable $engine = null): array
{
    $wanted = count($items);
    if ($wanted === 0) {
        return ['status' => 'complete', 'changed' => false, 'stored' => 0, 'wanted' => 0];
    }
    if (!in_array($lang, ['en', 'zh'], true)) {
        return ['status' => 'failed', 'changed' => false, 'stored' => 0, 'wanted' => $wanted];
    }
    try {
        $fields = [];
        foreach ($items as $it) {
            $fields[$it['key']] = $it['text'];
        }
        $answer = aiTranslateFields($fields, [$lang], $budgetSeconds, $engine);
        $byKey = is_array($answer[$lang] ?? null) ? $answer[$lang] : [];
        $stored = 0;
        foreach ($items as $it) {
            $text = $byKey[$it['key']] ?? null;
            if (!is_string($text) || trim($text) === '') {
                continue;
            }
            if (storeTranslation($pdo, $it, $lang, trim($text))) {
                $stored++;
            }
        }
    } catch (Throwable $e) {
        error_log('page translation failed: ' . $e->getMessage());
        return ['status' => 'failed', 'changed' => false, 'stored' => 0, 'wanted' => $wanted];
    }
    return ['status' => $stored === 0 ? 'failed' : ($stored < $wanted ? 'partial' : 'translated'), 'changed' => $stored > 0, 'stored' => $stored, 'wanted' => $wanted];
}

/** Writes one translated text; table and column names come from fixed lists, never from the request. */
function storeTranslation(PDO $pdo, array $item, string $lang, string $text): bool
{
    switch ($item['kind']) {
        case 'species':
            if (!array_key_exists($item['field'], translatableSpeciesFields())) {
                return false;
            }
            $sql = 'UPDATE species SET "' . $item['field'] . '_' . $lang . '" = :t WHERE id = :id AND COALESCE("' . $item['field'] . '_' . $lang . '", \'\') = \'\'';
            break;
        case 'zone':
            if (!array_key_exists($item['field'], translatableZoneFields())) {
                return false;
            }
            $sql = 'UPDATE zones SET "' . $item['field'] . '_' . $lang . '" = :t WHERE id = :id AND COALESCE("' . $item['field'] . '_' . $lang . '", \'\') = \'\'';
            break;
        case 'category':
            $sql = 'UPDATE categories SET "name_' . $lang . '" = :t WHERE code = :id AND COALESCE("name_' . $lang . '", \'\') = \'\'';
            break;
        case 'setting':
            if (!in_array($item['id'], translatableSettingKeys(), true)) {
                return false;
            }
            setSetting($pdo, $item['id'] . '_' . $lang, $text);
            return true;
        default:
            return false;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['t' => $text, 'id' => $item['id']]);
    return true;
}

/**
 * Runs $work for one page + language unless another request is already doing it (then 'busy'), and remembers a failure
 * for PAGE_TRANSLATION_FAIL_PAUSE seconds so a down AI is not hammered by every visitor. $work returns translateItems()'s array.
 */
function guardedTranslation(string $key, callable $work, ?string $dir = null): array
{
    $dir = $dir ?? sys_get_temp_dir();
    $safe = preg_replace('/[^a-z0-9_]/i', '_', $key);
    $failFile = "$dir/page_translate_fail_$safe";
    clearstatcache(true, $failFile);
    if (is_file($failFile) && time() - (int) filemtime($failFile) < PAGE_TRANSLATION_FAIL_PAUSE) {
        return ['status' => 'failed', 'changed' => false, 'stored' => 0, 'wanted' => 0, 'paused' => true];
    }
    $lock = @fopen("$dir/page_translate_lock_$safe", 'c');
    if ($lock === false) {
        return $work(); // cannot lock: still translate rather than leave the page untranslated
    }
    try {
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            return ['status' => 'busy', 'changed' => false, 'stored' => 0, 'wanted' => 0];
        }
        $result = $work();
        if ($result['status'] === 'failed') {
            @touch($failFile);
        } else {
            @unlink($failFile);
        }
        return $result;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** All the translating the tree page needs, in one call. */
function translateTreePage(PDO $pdo, array $tree, string $lang, float $budgetSeconds = 60.0, ?callable $engine = null): array
{
    return translateItems($pdo, treePageMissing($pdo, $tree, $lang), $lang, $budgetSeconds, $engine);
}

/** Everything that is not tied to one species and could still be missing in $lang: all zones, all categories, the settings. */
function sharedMissingItems(PDO $pdo, string $lang): array
{
    $items = settingMissingItems($pdo, $lang);
    foreach ($pdo->query('SELECT * FROM zones ORDER BY id')->fetchAll() as $zone) {
        $items = array_merge($items, zoneMissingItems($zone, $lang));
    }
    foreach ($pdo->query('SELECT * FROM categories ORDER BY code')->fetchAll() as $category) {
        $items = array_merge($items, categoryMissingItems($category, $lang));
    }
    return $items;
}
