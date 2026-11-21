/**
 * Pure logic for the Manage Tournament status checklist.
 *
 * Mirrors the Common/js/finals-playability.js pattern: no jQuery/DOM, so it
 * can be unit-tested directly (tests/js/). ManageTournament/js/app.js calls
 * into this for the "unplayable finals" items and the checklist order.
 */
(function(root, factory) {
    'use strict';
    if (typeof module === 'object' && module.exports) {
        module.exports = factory(function() { return require('./i18n.js').englishTranslator(); });
    } else {
        root.LaneAssist = root.LaneAssist || {};
        root.LaneAssist.statusLogic = factory(function() { return { t: root.laT, tn: root.laTn }; });
    }
}(typeof self !== 'undefined' ? self : this, function(defaultTranslator) {
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
     *
     * Collapses into a single "Unplayable scheduled finals" card, deduped by
     * event: a bracket with several unplayable pairs still needs only one
     * row telling the admin which event to go fix.
     */
    function computeUnplayableFinalsItems(finalsRows, playability, rootDir, translator) {
        var tr = translator || defaultTranslator();
        var pairs = {};
        (finalsRows || []).forEach(function(row) {
            var pairNo = Math.floor((row.matchNo || 0) / 2);
            var key = row.teamEvent + '|' + row.event + '|' + row.group + '|' + row.phase + '|' + pairNo;
            (pairs[key] = pairs[key] || []).push(row);
        });

        var eventsSeen = {};
        var rows = [];
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
            if (eventsSeen[sample.event]) {
                return;
            }
            eventsSeen[sample.event] = true;
            rows.push({
                text: sample.event,
                link: (rootDir || '') + 'Modules/Custom/LaneAssist/ManageFinals/index.php',
            });
        });

        if (!rows.length) {
            return [];
        }
        rows.sort(function(a, b) { return a.text.localeCompare(b.text); });

        return [{
            key: 'unplayableFinals',
            severity: 'warning',
            title: tr.t('status.unplayableTitle'),
            detail: tr.tn('status.unplayableEvents', rows.length),
            link: null,
            fix: null,
            rows: rows,
        }];
    }

    var SEVERITY_RANK = { danger: 0, warning: 1, info: 2 };

    /** Problems first (danger, warning), then info; stable within a severity. */
    function sortChecklistItems(items) {
        function rank(item) {
            return Object.prototype.hasOwnProperty.call(SEVERITY_RANK, item.severity) ? SEVERITY_RANK[item.severity] : 3;
        }
        return items.map(function(item, index) { return { item: item, index: index }; })
            .sort(function(a, b) { return rank(a.item) - rank(b.item) || a.index - b.index; })
            .map(function(entry) { return entry.item; });
    }

    return {
        parseTargetNumber: parseTargetNumber,
        computeUnplayableFinalsItems: computeUnplayableFinalsItems,
        sortChecklistItems: sortChecklistItems
    };
}));
