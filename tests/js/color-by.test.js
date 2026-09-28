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

test('getColorPalette is deterministic for the same key', () => {
    const a = colorBy.getColorPalette('country|KLUB1');
    const b = colorBy.getColorPalette('country|KLUB1');
    assert.deepStrictEqual(a, b);
});

test('getColorPalette differs for different keys (no collision for these two)', () => {
    const a = colorBy.getColorPalette('country|KLUB1');
    const b = colorBy.getColorPalette('country|KLUB2');
    assert.notStrictEqual(a.color, b.color);
});

test('getColorPalette produces a hue-based color and a lighter background', () => {
    const palette = colorBy.getColorPalette('class|R');
    assert.match(palette.color, /^hsl\(\d+, 60%, 40%\)$/);
    assert.match(palette.bg, /^hsl\(\d+, 85%, 94%\)$/);
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
