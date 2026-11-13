(function($) {
    'use strict';

    var apiUrl = ROOT_DIR + 'Modules/Custom/LaneAssist/ManageTournament/api.php';
    var settingsApiUrl = ROOT_DIR + 'Modules/Custom/LaneAssist/Settings/api.php';
    var stageLabels = { planning: 'Planning', qualification: 'Qualification', finals: 'Finals', over: 'Over' };
    var state = { snapshot: null, loading: false, settings: {} };

    function escapeHtml(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
    }

    function loadStatusSnapshot() {
        if (state.loading) {
            return;
        }
        state.loading = true;
        $('#mt-refresh i').addClass('fa-spin');
        $.ajax({ url: apiUrl, method: 'GET', dataType: 'json', cache: false, data: { action: 'statusSnapshot' } })
            .done(function(response) {
                if (response && response.error === 0) {
                    state.snapshot = response;
                    renderStatusView();
                } else {
                    $('#mt-summary').text((response && response.message) || 'Tournament status could not be loaded.');
                }
            })
            .fail(function() {
                $('#mt-summary').text('Tournament status could not be loaded.');
            })
            .always(function() {
                state.loading = false;
                $('#mt-refresh i').removeClass('fa-spin');
            });
    }

    function renderStatusView() {
        var $view = $('#mt-checklist');
        $view.empty();

        var stage = state.snapshot.stage;
        $('#mt-stage').text('Stage: ' + (stageLabels[stage] || stage)).prop('hidden', false);

        var statusLogic = window.LaneAssist.statusLogic;
        var items = statusLogic.sortChecklistItems((state.snapshot.items || []).concat(statusLogic.computeUnplayableFinalsItems(
            state.snapshot.finalsRows || [], window.LaneAssist.finalsPlayability, ROOT_DIR
        )));

        var issueCount = 0;
        items.forEach(function(item) {
            if (item.severity === 'warning' || item.severity === 'danger') {
                issueCount++;
            }

            var $card = $('<div class="status-item status-item-' + escapeHtml(item.severity) + '"></div>');
            var $header = $('<header></header>');
            $header.append('<span class="status-dot ' + (item.severity === 'info' ? '' : escapeHtml(item.severity)) + '"></span>');
            $header.append('<div><strong>' + escapeHtml(item.title) + '</strong><br><small>' + escapeHtml(item.detail) + '</small></div>');
            $card.append($header);

            if (item.rows && item.rows.length) {
                var $rows = $('<ul class="status-item-rows"></ul>');
                item.rows.forEach(function(row) {
                    var $row = $('<li></li>');
                    if (row.link) {
                        $('<a></a>').attr('href', row.link).text(row.text).appendTo($row);
                    } else {
                        $row.text(row.text);
                    }
                    $rows.append($row);
                });
                $card.append($rows);
            }

            if (item.link) {
                $card.append($('<footer></footer>').append($('<a></a>').attr('href', item.link).text('Open')));
            } else if (item.fix) {
                var $fixButton = $('<button type="button" class="status-fix-button">Fix</button>');
                $fixButton.on('click', function() {
                    applyStatusFix(item, $fixButton);
                });
                $card.append($('<footer></footer>').append($fixButton));
            }

            $view.append($card);
        });

        if (!items.length) {
            $view.append('<div class="mt-empty">Nothing to check yet.</div>');
        }
        $('#mt-summary').text(issueCount === 0
            ? 'Nothing needs attention.'
            : issueCount + ' item' + (issueCount === 1 ? ' needs' : 's need') + ' attention.');
    }

    function applyStatusFix(item, $button) {
        if (!window.confirm((item.fix.confirm || item.detail) + '?')) {
            return;
        }
        $button.prop('disabled', true);
        $.ajax({
            url: apiUrl,
            method: 'POST',
            dataType: 'json',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            data: $.extend({ action: item.fix.action }, item.fix.params),
        }).done(function(response) {
            if (response && response.error) {
                window.alert(response.message || 'The fix could not be applied.');
            }
            loadStatusSnapshot();
        }).fail(function() {
            window.alert('The fix could not be applied.');
            $button.prop('disabled', false);
        });
    }

    function showSettingsStatus(message, type) {
        $('#mt-settings-status').removeClass('hidden status-success status-error')
            .addClass(type === 'error' ? 'status-error' : 'status-success').text(message);
    }

    function renderTournamentSettings() {
        var finals = window.LaneAssist.tournamentSettings.describeFinalsLength(state.settings);
        var layout = ((state.settings.tournament || {}).manageTargetsLayout || '').toString();
        $('#competition-default-finals-length').val(finals.value);
        $('#finals-length-default-tag').prop('hidden', !finals.isDefault);
        $('#btn-reset-finals-length').prop('hidden', finals.isDefault);
        $('#finals-length-default-note').text('Default: ' + finals.defaultValue + ' minutes, from your user/admin setting.');
        $('#competition-manage-targets-layout').val(layout);
        $('#layout-default-tag').prop('hidden', layout !== '');
        $('#btn-reset-layout').prop('hidden', layout === '');
    }

    function loadTournamentSettings() {
        $.ajax({ url: settingsApiUrl, method: 'GET', dataType: 'json', cache: false, data: { action: 'getSettings' } })
            .done(function(response) {
                if (response.error) {
                    showSettingsStatus(response.message || 'Failed to load settings', 'error');
                    return;
                }
                state.settings = response.settings || {};
                renderTournamentSettings();
            })
            .fail(function() {
                showSettingsStatus('Failed to load settings', 'error');
            });
    }

    function saveTournamentSettings(defaultFinalsLength, manageTargetsLayout) {
        $.ajax({
            url: settingsApiUrl,
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'saveTournamentSettings',
                defaultFinalsLength: defaultFinalsLength,
                manageTargetsLayout: manageTargetsLayout
            }
        }).done(function(response) {
            if (response.error) {
                showSettingsStatus(response.message || 'Failed to save tournament settings', 'error');
                return;
            }
            showSettingsStatus(response.message || 'Tournament settings saved', 'success');
            loadTournamentSettings();
        }).fail(function() {
            showSettingsStatus('Failed to save tournament settings', 'error');
        });
    }

    function currentFinalsLength() {
        var finals = window.LaneAssist.tournamentSettings.describeFinalsLength(state.settings);
        return window.LaneAssist.tournamentSettings.finalsLengthToSave($('#competition-default-finals-length').val(), finals.defaultValue);
    }

    function currentLayout() {
        return ($('#competition-manage-targets-layout').val() || '').toString().trim();
    }

    $(function() {
        $('#mt-refresh').on('click', loadStatusSnapshot);
        loadStatusSnapshot();
        if (window.LANEASSIST_CAN_EDIT_SETTINGS) {
            $('#btn-save-competition-settings').on('click', function() { saveTournamentSettings(currentFinalsLength(), currentLayout()); });
            $('#btn-reset-finals-length').on('click', function() { saveTournamentSettings(0, currentLayout()); });
            $('#btn-reset-layout').on('click', function() { saveTournamentSettings(currentFinalsLength(), ''); });
            loadTournamentSettings();
        }
    });
})(jQuery);
