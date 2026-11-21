(function($) {
    'use strict';

    // Common/js/i18n.js (loaded before this file) puts the page's translator on window.
    const laT = window.laT;
    const laTn = window.laTn;

    const API_URL = ROOT_DIR + 'Modules/Custom/LaneAssist/ChangeEvents/api.php';
    const LIST_LIMIT = 20;
    const BRACKET_LABELS = {
        0: laT('events.bracketNone'),
        1: laT('status.phaseGold'),
        2: laT('events.bracketSemi'),
        4: '1/4',
        8: '1/8',
        16: '1/16',
        32: '1/32',
        64: '1/64'
    };

    const state = {
        preview: null,
        busy: false
    };

    function init() {
        $('.ce-criterion').on('change', function() {
            syncDistance();
            clearPreview();
        });
        $('#btn-preview').on('click', loadPreview);
        $('#ce-confirm').on('change', syncApplyButton);
        $('#btn-apply').on('click', applyChanges);
        syncDistance();
    }

    /** Distance is forced off (and greyed) whenever Division is ticked. */
    function syncDistance() {
        const divisionOn = $('#ce-division').is(':checked');
        const $distance = $('#ce-distance');
        if (divisionOn) {
            $distance.prop('checked', false);
        }
        $distance.prop('disabled', divisionOn);
        $('#ce-distance-label').toggleClass('disabled', divisionOn);
        $('#ce-distance-hint').toggle(divisionOn);
    }

    function criteria() {
        const divisionOn = $('#ce-division').is(':checked');
        return {
            gender: $('#ce-gender').is(':checked') ? 1 : 0,
            'class': $('#ce-class').is(':checked') ? 1 : 0,
            division: divisionOn ? 1 : 0,
            distance: (!divisionOn && $('#ce-distance').is(':checked')) ? 1 : 0
        };
    }

    function clearPreview() {
        state.preview = null;
        $('#preview').addClass('hidden');
        $('#old-events, #new-events, #warning-box').empty();
        $('#ce-confirm').prop('checked', false);
        $('#result').addClass('hidden').empty();
        hideStatus();
        syncApplyButton();
    }

    function setBusy(busy) {
        state.busy = busy;
        $('#btn-preview').prop('disabled', busy);
        $('.ce-criterion').prop('disabled', busy);
        if (!busy) {
            syncDistance();
        }
        syncApplyButton();
    }

    function canApply() {
        const preview = state.preview;
        return !!preview && !state.busy && !preview.blocked && (preview.newEvents || []).length > 0;
    }

    function syncApplyButton() {
        const ok = canApply();
        $('#ce-confirm').prop('disabled', !ok);
        if (!ok) {
            $('#ce-confirm').prop('checked', false);
        }
        $('#btn-apply').prop('disabled', !ok || !$('#ce-confirm').is(':checked'));
    }

    function loadPreview() {
        clearPreview();
        setBusy(true);
        showStatus(laT('events.loadingPreview'), 'info');

        $.ajax({
            url: API_URL,
            method: 'GET',
            dataType: 'json',
            data: $.extend({ action: 'preview' }, criteria()),
            success: function(response) {
                if (response.error) {
                    showStatus(response.message || laT('events.previewFailed'), 'error');
                    return;
                }
                hideStatus();
                renderPreview(response);
            },
            error: function() {
                showStatus(laT('events.previewFailed'), 'error');
            },
            complete: function() {
                setBusy(false);
            }
        });
    }

    function applyChanges() {
        if (!canApply() || !$('#ce-confirm').is(':checked')) {
            return;
        }
        const sent = state.preview;
        setBusy(true);
        showStatus(laT('events.replacing'), 'info');

        $.ajax({
            url: API_URL,
            method: 'POST',
            dataType: 'json',
            data: $.extend({ action: 'apply', fingerprint: sent.fingerprint }, sent.criteria ? toFlags(sent.criteria) : criteria()),
            success: function(response) {
                if (response.stale && response.preview) {
                    renderPreview(response.preview);
                    showStatus(laT('events.stale'), 'error');
                    return;
                }
                if (response.error) {
                    showStatus(response.message || laT('events.replaceFailed'), 'error');
                    return;
                }
                renderResult(response);
            },
            error: function() {
                showStatus(laT('events.replaceFailed'), 'error');
            },
            complete: function() {
                setBusy(false);
            }
        });
    }

    /** The server's normalised criteria back as 0/1 request flags. */
    function toFlags(c) {
        return {
            gender: c.gender ? 1 : 0,
            'class': c['class'] ? 1 : 0,
            division: c.division ? 1 : 0,
            distance: c.distance ? 1 : 0
        };
    }

    function renderPreview(preview) {
        state.preview = preview;
        $('#ce-confirm').prop('checked', false);
        $('#old-events').html(renderEventTable(preview.oldEvents || [], false));
        $('#new-events').html(renderEventTable(preview.newEvents || [], true));
        $('#warning-box').html(renderWarning(preview));
        $('#preview').removeClass('hidden');
        bindShowMore();
        syncApplyButton();
    }

    function renderEventTable(events, isNew) {
        if (!events.length) {
            return '<div class="empty-note">' + escapeHtml(isNew ? laT('events.noneCreated') : laT('events.noneYet')) + '</div>';
        }

        const cols = isNew ? 4 : 3;
        let html = '<table class="events-table"><thead><tr><th>' + escapeHtml(laT('events.colCode')) + '</th><th>'
            + escapeHtml(laT('events.colName')) + '</th><th class="num">' + escapeHtml(laT('events.colArchers')) + '</th>'
            + (isNew ? '<th>' + escapeHtml(laT('events.colBracket')) + '</th>' : '') + '</tr></thead><tbody>';

        events.forEach(function(ev, idx) {
            const extra = idx >= LIST_LIMIT ? ' class="more-row hidden"' : '';
            html += '<tr' + extra + '><td>' + escapeHtml(ev.code) + '</td><td>' + escapeHtml(ev.name)
                + '</td><td class="num">' + escapeHtml(String(ev.count)) + '</td>'
                + (isNew ? '<td>' + escapeHtml(bracketLabel(ev.firstPhase)) + '</td>' : '') + '</tr>';
        });

        if (events.length > LIST_LIMIT) {
            html += '<tr class="show-more-row"><td colspan="' + cols + '"><a href="#" class="show-more">… '
                + escapeHtml(laT('events.more', { count: events.length - LIST_LIMIT })) + '</a></td></tr>';
        }
        html += '</tbody></table>';
        html += '<div class="table-total">' + escapeHtml(laTn('events.total', events.length, { archers: sumCounts(events) })) + '</div>';
        return html;
    }

    function bindShowMore() {
        $('#preview .show-more').on('click', function(e) {
            e.preventDefault();
            const $table = $(this).closest('table');
            $table.find('.more-row').removeClass('hidden');
            $(this).closest('tr').remove();
        });
    }

    function sumCounts(events) {
        return events.reduce(function(sum, ev) { return sum + (parseInt(ev.count, 10) || 0); }, 0);
    }

    function bracketLabel(phase) {
        const key = parseInt(phase, 10) || 0;
        return Object.prototype.hasOwnProperty.call(BRACKET_LABELS, key) ? BRACKET_LABELS[key] : String(phase);
    }

    /** One warning line: bold label, then plain (escaped) text. */
    function warningLine(label, text) {
        return '<b>' + escapeHtml(label) + ':</b> ' + escapeHtml(text);
    }

    function renderWarning(preview) {
        const del = preview.deletion || {};
        const stale = del.stale || {};
        const oldCount = (preview.oldEvents || []).length;
        let html = '';

        html += '<div class="warning-title"><i class="fa fa-exclamation-triangle"></i> '
            + escapeHtml(oldCount > 0 ? laTn('events.wDeletesAll', oldCount) : laT('events.wNothingToDelete')) + '</div>';

        const lines = [];
        if (del.scoredMatches > 0) {
            const scoredEvents = del.scoredEvents || [];
            lines.push(warningLine(laT('events.wFinalsResults'),
                laTn('events.wScoredMatches', del.scoredMatches, { events: laTn('events.nEvents', scoredEvents.length) })
                + (scoredEvents.length ? ' (' + scoredEvents.join(', ') + ')' : '')));
        }
        if (del.scheduledMatches > 0) {
            lines.push(warningLine(laT('events.wFinalsSchedule'), laTn('events.wScheduledMatches', del.scheduledMatches)));
        }
        if (del.rankedRows > 0 || del.shootOffRows > 0) {
            let text = laTn('events.wRankingRows', del.rankedRows);
            if (del.shootOffRows > 0) {
                text += ', ' + laTn('events.wShootOffs', del.shootOffRows);
            }
            lines.push(warningLine(laT('events.wRankings'), text + ' ' + laT('events.wRankingsKept')));
        }
        if (del.eliminationRows > 0) {
            lines.push(warningLine(laT('events.wEliminations'), laTn('events.wEliminationRows', del.eliminationRows)));
        }
        const elimTypeEvents = del.elimTypeEvents || [];
        if (elimTypeEvents.length > 0) {
            lines.push(warningLine(laT('events.wElimSetup'),
                laTn('events.wElimSetupEvents', elimTypeEvents.length, { events: elimTypeEvents.join(', ') })));
        }
        const collapsed = del.collapsed || [];
        if (collapsed.length > 0) {
            lines.push(warningLine(laT('events.wMappings'),
                laTn('events.wMappingsEvents', collapsed.length, { events: collapsed.join(', ') })));
        }
        if (lines.length) {
            html += '<ul>' + lines.map(function(l) { return '<li>' + l + '</li>'; }).join('') + '</ul>';
        }

        const redo = [];
        if (stale.awards > 0) {
            redo.push(laTn('events.redoAwards', stale.awards));
        }
        if (stale.tvRules > 0) {
            redo.push(laTn('events.redoTvRules', stale.tvRules));
        }
        if (stale.iskPending > 0) {
            redo.push(laTn('events.redoIsk', stale.iskPending));
        }
        if (stale.hhtEvents > 0) {
            redo.push(laTn('events.redoHht', stale.hhtEvents));
        }
        if (redo.length) {
            html += '<div class="warning-line">' + warningLine(laT('events.wRedo'), redo.join(', ') + '.') + '</div>';
        }

        html += '<div class="warning-line">' + warningLine(laT('events.wNotTouched'), laT('events.wNotTouchedList')) + '</div>';

        if (preview.blocked) {
            html += '<div class="warning-line"><b>' + escapeHtml(laT('events.blocked')) + '</b></div>';
        }
        return html;
    }

    function renderResult(response) {
        clearPreview();
        const listUrl = ROOT_DIR + 'Final/Individual/ListEvents.php';
        const summary = laT('events.resultSummary', {
            created: laTn('events.nEvents', parseInt(response.created, 10) || 0),
            deleted: parseInt(response.deleted, 10) || 0,
            placed: laTn('events.nArchers', parseInt(response.placed, 10) || 0)
        });
        const warning = response.warning
            ? '<div class="result-line result-warning"><i class="fa fa-exclamation-triangle"></i> '
                + escapeHtml(response.warning) + '</div>'
            : '';
        $('#result').removeClass('hidden').html(
            '<div class="result-line"><i class="fa fa-check"></i> ' + escapeHtml(summary) + '</div>'
            + warning
            + '<a class="btn btn-info" href="' + escapeHtml(listUrl) + '"><i class="fa fa-list"></i> ' + escapeHtml(laT('events.openList')) + '</a>'
        );
        showStatus(response.warning ? summary + ' — ' + response.warning : summary, 'success');
    }

    function showStatus(message, type) {
        $('#status-message')
            .removeClass('hidden status-info status-success status-error')
            .addClass('status-' + (type || 'info'))
            .text(message);
    }

    function hideStatus() {
        $('#status-message').addClass('hidden').text('');
    }

    function escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            '\'': '&#039;'
        };
        return (text === null || text === undefined ? '' : text).toString().replace(/[&<>"']/g, function(m) { return map[m]; });
    }

    $(init);
})(jQuery);
