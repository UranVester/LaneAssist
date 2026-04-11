(function($) {
    'use strict';

    const hasCompetition = parseInt((typeof LANEASSIST_HAS_COMPETITION !== 'undefined' ? LANEASSIST_HAS_COMPETITION : 0), 10) > 0;
    const isDebugMode = parseInt((typeof LANEASSIST_DEBUG_MODE !== 'undefined' ? LANEASSIST_DEBUG_MODE : 0), 10) > 0;
    let updateInfo = null;

    function init() {
        bindEvents();
        loadSettings();
        checkUpdates();
        if (isDebugMode) {
            loadFeedbackQueue();
        }
    }

    function bindEvents() {
        $('#btn-save-admin-settings').on('click', saveAdminSettings);
        $('#btn-save-global-settings').on('click', saveGlobalSettings);
        $('#btn-save-competition-settings').on('click', saveCompetitionSettings);
        $('#btn-send-feedback').on('click', sendFeedback);
        $('#btn-donate').on('click', donateIntent);
        $('#btn-refresh-feedback-debug').on('click', loadFeedbackQueue);
        $('#btn-apply-update-file').on('click', applyUpdateFromFile);
        $('#btn-check-updates').on('click', checkUpdates);
        $('#btn-apply-update-github').on('click', applyUpdateFromGithub);
    }

    function checkUpdates() {
        if ($('#update-github-summary').length) {
            $('#update-github-summary').text('Checking for updates...');
        }
        if ($('#update-github-meta').length) {
            $('#update-github-meta').text('');
        }
        if ($('#btn-apply-update-github').length) {
            $('#btn-apply-update-github').prop('disabled', true);
        }

        $.ajax({
            url: ROOT_DIR + 'Modules/Custom/LaneAssist/Settings/api.php',
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'checkUpdates'
            },
            success: function(response) {
                if (response.error) {
                    updateInfo = null;
                    if ($('#update-github-summary').length) {
                        $('#update-github-summary').text(response.message || 'Update check failed');
                    }
                    showStatus(response.message || 'Update check failed', 'error');
                    return;
                }

                updateInfo = response;
                const hasUpdate = parseInt(response.hasUpdate, 10) > 0;
                const currentVersion = (response.currentVersion || 'unknown').toString();
                const latestVersion = (response.latestVersion || 'unknown').toString();
                const published = (response.publishedAt || '').toString();
                const signed = response.signature && parseInt(response.signature.ok, 10) > 0;

                if ($('#update-github-summary').length) {
                    $('#update-github-summary').text(hasUpdate
                        ? ('Update available: ' + latestVersion + ' (installed: ' + currentVersion + ')')
                        : ('Up to date: ' + currentVersion));
                }

                if ($('#update-github-meta').length) {
                    const bits = [];
                    if (response.releaseTag) {
                        bits.push('Tag: ' + response.releaseTag);
                    }
                    if (published) {
                        bits.push('Published: ' + published.replace('T', ' ').replace('Z', ' UTC'));
                    }
                    bits.push(signed ? 'Signature: verified' : 'Signature: not verified');
                    $('#update-github-meta').text(bits.join(' | '));
                }

                if ($('#btn-apply-update-github').length) {
                    $('#btn-apply-update-github').prop('disabled', !hasUpdate);
                }
            },
            error: function() {
                updateInfo = null;
                if ($('#update-github-summary').length) {
                    $('#update-github-summary').text('Update check failed');
                }
                showStatus('Update check failed', 'error');
            }
        });
    }

    function applyUpdateFromGithub() {
        if (!$('#btn-apply-update-github').length) {
            return;
        }

        if (!updateInfo || parseInt(updateInfo.hasUpdate, 10) <= 0) {
            showStatus('No update is available right now', 'info');
            return;
        }

        if (!window.confirm('Install update ' + (updateInfo.latestVersion || '') + ' now?')) {
            return;
        }

        $('#btn-apply-update-github').prop('disabled', true);
        $('#update-github-summary').text('Installing update...');

        $.ajax({
            url: ROOT_DIR + 'Modules/Custom/LaneAssist/Settings/api.php',
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'applyUpdateFromGithub'
            },
            success: function(response) {
                if (response.error) {
                    $('#update-github-summary').text(response.message || 'Update install failed');
                    showStatus(response.message || 'Update install failed', 'error');
                    return;
                }

                const message = (response.message || 'Update installed') +
                    ' (written=' + (response.writtenFiles || 0) +
                    ', backup=' + (response.backedUpFiles || 0) + ')';

                $('#update-github-summary').text(message);
                if ($('#update-github-meta').length) {
                    $('#update-github-meta').text(response.backupPath ? ('Backup: ' + response.backupPath) : '');
                }
                showStatus(message, 'success');
                checkUpdates();
            },
            error: function() {
                $('#update-github-summary').text('Update install failed');
                showStatus('Update install failed', 'error');
            },
            complete: function() {
                if ($('#btn-apply-update-github').length) {
                    const hasUpdate = updateInfo && parseInt(updateInfo.hasUpdate, 10) > 0;
                    $('#btn-apply-update-github').prop('disabled', !hasUpdate);
                }
            }
        });
    }

    function loadSettings() {
        $.ajax({
            url: ROOT_DIR + 'Modules/Custom/LaneAssist/Settings/api.php',
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'getSettings'
            },
            success: function(response) {
                if (response.error) {
                    showStatus(response.message || 'Failed to load settings', 'error');
                    return;
                }

                const settings = response.settings || {};
                const adminSettings = settings.admin || {};
                const userSettings = settings.user || {};
                const tournamentSettings = settings.tournament || {};

                if ($('#admin-default-finals-length').length) {
                    $('#admin-default-finals-length').val(adminSettings.defaultFinalsLength || 30);
                }
                if ($('#admin-hide-ianseo-update-menu').length) {
                    const menuSettings = adminSettings.menu || {};
                    $('#admin-hide-ianseo-update-menu').prop('checked', parseInt(menuSettings.hideIanseoUpdateEntry, 10) > 0);
                    $('#admin-hide-clone-tournament-menu').prop('checked', parseInt(menuSettings.hideCloneTournamentEntry, 10) > 0);
                    $('#admin-hide-target-faces-menu').prop('checked', parseInt(menuSettings.hideTargetFacesEntry, 10) > 0);
                }

                $('#global-default-finals-length').val(userSettings.defaultFinalsLength || 30);
                if ($('#competition-default-finals-length').length) {
                    $('#competition-default-finals-length').val(tournamentSettings.defaultFinalsLength || 0);
                }
                if ($('#competition-manage-targets-layout').length) {
                    $('#competition-manage-targets-layout').val((tournamentSettings.manageTargetsLayout || '').toString());
                }
                showStatus('Settings loaded', 'success');
            },
            error: function() {
                showStatus('Failed to load settings', 'error');
            }
        });
    }

    function saveGlobalSettings() {
        const defaultFinalsLength = parseInt($('#global-default-finals-length').val(), 10) || 0;

        $.ajax({
            url: ROOT_DIR + 'Modules/Custom/LaneAssist/Settings/api.php',
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'saveUserSettings',
                defaultFinalsLength: defaultFinalsLength
            },
            success: function(response) {
                if (response.error) {
                    showStatus(response.message || 'Failed to save settings', 'error');
                    return;
                }

                showStatus(response.message || 'Settings saved', 'success');
            },
            error: function() {
                showStatus('Failed to save settings', 'error');
            }
        });
    }

    function saveCompetitionSettings() {
        if (!hasCompetition) {
            showStatus('No competition selected', 'error');
            return;
        }

        const defaultFinalsLength = parseInt($('#competition-default-finals-length').val(), 10);
        const normalized = Number.isFinite(defaultFinalsLength) ? defaultFinalsLength : 0;
        const manageTargetsLayout = ($('#competition-manage-targets-layout').val() || '').toString().trim();

        $.ajax({
            url: ROOT_DIR + 'Modules/Custom/LaneAssist/Settings/api.php',
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'saveTournamentSettings',
                defaultFinalsLength: normalized,
                manageTargetsLayout: manageTargetsLayout
            },
            success: function(response) {
                if (response.error) {
                    showStatus(response.message || 'Failed to save competition settings', 'error');
                    return;
                }

                showStatus(response.message || 'Competition settings saved', 'success');
            },
            error: function() {
                showStatus('Failed to save competition settings', 'error');
            }
        });
    }

    function saveAdminSettings() {
        const defaultFinalsLength = parseInt($('#admin-default-finals-length').val(), 10) || 0;
        const hideIanseoUpdateEntry = $('#admin-hide-ianseo-update-menu').is(':checked') ? 1 : 0;
        const hideCloneTournamentEntry = $('#admin-hide-clone-tournament-menu').is(':checked') ? 1 : 0;
        const hideTargetFacesEntry = $('#admin-hide-target-faces-menu').is(':checked') ? 1 : 0;

        $.ajax({
            url: ROOT_DIR + 'Modules/Custom/LaneAssist/Settings/api.php',
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'saveAdminSettings',
                defaultFinalsLength: defaultFinalsLength,
                hideIanseoUpdateEntry: hideIanseoUpdateEntry,
                hideCloneTournamentEntry: hideCloneTournamentEntry,
                hideTargetFacesEntry: hideTargetFacesEntry
            },
            success: function(response) {
                if (response.error) {
                    showStatus(response.message || 'Failed to save admin settings', 'error');
                    return;
                }

                showStatus(response.message || 'Admin settings saved', 'success');
            },
            error: function() {
                showStatus('Failed to save admin settings', 'error');
            }
        });
    }

    function sendFeedback() {
        const type = ($('#feedback-type').val() || 'general').toString();
        const message = ($('#feedback-text').val() || '').toString().trim();

        if (!message) {
            showStatus('Please write a feedback message first', 'error');
            return;
        }

        $.ajax({
            url: ROOT_DIR + 'Modules/Custom/LaneAssist/Settings/api.php',
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'submitFeedback',
                type: type,
                message: message
            },
            success: function(response) {
                if (response.error) {
                    showStatus(response.message || 'Failed to send feedback', 'error');
                    return;
                }

                $('#feedback-text').val('');
                const stored = response.stored || {};
                if (isDebugMode) {
                    const g = parseInt(stored.globalCount, 10);
                    const c = parseInt(stored.competitionCount, 10);
                    const details = [];
                    if (!Number.isNaN(g)) {
                        details.push('global=' + g);
                    }
                    if (!Number.isNaN(c)) {
                        details.push('competition=' + c);
                    }
                    const suffix = details.length ? ' (' + details.join(', ') + ')' : '';
                    showStatus((response.message || 'Feedback sent') + suffix, 'success');
                } else {
                    showStatus(response.message || 'Feedback sent', 'success');
                }
                if (isDebugMode) {
                    loadFeedbackQueue();
                }
            },
            error: function() {
                showStatus('Failed to send feedback', 'error');
            }
        });
    }

    function donateIntent() {
        $.ajax({
            url: ROOT_DIR + 'Modules/Custom/LaneAssist/Settings/api.php',
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'donateIntent'
            },
            success: function(response) {
                if (response.error) {
                    showStatus(response.message || 'Donate action failed', 'error');
                    return;
                }

                showStatus(response.message || 'Donate action ready', 'info');
            },
            error: function() {
                showStatus('Donate action failed', 'error');
            }
        });
    }

    function applyUpdateFromFile() {
        const input = $('#update-file-input')[0];
        const signatureB64 = ($('#update-signature-text').val() || '').toString().trim();

        if (!input || !input.files || !input.files.length) {
            showStatus('Please select a ZIP file first', 'error');
            return;
        }

        if (!signatureB64) {
            showStatus('Please paste the signature value first', 'error');
            return;
        }

        const file = input.files[0];
        const formData = new FormData();
        formData.append('action', 'applyUpdateFromFile');
        formData.append('updateFile', file);
        formData.append('updateSigB64', signatureB64);

        $('#btn-apply-update-file').prop('disabled', true);
        $('#update-file-result').text('Applying update file...');

        $.ajax({
            url: ROOT_DIR + 'Modules/Custom/LaneAssist/Settings/api.php',
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(response) {
                if (response.error) {
                    $('#update-file-result').text(response.message || 'Update file apply failed');
                    showStatus(response.message || 'Update file apply failed', 'error');
                    return;
                }

                const msg = (response.message || 'Update file applied') +
                    ' (written=' + (response.writtenFiles || 0) +
                    ', backup=' + (response.backedUpFiles || 0) + ')';
                $('#update-file-result').text(msg + (response.backupPath ? (' | backup: ' + response.backupPath) : ''));
                showStatus(msg, 'success');
            },
            error: function() {
                $('#update-file-result').text('Update file apply failed');
                showStatus('Update file apply failed', 'error');
            },
            complete: function() {
                $('#btn-apply-update-file').prop('disabled', false);
            }
        });
    }

    function loadFeedbackQueue() {
        if (!isDebugMode || !$('#feedback-debug-body').length) {
            return;
        }

        $('#feedback-debug-body').html('<tr><td colspan="6">Loading…</td></tr>');
        $.ajax({
            url: ROOT_DIR + 'Modules/Custom/LaneAssist/Settings/api.php',
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'getFeedbackQueue'
            },
            success: function(response) {
                if (response.error) {
                    $('#feedback-debug-body').html('<tr><td colspan="6">' + escapeHtml(response.message || 'Failed to load feedback queue') + '</td></tr>');
                    return;
                }

                const items = Array.isArray(response.items) ? response.items : [];
                renderFeedbackQueue(items);
                $('#feedback-debug-summary').text((response.count || items.length) + ' feedback item(s) loaded');
            },
            error: function() {
                $('#feedback-debug-body').html('<tr><td colspan="6">Failed to load feedback queue</td></tr>');
            }
        });
    }

    function renderFeedbackQueue(items) {
        if (!items.length) {
            $('#feedback-debug-body').html('<tr><td colspan="6">No feedback stored yet</td></tr>');
            return;
        }

        const rows = items.map(function(item) {
            const when = escapeHtml((item.createdAt || '').toString());
            const author = escapeHtml((item.author || 'Unknown').toString());
            const scope = escapeHtml((item.scope || '').toString());
            const type = escapeHtml((item.type || '').toString());
            const tournament = parseInt(item.tournament, 10) || 0;
            const message = escapeHtml((item.message || '').toString());
            return '<tr>' +
                '<td>' + (when || '-') + '</td>' +
                '<td>' + (author || 'Unknown') + '</td>' +
                '<td>' + (scope || '-') + '</td>' +
                '<td>' + (type || '-') + '</td>' +
                '<td>' + (tournament > 0 ? tournament : '-') + '</td>' +
                '<td>' + message + '</td>' +
                '</tr>';
        });

        $('#feedback-debug-body').html(rows.join(''));
    }

    function escapeHtml(text) {
        return text
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function showStatus(text, type) {
        const $status = $('#settings-status');
        $status.removeClass('hidden status-info status-success status-error')
            .addClass('status-' + type)
            .text(text);

        if (type === 'success') {
            setTimeout(function() {
                $status.addClass('hidden');
            }, 2500);
        }
    }

    $(document).ready(init);

})(jQuery);
