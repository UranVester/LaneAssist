'use strict';

const test = require('node:test');
const assert = require('node:assert');
const fs = require('fs');
const path = require('path');

// An unversioned asset stays cached after an update: the face-name caption was removed
// from ManageTargets/js/app.js, but browsers kept printing it from the cached copy.
test('ManageTargets loads every LaneAssist asset with a ?v= cache buster', () => {
    const php = fs.readFileSync(path.join(__dirname, '../../ManageTargets/index.php'), 'utf8');
    const assets = php.match(/Modules\/Custom\/LaneAssist\/[^"']+\.(js|css)[^"']*/g) || [];
    assert.ok(assets.length >= 5, 'expected the page assets to be found');
    assets.forEach((asset) => assert.match(asset, /\?v=/, asset + ' has no cache buster'));
});

test('ChangeTargetFaces loads every LaneAssist asset with a ?v= cache buster, sort.js included', () => {
    const php = fs.readFileSync(path.join(__dirname, '../../ChangeTargetFaces/index.php'), 'utf8');
    const assets = php.match(/Modules\/Custom\/LaneAssist\/[^"']+\.(js|css)[^"']*/g) || [];
    assert.ok(assets.some((a) => a.includes('ChangeTargetFaces/js/sort.js')), 'sort.js is not loaded');
    assets.forEach((asset) => assert.match(asset, /\?v=/, asset + ' has no cache buster'));
});
