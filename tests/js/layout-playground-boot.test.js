'use strict';

// The Layout Playground page loads ManageTargets/js/app.js with its own script
// list. app.js must boot there (and expose ManageTargetsDebug) with exactly the
// scripts that page includes - a missing shared script once made it throw on load.
const test = require('node:test');
const assert = require('node:assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const moduleRoot = path.join(__dirname, '..', '..');

function chainableStub() {
    const fn = function() { return proxy; };
    const proxy = new Proxy(fn, {
        get: function(target, prop) {
            if (prop === 'length') return 0;
            if (prop === Symbol.toPrimitive) return function() { return ''; };
            return proxy;
        },
        apply: function() { return proxy; }
    });
    return proxy;
}

test('ManageTargets app.js boots with the Layout Playground script list', () => {
    const page = fs.readFileSync(path.join(moduleRoot, 'ManageTargets', 'layout-playground.php'), 'utf8');
    const scripts = [];
    const re = /Modules\/Custom\/LaneAssist\/([\w\/.-]+\.js)/g;
    let match;
    while ((match = re.exec(page)) !== null) {
        scripts.push(match[1]);
    }
    assert.ok(scripts.indexOf('ManageTargets/js/app.js') !== -1);

    const $ = chainableStub();
    const window = {};
    const context = vm.createContext({ window: window, self: window, jQuery: $, $: $, document: {}, console: console });
    window.window = window;
    scripts.filter(function(src) { return src !== 'ManageTargets/js/layout-playground.js'; }).forEach(function(src) {
        vm.runInContext(fs.readFileSync(path.join(moduleRoot, src), 'utf8'), context, { filename: src });
    });

    assert.ok(window.LaneAssist && window.LaneAssist.ManageTargetsDebug, 'ManageTargetsDebug exposed');
});
