<?php
// Multilingual support: Thai (default), English, Chinese.
define('SUPPORTED_LOCALES', ['th', 'en', 'zh']);
define('LANG_COOKIE_NAME', 'tree_lang');

/**
 * Resolves the active locale for this request: ?lang= query param wins and
 * is remembered in a cookie, otherwise the existing cookie is used,
 * otherwise Thai. Unknown values fall back to Thai.
 */
function currentLocale(): string
{
    static $locale = null;
    if ($locale !== null) {
        return $locale;
    }

    $requested = $_GET['lang'] ?? null;
    $cookieVal = $_COOKIE[LANG_COOKIE_NAME] ?? null;
    $candidate = $requested ?? $cookieVal ?? 'th';

    if (!in_array($candidate, SUPPORTED_LOCALES, true)) {
        $candidate = 'th';
    }

    if ($requested !== null && $candidate !== $cookieVal && !headers_sent()) {
        setcookie(LANG_COOKIE_NAME, $candidate, [
            'expires' => time() + 60 * 60 * 24 * 365,
            'path' => '/',
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
    }

    $locale = $candidate;
    return $locale;
}

function loadTranslations(string $locale): array
{
    static $cache = [];
    if (isset($cache[$locale])) {
        return $cache[$locale];
    }
    $file = __DIR__ . "/lang/$locale.php";
    $cache[$locale] = is_file($file) ? require $file : [];
    return $cache[$locale];
}

/** Translates a UI string key for the current locale, falling back to Thai. */
function t(string $key): string
{
    $strings = loadTranslations(currentLocale());
    if (isset($strings[$key])) {
        return $strings[$key];
    }
    $fallback = loadTranslations('th');
    return $fallback[$key] ?? $key;
}

/** Builds the current page's URL with ?lang= swapped, keeping other query params (e.g. ?id=). */
function localeSwitchUrl(string $locale): string
{
    $params = $_GET;
    $params['lang'] = $locale;
    return '?' . http_build_query($params);
}

/**
 * Picks the localized value of a per-language tree field (name/description),
 * falling back to the Thai (default) column when no translation was entered.
 */
function localizedTreeField(array $tree, string $baseField): string
{
    $locale = currentLocale();
    if ($locale !== 'th') {
        $localizedKey = $baseField . '_' . $locale;
        if (!empty($tree[$localizedKey])) {
            return $tree[$localizedKey];
        }
    }
    return $tree[$baseField] ?? '';
}

