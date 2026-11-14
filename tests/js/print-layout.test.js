'use strict';

const test = require('node:test');
const assert = require('node:assert');

const pl = require('../../Common/js/print-layout.js');

const base = {tournament: 'T', session: 'S', layout: 'L', printedAt: 'x', hasChanges: false, stylesheets: [], matsHtml: ''};

test('a mat with no assigned spot is not in use', () => {
    assert.strictEqual(pl.isMatInUse(['001A', '001B', '002A'], ['005A']), false);
    assert.strictEqual(pl.isMatInUse([], ['001A']), false);
});

test('one assigned spot makes the mat in use', () => {
    assert.strictEqual(pl.isMatInUse(['001A', '001B'], ['001B']), true);
});

test('spotStatus distinguishes archer from empty', () => {
    assert.strictEqual(pl.spotStatus('001A', ['001A']), 'archer');
    assert.strictEqual(pl.spotStatus('001C', ['001A']), 'empty');
});

test('header fields are escaped, mats html is not', () => {
    const html = pl.buildPrintDocument(Object.assign({}, base, {
        tournament: 'A & B <test>',
        matsHtml: '<div class="target-mat"></div>'
    }));
    assert.match(html, /A &amp; B &lt;test&gt;/);
    assert.doesNotMatch(html, /A & B <test>/);
    assert.match(html, /<div class="target-mat"><\/div>/);
});

test('unapplied note appears only with changes', () => {
    assert.match(pl.buildPrintDocument(Object.assign({}, base, {hasChanges: true})), /Includes changes not applied yet/);
    assert.doesNotMatch(pl.buildPrintDocument(base), /Includes changes not applied yet/);
});

test('document links stylesheets, avoids splitting mats, and prints on load', () => {
    const html = pl.buildPrintDocument(Object.assign({}, base, {stylesheets: ['http://h/a.css']}));
    assert.match(html, /<link rel="stylesheet" href="http:\/\/h\/a.css">/);
    assert.match(html, /break-inside:\s*avoid/);
    assert.match(html, /size:\s*A4 portrait/);
    assert.match(html, /window\.print\(\)/);
});

function cssRule(html, selector) {
    const css = html.slice(html.indexOf('<style>'), html.indexOf('</style>'));
    const escaped = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const match = new RegExp('(^|\\n)' + escaped + '\\s*\\{([^}]*)\\}').exec(css);
    return match ? match[2] : null;
}

test('mats print four per row, overriding the narrow-screen single column', () => {
    const rule = cssRule(pl.buildPrintDocument(base), '.targets-grid');
    assert.ok(rule, 'expected a .targets-grid print rule');
    assert.match(rule, /grid-template-columns:\s*repeat\(4,\s*minmax\(0,\s*1fr\)\)\s*!important/);
});

test('mat width caps from the screen are lifted in print', () => {
    const rule = cssRule(pl.buildPrintDocument(base), '.target-mat');
    assert.ok(rule, 'expected a .target-mat print rule');
    assert.match(rule, /max-width:\s*none\s*!important/);
});

test('drop zones keep their own absolute positioning', () => {
    const html = pl.buildPrintDocument(base);
    const css = html.slice(html.indexOf('<style>'), html.indexOf('</style>'));
    assert.doesNotMatch(css, /\.target-drop-zone[^{]*\{[^}]*position:\s*relative/);
});

test('face caption sits at the bottom of the face, clear of the position letters', () => {
    const rule = cssRule(pl.buildPrintDocument(base), '.print-face-caption');
    assert.ok(rule, 'expected a .print-face-caption rule');
    assert.match(rule, /position:\s*absolute/);
    assert.match(rule, /bottom:\s*0/);
});

test('describeFilters is empty for the default view', () => {
    assert.strictEqual(pl.describeFilters({divisions: [], classes: [''], targetFrom: '1', targetTo: '99'}), '');
    assert.strictEqual(pl.describeFilters({divisions: [], classes: [], targetFrom: '', targetTo: ''}), '');
});

test('describeFilters names divisions, classes and a narrowed target range', () => {
    assert.strictEqual(
        pl.describeFilters({divisions: ['R', 'C'], classes: ['M'], targetFrom: '3', targetTo: '12'}),
        'Divisions: R, C · Classes: M · Targets 3–12'
    );
    assert.strictEqual(pl.describeFilters({divisions: ['', 'R'], classes: [], targetFrom: '1', targetTo: '99'}), 'Divisions: R');
});

test('a filtered sheet says so in the header, escaped', () => {
    const html = pl.buildPrintDocument(Object.assign({}, base, {filters: 'Divisions: <R>'}));
    assert.match(html, /Filtered: Divisions: &lt;R&gt;/);
    assert.doesNotMatch(pl.buildPrintDocument(base), /Filtered:/);
});

test('mat labels shrink to fit four mats per row', () => {
    const rule = cssRule(pl.buildPrintDocument(base), '.mat-label');
    assert.ok(rule, 'expected a .mat-label print rule');
    assert.match(rule, /font-size:\s*11px/);
});

test('describeFilters treats the session range as unfiltered', () => {
    assert.strictEqual(pl.describeFilters({divisions: [], classes: [], targetFrom: '1', targetTo: '24', sessionFirst: 1, sessionLast: 24}), '');
    assert.strictEqual(pl.describeFilters({divisions: [], classes: [], targetFrom: '1', targetTo: '12', sessionFirst: 1, sessionLast: 24}), 'Targets 1–12');
});

test('autoPrint false omits the print script', () => {
    assert.doesNotMatch(pl.buildPrintDocument(Object.assign({}, base, {autoPrint: false})), /window\.print\(\)/);
    assert.match(pl.buildPrintDocument(base), /window\.print\(\)/);
});

test('qualPrintUrl builds the ManageTargets print link', () => {
    assert.strictEqual(pl.qualPrintUrl('/ianseo/', 3), '/ianseo/Modules/Custom/LaneAssist/ManageTargets/index.php?session=3&print=1');
});

test('finalsPrintState covers unscheduled, waiting and ready', () => {
    assert.deepStrictEqual(pl.finalsPrintState(null), {enabled: false, title: 'Unscheduled matches cannot be printed'});
    assert.deepStrictEqual(pl.finalsPrintState({slot: '', printReady: false, printWaiting: 0}), {enabled: false, title: 'Unscheduled matches cannot be printed'});
    assert.deepStrictEqual(pl.finalsPrintState({slot: '2026-11-14 10:30:00', printReady: true, printWaiting: 0}), {enabled: true, title: 'Print lane layout for this round'});
    assert.deepStrictEqual(pl.finalsPrintState({slot: '2026-11-14 10:30:00', printReady: false, printWaiting: 3}), {enabled: false, title: 'Waiting for 3 matches to finish'});
    assert.deepStrictEqual(pl.finalsPrintState({slot: '2026-11-14 10:30:00', printReady: false, printWaiting: 1}), {enabled: false, title: 'Waiting for 1 match to finish'});
});

test('finalsPrintUrl encodes the slot', () => {
    assert.strictEqual(
        pl.finalsPrintUrl('/ianseo/', '2026-11-14 10:30:00'),
        '/ianseo/Modules/Custom/LaneAssist/LiveView/print-finals.php?slot=2026-11-14%2010%3A30%3A00'
    );
});

test('describeFilters copes with the session range arriving as strings', () => {
    assert.strictEqual(pl.describeFilters({divisions: [], classes: [], targetFrom: '1', targetTo: '25', sessionFirst: '1', sessionCount: '25'}), '');
    assert.strictEqual(pl.describeFilters({divisions: [], classes: [], targetFrom: '1', targetTo: '12', sessionFirst: '1', sessionCount: '25'}), 'Targets 1–12');
});
