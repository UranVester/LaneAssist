(function($) {
    'use strict';

    var state = { mode: 'qualification', initialModeResolved: false, loading: false, refreshPending: false, bulkAdvancing: false, timer: null, actionMessage: '', snapshot: null, qualificationNotice: '', finalsNotice: '', qualificationForfeitEligible: [], colorBy: 'none' };
    var apiUrl = ROOT_DIR + 'Modules/Custom/LaneAssist/LiveView/api.php';
    var divisionMetaMap = (typeof DivisionMeta !== 'undefined' && DivisionMeta) ? DivisionMeta : {};
    var classMetaMap = (typeof ClassMeta !== 'undefined' && ClassMeta) ? ClassMeta : {};
    var ColorBy = window.LaneAssist && window.LaneAssist.colorBy;

    var QUAL_COLOR_OPTIONS = [
        { value: 'none', label: 'None' },
        { value: 'country', label: 'Country/Club' },
        { value: 'class', label: 'Class (Age/Gender)' },
        { value: 'division', label: 'Division (Bow type)' }
    ];
    var FINALS_COLOR_OPTIONS = [
        { value: 'none', label: 'None' },
        { value: 'country', label: 'Country/Club' },
        { value: 'event', label: 'Event' },
        { value: 'division', label: 'Division (Bow type)' },
        { value: 'class', label: 'Class (Age/Gender)' }
    ];

    function escapeHtml(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
    }

    function normalizeColorValue(value, fallback) {
        var text = (value == null ? '' : String(value)).trim();
        return text !== '' ? text : (fallback || 'Unknown');
    }

    function getClassInfo(classId) {
        var id = normalizeColorValue(classId, '');
        var entry = id !== '' ? classMetaMap[id] : null;
        var description = entry && entry.description ? String(entry.description).trim() : '';
        if (description && id) return { id: id, label: description + ' (' + id + ')' };
        if (description) return { id: description, label: description };
        if (id) return { id: id, label: id };
        return { id: 'No Class', label: 'No Class' };
    }

    function getDivisionInfo(divisionId) {
        var id = normalizeColorValue(divisionId, '');
        var entry = id !== '' ? divisionMetaMap[id] : null;
        var description = entry && entry.description ? String(entry.description).trim() : '';
        if (description || id) {
            return { id: id || description, description: description || id };
        }
        return { id: 'No Division', description: 'No Division' };
    }

    function getColorGrouping(entity, mode) {
        if (!mode || mode === 'none') return null;

        if (mode === 'country') {
            var club = normalizeColorValue(entity.club, 'No Club');
            return { key: 'country|' + club, label: 'Country/Club: ' + club };
        }

        if (mode === 'class') {
            var classInfo = getClassInfo(entity.class);
            return { key: 'class|' + classInfo.id, label: 'Class: ' + classInfo.label };
        }

        if (mode === 'division') {
            var divisionInfo = getDivisionInfo(entity.division);
            var bowType = ColorBy.extractBowType(divisionInfo.description, divisionInfo.id);
            return { key: 'division|' + bowType, label: 'Division (Bow): ' + bowType };
        }

        if (mode === 'event') {
            var div = (entity.division == null ? '' : String(entity.division)).trim();
            var cls = (entity.class == null ? '' : String(entity.class)).trim();
            var key = normalizeColorValue(div + cls, 'No Event');
            return { key: 'event|' + key, label: 'Event: ' + key };
        }

        return null;
    }

    function colorByAttrs(entity) {
        var grouping = getColorGrouping(entity, state.colorBy);
        if (!grouping) return { classAttr: '', styleAttr: '', titleAttr: '' };
        var palette = ColorBy.getColorPalette(grouping.key);
        return {
            classAttr: ' colorized',
            styleAttr: ' style="--group-color:' + palette.color + ';--group-bg:' + palette.bg + '"',
            titleAttr: ' title="' + escapeHtml(grouping.label) + '"'
        };
    }

    function updateColorByOptions() {
        if (state.colorByOptionsMode === state.mode) return;
        state.colorByOptionsMode = state.mode;
        var options = state.mode === 'finals' ? FINALS_COLOR_OPTIONS : QUAL_COLOR_OPTIONS;
        var validValues = options.map(function(opt) { return opt.value; });
        if (validValues.indexOf(state.colorBy) === -1) {
            state.colorBy = 'none';
        }
        $('#color-by').html(options.map(function(opt) {
            return '<option value="' + opt.value + '"' + (opt.value === state.colorBy ? ' selected' : '') + '>' + escapeHtml(opt.label) + '</option>';
        }).join(''));
    }

    function targetFace(label) {
        return '<div class="target-illustration" aria-hidden="true"><span>' + escapeHtml(label || '-') + '</span></div>';
    }

    function positionFaceStyleAttr(archer) {
        return (archer && archer.targetFaceUrl)
            ? ' style="background-image:url(&quot;' + escapeHtml(archer.targetFaceUrl) + '&quot;)"'
            : '';
    }

    function renderQualification(mats) {
        // expectedEnds is the most-common completed-end count, not the max, so
        // it can stay 0 even once several archers have shot — it's the wrong
        // signal for "has anyone started." Use the literal signal instead.
        var sessionStarted = mats.some(function(mat) {
            return mat.archers.some(function(archer) {
                return !archer.retired && archer.arrowsShot > 0;
            });
        });
        var forfeitEligible = [];
        mats.forEach(function(mat) {
            mat.archers.forEach(function(archer) {
                if (!archer.retired && archer.arrowsShot === 0 && sessionStarted) {
                    forfeitEligible.push(archer.participantId);
                }
            });
        });
        state.qualificationForfeitEligible = forfeitEligible;
        state.qualificationNotice = forfeitEligible.length
            ? '<div class="notice bye-notice"><span><i class="fa fa-bell"></i><strong>' + forfeitEligible.length + '</strong> archer' + (forfeitEligible.length === 1 ? '' : 's') + ' with 0 arrows shot.</span>' +
              '<button type="button" id="forfeit-all-noshows" class="bulk-advance-button"><i class="fa fa-ban" aria-hidden="true"></i> Forfeit all no-shows</button></div>'
            : '';

        var html = mats.map(function(mat) {
            var archers = mat.archers.map(function(archer) {
                var problemClass = archer.isBehind ? ' danger' : (archer.isAhead ? ' warning' : '');
                var retiredClass = archer.retired ? ' retired' : '';
                var endPoints = archer.lastEndPoints === null ? '-' : archer.lastEndPoints;
                var badgeHtml = (window.LaneAssist && window.LaneAssist.badges)
                    ? window.LaneAssist.badges.render(archer.badges)
                    : {edge: '', inline: ''};
                var toggleHtml = '';
                if (archer.retired || (archer.arrowsShot === 0 && sessionStarted)) {
                    toggleHtml = '<button type="button" class="retire-toggle" data-participant-id="' + archer.participantId + '"'
                        + (archer.retired ? ' data-retired="1">Un-forfeit</button>' : '>Forfeit</button>');
                }
                var colorAttrs = colorByAttrs(archer);
                var endsSuffix = archer.completedEnds === mat.expectedEnds ? '' : ' · ' + archer.completedEnds + ' ends';
                var clubEventLabel = escapeHtml(archer.club) + (archer.event ? ' · ' + escapeHtml(archer.event) : '');
                return '<div class="competitor' + problemClass + retiredClass + '" data-participant-id="' + archer.participantId + '">' + badgeHtml.edge +
                    '<div class="competitor-position"><span class="position-label">' + escapeHtml(archer.position) + '</span><span class="position-face"' + positionFaceStyleAttr(archer) + '></span></div>' +
                    '<div class="competitor-main' + colorAttrs.classAttr + '"' + colorAttrs.styleAttr + colorAttrs.titleAttr + '><strong>' + escapeHtml(archer.name) + '</strong>' + badgeHtml.inline + '<small>' + clubEventLabel + endsSuffix + '</small></div>' +
                    '<div class="competitor-actions"><div class="score-pair"><span><small>Last end</small><b>' + endPoints + '</b></span><span><small>Total</small><b>' + archer.totalPoints + '</b></span></div>' + toggleHtml + '</div></div>';
            }).join('');
            var paceLabel = mat.hasMultipleDistances
                ? 'Pace: distance ' + mat.expectedDistance + ' · end ' + mat.expectedEnds
                : 'Pace: end ' + mat.expectedEnds;
            return '<article class="live-card qual-card"><header>' + targetFace(mat.target) + '<div><span class="eyebrow">Target / mat</span><h3>' + escapeHtml(mat.target) + '</h3><small>' + paceLabel + '</small></div></header><div class="competitors">' + archers + '</div></article>';
        }).join('');
        $('#qualification-view').html(html);
    }

    function statusLabel(status) {
        return { bye: 'Bye ready', complete: 'Complete', advanced: 'Advanced', unreported: 'No arrows', uneven: 'Uneven ends', partial: 'Partial end', waiting: 'Waiting', live: 'Live' }[status] || status;
    }

    function phaseLabel(phase) {
        return { 0: 'Gold', 1: 'Bronze', 2: '1/2', 4: '1/4', 8: '1/8', 16: '1/16', 32: '1/32', 64: '1/64' }[phase] || ('Phase ' + phase);
    }

    function finalBlockLabel(matches, slot) {
        if (!matches.length) return 'No current finals block';
        var scopes = [];
        matches.forEach(function(match) {
            var label = phaseLabel(match.phase) + ' ' + match.event;
            if (scopes.indexOf(label) === -1) scopes.push(label);
        });
        var time = slot ? new Date(slot.replace(' ', 'T')).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '';
        return 'Current finals: ' + scopes.join(', ') + (time ? ' · ' + time : '');
    }

    function renderFinals(matches) {
        var byeCount = matches.filter(function(match) { return match.status === 'bye' && match.canMarkBye; }).length;
        $('#final-count').text(matches.length);
        state.finalsNotice = byeCount ? '<div class="notice bye-notice"><span><i class="fa fa-bell"></i><strong>' + byeCount + '</strong> bye match' + (byeCount === 1 ? '' : 'es') + ' ready to advance.</span>' +
            '<button type="button" id="advance-all-byes" class="bulk-advance-button"><i class="fa fa-step-forward" aria-hidden="true"></i> Mark all byes &amp; advance</button></div>' : '';
        var html = matches.map(function(match) {
            var sides = match.sides.map(function(side) {
                var winner = side.winLose || side.tie === 2;
                var scores;
                if (match.matchMode) {
                    var sets = (side.setPoints || []).slice(0, 5).map(function(points, index) {
                        return '<span class="set-point"><small>S' + (index + 1) + '</small><b>' + (points === null ? '-' : escapeHtml(points)) + '</b></span>';
                    }).join('');
                    scores = '<div class="final-scores set-system"><div class="set-points">' + sets + '</div><span class="set-score"><small>Sets</small><b>' + side.setScore + '</b></span></div>';
                } else {
                    scores = '<div class="final-scores"><span><small>Total</small><b>' + side.score + '</b></span></div>';
                }
                var colorAttrs = colorByAttrs(side);
                return '<div class="final-side' + (winner ? ' winner' : '') + '"><div class="final-side-name' + colorAttrs.classAttr + '"' + colorAttrs.styleAttr + colorAttrs.titleAttr + '><strong>' + escapeHtml(side.name || 'Awaiting opponent') + '</strong><small>' + side.completedEnds + ' ends reported</small></div>' + scores + '</div>';
            }).join('');
            var canAct = match.canAdvance || match.canMarkBye;
            var actionLabel = match.status === 'bye' ? (match.canAdvance ? 'Mark bye & advance' : 'Mark bye') : 'Advance winner';
            var action = canAct ? '<button type="button" class="advance-button" data-team="' + match.teamEvent + '" data-event="' + escapeHtml(match.event) + '" data-match="' + match.matchNo + '"><i class="fa fa-step-forward"></i> ' + actionLabel + '</button>' : '';
            if (match.advanced) action = '<span class="advanced-mark"><i class="fa fa-check-circle"></i> Advanced to next bracket</span>';
            return '<article class="live-card final-card status-' + match.status + '"><header>' + targetFace(match.target) + '<div><span class="eyebrow">' + escapeHtml(match.eventName) + '</span><h3>' + escapeHtml(phaseLabel(match.phase)) + '</h3><small>' + escapeHtml(match.target ? 'Target ' + match.target : 'Unassigned') + '</small></div><span class="status-pill">' + statusLabel(match.status) + '</span></header><div class="final-sides">' + sides + '</div><footer>' + action + '</footer></article>';
        }).join('');
        $('#finals-view').html(html);
    }

    function updateProgress() {
        if (!state.snapshot) return;
        var progress = state.mode === 'finals' ? state.snapshot.finalsProgress : state.snapshot.qualificationProgress;
        progress = progress || {};
        var end = Number(progress.end) || 0;
        var totalEnds = Number(progress.totalEnds) || 0;
        var label = state.mode === 'finals' ? 'Current finals end' : 'Current qualification end';
        var value = state.mode === 'finals' && progress.complete
            ? 'Finals complete'
            : (end ? 'End ' + end + (totalEnds ? ' / ' + totalEnds : '') : 'Not started');
        if (state.mode === 'qualification' && progress.hasMultipleDistances) {
            value += '<small>Distance ' + progress.expectedDistance + ' · end ' + progress.currentDistanceEnd + '</small>';
        }
        if (state.mode === 'qualification' && Number(progress.totalArrows)) {
            value += '<small>' + Number(progress.arrowsShot || 0) + ' / ' + Number(progress.totalArrows) + ' arrows</small>';
        }
        $('#live-progress').html('<span>' + label + '</span><strong>' + value + '</strong>');
    }

    function renderNotices() {
        var notices = state.actionMessage ? '<div class="notice success"><i class="fa fa-check"></i>' + escapeHtml(state.actionMessage) + '</div>' : '';
        notices += state.mode === 'finals' ? (state.finalsNotice || '') : (state.qualificationNotice || '');
        $('#live-notices').html(notices);
    }

    function updateVisibleView() {
        var isFinals = state.mode === 'finals';
        $('#qualification-view').prop('hidden', isFinals);
        $('#finals-view').prop('hidden', !isFinals);
        $('.mode-button').removeClass('active').filter('[data-mode="' + state.mode + '"]').addClass('active');
        $('label[for="session-select"]').toggle(!isFinals);
        updateColorByOptions();
        var hasCards = $(isFinals ? '#finals-view' : '#qualification-view').children().length > 0;
        $('#empty-state').prop('hidden', hasCards);
        renderNotices();
        updateProgress();
    }

    function loadSnapshot(manual) {
        if (state.bulkAdvancing) {
            if (manual) state.refreshPending = true;
            return;
        }
        if (state.loading) {
            if (manual) state.refreshPending = true;
            return;
        }
        state.loading = true;
        $('#refresh-button i').addClass('fa-spin');
        $.ajax({
            url: apiUrl,
            method: 'GET',
            dataType: 'json',
            cache: false,
            data: { action: 'snapshot', session: $('#session-select').val() }
        }).done(function(data) {
            if (data.error) {
                $('#live-notices').html('<div class="notice error">' + escapeHtml(data.message || 'Unable to load live data') + '</div>');
                return;
            }
            state.snapshot = data;
            renderQualification(data.qualification || []);
            renderFinals(data.finals || []);
            var behind = (data.qualification || []).reduce(function(total, mat) { return total + mat.archers.filter(function(archer) { return archer.isBehind; }).length; }, 0);
            var problems = (data.finals || []).filter(function(match) { return ['unreported', 'uneven', 'partial'].indexOf(match.status) !== -1; }).length;
            $('#live-summary').text(finalBlockLabel(data.finals || [], data.finalsSlot) + ' · ' + behind + ' qualification archer' + (behind === 1 ? '' : 's') + ' behind · ' + problems + ' final match' + (problems === 1 ? '' : 'es') + ' need attention');
            $('#last-updated').text('Updated ' + new Date(data.updatedAt).toLocaleTimeString());
            if (!state.initialModeResolved) {
                state.mode = data.finalsInitialized && data.qualificationProgress && data.qualificationProgress.complete
                    ? 'finals'
                    : 'qualification';
                state.initialModeResolved = true;
            }
            updateVisibleView();
        }).fail(function(xhr) {
            var message = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Live data could not be refreshed.';
            $('#live-notices').html('<div class="notice error">' + escapeHtml(message) + '</div>');
        }).always(function() {
            state.loading = false;
            $('#refresh-button i').removeClass('fa-spin');
            if (state.refreshPending) {
                state.refreshPending = false;
                window.setTimeout(function() { loadSnapshot(true); }, 0);
            }
        });
    }

    function advanceMatch(button) {
        var $button = $(button).prop('disabled', true);
        $.ajax({ url: apiUrl, method: 'POST', dataType: 'json', headers: { 'X-Requested-With': 'XMLHttpRequest' }, data: {
            action: 'advance', teamEvent: $button.data('team'), event: $button.data('event'), matchNo: $button.data('match')
        }}).done(function(data) {
            if (data.error) {
                state.actionMessage = '';
                $('#live-notices').html('<div class="notice error">' + escapeHtml(data.message || 'Match could not be advanced') + '</div>');
                $button.prop('disabled', false);
                return;
            }
            state.actionMessage = data.message || 'Match advanced';
            loadSnapshot(true);
        }).fail(function(xhr) {
            state.actionMessage = '';
            var message = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Match could not be advanced.';
            $('#live-notices').html('<div class="notice error">' + escapeHtml(message) + '</div>');
            $button.prop('disabled', false);
        });
    }

    function applyArcherRetiredState(participantId, retired) {
        var $competitor = $('.competitor[data-participant-id="' + participantId + '"]');
        $competitor.toggleClass('retired', retired).removeClass('danger warning');
        $competitor.find('.retire-toggle')
            .data('retired', retired ? 1 : 0)
            .prop('disabled', false)
            .text(retired ? 'Un-forfeit' : 'Forfeit');
    }

    function toggleArcherRetired(button) {
        var $button = $(button);
        var retired = $button.data('retired') === 1 || $button.data('retired') === '1';
        if (!retired && !window.confirm('Forfeit this archer? They will be marked "Pull Out" and removed from Live View scoring until un-forfeited.')) {
            return;
        }
        $button.prop('disabled', true);
        $.ajax({ url: apiUrl, method: 'POST', dataType: 'json', headers: { 'X-Requested-With': 'XMLHttpRequest' }, data: {
            action: 'toggleArcherRetired', participantId: $button.data('participant-id'), session: $('#session-select').val()
        }}).done(function(data) {
            if (data.error) {
                $('#live-notices').html('<div class="notice error">' + escapeHtml(data.message || 'Could not update this archer') + '</div>');
                $button.prop('disabled', false);
                return;
            }
            // Reflect the change immediately rather than waiting on the round trip
            // of a full snapshot reload; that reload still runs in the background
            // to reconcile pace/progress/rank changes elsewhere on the page.
            applyArcherRetiredState(data.participantId, data.retired);
            loadSnapshot(true);
        }).fail(function(xhr) {
            var message = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Could not update this archer.';
            $('#live-notices').html('<div class="notice error">' + escapeHtml(message) + '</div>');
            $button.prop('disabled', false);
        });
    }

    function advanceAllByes(button) {
        if (state.bulkAdvancing || !state.snapshot) return;
        var byes = (state.snapshot.finals || []).filter(function(match) {
            return match.status === 'bye' && match.canMarkBye;
        });
        if (!byes.length) return;

        state.bulkAdvancing = true;
        state.refreshPending = false;
        var $button = $(button).prop('disabled', true).html('<i class="fa fa-refresh fa-spin" aria-hidden="true"></i> Processing 0 / ' + byes.length);
        $('.advance-button, .bulk-advance-button').prop('disabled', true);
        var advanced = 0;

        function advanceNext() {
            if (advanced >= byes.length) {
                state.bulkAdvancing = false;
                state.actionMessage = advanced + ' bye match' + (advanced === 1 ? '' : 'es') + ' processed';
                loadSnapshot(true);
                return;
            }

            var match = byes[advanced];
            $.ajax({ url: apiUrl, method: 'POST', dataType: 'json', headers: { 'X-Requested-With': 'XMLHttpRequest' }, data: {
                action: 'advance', teamEvent: match.teamEvent, event: match.event, matchNo: match.matchNo
            }}).done(function(data) {
                if (data.error) {
                    state.bulkAdvancing = false;
                    state.actionMessage = '';
                    $('#live-notices').html('<div class="notice error">Processed ' + advanced + ' of ' + byes.length + ' byes. ' + escapeHtml(data.message || 'The next bye could not be processed.') + '</div>');
                    $('.advance-button, .bulk-advance-button').prop('disabled', false);
                    return;
                }
                advanced++;
                $button.html('<i class="fa fa-refresh fa-spin" aria-hidden="true"></i> Processing ' + advanced + ' / ' + byes.length);
                advanceNext();
            }).fail(function(xhr) {
                state.bulkAdvancing = false;
                state.actionMessage = '';
                var message = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'The next bye could not be processed.';
                $('#live-notices').html('<div class="notice error">Processed ' + advanced + ' of ' + byes.length + ' byes. ' + escapeHtml(message) + '</div>');
                $('.advance-button, .bulk-advance-button').prop('disabled', false);
            });
        }

        advanceNext();
    }

    function forfeitAllNoShows(button) {
        if (state.bulkAdvancing || !state.snapshot) return;
        var participantIds = (state.qualificationForfeitEligible || []).slice();
        if (!participantIds.length) return;
        if (!window.confirm('Forfeit ' + participantIds.length + ' archer' + (participantIds.length === 1 ? '' : 's') + ' with 0 arrows shot? They will be marked "Pull Out" until un-forfeited.')) {
            return;
        }

        state.bulkAdvancing = true;
        state.refreshPending = false;
        var session = $('#session-select').val();
        $(button).prop('disabled', true).html('<i class="fa fa-refresh fa-spin" aria-hidden="true"></i> Forfeiting ' + participantIds.length + '...');
        $('.advance-button, .bulk-advance-button, .retire-toggle').prop('disabled', true);

        $.ajax({ url: apiUrl, method: 'POST', dataType: 'json', headers: { 'X-Requested-With': 'XMLHttpRequest' }, data: {
            action: 'toggleArcherRetiredBulk', participantIds: participantIds, session: session
        }}).done(function(data) {
            state.bulkAdvancing = false;
            if (data.error) {
                state.actionMessage = '';
                $('#live-notices').html('<div class="notice error">' + escapeHtml(data.message || 'Archers could not be forfeited.') + '</div>');
                $('.advance-button, .bulk-advance-button, .retire-toggle').prop('disabled', false);
                return;
            }
            (data.results || []).forEach(function(result) {
                if (result.ok) {
                    applyArcherRetiredState(result.participantId, result.retired);
                }
            });
            var failed = (data.results || []).filter(function(result) { return !result.ok; });
            state.actionMessage = data.succeeded + ' archer' + (data.succeeded === 1 ? '' : 's') + ' forfeited'
                + (failed.length ? ', ' + failed.length + ' skipped (' + failed[0].message + (failed.length > 1 ? ', ...' : '') + ')' : '');
            loadSnapshot(true);
        }).fail(function(xhr) {
            state.bulkAdvancing = false;
            state.actionMessage = '';
            var message = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Archers could not be forfeited.';
            $('#live-notices').html('<div class="notice error">' + escapeHtml(message) + '</div>');
            $('.advance-button, .bulk-advance-button, .retire-toggle').prop('disabled', false);
        });
    }

    $(function() {
        $('.mode-button').on('click', function() { state.mode = $(this).data('mode'); updateVisibleView(); });
        $('#session-select').on('change', function() { loadSnapshot(true); });
        $('#color-by').on('change', function() {
            state.colorBy = $(this).val();
            if (!state.snapshot) return;
            if (state.mode === 'finals') {
                renderFinals(state.snapshot.finals || []);
            } else {
                renderQualification(state.snapshot.qualification || []);
            }
        });
        $('#refresh-button').on('click', function() { loadSnapshot(true); });
        $(document).on('click', '.advance-button', function() { advanceMatch(this); });
        $(document).on('click', '.retire-toggle', function() { toggleArcherRetired(this); });
        $(document).on('click', '#advance-all-byes', function(event) { event.stopPropagation(); advanceAllByes(this); });
        $(document).on('click', '#forfeit-all-noshows', function(event) { event.stopPropagation(); forfeitAllNoShows(this); });
        loadSnapshot(false);
        state.timer = window.setInterval(function() { loadSnapshot(false); }, 5000);
        $(window).on('beforeunload', function() { window.clearInterval(state.timer); });
    });
})(jQuery);
