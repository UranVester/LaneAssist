// Per-end score columns for a LiveView finals card. Pure (no jQuery/DOM) so it
// can be unit-tested: set-system matches show S1..Sn + set score, cumulative
// (compound) matches show E1..En + running total. n is the event's end count.
(function(root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory(function() { return require('./i18n.js').englishTranslator(); });
    } else {
        root.LaneAssist = root.LaneAssist || {};
        root.LaneAssist.finalScores = factory(function() { return { t: root.laT, tn: root.laTn }; });
    }
}(typeof self !== 'undefined' ? self : this, function(defaultTranslator) {
    'use strict';

    function escapeHtml(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function build(match, side, translator) {
        var tr = translator || defaultTranslator();
        var setSystem = !!match.matchMode;
        var count = Math.max(1, parseInt(match.totalEnds, 10) || 5);
        var points = Array.isArray(side.setPoints) ? side.setPoints : [];
        var columns = [];
        for (var i = 0; i < count; i++) {
            var value = points[i];
            columns.push({label: (setSystem ? tr.t('live.setShort') : tr.t('live.endShort')) + (i + 1), value: value === undefined ? null : value});
        }
        return {
            columns: columns,
            summary: setSystem
                ? {label: tr.t('live.sets'), value: side.setScore || 0}
                : {label: tr.t('live.total'), value: side.score || 0}
        };
    }

    function render(match, side, translator) {
        var view = build(match, side, translator);
        var cells = view.columns.map(function(column) {
            return '<span class="set-point"><small>' + escapeHtml(column.label) + '</small><b>' +
                (column.value === null ? '-' : escapeHtml(column.value)) + '</b></span>';
        }).join('');
        return '<div class="final-scores set-system"><div class="set-points" style="--final-columns: ' + view.columns.length + '">' + cells + '</div>' +
            '<span class="set-score"><small>' + escapeHtml(view.summary.label) + '</small><b>' + escapeHtml(view.summary.value) + '</b></span></div>';
    }

    return {build: build, render: render};
}));
