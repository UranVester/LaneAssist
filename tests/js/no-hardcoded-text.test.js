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
    'menu.php',
    'ManageTournament/index.php',
    'ManageTournament/js/app.js',
    'ManageTournament/api.php',
    'Common/status-logic.php',
    'Common/js/status-logic.js',
    'Common/js/tournament-settings.js',
    'Common/disclaimer.php',
];
const ALLOW = JSON.parse(fs.readFileSync(path.join(__dirname, 'hardcoded-text-allowlist.json'), 'utf8'));

function stripComments(src) {
    return src
        .replace(/\/\*[\s\S]*?\*\//g, (m) => m.replace(/[^\n]/g, ' '))
        .replace(/(^|[^:'"\\\w])\/\/[^\n]*/g, '$1')
        .replace(/<!--[\s\S]*?-->/g, (m) => m.replace(/[^\n]/g, ' '));
}

// 'Some text', "Some text", `Some text` starting with a capitalised word.
// A string may contain the other quote kinds (e.g. 'Text (<a href="…">').
const LITERAL = /'([A-Z][a-z]+(?:[ ,.!?:…][^'\n]*)?)'|"([A-Z][a-z]+(?:[ ,.!?:…][^"\n]*)?)"|`([A-Z][a-z]+(?:[ ,.!?:…][^`\n]*)?)`/g;
// >Some text< in HTML (PHP templates or HTML strings built in JS).
// Also "No layout", "(default)", "60cm ABC (3 archers)".
// One line only, so PHP comparisons (`$a > 0 ... <`) don't read as text.
const TEXT_NODE = />[ \t]*([A-Z][a-z]{2,}[^<\n]*?|[A-Z][a-z]?[ ,.!?:…][^<\n]*?[a-z]{2,}[^<\n]*?|[(0-9][^<>\n]*?[a-z]{4,}[^<\n]*?)[ \t]*</g;
// Hits directly inside these calls are a key or IANSEO's own get_text() word.
const EXEMPT_CALL = /(?:get_text|la_t|la_h|laT|laPlural|laTn|define)\(\s*$/;

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
    for (const m of src.matchAll(LITERAL)) check(m[1] ?? m[2] ?? m[3], m.index);
    for (const m of src.matchAll(TEXT_NODE)) {
        if (src[m.index - 1] === '?' || src[m.index - 1] === '-' || src[m.index - 1] === '=') continue; // `?>`, `->`, `=>`
        check(m[1].trim(), m.index);
    }
    return found;
}

test('allowlist only names converted files', () => {
    assert.deepStrictEqual(Object.keys(ALLOW).filter((f) => !CONVERTED.includes(f)), []);
});

for (const rel of CONVERTED) {
    test(`${rel} has no hard-coded English`, () => {
        const found = hits(rel);
        assert.deepStrictEqual(found, [], '\n' + found.join('\n'));
    });
}
