'use strict';

const test = require('node:test');
const assert = require('node:assert');

const { createRequestGate } = require('../../ChangeTargetFaces/js/request-gate.js');

test('Set all sends the filters of the rows on screen, not the inputs as typed now', () => {
    const gate = createRequestGate();
    const seq = gate.startLoad({ div: 'R' });
    assert.strictEqual(gate.finishLoad(seq), true);
    // A later load is in flight for a filter the user hasn't seen yet.
    gate.startLoad({ div: 'C' });
    assert.strictEqual(gate.setAllRequest(5), null);
});

test('Set all is refused while a filter change is waiting for its debounce', () => {
    const gate = createRequestGate();
    gate.finishLoad(gate.startLoad({ div: 'R' }));
    gate.markDirty();
    assert.strictEqual(gate.setAllRequest(5), null);
});

test('Set all uses the last accepted load', () => {
    const gate = createRequestGate();
    gate.finishLoad(gate.startLoad({ div: 'R', face: '' }));
    assert.deepStrictEqual(gate.setAllRequest(5), { filters: { div: 'R', face: '' }, expectedCount: 5 });
});

test('Set all is refused before anything has loaded', () => {
    assert.strictEqual(createRequestGate().setAllRequest(0), null);
});

test('an older load response is dropped once a newer load started', () => {
    const gate = createRequestGate();
    const first = gate.startLoad({ div: 'R' });
    const second = gate.startLoad({ div: 'C' });
    assert.strictEqual(gate.finishLoad(first), false);
    assert.strictEqual(gate.finishLoad(second), true);
    assert.deepStrictEqual(gate.setAllRequest(1).filters, { div: 'C' });
});

test('a load issued before a write is dropped, so it cannot paint the old face back', () => {
    const gate = createRequestGate();
    gate.finishLoad(gate.startLoad({}));
    const inFlight = gate.startLoad({});
    gate.noteWrite();
    assert.strictEqual(gate.finishLoad(inFlight), false);
    assert.strictEqual(gate.setAllRequest(3), null);
});

test('a failed load clears pending only for the latest load', () => {
    const gate = createRequestGate();
    gate.finishLoad(gate.startLoad({ div: 'R' }));
    const seq = gate.startLoad({ div: 'C' });
    gate.failLoad(seq);
    // Still not safe: the rows on screen are for R but the inputs say C.
    assert.strictEqual(gate.setAllRequest(1), null);
});
