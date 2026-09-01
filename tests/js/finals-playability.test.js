'use strict';

const test = require('node:test');
const assert = require('node:assert');

const playability = require('../../Common/js/finals-playability.js');

/** Two bracket rows of one match. seed/seed2 map to Grids GrPosition/GrPosition2. */
function pair(overrides) {
    const o = overrides || {};
    const mk = function(matchNo, seed) {
        return {
            matchNo: matchNo,
            hasParticipant: o.hasParticipant === undefined ? 0 : o.hasParticipant,
            projectedParticipants: o.projectedParticipants,
            gridPosition: seed,
            gridPosition2: null
        };
    };
    const seeds = o.seeds === undefined ? [1, 2] : o.seeds;
    return [mk(0, seeds[0]), mk(1, seeds[1])];
}

test('a seated pair is playable regardless of projection', () => {
    const rows = pair({hasParticipant: 1, projectedParticipants: 0, seeds: [null, null]});
    assert.strictEqual(playability.isPairPlayable(rows), true);
});

test('zero projected finalists is NOT playable (regression: arhS26 phantom team finals)', () => {
    // 14 team events with 1/8 brackets configured but no Teams rows: getCurrent()
    // still synthesizes every bracket row, and each carries projected 0. Before
    // the fix, `projectedParticipants === 0` counted as playable and all 448 rows
    // showed up in the unassigned pool as work that needed scheduling.
    const rows = pair({projectedParticipants: 0, seeds: [1, 2]});
    assert.strictEqual(playability.isPairPlayable(rows), false);
});

test('a single projected finalist is not playable', () => {
    assert.strictEqual(playability.isPairPlayable(pair({projectedParticipants: 1})), false);
});

test('an unknown projection stays playable (planning escape hatch)', () => {
    assert.strictEqual(playability.maxProjectedParticipants(pair({projectedParticipants: undefined})), null);
    assert.strictEqual(playability.isPairPlayable(pair({projectedParticipants: undefined})), true);
    assert.strictEqual(playability.isPairPlayable(pair({projectedParticipants: null})), true);
    assert.strictEqual(playability.isPairPlayable(pair({projectedParticipants: 'abc'})), true);
});

test('a known zero beats an unknown on the sibling row', () => {
    const rows = pair({projectedParticipants: 0});
    delete rows[1].projectedParticipants;
    assert.strictEqual(playability.maxProjectedParticipants(rows), 0);
    assert.strictEqual(playability.isPairPlayable(rows), false);
});

test('seeds inside the projected field are playable, seeds beyond it are not', () => {
    // arhS26 40B: 5 finals-marked entrants, EvNumQualified 16 -> projected 5.
    assert.strictEqual(playability.isPairPlayable(pair({projectedParticipants: 5, seeds: [4, 5]})), true);
    assert.strictEqual(playability.isPairPlayable(pair({projectedParticipants: 5, seeds: [6, 11]})), false);
    // Only one seed beyond the field is still enough to rule the match out.
    assert.strictEqual(playability.isPairPlayable(pair({projectedParticipants: 5, seeds: [3, 14]})), false);
});

test('a pair that cannot supply two seeds is not playable on projection alone', () => {
    assert.strictEqual(playability.isPairPlayable(pair({projectedParticipants: 8, seeds: [1, null]})), false);
    assert.strictEqual(playability.isPairPlayable(pair({projectedParticipants: 8, seeds: [3, 3]})), false);
});

test('gridPosition2 fills in only when gridPosition cannot supply two seeds', () => {
    const rows = pair({projectedParticipants: 8, seeds: [2, null]});
    rows[1].gridPosition2 = 7;
    assert.deepStrictEqual(playability.collectSeedPositions(rows), [2, 7]);
    assert.strictEqual(playability.isPairPlayable(rows), true);

    const bothPrimary = pair({projectedParticipants: 8, seeds: [1, 2]});
    bothPrimary[0].gridPosition2 = 99;
    assert.deepStrictEqual(playability.collectSeedPositions(bothPrimary), [1, 2]);
});

test('non-positive seeds are ignored', () => {
    const rows = pair({projectedParticipants: 4, seeds: [0, -3]});
    assert.deepStrictEqual(playability.collectSeedPositions(rows), []);
    assert.strictEqual(playability.isPairPlayable(rows), false);
});

test('countParticipants needs two seated rows, not one', () => {
    const rows = pair({projectedParticipants: 0, seeds: [1, 2]});
    rows[0].hasParticipant = 1;
    assert.strictEqual(playability.countParticipants(rows), 1);
    assert.strictEqual(playability.isPairPlayable(rows), false);
});

test('empty and malformed input does not throw', () => {
    assert.strictEqual(playability.isPairPlayable([]), true);
    assert.strictEqual(playability.isPairPlayable(null), true);
    assert.deepStrictEqual(playability.collectSeedPositions([null, undefined]), []);
});

test('the rule is identical for individual and team events', () => {
    // The module never reads teamEvent, so an individual event with nobody entered
    // is hidden exactly like an unentered team event (real cases: arhS26 team
    // brackets, CloneTes individual brackets). This pins that symmetry.
    const scenarios = [
        {projectedParticipants: 0, seeds: [1, 2], expected: false},
        {projectedParticipants: 1, seeds: [1, 2], expected: false},
        {projectedParticipants: 4, seeds: [1, 4], expected: true},
        {projectedParticipants: 4, seeds: [5, 12], expected: false},
        {projectedParticipants: undefined, seeds: [1, 2], expected: true},
    ];

    scenarios.forEach(function(s) {
        [0, 1].forEach(function(teamEvent) {
            const rows = pair(s).map(function(row) {
                return Object.assign({teamEvent: teamEvent}, row);
            });
            assert.strictEqual(playability.isPairPlayable(rows), s.expected,
                'projected ' + s.projectedParticipants + ' seeds ' + JSON.stringify(s.seeds) +
                ' teamEvent ' + teamEvent);
        });
    });
});
