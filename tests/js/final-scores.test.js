'use strict';

const test = require('node:test');
const assert = require('node:assert');

const finalScores = require('../../Common/js/final-scores.js');

test('set-system match shows one S column per set and the set score', () => {
    const view = finalScores.build({matchMode: 1, totalEnds: 5}, {setPoints: [28, 27, null, null, null], setScore: 2, score: 55});
    assert.deepStrictEqual(view.columns, [
        {label: 'S1', value: 28}, {label: 'S2', value: 27}, {label: 'S3', value: null},
        {label: 'S4', value: null}, {label: 'S5', value: null}
    ]);
    assert.deepStrictEqual(view.summary, {label: 'Sets', value: 2});
});

test('cumulative (compound) match shows every end and the running total', () => {
    const view = finalScores.build({matchMode: 0, totalEnds: 5}, {setPoints: [30, 29, 28, null, null], setScore: 0, score: 87});
    assert.deepStrictEqual(view.columns.map((c) => c.label), ['E1', 'E2', 'E3', 'E4', 'E5']);
    assert.deepStrictEqual(view.columns.map((c) => c.value), [30, 29, 28, null, null]);
    assert.deepStrictEqual(view.summary, {label: 'Total', value: 87});
});

test('column count follows the event, not a fixed five (team matches have 4 ends)', () => {
    const view = finalScores.build({matchMode: 0, totalEnds: 4}, {setPoints: [58, 57, null, null], score: 115});
    assert.strictEqual(view.columns.length, 4);
    assert.strictEqual(finalScores.build({matchMode: 1, totalEnds: 4}, {setPoints: [55, null, null, null]}).columns.length, 4);
});

test('an empty side (awaiting opponent) still gets blank columns', () => {
    const view = finalScores.build({matchMode: 0, totalEnds: 5}, {score: 0});
    assert.deepStrictEqual(view.columns.map((c) => c.value), [null, null, null, null, null]);
    assert.deepStrictEqual(view.summary, {label: 'Total', value: 0});
});

test('render escapes values and marks blank columns with a dash', () => {
    const html = finalScores.render({matchMode: 0, totalEnds: 2}, {setPoints: [30, null], score: 30});
    assert.match(html, /<small>E1<\/small><b>30<\/b>/);
    assert.match(html, /<small>E2<\/small><b>-<\/b>/);
    assert.match(html, /<small>Total<\/small><b>30<\/b>/);
    assert.match(html, /style="--final-columns: 2"/);
});

test('score column labels come from the translator and are escaped in the html', () => {
    const { createTranslator } = require('../../Common/js/i18n.js');
    const dk = createTranslator({ 'live.setShort': 'S', 'live.endShort': 'R', 'live.sets': 'Sæt', 'live.total': 'I <alt>' });
    const cumulative = finalScores.build({ matchMode: 0, totalEnds: 2 }, { setPoints: [29], score: 29 }, dk);
    assert.deepStrictEqual(cumulative.columns.map((c) => c.label), ['R1', 'R2']);
    assert.strictEqual(cumulative.summary.label, 'I <alt>');
    assert.strictEqual(finalScores.build({ matchMode: 1, totalEnds: 1 }, { setPoints: [2], setScore: 2 }, dk).summary.label, 'Sæt');
    assert.match(finalScores.render({ matchMode: 0, totalEnds: 1 }, { setPoints: [1], score: 1 }, dk), /I &lt;alt&gt;/);
});
