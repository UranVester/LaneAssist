<?php
/**
 * LaneAssist translations.
 *
 * Text lives in Lang/<code>.php (named keys, `area.name`). Language follows IANSEO:
 * SelectLanguage(true) — the `true` skips PRINTLANG, which core defines as a side
 * effect (getIdFromCode(), Obj_Rank) and would otherwise switch the page to the
 * tournament's print language. Unknown codes fall back to English.
 *
 * Pure: includable without DB/session/config so logic files and tests can use it.
 * Keys passed to la_t()/laPlural() must be string literals (tests/js/i18n-keys.test.js).
 */

/** @internal shared mutable state (override + catalog cache). */
function &laI18nStateRef(): array {
    static $state = ['override' => null, 'catalogs' => []];
    return $state;
}

/** Force the language (tests, or callers that already know it). null = follow IANSEO. */
function laSetLang(?string $code): void {
    $state = &laI18nStateRef();
    $state['override'] = $code;
}

function laValidLang(string $code): string {
    $code = strtolower(trim($code));
    if (preg_match('/^[a-z-]+$/', $code) && is_file(dirname(__DIR__) . '/Lang/' . $code . '.php')) {
        return $code;
    }
    return 'en';
}

function laLang(): string {
    $state = &laI18nStateRef();
    if ($state['override'] !== null) {
        return laValidLang($state['override']);
    }
    if (function_exists('SelectLanguage')) {
        return laValidLang((string) SelectLanguage(true));
    }
    return 'en';
}

function laCatalog(string $code): array {
    $state = &laI18nStateRef();
    if (!isset($state['catalogs'][$code])) {
        $catalog = [];
        if (preg_match('/^[a-z-]+$/', $code)) {
            $file = dirname(__DIR__) . '/Lang/' . $code . '.php';
            if (is_file($file)) {
                $loaded = require $file;
                $catalog = is_array($loaded) ? $loaded : [];
            }
        }
        $state['catalogs'][$code] = $catalog;
    }
    return $state['catalogs'][$code];
}

/** Test-only: add entries to a language's catalog for this process. */
function laCatalogInject(string $code, array $entries): void {
    $state = &laI18nStateRef();
    $state['catalogs'][$code] = $entries + laCatalog($code);
}

function laFill(string $text, array $params): string {
    if (!$params) {
        return $text;
    }
    return preg_replace_callback('/\{([a-zA-Z0-9_]+)\}/', function ($m) use ($params) {
        return array_key_exists($m[1], $params) ? (string) $params[$m[1]] : $m[0];
    }, $text);
}

/** Text for $key in the user's language, else English, else the key itself. */
function la_t(string $key, array $params = []): string {
    $active = laCatalog(laLang());
    if (isset($active[$key])) {
        return laFill($active[$key], $params);
    }
    $en = laCatalog('en');
    return laFill($en[$key] ?? $key, $params);
}

/** `$baseKey.one` when $count is 1, else `$baseKey.other`; {count} is filled in. */
function laPlural(string $baseKey, int $count, array $params = []): string {
    return la_t($baseKey . ($count === 1 ? '.one' : '.other'), $params + ['count' => $count]);
}

/** English merged with the active language, only keys starting with one of $prefixes. */
function laCatalogFor(array $prefixes): array {
    $merged = laCatalog(laLang()) + laCatalog('en');
    $out = [];
    foreach ($merged as $key => $text) {
        foreach ($prefixes as $prefix) {
            if (strncmp($key, $prefix, strlen($prefix)) === 0) {
                $out[$key] = $text;
                break;
            }
        }
    }
    ksort($out);
    return $out;
}

/** `<script>var LA_I18N = {...};</script>` for the page's JS (read by Common/js/i18n.js). */
function laJsCatalogScript(array $prefixes): string {
    $json = json_encode(laCatalogFor($prefixes) ?: new stdClass(),
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
    return '<script>var LA_I18N = ' . $json . ';</script>';
}
