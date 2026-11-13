(function($) {
    'use strict';

    const API_URL = ROOT_DIR + 'Modules/Custom/LaneAssist/ChangeTargetFaces/api.php';
    const FILTER_DELAY_MS = 300;

    const state = {
        groups: [],
        archers: [],
        canWrite: false,
        busy: false,
        gate: ChangeTargetFacesGate.createRequestGate(),
        filterTimer: null
    };

    function escapeHtml(text) {
        return String(text == null ? '' : text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function init() {
        $('.tf-filter').on('input change', function() {
            state.gate.markDirty();
            clearTimeout(state.filterTimer);
            state.filterTimer = setTimeout(load, FILTER_DELAY_MS);
        });
        $('#tf-body').on('click', 'button.tf-cell', function() {
            setOne(parseInt($(this).data('en'), 10), String($(this).data('group')));
        });
        $('#tf-head').on('click', 'button.tf-set-all', function() {
            setAll(String($(this).data('group')));
        });
        load();
    }

    /** The filters as sent to the API; the server re-runs them for Set all. */
    function filters() {
        const out = {};
        $('.tf-filter').each(function() {
            out[$(this).data('key')] = $(this).val();
        });
        return out;
    }

    function showStatus(message, type) {
        $('#status-message').removeClass('hidden status-info status-success status-error')
            .addClass('status-' + type).text(message);
    }

    function hideStatus() {
        $('#status-message').addClass('hidden').text('');
    }

    function setBusy(busy) {
        state.busy = busy;
        $('.tf-cell, .tf-set-all').prop('disabled', busy || !state.canWrite);
        $('.tf-cell.tf-illegal').prop('disabled', true);
    }

    function load() {
        const sent = filters();
        const token = state.gate.startLoad(sent);
        $.ajax({
            url: API_URL,
            method: 'GET',
            dataType: 'json',
            data: $.extend({ action: 'load' }, sent),
            success: function(response) {
                if (!state.gate.finishLoad(token)) {
                    return; // a newer filter or a write overtook this response
                }
                if (response.error) {
                    showStatus(response.message || 'Loading archers failed', 'error');
                    return;
                }
                state.groups = response.groups;
                state.archers = response.archers;
                state.canWrite = !!response.canWrite;
                $('#readonly-note').toggleClass('hidden', state.canWrite).text(response.readOnlyReason || '');
                renderFaceFilter();
                render();
            },
            error: function() {
                state.gate.failLoad(token);
                showStatus('Loading archers failed', 'error');
            }
        });
    }

    /** Fill the Current face filter once groups are known, keeping the selection. */
    function renderFaceFilter() {
        const $select = $('#f-face');
        const current = $select.val();
        let html = '<option value="">All</option>';
        state.groups.forEach(function(g) {
            html += '<option value="' + escapeHtml(g.key) + '">' + escapeHtml(g.label) + '</option>';
        });
        $select.html(html).val(current);
    }

    function render() {
        const n = state.archers.length;
        $('#tf-count').text(n + ' shown');

        let head = '<tr><th>Code</th><th>Archer</th><th>Club</th><th>Div</th><th>Cl</th>';
        state.groups.forEach(function(g) {
            head += '<th class="tf-group-head">' + escapeHtml(g.label) + '</th>';
        });
        head += '</tr><tr class="tf-set-all-row"><th colspan="5"></th>';
        state.groups.forEach(function(g) {
            head += '<th><button type="button" class="btn btn-secondary tf-set-all" data-group="' + escapeHtml(g.key) + '"'
                + (n ? '' : ' disabled') + '>Set all ' + n + '</button></th>';
        });
        $('#tf-head').html(head + '</tr>');

        if (!state.groups.length) {
            $('#tf-body').html('<tr><td colspan="5" class="tf-empty">This tournament has no target faces defined.</td></tr>');
        } else if (!n) {
            $('#tf-body').html('<tr><td colspan="' + (5 + state.groups.length) + '" class="tf-empty">No archers match the filter.</td></tr>');
        } else {
            $('#tf-body').html(state.archers.map(rowHtml).join(''));
        }
        setBusy(state.busy);
    }

    function rowHtml(a) {
        let marker = '';
        if (a.noFace) {
            marker = ' <span class="tf-marker" title="No target face is defined for ' + escapeHtml(a.div + a.cls) + '">no face defined</span>';
        } else if (a.currentIllegal) {
            marker = ' <span class="tf-marker" title="IANSEO will reset this to the default">current face not allowed</span>';
        }
        let html = '<tr data-en="' + a.id + '"><td>' + escapeHtml(a.code) + '</td>'
            + '<td>' + escapeHtml(a.name) + marker + '</td>'
            + '<td title="' + escapeHtml(a.coName) + '">' + escapeHtml(a.coCode) + '</td>'
            + '<td class="tf-center">' + escapeHtml(a.div) + '</td>'
            + '<td class="tf-center">' + escapeHtml(a.cls) + '</td>';
        state.groups.forEach(function(g) {
            const legal = !!a.cells[g.key];
            const selected = a.selected === g.key;
            const cls = 'tf-cell' + (selected ? ' tf-selected' : '') + (legal ? '' : ' tf-illegal');
            const title = legal ? g.label : g.label + ' is not allowed for ' + a.div + a.cls;
            html += '<td class="tf-center"><button type="button" class="' + cls + '" data-en="' + a.id + '" data-group="' + escapeHtml(g.key) + '"'
                + ' title="' + escapeHtml(title) + '" aria-pressed="' + (selected ? 'true' : 'false') + '"></button></td>';
        });
        return html + '</tr>';
    }

    function setOne(enId, groupKey) {
        if (state.busy || !state.canWrite) {
            return;
        }
        setBusy(true);
        $.ajax({
            url: API_URL,
            method: 'POST',
            dataType: 'json',
            data: { action: 'set', enId: enId, group: groupKey },
            success: function(response) {
                if (response.error) {
                    showStatus(response.message || 'Changing the face failed', 'error');
                    return;
                }
                hideStatus();
                const i = state.archers.findIndex(function(a) { return a.id === enId; });
                if (i >= 0 && response.archer) {
                    state.archers[i] = response.archer;
                    $('#tf-body tr[data-en="' + enId + '"]').replaceWith(rowHtml(response.archer));
                }
                // Reload so the row drops out of a Current face filter it no
                // longer matches, and so no older load can paint the old face back.
                state.gate.noteWrite();
                load();
            },
            error: function() {
                showStatus('Changing the face failed', 'error');
            },
            complete: function() {
                setBusy(false);
            }
        });
    }

    function setAll(groupKey) {
        const group = state.groups.find(function(g) { return g.key === groupKey; });
        const n = state.archers.length;
        if (!group || !n || state.busy || !state.canWrite) {
            return;
        }
        // Only the filters of the rows on screen; refuse while a newer filter is pending.
        const request = state.gate.setAllRequest(n);
        if (!request) {
            showStatus('The list is still updating. Try again in a moment.', 'info');
            return;
        }
        if (!window.confirm('Set ' + group.label + ' for ' + n + ' archers?\nArchers it isn\'t allowed for are skipped.')) {
            return;
        }
        setBusy(true);
        $.ajax({
            url: API_URL,
            method: 'POST',
            dataType: 'json',
            data: $.extend({ action: 'setAll', group: groupKey, expectedCount: request.expectedCount }, request.filters),
            success: function(response) {
                if (response.error) {
                    showStatus(response.message || 'Changing faces failed', 'error');
                    if (response.stale) {
                        load();
                    }
                    return;
                }
                showStatus(summary(response), 'success');
                state.gate.noteWrite();
                load();
            },
            error: function() {
                showStatus('Changing faces failed', 'error');
            },
            complete: function() {
                setBusy(false);
            }
        });
    }

    /** "Changed 38, unchanged 0, skipped 4 (not allowed: CM, CW)" */
    function summary(r) {
        const skippedCats = Object.keys(r.skipped || {});
        const skipped = skippedCats.reduce(function(sum, k) { return sum + r.skipped[k]; }, 0);
        let text = 'Changed ' + r.changed + ', unchanged ' + r.unchanged + ', skipped ' + skipped;
        if (skipped) {
            text += ' (not allowed: ' + skippedCats.join(', ') + ')';
        }
        return text;
    }

    $(init);
})(jQuery);
