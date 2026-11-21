'use strict';
// The release zip is built from a `cp -a` of the module directory, so anything
// not excluded ships. Design docs and the git-ignored plan workspace must not.
const test = require('node:test');
const assert = require('node:assert');
const fs = require('fs');
const path = require('path');

const script = fs.readFileSync(path.join(__dirname, '..', '..', 'build-update.sh'), 'utf8');

for (const pattern of ['*/docs/superpowers/*', '*/.superpowers/*', '*/tests/*', '*/tools/*']) {
    test(`release zip excludes ${pattern}`, () => {
        assert.ok(script.includes(`"${pattern}"`), `build-update.sh zip -x is missing "${pattern}"`);
    });
}
