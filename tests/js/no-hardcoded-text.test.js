'use strict';
// Translated files must not show hard-coded English. Each page task adds its files to
// CONVERTED. English that must stay (real-failure API messages, data field names, CSS
// font names, …) goes in hardcoded-text-allowlist.json — not into looser regexes.
const test = require('node:test');
const assert = require('node:assert');
const fs = require('fs');
const path = require('path');

const ROOT = path.join(__dirname, '..', '..');
const CONVERTED = [
];
const ALLOW = JSON.parse(fs.readFileSync(path.join(__dirname, 'hardcoded-text-allowlist.json'), 'utf8'));

function stripComments(src) {
    return src
        .replace(/\/\*[\s\S]*?\*\//g, (m) => m.replace(/[^\n]/g, ' '))
        .replace(/(^|[^:'"\\\w])\/\/[^\n]*/g, '$1')
        .replace(/<!--[\s\S]*?-->/g, (m) => m.replace(/[^\n]/g, ' '));
}

// 'Some text', "Some text", `Some text` starting with a capitalised word.
const LITERAL = /(['"`])([A-Z][a-z]+(?:[ ,.!?:…][^'"`\n]*)?)\1/g;
// >Some text< in HTML (PHP templates or HTML strings built in JS).
const TEXT_NODE = />\s*([A-Z][a-z]{2,}[^<]*?)\s*</g;
// Hits directly inside these calls are a key or IANSEO's own get_text() word.
const EXEMPT_CALL = /(?:get_text|la_t|laT|laPlural|laTn|define)\(\s*$/;

function hits(rel) {
    const src = stripComments(fs.readFileSync(path.join(ROOT, rel), 'utf8'));
    const allowed = new Set(ALLOW[rel] || []);
    const found = [];
    const check = (text, index) => {
        if (allowed.has(text)) return;
        if (EXEMPT_CALL.test(src.slice(Math.max(0, index - 20), index))) return;
        const line = src.slice(0, index).split('\n').length;
        found.push(`${rel}:${line} ${JSON.stringify(text)}`);
    };
    for (const m of src.matchAll(LITERAL)) check(m[2], m.index);
    for (const m of src.matchAll(TEXT_NODE)) {
        check(m[1].trim(), m.index);
    }
    return found;
}

test('allowlist only names converted files', () => {
    assert.deepStrictEqual(Object.keys(ALLOW).filter((f) => !CONVERTED.includes(f)), []);
});

for (const rel of CONVERTED) {
    test(`${rel} has no hard-coded English`, () => {
        assert.deepStrictEqual(hits(rel), []);
    });
}
