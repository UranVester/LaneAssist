(function($) {
    'use strict';

    // Common/js/i18n.js (loaded before this file) puts the page's translator on window.
    var laT = window.laT;
    var laTn = window.laTn;
    var TRANSLATOR = { t: laT, tn: laTn };

    var state = { mode: 'qualification', initialModeResolved: false, loading: false, refreshPending: false, bulkAdvancing: false, timer: null, actionMessage: '', snapshot: null, qualificationNotice: '', finalsNotice: '', qualificationForfeitEligible: [], colorBy: 'none', totalMode: 'combined', selectedRoundSlot: null, hasSelectedRound: false, finals: [] };
    var apiUrl = ROOT_DIR + 'Modules/Custom/LaneAssist/LiveView/api.php';
    var divisionMetaMap = (typeof DivisionMeta !== 'undefined' && DivisionMeta) ? DivisionMeta : {};
    var classMetaMap = (typeof ClassMeta !== 'undefined' && ClassMeta) ? ClassMeta : {};
    var ColorBy = window.LaneAssist && window.LaneAssist.colorBy;
    var colorIndex = ColorBy.createColorIndex(typeof ColorGroups !== 'undefined' ? ColorGroups : undefined);

    var QUAL_COLOR_OPTIONS = [
        { value: 'none', label: laT('colorBy.none') },
        { value: 'country', label: laT('colorBy.country') },
        { value: 'bow', label: laT('colorBy.bow') },
        { value: 'class', label: laT('colorBy.class') },
        { value: 'category', label: laT('colorBy.category') }
    ];
    var FINALS_COLOR_OPTIONS = QUAL_COLOR_OPTIONS.concat([
        { value: 'event', label: laT('colorBy.event') }
    ]);

    function escapeHtml(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
    }

    function normalizeColorValue(value, fallback) {
        var text = (value == null ? '' : String(value)).trim();
        return text !== '' ? text : (fallback || laT('common.unknown'));
    }

    function getClassInfo(classId) {
        var id = normalizeColorValue(classId, '');
        var entry = id !== '' ? classMetaMap[id] : null;
        var description = entry && entry.description ? String(entry.description).trim() : '';
        if (description && id) return { id: id, label: description + ' (' + id + ')' };
        if (description) return { id: description, label: description };
        if (id) return { id: id, label: id };
        return { id: laT('colorBy.noClass'), label: laT('colorBy.noClass') };
    }

    function getDivisionInfo(divisionId) {
        var id = normalizeColorValue(divisionId, '');
        var entry = id !== '' ? divisionMetaMap[id] : null;
        var description = entry && entry.description ? String(entry.description).trim() : '';
        if (description || id) {
            return { id: id || description, description: description || id };
        }
        return { id: laT('colorBy.noDivision'), description: laT('colorBy.noDivision') };
    }

    function getColorGrouping(entity, mode, match) {
        if (!mode || mode === 'none') return null;

        if (mode === 'country') {
            var club = normalizeColorValue(entity.club, laT('colorBy.noClub'));
            return { group: 'country', key: club, label: laT('colorBy.country') + ': ' + club };
        }

        if (mode === 'bow') {
            var divisionInfo = getDivisionInfo(entity.division);
            var bowType = ColorBy.extractBowType(divisionInfo.description, divisionInfo.id);
            return { group: 'bow', key: bowType, label: laT('colorBy.bow') + ': ' + ColorBy.bowLabel(bowType, TRANSLATOR) };
        }

        if (mode === 'class') {
            var classInfo = getClassInfo(entity.class);
            return { group: 'class', key: classInfo.id, label: laT('colorBy.class') + ': ' + classInfo.label };
        }

        if (mode === 'category') {
            var div = (entity.division == null ? '' : String(entity.division)).trim();
            var cls = (entity.class == null ? '' : String(entity.class)).trim();
            var category = normalizeColorValue(div + cls, laT('colorBy.noCategory'));
            return { group: 'category', key: category, label: laT('colorBy.category') + ': ' + category };
        }

        if (mode === 'event' && match && match.event) {
            return {
                group: 'event',
                key: (parseInt(match.teamEvent, 10) ? 1 : 0) + '|' + String(match.event).trim(),
                label: laT('colorBy.event') + ': ' + (match.eventName || match.event) + (parseInt(match.teamEvent, 10) ? ' (' + laT('live.team') + ')' : '')
            };
        }

        return null;
    }

    function colorByAttrs(entity, match) {
        var grouping = getColorGrouping(entity, state.colorBy, match);
        var palette = grouping ? colorIndex.colorFor(grouping.group, grouping.key) : null;
        if (!palette) return { classAttr: '', styleAttr: '', titleAttr: '' };
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

    function clubLogoImgHtml(archer) {
        return (archer && archer.clubLogoUrl)
            ? '<img class="club-logo" src="' + escapeHtml(archer.clubLogoUrl) + '" alt="">'
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
            ? '<div class="notice bye-notice"><span><i class="fa fa-bell"></i>' + escapeHtml(laTn('live.noShows', forfeitEligible.length)) + '</span>' +
              '<button type="button" id="forfeit-all-noshows" class="bulk-advance-button"><i class="fa fa-ban" aria-hidden="true"></i> ' + escapeHtml(laT('live.forfeitAllNoShows')) + '</button></div>'
            : '';

        var html = mats.map(function(mat) {
            var archers = mat.archers.map(function(archer) {
                var problemClass = archer.isBehind ? ' danger' : (archer.isAhead ? ' warning' : (archer.isEntered ? ' entered' : ''));
                var retiredClass = archer.retired ? ' retired' : '';
                var endPoints = archer.lastEndPoints === null ? '-' : archer.lastEndPoints;
                var byDistance = state.totalMode === 'distance';
                var shownTotal = byDistance ? archer.distancePoints : archer.totalPoints;
                var shownAverage = byDistance ? archer.distanceAverageArrow : archer.averageArrow;
                var totalLabel = byDistance && archer.distance ? laT('live.totalDistanceShort', { distance: archer.distance }) : laT('live.total');
                var avgHtml = typeof shownAverage === 'number'
                    ? '<span class="competitor-avg" title="' + escapeHtml(byDistance ? laT('live.avgHelpDistance') : laT('live.avgHelp')) + '">'
                        + escapeHtml(laT('live.avg', { value: shownAverage.toFixed(2) })) + '</span>'
                    : '';
                var badgeHtml = (window.LaneAssist && window.LaneAssist.badges)
                    ? window.LaneAssist.badges.render(archer.badges)
                    : {edge: '', inline: ''};
                var toggleHtml = '';
                if (archer.retired || (archer.arrowsShot === 0 && sessionStarted)) {
                    toggleHtml = '<button type="button" class="retire-toggle" data-participant-id="' + archer.participantId + '"'
                        + (archer.retired ? ' data-retired="1">' + escapeHtml(laT('live.unforfeit')) + '</button>' : '>' + escapeHtml(laT('live.forfeit')) + '</button>');
                }
                var colorAttrs = colorByAttrs(archer);
                var endsSuffix = archer.completedEnds === mat.expectedEnds ? '' : ' · ' + escapeHtml(laTn('live.nEnds', archer.completedEnds));
                var clubEventLabel = clubLogoImgHtml(archer) + escapeHtml(archer.club) + (archer.event ? ' · ' + escapeHtml(archer.event) : '');
                return '<div class="competitor' + problemClass + retiredClass + '" data-participant-id="' + archer.participantId + '">' + badgeHtml.edge +
                    '<div class="competitor-position"><span class="position-label">' + escapeHtml(archer.position) + '</span><span class="position-face"' + positionFaceStyleAttr(archer) + '></span></div>' +
                    '<div class="competitor-main' + colorAttrs.classAttr + '"' + colorAttrs.styleAttr + colorAttrs.titleAttr + '><strong>' + escapeHtml(archer.name) + '</strong>' + badgeHtml.inline + '<small class="competitor-meta"><span class="competitor-meta-label">' + clubEventLabel + endsSuffix + '</span>' + avgHtml + '</small></div>' +
                    '<div class="competitor-actions"><div class="score-pair"><span><small>' + escapeHtml(laT('live.lastEnd')) + '</small><b>' + endPoints + '</b></span><span><small>' + escapeHtml(totalLabel) + '</small><b>' + shownTotal + '</b></span></div>' + toggleHtml + '</div></div>';
            }).join('');
            var paceLabel = mat.hasMultipleDistances
                ? laT('live.paceDistance', { distance: mat.expectedDistance, end: mat.expectedEnds })
                : laT('live.paceEnd', { end: mat.expectedEnds });
            return '<article class="live-card qual-card"><header>' + targetFace(mat.target) + '<div><span class="eyebrow">' + escapeHtml(laT('live.targetMat')) + '</span><h3>' + escapeHtml(mat.target) + '</h3><small>' + escapeHtml(paceLabel) + '</small></div></header><div class="competitors">' + archers + '</div></article>';
        }).join('');
        $('#qualification-view').html(html);
    }

    function statusLabel(status) {
        return {
            bye: laT('live.statusBye'),
            complete: laT('live.statusComplete'),
            advanced: laT('live.statusAdvanced'),
            unreported: laT('live.statusUnreported'),
            uneven: laT('live.statusUneven'),
            partial: laT('live.statusPartial'),
            waiting: laT('live.statusWaiting'),
            live: laT('live.statusLive')
        }[status] || status;
    }

    function phaseLabel(phase) {
        return { 0: laT('status.phaseGold'), 1: laT('status.phaseBronze'), 2: '1/2', 4: '1/4', 8: '1/8', 16: '1/16', 32: '1/32', 64: '1/64' }[phase]
            || laT('live.phaseNumber', { phase: phase });
    }

    function finalBlockLabel(matches, slot) {
        if (!matches.length) return laT('live.noFinalsBlock');
        var scopes = [];
        matches.forEach(function(match) {
            var label = phaseLabel(match.phase) + ' ' + match.event;
            if (scopes.indexOf(label) === -1) scopes.push(label);
        });
        var time = slot ? new Date(slot.replace(' ', 'T')).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '';
        return laT('live.currentFinals', { scopes: scopes.join(', ') }) + (time ? ' · ' + time : '');
    }

    // The round the finals view is showing: the browsed one, else the current one.
    function selectedFinalRound() {
        var rounds = (state.snapshot && state.snapshot.finalsRounds) || [];
        for (var i = 0; i < rounds.length; i++) {
            if (rounds[i].slot === state.selectedRoundSlot) return rounds[i];
        }
        return currentFinalRound(rounds);
    }

    function currentFinalRound(rounds) {
        var list = rounds || [];
        for (var i = 0; i < list.length; i++) {
            if (list[i].isCurrent) return list[i];
        }
        return null;
    }

    function roundOptionLabel(round) {
        var time = round.slot
            ? new Date(round.slot.replace(' ', 'T')).toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
            : laT('live.unscheduled');
        if (!round.matches.length) return time;
        var scopes = [];
        round.matches.forEach(function(match) {
            var label = phaseLabel(match.phase) + ' ' + match.event;
            if (scopes.indexOf(label) === -1) scopes.push(label);
        });
        // Capped so a round bundling many events/phases can't blow up the
        // option's rendered width - the native dropdown sizes every row to
        // the widest option, so one long label wastes space on every other row.
        var shown = scopes.slice(0, 2).join(', ');
        var extra = scopes.length > 2 ? ' ' + laT('live.plusMore', { count: scopes.length - 2 }) : '';
        return shown + extra + ' · ' + time;
    }

    function updateRoundSelect(rounds) {
        var html = rounds.map(function(round) {
            var label = roundOptionLabel(round) + (round.isCurrent ? ' (' + laT('live.current') + ')' : '');
            var selected = round.slot === state.selectedRoundSlot ? ' selected' : '';
            return '<option value="' + escapeHtml(round.slot) + '"' + selected + '>' + escapeHtml(label) + '</option>';
        }).join('');
        $('#round-select').html(html);
        var current = currentFinalRound(rounds);
        $('#round-goto-current').prop('disabled', !current || current.slot === state.selectedRoundSlot);
        var index = -1;
        for (var i = 0; i < rounds.length; i++) {
            if (rounds[i].slot === state.selectedRoundSlot) { index = i; break; }
        }
        $('#round-prev').prop('disabled', index <= 0);
        $('#round-next').prop('disabled', index === -1 || index >= rounds.length - 1);
    }

    function stepRound(delta) {
        var rounds = (state.snapshot && state.snapshot.finalsRounds) || [];
        var index = -1;
        for (var i = 0; i < rounds.length; i++) {
            if (rounds[i].slot === state.selectedRoundSlot) { index = i; break; }
        }
        var target = rounds[index + delta];
        if (!target) return;
        state.selectedRoundSlot = target.slot;
        applySelectedRound();
        updateVisibleView();
    }

    // Defaults the browsed round to whichever one the server flags current,
    // but only the first time rounds are seen - after that it stays wherever
    // the user last pointed it, even once that round finishes advancing.
    function applySelectedRound() {
        var rounds = (state.snapshot && state.snapshot.finalsRounds) || [];
        var current = currentFinalRound(rounds);
        if (!state.hasSelectedRound && current) {
            state.selectedRoundSlot = current.slot;
            state.hasSelectedRound = true;
        }
        var selected = null;
        for (var i = 0; i < rounds.length; i++) {
            if (rounds[i].slot === state.selectedRoundSlot) { selected = rounds[i]; break; }
        }
        if (!selected) selected = current;
        state.finals = selected ? selected.matches : [];
        updateRoundSelect(rounds);
        renderFinals(state.finals);
    }

    function medalIcon(match, side) {
        var medal = window.LaneAssist.finalScores.medal(match, side);
        if (!medal) return '';
        var label = { gold: laT('live.medalGold'), silver: laT('live.medalSilver'), bronze: laT('live.medalBronze') }[medal];
        return '<i class="fa fa-medal medal-icon medal-' + medal + '" title="' + escapeHtml(label) + '" aria-label="' + escapeHtml(label) + '"></i> ';
    }

    function renderFinals(matches) {
        var byeCount = matches.filter(function(match) { return match.status === 'bye' && match.canMarkBye; }).length;
        $('#final-count').text(matches.length);
        state.finalsNotice = byeCount ? '<div class="notice bye-notice"><span><i class="fa fa-bell"></i>' + escapeHtml(laTn('live.byesReady', byeCount)) + '</span>' +
            '<button type="button" id="advance-all-byes" class="bulk-advance-button"><i class="fa fa-step-forward" aria-hidden="true"></i> ' + escapeHtml(laT('live.markAllByes')) + '</button></div>' : '';
        var html = matches.map(function(match) {
            var sides = match.sides.map(function(side) {
                var winner = side.winLose || side.tie === 2;
                var scores = window.LaneAssist.finalScores.render(match, side, TRANSLATOR);
                var colorAttrs = colorByAttrs(side, match);
                return '<div class="final-side' + (winner ? ' winner' : '') + '"><div class="final-side-name' + colorAttrs.classAttr + '"' + colorAttrs.styleAttr + colorAttrs.titleAttr + '><strong>' + medalIcon(match, side) + escapeHtml(side.name || laT('live.awaitingOpponent')) + '</strong><small>' + escapeHtml(laTn('live.endsReported', side.completedEnds)) + '</small></div>' + scores + '</div>';
            }).join('');
            var canAct = match.canAdvance || match.canMarkBye;
            var actionLabel = match.status === 'bye' ? (match.canAdvance ? laT('live.markByeAdvance') : laT('live.markBye')) : laT('live.advanceWinner');
            var action = canAct ? '<button type="button" class="advance-button" data-team="' + match.teamEvent + '" data-event="' + escapeHtml(match.event) + '" data-match="' + match.matchNo + '"><i class="fa fa-step-forward"></i> ' + escapeHtml(actionLabel) + '</button>' : '';
            if (match.advanced) action = '<span class="advanced-mark"><i class="fa fa-check-circle"></i> ' + escapeHtml(laT('live.advancedToNext')) + '</span>';
            return '<article class="live-card final-card status-' + match.status + '"><header>' + targetFace(match.target) + '<div><span class="eyebrow">' + escapeHtml(match.eventName) + '</span><h3>' + escapeHtml(phaseLabel(match.phase)) + '</h3><small>' + escapeHtml(match.target ? laT('live.target', { target: match.target }) : laT('live.unassigned')) + '</small></div><span class="status-pill">' + escapeHtml(statusLabel(match.status)) + '</span></header><div class="final-sides">' + sides + '</div><footer>' + action + '</footer></article>';
        }).join('');
        $('#finals-view').html(html);
    }

    function updateProgress() {
        if (!state.snapshot) return;
        var progress = state.mode === 'finals' ? state.snapshot.finalsProgress : state.snapshot.qualificationProgress;
        progress = progress || {};
        var end = Number(progress.end) || 0;
        var totalEnds = Number(progress.totalEnds) || 0;
        var label = state.mode === 'finals' ? laT('live.currentFinalsEnd') : laT('live.currentQualEnd');
        var value = escapeHtml(state.mode === 'finals' && progress.complete
            ? laT('live.finalsComplete')
            : (end ? laT('live.endOf', { end: end }) + (totalEnds ? ' / ' + totalEnds : '') : laT('live.notStarted')));
        if (state.mode === 'qualification' && progress.hasMultipleDistances) {
            value += '<small>' + escapeHtml(laT('live.distanceEnd', { distance: progress.expectedDistance, end: progress.currentDistanceEnd })) + '</small>';
        }
        if (state.mode === 'qualification' && Number(progress.totalArrows)) {
            value += '<small>' + escapeHtml(laT('live.arrowsOf', { shot: Number(progress.arrowsShot || 0), total: Number(progress.totalArrows) })) + '</small>';
        }
        $('#live-progress').html('<span>' + escapeHtml(label) + '</span><strong>' + value + '</strong>');
    }

    function renderNotices() {
        var notices = state.actionMessage ? '<div class="notice success"><i class="fa fa-check"></i>' + escapeHtml(state.actionMessage) + '</div>' : '';
        notices += state.mode === 'finals' ? (state.finalsNotice || '') : (state.qualificationNotice || '');
        $('#live-notices').html(notices);
    }

    function updateVisibleView() {
        var isFinals = state.mode === 'finals';
        $('#qualification-view').prop('hidden', isFinals);
        $('#total-mode-control').prop('hidden', isFinals);
        $('#finals-view').prop('hidden', !isFinals);
        $('.mode-button').removeClass('active').filter('[data-mode="' + state.mode + '"]').addClass('active');
        $('label[for="session-select"]').toggle(!isFinals);
        $('#print-qual').prop('hidden', isFinals).prop('disabled', !$('#session-select').val());
        var printState = window.LaneAssist.printLayout.finalsPrintState(selectedFinalRound(), TRANSLATOR);
        $('#print-finals').prop('hidden', !isFinals).prop('disabled', !printState.enabled)
            .attr('title', printState.title).attr('aria-label', printState.title);
        var rounds = (state.snapshot && state.snapshot.finalsRounds) || [];
        $('#round-control').prop('hidden', !isFinals || rounds.length < 2);
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
                $('#live-notices').html('<div class="notice error">' + escapeHtml(data.message || laT('live.loadFailed')) + '</div>');
                return;
            }
            state.snapshot = data;
            renderQualification(data.qualification || []);
            applySelectedRound();
            var current = currentFinalRound(data.finalsRounds || []);
            var currentMatches = current ? current.matches : [];
            var behind = (data.qualification || []).reduce(function(total, mat) { return total + mat.archers.filter(function(archer) { return archer.isBehind; }).length; }, 0);
            var problems = currentMatches.filter(function(match) { return ['unreported', 'uneven', 'partial'].indexOf(match.status) !== -1; }).length;
            $('#live-summary').text(finalBlockLabel(currentMatches, current ? current.slot : '') + ' · ' + laTn('live.archersBehind', behind) + ' · ' + laTn('live.matchesNeedAttention', problems));
            $('#last-updated').text(laT('live.updatedAt', { time: new Date(data.updatedAt).toLocaleTimeString() }));
            if (!state.initialModeResolved) {
                state.mode = data.finalsInitialized && data.qualificationProgress && data.qualificationProgress.complete
                    ? 'finals'
                    : 'qualification';
                state.initialModeResolved = true;
            }
            updateVisibleView();
        }).fail(function(xhr) {
            var message = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : laT('live.refreshFailed');
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
                $('#live-notices').html('<div class="notice error">' + escapeHtml(data.message || laT('live.advanceFailed')) + '</div>');
                $button.prop('disabled', false);
                return;
            }
            state.actionMessage = data.message || laT('live.matchAdvanced');
            loadSnapshot(true);
        }).fail(function(xhr) {
            state.actionMessage = '';
            var message = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : laT('live.advanceFailed');
            $('#live-notices').html('<div class="notice error">' + escapeHtml(message) + '</div>');
            $button.prop('disabled', false);
        });
    }

    function applyArcherRetiredState(participantId, retired) {
        var $competitor = $('.competitor[data-participant-id="' + participantId + '"]');
        $competitor.toggleClass('retired', retired).removeClass('danger warning entered');
        $competitor.find('.retire-toggle')
            .data('retired', retired ? 1 : 0)
            .prop('disabled', false)
            .text(retired ? laT('live.unforfeit') : laT('live.forfeit'));
    }

    function toggleArcherRetired(button) {
        var $button = $(button);
        var retired = $button.data('retired') === 1 || $button.data('retired') === '1';
        if (!retired && !window.confirm(laT('live.confirmForfeit'))) {
            return;
        }
        $button.prop('disabled', true);
        $.ajax({ url: apiUrl, method: 'POST', dataType: 'json', headers: { 'X-Requested-With': 'XMLHttpRequest' }, data: {
            action: 'toggleArcherRetired', participantId: $button.data('participant-id'), session: $('#session-select').val()
        }}).done(function(data) {
            if (data.error) {
                $('#live-notices').html('<div class="notice error">' + escapeHtml(data.message || laT('live.archerUpdateFailed')) + '</div>');
                $button.prop('disabled', false);
                return;
            }
            // Reflect the change immediately rather than waiting on the round trip
            // of a full snapshot reload; that reload still runs in the background
            // to reconcile pace/progress/rank changes elsewhere on the page.
            applyArcherRetiredState(data.participantId, data.retired);
            loadSnapshot(true);
        }).fail(function(xhr) {
            var message = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : laT('live.archerUpdateFailed');
            $('#live-notices').html('<div class="notice error">' + escapeHtml(message) + '</div>');
            $button.prop('disabled', false);
        });
    }

    function advanceAllByes(button) {
        if (state.bulkAdvancing || !state.snapshot) return;
        var byes = (state.finals || []).filter(function(match) {
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
                state.actionMessage = laTn('live.byesProcessed', advanced);
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
                    $('#live-notices').html('<div class="notice error">' + escapeHtml(laT('live.byesPartly', { done: advanced, total: byes.length }) + ' ' + (data.message || laT('live.nextByeFailed'))) + '</div>');
                    $('.advance-button, .bulk-advance-button').prop('disabled', false);
                    return;
                }
                advanced++;
                $button.html('<i class="fa fa-refresh fa-spin" aria-hidden="true"></i> Processing ' + advanced + ' / ' + byes.length);
                advanceNext();
            }).fail(function(xhr) {
                state.bulkAdvancing = false;
                state.actionMessage = '';
                var message = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : laT('live.nextByeFailed');
                $('#live-notices').html('<div class="notice error">' + escapeHtml(laT('live.byesPartly', { done: advanced, total: byes.length }) + ' ' + message) + '</div>');
                $('.advance-button, .bulk-advance-button').prop('disabled', false);
            });
        }

        advanceNext();
    }

    function forfeitAllNoShows(button) {
        if (state.bulkAdvancing || !state.snapshot) return;
        var participantIds = (state.qualificationForfeitEligible || []).slice();
        if (!participantIds.length) return;
        if (!window.confirm(laTn('live.confirmForfeitAll', participantIds.length))) {
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
                $('#live-notices').html('<div class="notice error">' + escapeHtml(data.message || laT('live.forfeitAllFailed')) + '</div>');
                $('.advance-button, .bulk-advance-button, .retire-toggle').prop('disabled', false);
                return;
            }
            (data.results || []).forEach(function(result) {
                if (result.ok) {
                    applyArcherRetiredState(result.participantId, result.retired);
                }
            });
            var failed = (data.results || []).filter(function(result) { return !result.ok; });
            state.actionMessage = laTn('live.forfeited', data.succeeded)
                + (failed.length ? ', ' + laTn('live.forfeitSkipped', failed.length, { reason: failed[0].message + (failed.length > 1 ? ', ...' : '') }) : '');
            loadSnapshot(true);
        }).fail(function(xhr) {
            state.bulkAdvancing = false;
            state.actionMessage = '';
            var message = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : laT('live.forfeitAllFailed');
            $('#live-notices').html('<div class="notice error">' + escapeHtml(message) + '</div>');
            $('.advance-button, .bulk-advance-button, .retire-toggle').prop('disabled', false);
        });
    }

    $(function() {
        $('.mode-button').on('click', function() {
            state.mode = $(this).data('mode');
            updateVisibleView();
        });
        $('#session-select').on('change', function() { loadSnapshot(true); });
        $('#round-select').on('change', function() {
            state.selectedRoundSlot = $(this).val();
            applySelectedRound();
            updateVisibleView();
        });
        $('#round-goto-current').on('click', function() {
            var current = currentFinalRound((state.snapshot && state.snapshot.finalsRounds) || []);
            if (!current) return;
            state.selectedRoundSlot = current.slot;
            applySelectedRound();
            updateVisibleView();
        });
        $('#round-prev').on('click', function() { stepRound(-1); });
        $('#round-next').on('click', function() { stepRound(1); });
        $('#color-by').on('change', function() {
            state.colorBy = $(this).val();
            if (!state.snapshot) return;
            if (state.mode === 'finals') {
                renderFinals(state.finals || []);
            } else {
                renderQualification(state.snapshot.qualification || []);
            }
        });
        $('#total-mode').on('change', function() {
            state.totalMode = $(this).val() === 'distance' ? 'distance' : 'combined';
            if (state.snapshot) renderQualification(state.snapshot.qualification || []);
        });
        $('#refresh-button').on('click', function() { loadSnapshot(true); });
        $('#print-finals').on('click', function() {
            var round = selectedFinalRound();
            if (!window.LaneAssist.printLayout.finalsPrintState(round, TRANSLATOR).enabled) return;
            window.open(window.LaneAssist.printLayout.finalsPrintUrl(ROOT_DIR, round.slot), '_blank');
        });
        $('#print-qual').on('click', function() {
            var session = $('#session-select').val();
            if (!session) return;
            window.open(window.LaneAssist.printLayout.qualPrintUrl(ROOT_DIR, session), '_blank');
        });
        $(document).on('click', '.advance-button', function() { advanceMatch(this); });
        $(document).on('click', '.retire-toggle', function() { toggleArcherRetired(this); });
        $(document).on('click', '#advance-all-byes', function(event) { event.stopPropagation(); advanceAllByes(this); });
        $(document).on('click', '#forfeit-all-noshows', function(event) { event.stopPropagation(); forfeitAllNoShows(this); });
        loadSnapshot(false);
        state.timer = window.setInterval(function() { loadSnapshot(false); }, 5000);
        $(window).on('beforeunload', function() { window.clearInterval(state.timer); });
    });
})(jQuery);
