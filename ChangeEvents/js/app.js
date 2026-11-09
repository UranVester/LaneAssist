(function($) {
    'use strict';

    const API_URL = ROOT_DIR + 'Modules/Custom/LaneAssist/ChangeEvents/api.php';
    const LIST_LIMIT = 20;
    const BRACKET_LABELS = {
        0: 'none',
        1: 'Gold',
        2: 'Semi',
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
        showStatus('Loading preview...', 'info');

        $.ajax({
            url: API_URL,
            method: 'GET',
            dataType: 'json',
            data: $.extend({ action: 'preview' }, criteria()),
            success: function(response) {
                if (response.error) {
                    showStatus(response.message || 'Preview failed', 'error');
                    return;
                }
                hideStatus();
                renderPreview(response);
            },
            error: function() {
                showStatus('Preview failed', 'error');
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
        showStatus('Replacing events...', 'info');

        $.ajax({
            url: API_URL,
            method: 'POST',
            dataType: 'json',
            data: $.extend({ action: 'apply', fingerprint: sent.fingerprint }, sent.criteria ? toFlags(sent.criteria) : criteria()),
            success: function(response) {
                if (response.stale && response.preview) {
                    renderPreview(response.preview);
                    showStatus('Something changed since the preview — please check again.', 'error');
                    return;
                }
                if (response.error) {
                    showStatus(response.message || 'Replacing events failed', 'error');
                    return;
                }
                renderResult(response);
            },
            error: function() {
                showStatus('Replacing events failed', 'error');
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
            return isNew
                ? '<div class="empty-note">No events would be created with these settings, so there is nothing to replace.</div>'
                : '<div class="empty-note">There are no individual events yet.</div>';
        }

        const cols = isNew ? 4 : 3;
        let html = '<table class="events-table"><thead><tr><th>Code</th><th>Name</th><th class="num">Archers</th>'
            + (isNew ? '<th>Bracket</th>' : '') + '</tr></thead><tbody>';

        events.forEach(function(ev, idx) {
            const extra = idx >= LIST_LIMIT ? ' class="more-row hidden"' : '';
            html += '<tr' + extra + '><td>' + escapeHtml(ev.code) + '</td><td>' + escapeHtml(ev.name)
                + '</td><td class="num">' + escapeHtml(String(ev.count)) + '</td>'
                + (isNew ? '<td>' + escapeHtml(bracketLabel(ev.firstPhase)) + '</td>' : '') + '</tr>';
        });

        if (events.length > LIST_LIMIT) {
            html += '<tr class="show-more-row"><td colspan="' + cols + '"><a href="#" class="show-more">… '
                + (events.length - LIST_LIMIT) + ' more</a></td></tr>';
        }
        html += '</tbody></table>';
        html += '<div class="table-total">' + events.length + ' event' + (events.length === 1 ? '' : 's')
            + ', ' + sumCounts(events) + ' archers</div>';
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

    function plural(n, one, many) {
        return n + ' ' + (n === 1 ? one : many);
    }

    function renderWarning(preview) {
        const del = preview.deletion || {};
        const stale = del.stale || {};
        const oldCount = (preview.oldEvents || []).length;
        let html = '';

        if (oldCount > 0) {
            html += '<div class="warning-title"><i class="fa fa-exclamation-triangle"></i> This permanently deletes all '
                + plural(oldCount, 'current individual event', 'current individual events') + ':</div>';
        } else {
            html += '<div class="warning-title"><i class="fa fa-exclamation-triangle"></i> There are no current individual events to delete.</div>';
        }

        const lines = [];
        if (del.scoredMatches > 0) {
            const scoredEvents = del.scoredEvents || [];
            lines.push('<b>Finals results:</b> ' + plural(del.scoredMatches, 'match', 'matches') + ' with scores in '
                + plural(scoredEvents.length, 'event', 'events')
                + (scoredEvents.length ? ' (' + escapeHtml(scoredEvents.join(', ')) + ')' : ''));
        }
        if (del.scheduledMatches > 0) {
            lines.push('<b>Finals schedule:</b> ' + plural(del.scheduledMatches, 'scheduled match', 'scheduled matches')
                + ' (times + targets, incl. ManageFinals work)');
        }
        if (del.rankedRows > 0 || del.shootOffRows > 0) {
            let text = '<b>Event rankings + shoot-off decisions:</b> ' + plural(del.rankedRows, 'ranking row', 'ranking rows');
            if (del.shootOffRows > 0) {
                text += ', ' + plural(del.shootOffRows, 'shoot-off decision', 'shoot-off decisions');
            }
            lines.push(text + ' (qualification scores are kept and re-ranked)');
        }
        if (del.eliminationRows > 0) {
            lines.push('<b>Eliminations:</b> ' + plural(del.eliminationRows, 'elimination row', 'elimination rows'));
        }
        const collapsed = del.collapsed || [];
        if (collapsed.length > 0) {
            lines.push('<b>Subclass / multi-event mappings:</b> ' + plural(collapsed.length, 'event', 'events')
                + ' (' + escapeHtml(collapsed.join(', ')) + ') use subclass/extras rules or share a division+class with another event; new events map by division+class only');
        }
        if (lines.length) {
            html += '<ul>' + lines.map(function(l) { return '<li>' + l + '</li>'; }).join('') + '</ul>';
        }

        const redo = [];
        if (stale.awards > 0) {
            redo.push('award setup (refers to ' + plural(stale.awards, 'old event', 'old events') + ')');
        }
        if (stale.tvRules > 0) {
            redo.push(plural(stale.tvRules, 'TV rule', 'TV rules'));
        }
        if (stale.iskPending > 0) {
            redo.push(plural(stale.iskPending, 'pending ISK arrow', 'pending ISK arrows'));
        }
        if (stale.hhtEvents > 0) {
            redo.push(plural(stale.hhtEvents, 'HHT finals config row', 'HHT finals config rows'));
        }
        if (redo.length) {
            html += '<div class="warning-line"><b>Needs redoing afterwards:</b> ' + escapeHtml(redo.join(', ')) + '.</div>';
        }

        html += '<div class="warning-line"><b>Not touched:</b> archers, qualification scores, sessions, qualification targets, team events.</div>';

        if (preview.blocked) {
            html += '<div class="warning-line"><b>This tournament\'s data is blocked; events cannot be changed.</b></div>';
        }
        return html;
    }

    function renderResult(response) {
        clearPreview();
        const listUrl = ROOT_DIR + 'Final/Individual/ListEvents.php';
        $('#result').removeClass('hidden').html(
            '<div class="result-line"><i class="fa fa-check"></i> '
            + escapeHtml('Created ' + response.created + ' events, deleted ' + response.deleted) + '</div>'
            + '<a class="btn btn-info" href="' + escapeHtml(listUrl) + '"><i class="fa fa-list"></i> Open individual events list</a>'
        );
        showStatus('Created ' + response.created + ' events, deleted ' + response.deleted, 'success');
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
