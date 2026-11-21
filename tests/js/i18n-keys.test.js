'use strict';
// Every translation key the code uses must exist in Lang/en.php, every English key must
// be used somewhere, and keys must be string literals so this scan can see them.
const test = require('node:test');
const assert = require('node:assert');
const fs = require('fs');
const path = require('path');

const ROOT = path.join(__dirname, '..', '..');
const SKIP_DIRS = new Set(['tests', 'tools', 'Settings', 'docs', 'node_modules', 'vendor', 'Lang']);

// The translator itself defines/forwards these functions with variable keys.
const IMPLEMENTATION = new Set(['Common/i18n.php', path.join('Common', 'js', 'i18n.js')]);

function sourceFiles(dir) {
    let out = [];
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        if (entry.name.startsWith('.')) continue;
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) {
            if (!SKIP_DIRS.has(entry.name)) out = out.concat(sourceFiles(full));
        } else if (/\.(php|js)$/.test(entry.name)) {
            out.push(full);
        }
    }
    return out;
}

function englishKeys() {
    const src = fs.readFileSync(path.join(ROOT, 'Lang', 'en.php'), 'utf8');
    return new Set([...src.matchAll(/^\s*'([a-zA-Z0-9.]+)'\s*=>/gm)].map((m) => m[1]));
}

// la_t / la_h / laT / t / x.t take a key; laPlural / laTn / tn / x.tn take a base key.
const SINGLE = /(?:\bla_[th]|\blaT|(?:^|[^\w$])t)\(\s*(['"])([a-zA-Z0-9.]+)\1/g;
const PLURAL = /(?:\blaPlural|\blaTn|(?:^|[^\w$])tn)\(\s*(['"])([a-zA-Z0-9.]+)\1/g;
const ANY_CALL = /(?:\bla_[th]|\blaT|\blaPlural|\blaTn)\(\s*([^)\s][^,)]*)/g;

function scan() {
    const used = new Map();
    const nonLiteral = [];
    for (const file of sourceFiles(ROOT)) {
        const rel = path.relative(ROOT, file);
        const src = fs.readFileSync(file, 'utf8');
        const add = (key) => { if (!used.has(key)) used.set(key, rel); };
        for (const m of src.matchAll(SINGLE)) if (m[2].includes('.')) add(m[2]);
        for (const m of src.matchAll(PLURAL)) if (m[2].includes('.')) { add(m[2] + '.one'); add(m[2] + '.other'); }
        if (IMPLEMENTATION.has(rel)) continue;
        for (const m of src.matchAll(ANY_CALL)) {
            if (!/^(['"])[a-zA-Z0-9.]+\1$/.test(m[1].trim())) nonLiteral.push(`${rel}: ${m[0]}`);
        }
    }
    return { used, nonLiteral };
}

const { used, nonLiteral } = scan();
const en = englishKeys();

test('every key used in the code exists in Lang/en.php', () => {
    const missing = [...used].filter(([key]) => !en.has(key)).map(([key, file]) => `${key} (${file})`);
    assert.deepStrictEqual(missing, []);
});

test('every key in Lang/en.php is used somewhere', () => {
    const unused = [...en].filter((key) => !used.has(key));
    assert.deepStrictEqual(unused, []);
});

test('translation keys are string literals', () => {
    assert.deepStrictEqual(nonLiteral, []);
});

// A page hands the browser only the catalog prefixes it names in laJsCatalogScript([...]);
// every key the page's LaneAssist scripts look up must fall under one of them.
function pageCoverage() {
    const problems = [];
    for (const file of sourceFiles(ROOT).filter((f) => f.endsWith('.php'))) {
        const src = fs.readFileSync(file, 'utf8');
        const call = src.match(/laJsCatalogScript\(\[([^\]]*)\]\)/);
        if (!call) continue;
        const prefixes = [...call[1].matchAll(/'([^']+)'/g)].map((m) => m[1]);
        const scripts = [...src.matchAll(/Modules\/Custom\/LaneAssist\/([\w\/.-]+\.js)/g)].map((m) => m[1]);
        for (const script of new Set(scripts)) {
            const js = fs.readFileSync(path.join(ROOT, script), 'utf8');
            const keys = [...js.matchAll(SINGLE)].map((m) => m[2])
                .concat([...js.matchAll(PLURAL)].map((m) => m[2] + '.one'))
                .filter((k) => k.includes('.'));
            for (const key of new Set(keys)) {
                if (!prefixes.some((p) => key.startsWith(p))) {
                    problems.push(`${path.relative(ROOT, file)} loads ${script} which uses ${key}, not in [${prefixes.join(', ')}]`);
                }
            }
        }
    }
    return problems;
}

test('each page sends the browser every catalog prefix its scripts use', () => {
    const problems = pageCoverage();
    assert.deepStrictEqual(problems, [], '\n' + problems.join('\n'));
});

// A script that looks text up needs Common/js/i18n.js (and the page's catalog) loaded first.
test('pages load i18n.js and their catalog before any script that uses laT', () => {
    const problems = [];
    for (const file of sourceFiles(ROOT).filter((f) => f.endsWith('.php'))) {
        const src = fs.readFileSync(file, 'utf8');
        const scripts = [...src.matchAll(/Modules\/Custom\/LaneAssist\/([\w\/.-]+\.js)/g)].map((m) => ({ src: m[1], at: m.index }));
        const i18n = scripts.find((s) => s.src === 'Common/js/i18n.js');
        const catalogAt = src.indexOf('laJsCatalogScript(');
        for (const s of scripts) {
            if (s.src === 'Common/js/i18n.js') continue;
            const js = fs.readFileSync(path.join(ROOT, s.src), 'utf8');
            if (!/\blaTn?\(|window\.laT\b/.test(js)) continue;
            if (!i18n || i18n.at > s.at || catalogAt === -1 || catalogAt > s.at) {
                problems.push(`${path.relative(ROOT, file)} loads ${s.src} without i18n.js + laJsCatalogScript() before it`);
            }
        }
    }
    assert.deepStrictEqual(problems, [], '\n' + problems.join('\n'));
});
