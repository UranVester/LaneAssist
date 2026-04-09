(function($) {
    'use strict';

    const state = {
        tournaments: [],
        parts: [],
        selectedTournamentId: null
    };

    function init() {
        $('#btn-clone').on('click', cloneTournament);
        $('#new-code').on('input', function() {
            const sanitized = sanitizeTournamentCodeLikeCore($(this).val());
            if ($(this).val() !== sanitized) {
                $(this).val(sanitized);
            }
            validateCodeField(true);
        });
        loadCloneMeta();
    }

    function loadCloneMeta() {
        showStatus('Loading tournaments...', 'info');

        $.ajax({
            url: ROOT_DIR + 'Modules/Custom/LaneAssist/CloneTournament/api.php',
            method: 'POST',
            dataType: 'json',
            data: { action: 'getMeta' },
            success: function(response) {
                if (response.error) {
                    showStatus(response.message || 'Failed to load data', 'error');
                    return;
                }

                state.tournaments = response.tournaments || [];
                state.parts = response.parts || [];

                renderTournaments();
                renderParts();
                showStatus('Select a source tournament', 'info');
            },
            error: function() {
                showStatus('Failed to load data', 'error');
            }
        });
    }

    function renderTournaments() {
        const $list = $('#tournaments-list');
        $list.empty();

        if (!state.tournaments.length) {
            $list.append('<div class="empty">No tournaments found</div>');
            return;
        }

        state.tournaments.forEach(function(tour) {
            const $item = $('<div class="tour-item" role="button" tabindex="0"></div>').attr('data-tour-id', String(tour.id));
            $item.append('<div class="tour-title">' + escapeHtml(tour.code + ' - ' + tour.name) + '</div>');
            $item.append('<div class="tour-meta">' + escapeHtml((tour.whenFrom || '-') + ' → ' + (tour.whenTo || '-')) + '</div>');
            $item.on('click', function() {
                selectTournament(tour.id);
            });
            $item.on('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    selectTournament(tour.id);
                }
            });
            $list.append($item);
        });
    }

    function renderParts() {
        const $parts = $('#parts-list');
        $parts.empty();

        if (!state.parts.length) {
            $parts.append('<div class="empty">No clone parts available</div>');
            return;
        }

        state.parts.forEach(function(part, index) {
            const inputId = 'clone-part-' + index;
            const checkedAttr = part.defaultSelected ? ' checked' : '';
            const html =
                '<label class="part-item">' +
                    '<input type="checkbox" id="' + escapeHtml(inputId) + '" class="clone-part-check" value="' + escapeHtml(part.key) + '"' + checkedAttr + ' disabled>' +
                    '<span class="part-text"><strong>' + escapeHtml(part.label) + '</strong><br>' + escapeHtml(part.description || '') + '</span>' +
                '</label>';
            $parts.append(html);
        });
    }

    function selectTournament(tourId) {
        const selected = state.tournaments.find(function(t) { return parseInt(t.id, 10) === parseInt(tourId, 10); });
        if (!selected) {
            return;
        }

        state.selectedTournamentId = parseInt(selected.id, 10);

        $('.tour-item').removeClass('selected');
        $('.tour-item[data-tour-id="' + selected.id + '"]').addClass('selected');

        $('#selected-source').removeClass('empty').text(selected.code + ' - ' + selected.name);
        $('#new-name').prop('disabled', false);
        $('#new-code').prop('disabled', false);
        $('#new-name').val(selected.name + ' (Clone)');
        $('#new-code').val((selected.code || '').toString());
        $('.clone-part-check').prop('disabled', false);
        validateCodeField(true);
    }

    function cloneTournament() {
        if (!state.selectedTournamentId) {
            showStatus('Select a source tournament first', 'error');
            return;
        }

        const newName = ($('#new-name').val() || '').toString().trim();
        if (!newName) {
            showStatus('Enter a name for the new tournament', 'error');
            return;
        }

        const newCode = ($('#new-code').val() || '').toString().trim();
        if (!newCode) {
            showStatus('Enter a competition code', 'error');
            return;
        }
        if (newCode.length > 8) {
            showStatus('Competition code must be max 8 characters', 'error');
            return;
        }
        if (isCodeAlreadyUsed(newCode)) {
            showStatus('Competition code already exists', 'error');
            return;
        }

        const selectedParts = $('.clone-part-check:checked').map(function() {
            return $(this).val();
        }).get();

        if (!selectedParts.length) {
            showStatus('Select at least one part to clone', 'error');
            return;
        }

        $('#btn-clone').prop('disabled', true);
        showStatus('Cloning tournament...', 'info');

        $.ajax({
            url: ROOT_DIR + 'Modules/Custom/LaneAssist/CloneTournament/api.php',
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'cloneTournament',
                sourceTournamentId: state.selectedTournamentId,
                newName: newName,
                newCode: newCode,
                parts: JSON.stringify(selectedParts)
            },
            success: function(response) {
                $('#btn-clone').prop('disabled', false);

                if (response.error) {
                    showStatus(response.message || 'Clone failed', 'error');
                    return;
                }

                const message = 'Cloned as ' + (response.newCode || '') + ' - ' + (response.newName || '') + ' (ID ' + (response.newTournamentId || '?') + ')';
                showStatus(message, 'success');
                loadCloneMeta();
            },
            error: function() {
                $('#btn-clone').prop('disabled', false);
                showStatus('Clone failed', 'error');
            }
        });
    }

    function showStatus(text, type) {
        const $status = $('#status-message');
        $status.removeClass('hidden status-info status-success status-error')
            .addClass('status-' + type)
            .text(text || '');
    }

    function escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            '\'': '&#039;'
        };
        return (text || '').toString().replace(/[&<>"']/g, function(m) { return map[m]; });
    }

    function sanitizeTournamentCodeLikeCore(value) {
        let code = (value || '').toString();
        code = code.replace(/[^0-9a-z._-]+/gi, '_');
        return code.substring(0, 8);
    }

    function isCodeAlreadyUsed(code) {
        const value = (code || '').toString().trim().toLowerCase();
        if (!value) {
            return false;
        }

        return state.tournaments.some(function(tour) {
            return ((tour.code || '').toString().trim().toLowerCase() === value);
        });
    }

    function validateCodeField(showMessage) {
        const code = ($('#new-code').val() || '').toString().trim();
        const hasSelectedTournament = !!state.selectedTournamentId;
        let isValid = true;
        let message = '';

        if (!hasSelectedTournament) {
            isValid = false;
            message = 'Select a source tournament first';
        } else if (!code) {
            isValid = false;
            message = 'Enter a competition code';
        } else if (code.length > 8) {
            isValid = false;
            message = 'Competition code must be max 8 characters';
        } else if (isCodeAlreadyUsed(code)) {
            isValid = false;
            message = 'Competition code already exists';
        }

        $('#new-code').toggleClass('error', !isValid);
        $('#btn-clone').prop('disabled', !isValid);

        if (showMessage) {
            if (isValid) {
                showStatus('Ready to clone selected tournament', 'info');
            } else {
                showStatus(message, 'error');
            }
        }

        return isValid;
    }

    $(document).ready(init);

})(jQuery);
