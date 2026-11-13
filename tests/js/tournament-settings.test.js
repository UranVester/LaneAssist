'use strict';

const test = require('node:test');
const assert = require('node:assert');

const tournamentSettings = require('../../Common/js/tournament-settings.js');

test('describeFinalsLength shows the user default when the tournament has no override', () => {
    assert.deepStrictEqual(
        tournamentSettings.describeFinalsLength({ admin: { defaultFinalsLength: 30 }, user: { defaultFinalsLength: 25 }, tournament: { defaultFinalsLength: 0 } }),
        { value: 25, defaultValue: 25, isDefault: true }
    );
});

test('describeFinalsLength shows the override when the tournament has one', () => {
    assert.deepStrictEqual(
        tournamentSettings.describeFinalsLength({ admin: { defaultFinalsLength: 30 }, user: { defaultFinalsLength: 25 }, tournament: { defaultFinalsLength: 45 } }),
        { value: 45, defaultValue: 25, isDefault: false }
    );
});

test('describeFinalsLength falls back to the admin value, then 30', () => {
    assert.strictEqual(tournamentSettings.describeFinalsLength({ admin: { defaultFinalsLength: 40 }, tournament: {} }).defaultValue, 40);
    assert.strictEqual(tournamentSettings.describeFinalsLength({}).defaultValue, 30);
});

test('finalsLengthToSave stores no override when the value equals the default', () => {
    assert.strictEqual(tournamentSettings.finalsLengthToSave('25', 25), 0);
});

test('finalsLengthToSave stores a different value as the override', () => {
    assert.strictEqual(tournamentSettings.finalsLengthToSave('45', 25), 45);
});

test('finalsLengthToSave treats empty, zero or non-numeric input as no override', () => {
    assert.strictEqual(tournamentSettings.finalsLengthToSave('', 25), 0);
    assert.strictEqual(tournamentSettings.finalsLengthToSave('0', 25), 0);
    assert.strictEqual(tournamentSettings.finalsLengthToSave('abc', 25), 0);
});
