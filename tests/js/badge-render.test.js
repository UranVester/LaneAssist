'use strict';

const test = require('node:test');
const assert = require('node:assert');

const badges = require('../../Common/js/badge-render.js');

test('renders nothing for an empty, missing or non-array value', () => {
    assert.deepStrictEqual(badges.render([]), {edge: '', inline: ''});
    assert.deepStrictEqual(badges.render(undefined), {edge: '', inline: ''});
    assert.deepStrictEqual(badges.render(null), {edge: '', inline: ''});
    assert.deepStrictEqual(badges.render('Guld'), {edge: '', inline: ''});
});

test('an edge badge becomes a segment of the right strip and nothing inline', () => {
    const out = badges.render([{kind: 'edge', label: 'Guld', color: '#d4af37'}]);
    assert.match(out.edge, /class="competitor-edge"/);
    assert.match(out.edge, /class="competitor-edge-seg"/);
    assert.match(out.edge, /background:#d4af37/);
    assert.strictEqual(out.inline, '', 'an edge badge must not also render inline');
});

test('several edge badges become equal segments in provider order', () => {
    const out = badges.render([
        {kind: 'edge', label: 'Guld', color: '#d4af37'},
        {kind: 'edge', label: 'Record', color: '#1f5fbf'}
    ]);
    const segments = out.edge.match(/class="competitor-edge-seg"/g) || [];
    assert.strictEqual(segments.length, 2);
    assert.ok(out.edge.indexOf('#d4af37') < out.edge.indexOf('#1f5fbf'));
});

test('every segment is outlined so a near-white colour stays visible', () => {
    // DK 'Hvid' is #f2f2f2 against a white card.
    const out = badges.render([{kind: 'edge', label: 'Hvid', color: '#f2f2f2'}]);
    assert.match(out.edge, /competitor-edge-seg/);
    // The outline is a CSS class concern, so assert the class is applied rather
    // than the box-shadow: the stylesheet owns the look.
    assert.ok(!/style="[^"]*box-shadow/.test(out.edge));
});

test('a pill renders inline with its colours', () => {
    const out = badges.render([
        {kind: 'pill', label: 'PB 570', color: '#e8edf0', textColor: '#000000'}
    ]);
    assert.strictEqual(out.edge, '');
    assert.match(out.inline, /class="badge-pill"/);
    assert.match(out.inline, /background:#e8edf0/);
    assert.match(out.inline, /color:#000000/);
    assert.match(out.inline, /PB 570/);
});

test('an icon renders as a validated Font Awesome class, label only as a label', () => {
    const out = badges.render([
        {kind: 'icon', label: 'New personal best', icon: 'fa-trophy', color: '#b77900'}
    ]);
    assert.match(out.inline, /class="badge-icon fa fa-trophy"/);
    assert.match(out.inline, /color:#b77900/);
    // The label is an accessible name, not body text next to the glyph.
    assert.match(out.inline, /aria-label="New personal best"/);
});

test('an icon name that is not a plain fa- token is refused', () => {
    const out = badges.render([
        {kind: 'icon', label: 'bad', icon: 'fa-x" onload="alert(1)'}
    ]);
    assert.ok(!out.inline.includes('onload'));
    assert.strictEqual(out.inline, '', 'refuse rather than sanitise');
});

test('a missing kind is treated as a pill', () => {
    const out = badges.render([{label: 'PB 570', color: '#e8edf0'}]);
    assert.match(out.inline, /class="badge-pill"/);
});

test('an unknown kind renders nothing', () => {
    const out = badges.render([{kind: 'hologram', label: 'Shiny', color: '#000000'}]);
    assert.deepStrictEqual(out, {edge: '', inline: ''});
});

test('mixed kinds land in their own slots, inline order preserved', () => {
    const out = badges.render([
        {kind: 'edge', label: 'Guld', color: '#d4af37'},
        {kind: 'pill', label: 'PB 570', color: '#e8edf0'},
        {kind: 'icon', label: 'Record', icon: 'fa-flag'}
    ]);
    assert.match(out.edge, /competitor-edge-seg/);
    assert.ok(out.inline.indexOf('PB 570') < out.inline.indexOf('fa-flag'));
});

test('labels and titles are escaped', () => {
    const out = badges.render([{
        kind: 'pill',
        label: '<img src=x onerror=alert(1)>',
        color: '#000000',
        title: 'he said "hi" & left'
    }]);
    assert.ok(!out.inline.includes('<img'));
    assert.match(out.inline, /&lt;img/);
    assert.match(out.inline, /&quot;hi&quot;/);
    assert.match(out.inline, /&amp; left/);
});

test('a colour that is not a plain hex value falls back to neutral grey', () => {
    const pill = badges.render([
        {kind: 'pill', label: 'Guld', color: 'red; background:url(javascript:alert(1))'}
    ]);
    assert.ok(!pill.inline.includes('javascript'));
    assert.match(pill.inline, /background:#eeeeee/);

    const edge = badges.render([{kind: 'edge', label: 'Guld', color: 'red'}]);
    assert.match(edge.edge, /background:#eeeeee/);
});

test('a badge with no label is skipped whatever its kind', () => {
    assert.deepStrictEqual(
        badges.render([{kind: 'edge', color: '#000000'}, {kind: 'pill', color: '#000000'}]),
        {edge: '', inline: ''});
});

test('a title becomes the tooltip on both slots', () => {
    const out = badges.render([
        {kind: 'edge', label: 'Guld', color: '#d4af37', title: 'BD · Guld (570+)'},
        {kind: 'pill', label: 'PB', color: '#e8edf0', title: 'Set at Vordingborg'}
    ]);
    assert.match(out.edge, /title="BD · Guld \(570\+\)"/);
    assert.match(out.inline, /title="Set at Vordingborg"/);
});
