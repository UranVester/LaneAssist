'use strict';

const test = require('node:test');
const assert = require('node:assert');

const statusLogic = require('../../Common/js/status-logic.js');
const playability = require('../../Common/js/finals-playability.js');

/** Two bracket rows of one match, mirroring buildFinalsRows()'s shape. */
function pairRows(overrides) {
    const o = overrides || {};
    const base = {
        teamEvent: 0, event: 'TIC', group: 'R', phase: 8,
        hasParticipant: 0, projectedParticipants: o.projectedParticipants,
        gridPosition: null, gridPosition2: null, target: '',
    };
    const seeds = o.seeds === undefined ? [1, 2] : o.seeds;
    const targets = o.targets === undefined ? ['', ''] : o.targets;
    return [0, 1].map(function(i) {
        return Object.assign({}, base, {
            matchNo: i, gridPosition: seeds[i], target: targets[i],
        });
    });
}

test('a phantom (unscheduled) unplayable pair produces no warning', () => {
    // 0 projected finalists AND no row carries a real target: this is a
    // phantom bracket, not scheduled work -- same hidden-by-default rule
    // ManageFinals applies, per the module's CLAUDE.md.
    const rows = pairRows({projectedParticipants: 0, seeds: [1, 2], targets: ['', '']});
    assert.strictEqual(playability.isPairPlayable(rows), false);
    const items = statusLogic.computeUnplayableFinalsItems(rows, playability, '/root/');
    assert.deepStrictEqual(items, []);
});

test('an unplayable pair that is actually scheduled (has a real target) is flagged', () => {
    const rows = pairRows({projectedParticipants: 0, seeds: [1, 2], targets: ['12', '']});
    assert.strictEqual(playability.isPairPlayable(rows), false);
    const items = statusLogic.computeUnplayableFinalsItems(rows, playability, '/root/');
    assert.strictEqual(items.length, 1);
    assert.strictEqual(items[0].severity, 'warning');
    assert.strictEqual(items[0].title, 'Unplayable scheduled finals');
    assert.strictEqual(items[0].link, null);
    assert.deepStrictEqual(items[0].rows, [
        {text: 'TIC', link: '/root/Modules/Custom/LaneAssist/ManageFinals/index.php'},
    ]);
});

test('multiple unplayable scheduled pairs in the same event collapse into one row', () => {
    const rowsA = pairRows({projectedParticipants: 0, seeds: [1, 2], targets: ['12', '']});
    const rowsB = pairRows({projectedParticipants: 0, seeds: [3, 4], targets: ['13', '']})
        .map((r) => Object.assign({}, r, {matchNo: r.matchNo + 2}));

    const items = statusLogic.computeUnplayableFinalsItems(rowsA.concat(rowsB), playability, '/root/');
    assert.strictEqual(items.length, 1);
    assert.deepStrictEqual(items[0].rows, [
        {text: 'TIC', link: '/root/Modules/Custom/LaneAssist/ManageFinals/index.php'},
    ]);
});

test('a playable pair never produces a warning, scheduled or not', () => {
    const rows = pairRows({projectedParticipants: 4, seeds: [1, 2], targets: ['12', '13']});
    assert.strictEqual(playability.isPairPlayable(rows), true);
    assert.deepStrictEqual(statusLogic.computeUnplayableFinalsItems(rows, playability, '/root/'), []);
});

test('an ordinary unscheduled bye (single seed inside an unknown projection) is not flagged', () => {
    // Unknown projection (undefined) keeps isPairPlayable() true per the
    // planning escape hatch, so this is just here to pin that an ordinary,
    // not-yet-scheduled pair never reaches the "unplayable" branch at all.
    const rows = pairRows({projectedParticipants: undefined, seeds: [1, null], targets: ['', '']});
    assert.strictEqual(playability.isPairPlayable(rows), true);
    assert.deepStrictEqual(statusLogic.computeUnplayableFinalsItems(rows, playability, '/root/'), []);
});

test('parseTargetNumber mirrors ManageFinals/js/app.js\'s helper', () => {
    assert.strictEqual(statusLogic.parseTargetNumber('12'), 12);
    assert.strictEqual(statusLogic.parseTargetNumber(' 7 '), 7);
    assert.strictEqual(statusLogic.parseTargetNumber(''), null);
    assert.strictEqual(statusLogic.parseTargetNumber(null), null);
    assert.strictEqual(statusLogic.parseTargetNumber(undefined), null);
    assert.strictEqual(statusLogic.parseTargetNumber('abc'), null);
});
