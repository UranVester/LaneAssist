'use strict';

const test = require('node:test');
const assert = require('node:assert');

const updateStatus = require('../../Common/js/update-status.js');

test('accepts the boolean verification result returned by the updater API', () => {
    assert.strictEqual(updateStatus.isSignatureVerified({ok: true}), true);
});

test('accepts legacy numeric verification results', () => {
    assert.strictEqual(updateStatus.isSignatureVerified({ok: 1}), true);
    assert.strictEqual(updateStatus.isSignatureVerified({ok: '1'}), true);
});

test('rejects false and missing verification results', () => {
    assert.strictEqual(updateStatus.isSignatureVerified({ok: false}), false);
    assert.strictEqual(updateStatus.isSignatureVerified({ok: 0}), false);
    assert.strictEqual(updateStatus.isSignatureVerified({ok: '0'}), false);
    assert.strictEqual(updateStatus.isSignatureVerified(null), false);
});