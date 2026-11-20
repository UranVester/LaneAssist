/**
 * Print layout rules for ManageTargets.
 *
 * The printed sheet is a setup aid for the field crew: which faces go on which
 * mat, at what distance. Mats nobody is assigned to are left off; empty spots
 * on a mat in use stay blank so the gaps are visible. Those rules and the
 * document shell live here as pure functions (tests/js/print-layout.test.js).
 */
(function(root, factory) {
    'use strict';
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.LaneAssist = root.LaneAssist || {};
        root.LaneAssist.printLayout = factory();
    }
}(typeof self !== 'undefined' ? self : this, function() {
    'use strict';

    const PRINT_CSS = [
        '@page { size: A4 portrait; margin: 10mm; }',
        'body { background: #fff; color: #000; margin: 0; font-family: Arial, sans-serif; }',
        '.print-header { margin-bottom: 8px; border-bottom: 1px solid #999; padding-bottom: 6px; }',
        '.print-header h1 { font-size: 16px; margin: 0 0 4px; }',
        '.print-header .print-meta { font-size: 12px; }',
        '.print-header .print-unapplied { margin-top: 4px; font-size: 12px; font-weight: bold; color: #b00; }',
        '.print-header .print-filtered { margin-top: 4px; font-size: 12px; font-weight: bold; }',
        // A4 is narrow enough to trip every max-width screen query in style.css,
        // which collapses the grid to one mat per row; pin a print grid instead.
        '.targets-grid { grid-template-columns: repeat(4, minmax(0, 1fr)) !important; gap: 6px !important; padding: 0 !important; overflow: visible !important; max-height: none !important; height: auto !important; }',
        '.targets-grid.fallback-stacked { grid-template-columns: repeat(4, 170px) !important; }',
        '.target-mat { max-width: none !important; box-shadow: none !important; }',
        '.mat-label { font-size: 11px; padding: 3px; margin-bottom: 6px; }',
        '.target-mat, .target-box { break-inside: avoid; page-break-inside: avoid; }',
        '.print-face-img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: contain; z-index: 0; }',
        // The sheet reuses the screen render; drop zones are drag-and-drop UI, not part of the setup
        '.target-drop-zone { z-index: 1; }',
        '.target-drop-zone, .target-drop-zone.droppable-zone, .target-drop-zone.synthetic-target { border: none !important; background: transparent !important; box-shadow: none !important; opacity: 1 !important; }',
        '.print-empty-label { font-size: 11px; font-style: italic; color: #555; text-align: center; background: rgba(255, 255, 255, 0.85); padding: 1px 4px; border-radius: 3px; }',
        '.print-face-caption { position: absolute; left: 0; right: 0; bottom: 0; z-index: 2; font-size: 10px; text-align: center; background: rgba(255, 255, 255, 0.85); }',
        '* { -webkit-print-color-adjust: exact; print-color-adjust: exact; }'
    ].join('\n');

    function escapeHtml(text) {
        return String(text === null || text === undefined ? '' : text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function isMatInUse(spotTargetFulls, assignedTargetFulls) {
        return (spotTargetFulls || []).some(function(targetFull) {
            return spotStatus(targetFull, assignedTargetFulls) === 'archer';
        });
    }

    function spotStatus(targetFull, assignedTargetFulls) {
        return (assignedTargetFulls || []).indexOf(targetFull) !== -1 ? 'archer' : 'empty';
    }

    /**
     * One-line description of the active ManageTargets filters, or '' when the
     * sheet covers the whole session. '' in a multiselect means "All".
     * sessionFirst + sessionCount (or sessionLast) default to the page's 1-99 inputs.
     */
    function describeFilters(filters) {
        const f = filters || {};
        const pick = function(values) {
            return (values || []).filter(function(value) { return value !== ''; });
        };
        const parts = [];
        const divisions = pick(f.divisions);
        const classes = pick(f.classes);
        if (divisions.length) {
            parts.push('Divisions: ' + divisions.join(', '));
        }
        if (classes.length) {
            parts.push('Classes: ' + classes.join(', '));
        }
        // ManageTargets writes the session's own range into the inputs on load,
        // so only a range narrower than the session's counts as a filter.
        // The API sends the session range as strings, so never add them unparsed
        const first = parseInt(f.sessionFirst, 10) || 1;
        const count = parseInt(f.sessionCount, 10) || 0;
        const last = count > 0 ? first + count - 1 : (parseInt(f.sessionLast, 10) || 99);
        const from = parseInt(f.targetFrom, 10) || first;
        const to = parseInt(f.targetTo, 10) || last;
        if (from !== first || to !== last) {
            parts.push('Targets ' + from + '\u2013' + to);
        }
        return parts.join(' \u00b7 ');
    }

    function buildPrintDocument(opts) {
        const o = opts || {};
        const links = (o.stylesheets || []).map(function(url) {
            return '<link rel="stylesheet" href="' + escapeHtml(url) + '">';
        }).join('\n');
        const title = 'Target layout - ' + (o.session || '');

        return '<!doctype html>\n<html><head><meta charset="utf-8">' +
            '<title>' + escapeHtml(title) + '</title>\n' +
            links + '\n<style>\n' + PRINT_CSS + '\n</style></head><body>' +
            '<div class="print-header">' +
            '<h1>' + escapeHtml(o.tournament) + '</h1>' +
            '<div class="print-meta">Session: ' + escapeHtml(o.session) +
            ' &middot; Layout: ' + escapeHtml(o.layout) +
            ' &middot; Printed: ' + escapeHtml(o.printedAt) + '</div>' +
            (o.filters ? '<div class="print-filtered">Filtered: ' + escapeHtml(o.filters) + '</div>' : '') +
            (o.hasChanges ? '<div class="print-unapplied">Includes changes not applied yet</div>' : '') +
            '</div>' +
            (o.matsHtml || '') +
            (o.autoPrint === false ? '' : '<script>window.addEventListener(\'load\', function () { window.print(); });<\/script>') +
            '</body></html>';
    }

    function qualPrintUrl(rootDir, session) {
        return (rootDir || '') + 'Modules/Custom/LaneAssist/ManageTargets/index.php?session=' +
            encodeURIComponent(session) + '&print=1';
    }

    /**
     * LiveView finals print button state for a round from the snapshot
     * (finalsRounds[] carries printReady/printWaiting from the server rule).
     */
    function finalsPrintState(round) {
        if (!round || !round.slot) {
            return {enabled: false, title: 'Unscheduled matches cannot be printed'};
        }
        if (round.printReady) {
            return {enabled: true, title: 'Print lane layout for this round'};
        }
        const waiting = parseInt(round.printWaiting, 10) || 0;
        if (waiting === 0) {
            return {enabled: false, title: 'Nothing to print in this round'};
        }
        return {enabled: false, title: 'Waiting for ' + waiting + ' match' + (waiting === 1 ? '' : 'es') + ' to finish'};
    }

    function finalsPrintUrl(rootDir, slot) {
        return (rootDir || '') + 'Modules/Custom/LaneAssist/LiveView/print-finals.php?slot=' + encodeURIComponent(slot);
    }

    return {
        finalsPrintState: finalsPrintState,
        finalsPrintUrl: finalsPrintUrl,
        qualPrintUrl: qualPrintUrl,
        isMatInUse: isMatInUse,
        describeFilters: describeFilters,
        spotStatus: spotStatus,
        buildPrintDocument: buildPrintDocument
    };
}));
