'use strict';
const test = require('node:test');
const assert = require('node:assert');
const { createTranslator } = require('../../Common/js/i18n.js');

test('looks up the key, falls back to the key itself', () => {
    const { t } = createTranslator({ 'a.b': 'Hej' });
    assert.strictEqual(t('a.b'), 'Hej');
    assert.strictEqual(t('no.such.key'), 'no.such.key');
});

test('placeholders are filled once and never re-expanded', () => {
    const { t } = createTranslator({ 'x.of': '{n} of {total}' });
    assert.strictEqual(t('x.of', { n: 1, total: 3 }), '1 of 3');
    assert.strictEqual(t('x.of', { n: '{total}', total: 3 }), '{total} of 3');
    assert.strictEqual(t('x.of', { n: 1 }), '1 of {total}');
    assert.strictEqual(t('x.of', { n: 0, total: 0 }), '0 of 0');
});

test('tn picks .one only for 1 and fills {count}', () => {
    const { tn } = createTranslator({ 'x.a.one': '{count} skytte', 'x.a.other': '{count} skytter' });
    assert.strictEqual(tn('x.a', 1), '1 skytte');
    assert.strictEqual(tn('x.a', 0), '0 skytter');
    assert.strictEqual(tn('x.a', 2), '2 skytter');
});

test('missing or null catalog does not throw', () => {
    assert.strictEqual(createTranslator(null).t('k.k'), 'k.k');
    assert.strictEqual(createTranslator(undefined).t('k.k'), 'k.k');
});

test('inherited object properties are not treated as texts', () => {
    assert.strictEqual(createTranslator({}).t('toString'), 'toString');
});

test('englishTranslator reads Lang/en.php, including escaped quotes', () => {
    const { englishTranslator } = require('../../Common/js/i18n.js');
    const tr = englishTranslator();
    assert.strictEqual(tr.t('status.phaseGold'), 'Gold');
    assert.strictEqual(tr.tn('tournament.sessionDefaultsConfirm', 1), "Set this session to the tournament's start date, 09:00-12:00");
});
