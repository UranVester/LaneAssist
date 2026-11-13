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
