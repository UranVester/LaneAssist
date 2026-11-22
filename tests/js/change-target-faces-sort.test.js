'use strict';

const test = require('node:test');
const assert = require('node:assert');

const { sortArchers, formatTarget, hasMultipleSessions, parseSort } = require('../../ChangeTargetFaces/js/sort.js');

let nextId = 1;
function archer(session, target, letter, lastName, firstName) {
    return { id: nextId++, session, target, letter, lastName: lastName || 'X', firstName: firstName || 'Y' };
}
const spots = (list) => list.map((a) => a.target ? a.session + '-' + a.target + a.letter : '-');
const names = (list) => list.map((a) => a.lastName);

test('target sorts by session, then target, then letter', () => {
    const list = [archer(2, 1, 'A'), archer(1, 12, 'B'), archer(1, 12, 'A'), archer(1, 3, 'A')];
    assert.deepStrictEqual(spots(sortArchers(list, 'target', 'asc')), ['1-3A', '1-12A', '1-12B', '2-1A']);
    assert.deepStrictEqual(spots(sortArchers(list, 'target', 'desc')), ['2-1A', '1-12B', '1-12A', '1-3A']);
});

test('unassigned archers go last ascending and descending', () => {
    // Session 0 or target 0 both mean "no target yet".
    const list = [archer(0, 0, ''), archer(1, 5, 'A'), archer(1, 0, ''), archer(1, 2, 'A')];
    assert.deepStrictEqual(spots(sortArchers(list, 'target', 'asc')), ['1-2A', '1-5A', '-', '-']);
    assert.deepStrictEqual(spots(sortArchers(list, 'target', 'desc')), ['1-5A', '1-2A', '-', '-']);
});

test('target ties (and unassigned) are broken by last name', () => {
    const list = [archer(0, 0, '', 'Berg'), archer(0, 0, '', 'Andersen'), archer(1, 4, 'A', 'Holm'), archer(1, 4, 'A', 'Dahl')];
    assert.deepStrictEqual(names(sortArchers(list, 'target', 'asc')), ['Dahl', 'Holm', 'Andersen', 'Berg']);
});

test('archer sorts by last name, first name, id; desc reverses', () => {
    const a = archer(1, 1, 'A', 'Berg', 'Ole');
    const b = archer(1, 2, 'A', 'Berg', 'Anne');
    const c = archer(1, 3, 'A', 'Andersen', 'Zoe');
    const d = archer(1, 4, 'A', 'Berg', 'Anne'); // same name as b, later id
    assert.deepStrictEqual(sortArchers([a, b, c, d], 'archer', 'asc').map((x) => x.id), [c.id, b.id, d.id, a.id]);
    assert.deepStrictEqual(sortArchers([a, b, c, d], 'archer', 'desc').map((x) => x.id), [a.id, d.id, b.id, c.id]);
});

test('last names use Danish alphabet order', () => {
    const list = ['Øster', 'Aagaard', 'Zahle', 'Æbelø', 'andersen'].map((n) => archer(1, 1, 'A', n));
    assert.deepStrictEqual(names(sortArchers(list, 'archer', 'asc')), ['andersen', 'Zahle', 'Æbelø', 'Øster', 'Aagaard']);
});

test('sortArchers does not mutate its input', () => {
    const list = [archer(1, 2, 'A'), archer(1, 1, 'A')];
    const before = list.slice();
    sortArchers(list, 'target', 'asc');
    assert.deepStrictEqual(list, before);
});

test('formatTarget shows mat and letter, with session only when there are several', () => {
    const a = archer(1, 12, 'A');
    assert.strictEqual(formatTarget(a, false), '12A');
    assert.strictEqual(formatTarget(a, true), '1-12A');
    assert.strictEqual(formatTarget(archer(0, 0, ''), true), '');
    assert.strictEqual(formatTarget(archer(2, 0, ''), true), '');
});

test('hasMultipleSessions ignores unassigned archers', () => {
    assert.strictEqual(hasMultipleSessions([archer(1, 1, 'A'), archer(1, 2, 'A'), archer(0, 0, '')]), false);
    assert.strictEqual(hasMultipleSessions([archer(1, 1, 'A'), archer(2, 1, 'A')]), true);
    assert.strictEqual(hasMultipleSessions([]), false);
});

test('parseSort falls back to archer ascending on anything unknown', () => {
    const def = { key: 'archer', dir: 'asc' };
    assert.deepStrictEqual(parseSort(null), def);
    assert.deepStrictEqual(parseSort('garbage'), def);
    assert.deepStrictEqual(parseSort('{"key":"x"}'), def);
    assert.deepStrictEqual(parseSort('{"key":"target","dir":"sideways"}'), def);
    assert.deepStrictEqual(parseSort('null'), def);
    assert.deepStrictEqual(parseSort('{"key":"target","dir":"desc"}'), { key: 'target', dir: 'desc' });
});
