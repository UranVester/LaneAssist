'use strict';

const test = require('node:test');
const assert = require('node:assert');

const colorBy = require('../../Common/js/color-by.js');

test('hashString is deterministic for the same input', () => {
    assert.strictEqual(colorBy.hashString('KLUB1'), colorBy.hashString('KLUB1'));
});

test('hashString distinguishes different inputs', () => {
    assert.notStrictEqual(colorBy.hashString('KLUB1'), colorBy.hashString('KLUB2'));
});

test('hashString never returns a negative number', () => {
    assert.ok(colorBy.hashString('anything') >= 0);
    assert.ok(colorBy.hashString('') >= 0);
});

test('PALETTE is the 8 validated colors in order', () => {
    assert.deepStrictEqual(colorBy.PALETTE.map(p => p.color),
        ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#6250d6', '#e34948']);
    assert.strictEqual(colorBy.PALETTE[0].bg, '#e1ecf9');
    assert.strictEqual(colorBy.PALETTE[7].bg, '#fbe6e5');
});

test('paletteAt repeats after 8', () => {
    assert.deepStrictEqual(colorBy.paletteAt(8), colorBy.PALETTE[0]);
    assert.deepStrictEqual(colorBy.paletteAt(13), colorBy.PALETTE[5]);
});

test('colorFor follows payload order, not key order', () => {
    const idx = colorBy.createColorIndex({ country: ['ZZZ', 'AAA'] });
    assert.strictEqual(idx.colorFor('country', 'ZZZ').color, '#2a78d6');
    assert.strictEqual(idx.colorFor('country', 'AAA').color, '#eb6834');
});

test('numeric-looking keys keep payload order and stay distinct', () => {
    const idx = colorBy.createColorIndex({ class: ['10', '2', '1', '001'] });
    assert.deepStrictEqual(['10', '2', '1', '001'].map(k => idx.positionOf('class', k)), [0, 1, 2, 3]);
});

test('bow derives from division order, first occurrence wins', () => {
    const idx = colorBy.createColorIndex({ division: [
        { id: 'R', description: 'Recurve' },
        { id: 'RJ', description: 'Recurve Junior' },
        { id: 'C', description: 'Compound' }
    ] });
    assert.strictEqual(idx.positionOf('bow', 'Recurve'), 0);
    assert.strictEqual(idx.positionOf('bow', 'Compound'), 1);
});

test('phase uses the fixed phase order, standard phases first', () => {
    const idx = colorBy.createColorIndex({});
    assert.deepStrictEqual(['64', '32', '16', '8', '4', '2', '1', '0'].map(k => idx.positionOf('phase', k)),
        [0, 1, 2, 3, 4, 5, 6, 7]);
    assert.strictEqual(idx.positionOf('phase', '48'), 8);
});

test('the eight standard phases never share a color', () => {
    const idx = colorBy.createColorIndex({});
    const colors = ['64', '32', '16', '8', '4', '2', '1', '0'].map(k => idx.colorFor('phase', k).color);
    assert.strictEqual(new Set(colors).size, 8);
});

test('unknown keys get the next index after known ones, stably', () => {
    const idx = colorBy.createColorIndex({ country: ['A', 'B'] });
    assert.strictEqual(idx.positionOf('country', 'NEW1'), 2);
    assert.strictEqual(idx.positionOf('country', 'NEW2'), 3);
    assert.strictEqual(idx.positionOf('country', 'NEW1'), 2);
});

test('empty key gives no color', () => {
    const idx = colorBy.createColorIndex({ country: ['A'] });
    assert.strictEqual(idx.colorFor('country', ''), null);
    assert.strictEqual(idx.positionOf('country', null), null);
});

test('missing payload does not throw', () => {
    const idx = colorBy.createColorIndex(undefined);
    assert.strictEqual(idx.positionOf('country', 'X'), 0);
});

test('keyForMulti returns the single distinct value or null', () => {
    assert.strictEqual(colorBy.keyForMulti(['R', 'R', '']), 'R');
    assert.strictEqual(colorBy.keyForMulti(['M', 'W']), null);
    assert.strictEqual(colorBy.keyForMulti([]), null);
});

test('extractBowType finds a known bow token case-insensitively', () => {
    assert.strictEqual(colorBy.extractBowType('Recurve Men', 'R'), 'Recurve');
    assert.strictEqual(colorBy.extractBowType('COMPOUND WOMEN', 'C'), 'Compound');
});

test('extractBowType falls back to the first word when no token matches', () => {
    assert.strictEqual(colorBy.extractBowType('Historical Bow', 'HB'), 'Historical');
});

test('extractBowType falls back to the division id when there is no description', () => {
    assert.strictEqual(colorBy.extractBowType('', 'R'), 'R');
    assert.strictEqual(colorBy.extractBowType(null, 'R'), 'R');
});

test('extractBowType falls back to Unknown Bow when nothing is available', () => {
    assert.strictEqual(colorBy.extractBowType('', ''), 'Unknown Bow');
    assert.strictEqual(colorBy.extractBowType(null, null), 'Unknown Bow');
});
