/**
 * LaneAssist translations for the browser.
 *
 * Pages emit `var LA_I18N = {...}` (laJsCatalogScript() in Common/i18n.php) before
 * loading this file; it then exposes window.laT(key, params) and
 * window.laTn(baseKey, count, params). Same rules as la_t()/laPlural() in PHP:
 * missing key → the key itself; `{name}` placeholders filled once, never re-scanned;
 * `.one` only when count is 1. Keys must be string literals (tests/js/i18n-keys.test.js).
 */
(function(root, factory) {
    'use strict';
    const api = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    } else {
        const translator = api.createTranslator(root.LA_I18N || {});
        root.laT = translator.t;
        root.laTn = translator.tn;
    }
}(typeof self !== 'undefined' ? self : this, function() {
    'use strict';

    function fill(text, params) {
        if (!params) {
            return text;
        }
        return text.replace(/\{([a-zA-Z0-9_]+)\}/g, function(match, name) {
            return Object.prototype.hasOwnProperty.call(params, name) ? String(params[name]) : match;
        });
    }

    function createTranslator(catalog) {
        const texts = catalog || {};
        function t(key, params) {
            const text = Object.prototype.hasOwnProperty.call(texts, key) ? texts[key] : key;
            return fill(String(text), params);
        }
        function tn(baseKey, count, params) {
            const all = Object.assign({ count: count }, params || {});
            return t(baseKey + (count === 1 ? '.one' : '.other'), all);
        }
        return { t: t, tn: tn };
    }

    /**
     * Node only (tests): a translator over Lang/en.php, so pure modules that default
     * to English outside the browser read the same texts the PHP side does.
     * Entries are one per line: 'key' => 'text', (PHP single-quoted string).
     */
    function englishTranslator() {
        const fs = require('fs');
        const path = require('path');
        const src = fs.readFileSync(path.join(__dirname, '..', '..', 'Lang', 'en.php'), 'utf8');
        const catalog = {};
        const entry = /^\s*'([a-zA-Z0-9.]+)'\s*=>\s*'((?:[^'\\]|\\.)*)',?\s*$/gm;
        let m;
        while ((m = entry.exec(src)) !== null) {
            catalog[m[1]] = m[2].replace(/\\(['\\])/g, '$1');
        }
        return createTranslator(catalog);
    }

    return { createTranslator: createTranslator, englishTranslator: englishTranslator };
}));
