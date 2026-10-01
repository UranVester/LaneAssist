/**
 * Pure logic for the LiveView Status tab.
 *
 * Mirrors the Common/js/finals-playability.js pattern: no jQuery/DOM, so it
 * can be unit-tested directly (tests/js/). LiveView/js/app.js calls into
 * this for the "unplayable finals" checklist items.
 */
(function(root, factory) {
    'use strict';
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.LaneAssist = root.LaneAssist || {};
        root.LaneAssist.statusLogic = factory();
    }
}(typeof self !== 'undefined' ? self : this, function() {
    'use strict';

    /** Mirrors ManageFinals/js/app.js's own parseTargetNumber(). */
    function parseTargetNumber(target) {
        var parsed = parseInt((target || '').toString().trim(), 10);
        return Number.isNaN(parsed) ? null : parsed;
    }

    /**
     * Groups finalsRows into match pairs and flags only pairs that are both
     * genuinely unplayable (per finalsPlayability.isPairPlayable) AND actually
     * scheduled (at least one row carries a real target). This mirrors
     * ManageFinals/js/app.js's nonPlayableScheduledPairs gating so a phantom
     * bracket (0 projected finalists, unscheduled) or an ordinary bye is never
     * reported as a warning here.
     */
    function computeUnplayableFinalsItems(finalsRows, playability, rootDir) {
        var pairs = {};
        (finalsRows || []).forEach(function(row) {
            var pairNo = Math.floor((row.matchNo || 0) / 2);
            var key = row.teamEvent + '|' + row.event + '|' + row.group + '|' + row.phase + '|' + pairNo;
            (pairs[key] = pairs[key] || []).push(row);
        });

        var items = [];
        Object.keys(pairs).forEach(function(key) {
            var pairRows = pairs[key];
            if (playability.isPairPlayable(pairRows)) {
                return;
            }
            var pairHasAssignedTarget = pairRows.some(function(row) {
                return parseTargetNumber(row.target) !== null;
            });
            if (!pairHasAssignedTarget) {
                return;
            }
            var sample = pairRows[0];
            items.push({
                key: 'unplayable_' + key,
                severity: 'warning',
                title: 'Unplayable finals',
                detail: 'Event ' + sample.event + ': a scheduled match cannot be filled from the projected field',
                link: (rootDir || '') + 'Modules/Custom/LaneAssist/ManageFinals/index.php',
                fix: null,
            });
        });
        return items;
    }

    return {
        parseTargetNumber: parseTargetNumber,
        computeUnplayableFinalsItems: computeUnplayableFinalsItems
    };
}));
