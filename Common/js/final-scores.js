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

    function isWinner(side) {
        return !!side && (!!side.winLose || parseInt(side.tie, 10) === 2);
    }

    // Medal a side has earned on a decided medal match (phase 0 = gold final,
    // phase 1 = bronze final): 'gold' / 'silver' / 'bronze', or null.
    function medal(match, side) {
        var phase = parseInt(match && match.phase, 10);
        if ((phase !== 0 && phase !== 1) || !side || !side.participantId) return null;
        if (isWinner(side)) return phase === 0 ? 'gold' : 'bronze';
        if (phase !== 0) return null;
        var decided = (match.sides || []).some(function(other) { return other !== side && isWinner(other); });
        return decided ? 'silver' : null;
    }

    return {build: build, render: render, medal: medal};
}));
