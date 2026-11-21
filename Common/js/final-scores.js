// Per-end score columns for a LiveView finals card. Pure (no jQuery/DOM) so it
// can be unit-tested: set-system matches show S1..Sn + set score, cumulative
// (compound) matches show E1..En + running total. n is the event's end count.
(function(root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.LaneAssist = root.LaneAssist || {};
        root.LaneAssist.finalScores = factory();
    }
}(typeof self !== 'undefined' ? self : this, function() {
    'use strict';

    function escapeHtml(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function build(match, side) {
        var setSystem = !!match.matchMode;
        var count = Math.max(1, parseInt(match.totalEnds, 10) || 5);
        var points = Array.isArray(side.setPoints) ? side.setPoints : [];
        var columns = [];
        for (var i = 0; i < count; i++) {
            var value = points[i];
            columns.push({label: (setSystem ? 'S' : 'E') + (i + 1), value: value === undefined ? null : value});
        }
        return {
            columns: columns,
            summary: setSystem
                ? {label: 'Sets', value: side.setScore || 0}
                : {label: 'Total', value: side.score || 0}
        };
    }

    function render(match, side) {
        var view = build(match, side);
        var cells = view.columns.map(function(column) {
            return '<span class="set-point"><small>' + column.label + '</small><b>' +
                (column.value === null ? '-' : escapeHtml(column.value)) + '</b></span>';
        }).join('');
        return '<div class="final-scores set-system"><div class="set-points" style="--final-columns: ' + view.columns.length + '">' + cells + '</div>' +
            '<span class="set-score"><small>' + view.summary.label + '</small><b>' + escapeHtml(view.summary.value) + '</b></span></div>';
    }

    return {build: build, render: render};
}));
