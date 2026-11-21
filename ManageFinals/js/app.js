(function($) {
    'use strict';

    // Pure pair-playability rules, shared with the unit tests (Common/js/finals-playability.js).
    const playability = (window.LaneAssist && window.LaneAssist.finalsPlayability) || null;
    if (!playability) {
        throw new Error('LaneAssist finals-playability.js must load before ManageFinals app.js');
    }
    const distanceLanePlan = (window.LaneAssist && window.LaneAssist.distanceLanePlan) || null;
    if (!distanceLanePlan) {
        throw new Error('LaneAssist distance-lane-plan.js must load before ManageFinals app.js');
    }

    // Common/js/i18n.js (loaded before this file) puts the page's translator on window.
    const laT = window.laT;
    const laTn = window.laTn;

    let dragHintTimer = null;

    const state = {
        rows: [],
        originalRowsByKey: {},
        hasChanges: false,
        manualTimeslots: [],
        availableTargets: [],
        lastQualificationEnd: '',
        editingTimeslotKey: '',
        validationWarnings: [],
        serverValidationWarnings: [],
        bundleWarnings: {},
        targetWarnings: {},
        targetDistanceLocks: {},
        sharedTargetsScrollLeft: 0,
        syncingTargetsScroll: false,
        defaultFinalsLengthMinutes: 30,
        colorBy: 'event'
    };

    const ColorBy = window.LaneAssist && window.LaneAssist.colorBy;
    const colorGroups = (typeof ColorGroups !== 'undefined' && ColorGroups) ? ColorGroups : {};
    const colorIndex = ColorBy.createColorIndex(colorGroups);
    const divisionDescriptions = {};
    (Array.isArray(colorGroups.division) ? colorGroups.division : []).forEach(function(division) {
        divisionDescriptions[division.id] = division.description;
    });

    const PHASE_ORDER_TRACKED = [64, 32, 16, 8, 4, 2, 1, 0];
    const PHASE_DEPENDENCIES = [
        { before: 64, after: 32 },
        { before: 32, after: 16 },
        { before: 16, after: 8 },
        { before: 8, after: 4 },
        { before: 4, after: 2 },
        { before: 2, after: 1 },
        { before: 2, after: 0 }
    ];

    function init() {
        initMultiSelectDropdowns();
        setupEventHandlers();
        updateFiltersIndicator();
        const configuredLength = parseInt(window.LaneAssist_DEFAULT_FINALS_LENGTH, 10);
        if (!Number.isNaN(configuredLength) && configuredLength > 0) {
            state.defaultFinalsLengthMinutes = configuredLength;
        }
        updateAutoAssignDefaultsView();
        const initialColorBy = ($('#color-by').val() || '').toString().trim();
        if (!initialColorBy || initialColorBy === 'none') {
            $('#color-by').val('event');
            state.colorBy = 'event';
        } else {
            state.colorBy = initialColorBy;
        }
        updateUI();
        updateViewportLayout();
        loadData();
    }

    function initMultiSelectDropdowns() {
        if (window.LaneAssist && typeof window.LaneAssist.initMultiSelectDropdowns === 'function') {
            window.LaneAssist.initMultiSelectDropdowns({
                onDocumentClick: function() {
                    $('#targets-validation-summary').removeClass('open');
                    $('#targets-validation-toggle').attr('aria-expanded', 'false');
                    $('#targets-validation-details').hide();
                    $('#filters-popup').hide();
                    $('#btn-filters').attr('aria-expanded', 'false');
                    $('#auto-assign-options').hide();
                    updateViewportLayout();
                }
            });
        } else {
            $(document).on('click', function() {
                $('.multi-dropdown').removeClass('open');
                $('#targets-validation-summary').removeClass('open');
                $('#targets-validation-toggle').attr('aria-expanded', 'false');
                $('#targets-validation-details').hide();
                $('#filters-popup').hide();
                $('#btn-filters').attr('aria-expanded', 'false');
                $('#auto-assign-options').hide();
                updateViewportLayout();
            });
        }
    }

    function setupEventHandlers() {
        $('#btn-unassign-all').on('click', unassignAll);
        $('#btn-reset').on('click', resetChanges);
        $('#btn-apply').on('click', applyChanges);
        $('#btn-auto-assign').on('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $('#filters-popup').hide();
            $('#btn-filters').attr('aria-expanded', 'false');
            updateAutoAssignDefaultsView();
            $('#auto-assign-options').toggle();
            updateViewportLayout();
        });

        $('#btn-filters').on('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $('#auto-assign-options').hide();
            const shouldOpen = !$('#filters-popup').is(':visible');
            $('#filters-popup').toggle(shouldOpen);
            $(this).attr('aria-expanded', shouldOpen ? 'true' : 'false');
            updateViewportLayout();
        });

        $('#filters-popup').on('click', function(e) {
            e.stopPropagation();
        });

        $('#auto-assign-options').on('click', function(e) {
            e.stopPropagation();
        });
        $('#btn-close-auto').on('click', function(e) {
            e.preventDefault();
            $('#auto-assign-options').hide();
            updateViewportLayout();
        });
        $('#btn-run-auto').on('click', function(e) {
            e.preventDefault();
            $('#auto-assign-options').hide();
            autoAssignFinals();
        });
        $('#division-filter, #class-filter, #team-event-filter, #date-filter').on('change', function() {
            updateFiltersIndicator();
            renderAll();
        });
        $('#color-by').on('change', function() {
            state.colorBy = $(this).val() || 'none';
            renderAll();
        });
        $('#show-empty-timeslots').on('change', function() {
            renderAll();
        });
        $('#auto-restrict-distance-lanes').on('change', function() {
            if (!$(this).is(':checked')) {
                state.targetDistanceLocks = {};
            }
            renderAll();
        });
        $('#targets-validation-summary').on('click', function(e) {
            e.stopPropagation();
        });
        $('#targets-validation-toggle').on('click', function(e) {
            e.stopPropagation();
            const $summary = $('#targets-validation-summary');
            if ($summary.hasClass('single-line')) {
                return;
            }
            const $details = $('#targets-validation-details');
            const isOpen = $summary.hasClass('open');
            $summary.toggleClass('open', !isOpen);
            $(this).attr('aria-expanded', !isOpen ? 'true' : 'false');
            $details.toggle(!isOpen);
        });
        $('#visual-board').on('click', '.timeslot-insert-btn', function() {
            const index = parseInt($(this).attr('data-insert-index'), 10);
            if (Number.isNaN(index)) {
                return;
            }
            insertTimeslotAt(index);
        });
        $('#visual-board').on('click', '.timeslot-delete-btn', function() {
            const timeslotKey = ($(this).attr('data-timeslot-key') || '').toString();
            if (!timeslotKey) {
                return;
            }
            removeTimeslot(timeslotKey);
        });
        $('#visual-board').on('click', '.timeslot-edit-btn', function() {
            const timeslotKey = ($(this).attr('data-timeslot-key') || '').toString();
            if (!timeslotKey) {
                return;
            }
            editTimeslotDateTime(timeslotKey);
        });
        $('#visual-board').on('click', '.timeslot-edit-save-btn', function() {
            const timeslotKey = ($(this).attr('data-timeslot-key') || '').toString();
            if (!timeslotKey) {
                return;
            }

            const $title = $(this).closest('.timeslot-title');
            const newDate = (($title.find('.timeslot-edit-date').val() || '') + '').trim();
            const newTimeRaw = (($title.find('.timeslot-edit-time').val() || '') + '').trim();
            const newLenRaw = (($title.find('.timeslot-edit-length').val() || '') + '').trim();
            const normalizedTime = normalizeTimeInput(newTimeRaw);
            const normalizedLength = normalizeScheduledLen(newLenRaw);

            if (!/^\d{4}-\d{2}-\d{2}$/.test(newDate)) {
                showDragHint(laT('finals.invalidDate'));
                return;
            }
            if (!normalizedTime) {
                showDragHint(laT('finals.invalidTime'));
                return;
            }

            if (normalizedLength <= 0) {
                showDragHint(laT('finals.invalidLength'));
                return;
            }

            applyTimeslotDateTimeEdit(timeslotKey, newDate, normalizedTime, normalizedLength);
        });
        $('#visual-board').on('click', '.timeslot-edit-cancel-btn', function() {
            const timeslotKey = ($(this).attr('data-timeslot-key') || '').toString();
            if (!timeslotKey) {
                return;
            }

            if (state.editingTimeslotKey === timeslotKey) {
                state.editingTimeslotKey = '';
                renderAll();
            }
        });
        $('#visual-board').on('click', '.timeslot-bootstrap-btn', function() {
            addInitialTimeslotsFromQualificationEnd();
        });

        function handleBundleClearClick(e) {
            e.preventDefault();
            e.stopPropagation();

            const rowKeysRaw = ($(this).attr('data-row-keys') || '').toString().trim();
            if (rowKeysRaw) {
                const rowKeys = rowKeysRaw.split(',').map(function(value) {
                    return value.toString().trim();
                }).filter(function(value) {
                    return value !== '';
                });

                if (rowKeys.length) {
                    const rowKeyMap = {};
                    rowKeys.forEach(function(key) {
                        rowKeyMap[key] = true;
                    });

                    state.rows.forEach(function(row) {
                        if (!rowKeyMap[row.key]) {
                            return;
                        }

                        row.target = '';
                        row.scheduledDate = '';
                        row.scheduledTime = '';
                        row.scheduledLen = 0;
                        refreshRowPlacementKeys(row);
                    });

                    recomputeHasChanges();
                    syncValidationWarnings(getTimeslots(), buildBundles(state.rows));
                    renderAll();
                    showStatus(laT('finals.removedInvalidMatch'), 'success');
                }

                return;
            }

            const bundleKey = ($(this).attr('data-bundle-key') || '').toString();
            if (!bundleKey) {
                return;
            }

            const bundle = buildBundles(state.rows).find(function(item) {
                return item.key === bundleKey;
            });
            if (!bundle) {
                return;
            }

            bundle.rows.forEach(function(row) {
                row.target = '';
                row.scheduledDate = '';
                row.scheduledTime = '';
                row.scheduledLen = 0;
                refreshRowPlacementKeys(row);
            });

            recomputeHasChanges();
            syncValidationWarnings(getTimeslots(), buildBundles(state.rows));
            renderAll();
            showStatus(laT('finals.removedInvalidMatch'), 'success');
        }

        $('#unassigned-list').on('click', '.bundle-clear-btn', handleBundleClearClick);
        $('#visual-board').on('click', '.bundle-clear-btn', handleBundleClearClick);
        $('#unassigned-list, #visual-board')
            .on('mouseenter', '.bundle-card', handleBundleCardEnter)
            .on('mouseleave', '.bundle-card', clearEventHighlight);
        $(window).on('resize orientationchange', updateViewportLayout);
    }

    function updateAutoAssignDefaultsView() {
        $('#auto-default-length').text(String(Math.max(1, parseInt(state.defaultFinalsLengthMinutes, 10) || 30)));
    }

    function bindSynchronizedTimeslotScroll() {
        const $scrollers = $('#visual-board .targets-scroll-sync');
        $scrollers.off('scroll.mfSync').on('scroll.mfSync', function() {
            if (state.syncingTargetsScroll) {
                return;
            }

            const sourceEl = this;
            const scrollLeft = $(this).scrollLeft();
            state.sharedTargetsScrollLeft = scrollLeft;
            state.syncingTargetsScroll = true;

            $scrollers.each(function() {
                if (this === sourceEl) {
                    return;
                }
                $(this).scrollLeft(scrollLeft);
            });

            state.syncingTargetsScroll = false;
        });
    }

    function makeRowKey(row) {
        return [row.teamEvent, row.event, row.matchNo].join('|');
    }

    function makeTimeslotKey(row) {
        return [row.teamEvent, row.scheduledDate || '', row.scheduledTime || ''].join('|');
    }

    function normalizeScheduledLen(rawValue) {
        const parsed = parseInt(rawValue, 10);
        if (Number.isNaN(parsed) || parsed <= 0) {
            return Math.max(1, parseInt(state.defaultFinalsLengthMinutes, 10) || 30);
        }
        return parsed;
    }

    function makeBundleKey(row) {
        return [row.timeslotKey, row.event, row.group, row.phase].join('|');
    }

    function refreshRowPlacementKeys(row) {
        row.timeslotKey = makeTimeslotKey(row);
        row.bundleKey = makeBundleKey(row);
    }

    function hasRowChanged(row) {
        const original = state.originalRowsByKey[row.key] || { target: '', scheduledDate: '', scheduledTime: '', scheduledLen: normalizeScheduledLen(state.defaultFinalsLengthMinutes) };
        return row.target !== original.target ||
            (row.scheduledDate || '') !== (original.scheduledDate || '') ||
            (row.scheduledTime || '') !== (original.scheduledTime || '') ||
            normalizeScheduledLen(row.scheduledLen) !== normalizeScheduledLen(original.scheduledLen);
    }

    function getTimeslotByKey(timeslotKey) {
        const timeslots = getTimeslots();
        for (let index = 0; index < timeslots.length; index++) {
            if (timeslots[index].key === timeslotKey) {
                return timeslots[index];
            }
        }
        return null;
    }

    function bundleHasSchedule(bundle) {
        const rows = (bundle && bundle.rows) ? bundle.rows : [];
        if (!rows.length) {
            return false;
        }

        return rows.some(function(row) {
            const hasDate = ((row.scheduledDate || '').toString().trim() !== '');
            const hasTime = ((row.scheduledTime || '').toString().trim() !== '');
            const hasTarget = (parseTargetNumber(row.target) !== null);
            return hasDate || hasTime || hasTarget;
        });
    }

    function bundleHasAssignedTarget(bundle) {
        const rows = (bundle && bundle.rows) ? bundle.rows : [];
        if (!rows.length) {
            return false;
        }

        return rows.some(function(row) {
            return parseTargetNumber(row.target) !== null;
        });
    }

    function buildTimeslotSortValue(dateValue, timeValue) {
        const datePart = (dateValue || '').toString().trim();
        const timePart = (timeValue || '').toString().trim();
        if (!datePart) {
            return null;
        }

        const normalizedTime = timePart ? timePart : '00:00:00';
        return datePart + ' ' + normalizedTime;
    }

    function validatePhaseOrderAfterMove(bundle, targetTimeslot) {
        if (!bundle || !targetTimeslot) {
            return laT('finals.invalidTimeslot');
        }

        const impactedEvent = (bundle.event || '').toString();
        const impactedTeamEvent = parseInt(bundle.rows[0] ? bundle.rows[0].teamEvent : bundle.teamEvent, 10) || 0;
        const movedKeys = {};
        const movedPhases = {};

        bundle.rows.forEach(function(row) {
            movedKeys[row.key] = true;
            const movedPhase = parseInt(row.phase, 10);
            if (!Number.isNaN(movedPhase)) {
                movedPhases[movedPhase] = true;
            }
        });

        const phaseSlots = {};
        state.rows.forEach(function(row) {
            const eventCode = (row.event || '').toString();
            if (!eventCode || eventCode !== impactedEvent) {
                return;
            }

            if ((parseInt(row.teamEvent, 10) || 0) !== impactedTeamEvent) {
                return;
            }

            const phaseValue = parseInt(row.phase, 10);
            if (PHASE_ORDER_TRACKED.indexOf(phaseValue) === -1) {
                return;
            }

            const movedRow = !!movedKeys[row.key];
            const dateValue = movedRow ? targetTimeslot.scheduledDate : row.scheduledDate;
            const timeValue = movedRow ? targetTimeslot.scheduledTime : row.scheduledTime;
            const sortValue = buildTimeslotSortValue(dateValue, timeValue);
            if (!sortValue) {
                return;
            }

            if (!phaseSlots[phaseValue]) {
                phaseSlots[phaseValue] = [];
            }
            phaseSlots[phaseValue].push(sortValue);
        });

        for (let index = 0; index < PHASE_DEPENDENCIES.length; index++) {
            const dependency = PHASE_DEPENDENCIES[index];
            const earlierPhase = dependency.before;
            const laterPhase = dependency.after;

            if (!movedPhases[earlierPhase] && !movedPhases[laterPhase]) {
                continue;
            }

            const earlierSlots = phaseSlots[earlierPhase] || [];
            const laterSlots = phaseSlots[laterPhase] || [];

            if (!earlierSlots.length || !laterSlots.length) {
                continue;
            }

            const latestEarlier = earlierSlots.slice().sort().pop();
            const earliestLater = laterSlots.slice().sort()[0];
            if (latestEarlier >= earliestLater) {
                const slot = normalizeDateTimeValue(targetTimeslot.scheduledDate, targetTimeslot.scheduledTime);
                return laT('finals.phaseOrderDrop', { event: impactedEvent, before: formatPhaseLabel(earlierPhase), after: formatPhaseLabel(laterPhase), slot: slot });
            }
        }

        return null;
    }

    function moveBundleToTimeslot(bundle, targetTimeslot, rowsSubset) {
        const rows = Array.isArray(rowsSubset) ? rowsSubset : bundle.rows;
        rows.forEach(function(row) {
            row.scheduledDate = targetTimeslot.scheduledDate;
            row.scheduledTime = targetTimeslot.scheduledTime;
            row.scheduledLen = normalizeScheduledLen(targetTimeslot.scheduledLen);
            refreshRowPlacementKeys(row);
        });
    }

    function parseTargetNumber(target) {
        const parsed = parseInt((target || '').toString().trim(), 10);
        return Number.isNaN(parsed) ? null : parsed;
    }

    function padTargetNumber(number) {
        return String(number);
    }

    function formatPhaseLabel(phase) {
        const numericPhase = parseInt(phase, 10);
        const map = {
            0: laT('status.phaseGold'),
            1: laT('status.phaseBronze'),
            2: '1/2',
            4: '1/4',
            8: '1/8',
            16: '1/16',
            32: '1/32',
            64: '1/64'
        };
        return map[numericPhase] || laT('finals.phaseNumber', { phase: numericPhase });
    }

    function loadData() {
        showStatus(laT('finals.loading'), 'info');

        $.ajax({
            url: ROOT_DIR + 'Modules/Custom/LaneAssist/ManageFinals/api.php',
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'getCurrent'
            },
            success: function(response) {
                if (response.error) {
                    showStatus(response.message || laT('finals.loadFailed'), 'error');
                    return;
                }

                state.rows = (response.rows || []).map(function(row) {
                    row.key = makeRowKey(row);
                    row.scheduledDate = normalizeScheduledDate(row.scheduledDate);
                    row.scheduledTime = normalizeScheduledTime(row.scheduledTime, row.scheduledDate);
                    row.scheduledLen = normalizeScheduledLen(row.scheduledLen);
                    row.timeslotKey = makeTimeslotKey(row);
                    row.bundleKey = makeBundleKey(row);
                    row.target = (row.target || '').toString().trim().toUpperCase();
                    row.archersPerTarget = parseInt(row.archersPerTarget, 10) === 2 ? 2 : 1;
                    row.hasParticipant = parseInt(row.hasParticipant, 10) > 0 ? 1 : 0;
                    row.projectedParticipants = parseInt(row.projectedParticipants, 10);
                    if (Number.isNaN(row.projectedParticipants)) {
                        row.projectedParticipants = null;
                    } else {
                        row.projectedParticipants = Math.max(0, row.projectedParticipants);
                    }
                    row.gridPosition = parseInt(row.gridPosition, 10);
                    if (Number.isNaN(row.gridPosition)) {
                        row.gridPosition = null;
                    }
                    row.gridPosition2 = parseInt(row.gridPosition2, 10);
                    if (Number.isNaN(row.gridPosition2)) {
                        row.gridPosition2 = null;
                    }
                    row.division = (row.division || '').toString().trim();
                    row.classCode = (row.class || '').toString().trim();
                    row.eventDivisionOrder = parseInt(row.eventDivisionOrder, 10);
                    if (Number.isNaN(row.eventDivisionOrder)) {
                        row.eventDivisionOrder = null;
                    }
                    row.eventClassOrder = parseInt(row.eventClassOrder, 10);
                    if (Number.isNaN(row.eventClassOrder)) {
                        row.eventClassOrder = null;
                    }
                    row.distanceProfile = (row.distanceProfile || '').toString().trim();
                    row.distanceSort = parseInt(row.distanceSort, 10);
                    if (Number.isNaN(row.distanceSort)) {
                        row.distanceSort = null;
                    }
                    row.phaseLabel = formatPhaseLabel(row.phase);
                    return row;
                });

                state.originalRowsByKey = {};
                state.rows.forEach(function(row) {
                    state.originalRowsByKey[row.key] = {
                        target: row.target,
                        scheduledDate: row.scheduledDate,
                        scheduledTime: row.scheduledTime,
                        scheduledLen: row.scheduledLen
                    };
                });

                state.availableTargets = (response.availableTargets || []).map(function(target) {
                    return (target || '').toString().trim().toUpperCase();
                }).filter(function(target) {
                    return target !== '';
                });

                state.lastQualificationEnd = (response.lastQualificationEnd || '').toString().trim();

                state.serverValidationWarnings = (response.validationErrors || []).map(function(item) {
                    return (item && item.message ? item.message : '').toString().trim();
                }).filter(function(message) {
                    return message !== '';
                });

                state.hasChanges = false;
                state.manualTimeslots = [];
                state.editingTimeslotKey = '';
                state.targetDistanceLocks = {};
                clearValidationWarnings();
                renderAll();
                showStatus(laTn('finals.loaded', state.rows.length), 'success');
            },
            error: function() {
                showStatus(laT('finals.loadFailed'), 'error');
            }
        });
    }

    function getFilterValues(rawValue) {
        if (Array.isArray(rawValue)) {
            return rawValue.map(function(value) {
                return (value || '').toString().trim();
            }).filter(function(value) {
                return value !== '';
            });
        }

        const single = (rawValue || '').toString().trim();
        return single ? [single] : [];
    }

    function splitRowValues(rawValue) {
        return (rawValue || '').toString().split(',').map(function(value) {
            return value.toString().trim();
        }).filter(function(value) {
            return value !== '';
        });
    }

    function normalizeScheduledDate(dateValue) {
        const date = (dateValue || '').toString().trim();
        if (!date || date === '0000-00-00') {
            return '';
        }
        return date;
    }

    function normalizeScheduledTime(timeValue, normalizedDate) {
        if (!normalizedDate) {
            return '';
        }

        const time = (timeValue || '').toString().trim();
        if (!time) {
            return '';
        }
        return time;
    }

    function rowMatchesMultiFilter(rowValue, selectedValues) {
        if (!selectedValues.length) {
            return true;
        }

        const rowValues = splitRowValues(rowValue);
        if (!rowValues.length) {
            return false;
        }

        return selectedValues.some(function(value) {
            return rowValues.indexOf(value) !== -1;
        });
    }

    function getFilteredRows() {
        const selectedTeamEvents = getFilterValues($('#team-event-filter').val());
        const selectedDates = getFilterValues($('#date-filter').val());
        const selectedDivisions = getFilterValues($('#division-filter').val());
        const selectedClasses = getFilterValues($('#class-filter').val());

        return state.rows.filter(function(row) {
            if (selectedTeamEvents.length) {
                const teamEventValue = String(parseInt(row.teamEvent, 10) || 0);
                if (selectedTeamEvents.indexOf(teamEventValue) === -1) {
                    return false;
                }
            }

            if (selectedDates.length) {
                const dateValue = (row.scheduledDate || '').toString().trim();
                if (selectedDates.indexOf(dateValue) === -1) {
                    return false;
                }
            }

            if (!rowMatchesMultiFilter(row.division, selectedDivisions)) {
                return false;
            }

            if (!rowMatchesMultiFilter(row.classCode, selectedClasses)) {
                return false;
            }

            return true;
        });
    }

    function getTimeslots(rowsInput) {
        const sourceRows = Array.isArray(rowsInput) ? rowsInput : getFilteredRows();
        const map = {};
        sourceRows.forEach(function(row) {
            if (!(row.scheduledDate || '').toString().trim()) {
                return;
            }

            if (!map[row.timeslotKey]) {
                map[row.timeslotKey] = {
                    key: row.timeslotKey,
                    teamEvent: row.teamEvent,
                    scheduledDate: row.scheduledDate,
                    scheduledTime: row.scheduledTime,
                    scheduledLen: normalizeScheduledLen(row.scheduledLen),
                    rows: []
                };
            }
            map[row.timeslotKey].rows.push(row);
            map[row.timeslotKey].scheduledLen = Math.max(
                normalizeScheduledLen(map[row.timeslotKey].scheduledLen),
                normalizeScheduledLen(row.scheduledLen)
            );
        });

        state.manualTimeslots.forEach(function(slot) {
            const key = [slot.teamEvent, slot.scheduledDate || '', slot.scheduledTime || ''].join('|');
            if (!map[key]) {
                map[key] = {
                    key: key,
                    teamEvent: slot.teamEvent,
                    scheduledDate: slot.scheduledDate,
                    scheduledTime: slot.scheduledTime,
                    scheduledLen: normalizeScheduledLen(slot.scheduledLen),
                    rows: []
                };
            } else {
                map[key].scheduledLen = Math.max(
                    normalizeScheduledLen(map[key].scheduledLen),
                    normalizeScheduledLen(slot.scheduledLen)
                );
            }
        });

        return Object.values(map).sort(function(a, b) {
            const da = (a.scheduledDate || '') + ' ' + (a.scheduledTime || '');
            const db = (b.scheduledDate || '') + ' ' + (b.scheduledTime || '');
            if (da === db) {
                return a.teamEvent - b.teamEvent;
            }
            return da.localeCompare(db);
        });
    }

    function parseTimeslotDateTime(slot) {
        const datePart = (slot.scheduledDate || '').toString().trim();
        const timePart = (slot.scheduledTime || '').toString().trim();
        if (!datePart) {
            return null;
        }

        const safeTime = timePart ? timePart : '00:00:00';
        const normalizedTime = safeTime.length === 5 ? (safeTime + ':00') : safeTime;
        const dateObj = new Date(datePart + 'T' + normalizedTime);
        if (Number.isNaN(dateObj.getTime())) {
            return null;
        }
        return dateObj;
    }

    function formatDateIso(dateObj) {
        const y = dateObj.getFullYear();
        const m = String(dateObj.getMonth() + 1).padStart(2, '0');
        const d = String(dateObj.getDate()).padStart(2, '0');
        return y + '-' + m + '-' + d;
    }

    function formatTimeIso(dateObj) {
        const h = String(dateObj.getHours()).padStart(2, '0');
        const m = String(dateObj.getMinutes()).padStart(2, '0');
        const s = String(dateObj.getSeconds()).padStart(2, '0');
        return h + ':' + m + ':' + s;
    }

    function addMinutesToTimeslot(slot, minutes) {
        const dateObj = parseTimeslotDateTime(slot);
        if (!dateObj) {
            return {
                scheduledDate: slot.scheduledDate,
                scheduledTime: slot.scheduledTime
            };
        }

        dateObj.setMinutes(dateObj.getMinutes() + minutes);
        return {
            scheduledDate: formatDateIso(dateObj),
            scheduledTime: formatTimeIso(dateObj)
        };
    }

    function normalizeTimeInput(rawValue) {
        const raw = (rawValue || '').toString().trim();
        const match = raw.match(/^(\d{2}):(\d{2})(?::(\d{2}))?$/);
        if (!match) {
            return '';
        }

        const hh = parseInt(match[1], 10);
        const mm = parseInt(match[2], 10);
        const ss = parseInt(match[3] || '00', 10);
        if (hh < 0 || hh > 23 || mm < 0 || mm > 59 || ss < 0 || ss > 59) {
            return '';
        }

        return String(hh).padStart(2, '0') + ':' + String(mm).padStart(2, '0') + ':' + String(ss).padStart(2, '0');
    }

    function editTimeslotDateTime(timeslotKey) {
        const timeslot = getTimeslotByKey(timeslotKey);
        if (!timeslot) {
            showDragHint(laT('finals.timeslotNotFound'));
            return;
        }

        state.editingTimeslotKey = timeslotKey;
        renderAll();

        window.setTimeout(function() {
            const $dateInput = $('.timeslot-title .timeslot-edit-date').first();
            if ($dateInput.length) {
                $dateInput.trigger('focus');
            }
        }, 0);
    }

    function applyTimeslotDateTimeEdit(timeslotKey, newDate, normalizedTime, normalizedLength) {
        const timeslot = getTimeslotByKey(timeslotKey);
        if (!timeslot) {
            showDragHint(laT('finals.timeslotNotFound'));
            return;
        }

        const oldKey = timeslot.key;
        let affectedRows = 0;
        state.rows.forEach(function(row) {
            if (row.timeslotKey !== oldKey) {
                return;
            }
            row.scheduledDate = newDate;
            row.scheduledTime = normalizedTime;
            row.scheduledLen = normalizeScheduledLen(normalizedLength);
            refreshRowPlacementKeys(row);
            affectedRows++;
        });

        let touchedManual = false;
        state.manualTimeslots = state.manualTimeslots.map(function(slot) {
            const slotKey = [slot.teamEvent, slot.scheduledDate || '', slot.scheduledTime || ''].join('|');
            if (slotKey !== oldKey) {
                return slot;
            }

            touchedManual = true;
            return {
                teamEvent: slot.teamEvent,
                scheduledDate: newDate,
                scheduledTime: normalizedTime,
                scheduledLen: normalizeScheduledLen(normalizedLength)
            };
        });

        if (!affectedRows && !touchedManual) {
            showDragHint(laT('finals.nothingToUpdate'));
            return;
        }

        state.editingTimeslotKey = '';
        recomputeHasChanges();
        syncValidationWarnings(getTimeslots(), buildBundles(state.rows));
        renderAll();
        showStatus(laT('finals.timeslotUpdated'), 'success');
    }

    function parseApiDateTime(value) {
        const raw = (value || '').toString().trim();
        if (!raw) {
            return null;
        }

        const normalized = raw.replace(' ', 'T');
        const dateObj = new Date(normalized);
        if (Number.isNaN(dateObj.getTime())) {
            return null;
        }
        return dateObj;
    }

    function addInitialTimeslotsFromQualificationEnd() {
        if (getTimeslots().length > 0) {
            showDragHint(laT('finals.timeslotsExist'));
            return;
        }

        const baseDate = parseApiDateTime(state.lastQualificationEnd);
        if (!baseDate) {
            showDragHint(laT('finals.noQualEnd'));
            return;
        }

        const slotDurationMinutes = Math.max(1, parseInt(state.defaultFinalsLengthMinutes, 10) || 30);
        const firstSlotDate = new Date(baseDate.getTime());
        firstSlotDate.setMinutes(firstSlotDate.getMinutes() + slotDurationMinutes);

        const selectedTeamEvents = getFilterValues($('#team-event-filter').val());
        const filteredRows = getFilteredRows();
        const sourceRows = filteredRows.length ? filteredRows : state.rows;

        let teamEvents = [];
        if (selectedTeamEvents.length) {
            teamEvents = selectedTeamEvents.map(function(value) {
                return parseInt(value, 10) || 0;
            });
        } else {
            const seen = {};
            sourceRows.forEach(function(row) {
                const team = parseInt(row.teamEvent, 10) || 0;
                if (!seen[team]) {
                    seen[team] = true;
                    teamEvents.push(team);
                }
            });
        }

        if (!teamEvents.length) {
            teamEvents = [0];
        }

        let createdCount = 0;
        teamEvents.forEach(function(teamEvent) {
            const newSlot = {
                teamEvent: teamEvent,
                scheduledDate: formatDateIso(firstSlotDate),
                scheduledTime: formatTimeIso(firstSlotDate),
                scheduledLen: slotDurationMinutes
            };
            const key = [newSlot.teamEvent, newSlot.scheduledDate || '', newSlot.scheduledTime || ''].join('|');

            const existsInRows = state.rows.some(function(row) {
                return row.timeslotKey === key;
            });
            const existsInManual = state.manualTimeslots.some(function(slot) {
                return [slot.teamEvent, slot.scheduledDate || '', slot.scheduledTime || ''].join('|') === key;
            });

            if (!existsInRows && !existsInManual) {
                state.manualTimeslots.push(newSlot);
                createdCount++;
            }
        });

        if (!createdCount) {
            showDragHint(laT('finals.initialExists'));
            return;
        }

        recomputeHasChanges();
        syncValidationWarnings(getTimeslots(), buildBundles(state.rows));
        renderAll();
        showStatus(laTn('finals.addedInitial', createdCount), 'success');
    }

    function insertTimeslotAt(insertIndex) {
        const timeslots = getTimeslots();
        if (!timeslots.length) {
            showDragHint(laT('finals.noTimeslotsToInsert'));
            return;
        }

        const slotDurationMinutes = Math.max(1, parseInt(state.defaultFinalsLengthMinutes, 10) || 30);
        const safeIndex = Math.max(0, Math.min(insertIndex, timeslots.length));
        const referenceForInsert = safeIndex < timeslots.length ? timeslots[safeIndex] : timeslots[timeslots.length - 1];

        if (!referenceForInsert) {
            showDragHint(laT('finals.noInsertPosition'));
            return;
        }

        const shiftedSlots = timeslots.slice(safeIndex);
        const shiftMap = {};

        shiftedSlots.forEach(function(slot) {
            const shifted = addMinutesToTimeslot(slot, slotDurationMinutes);
            shiftMap[slot.key] = shifted;
        });

        state.rows.forEach(function(row) {
            const mapping = shiftMap[row.timeslotKey];
            if (!mapping) {
                return;
            }
            row.scheduledDate = mapping.scheduledDate;
            row.scheduledTime = mapping.scheduledTime;
            refreshRowPlacementKeys(row);
        });

        state.manualTimeslots = state.manualTimeslots.map(function(slot) {
            const key = [slot.teamEvent, slot.scheduledDate || '', slot.scheduledTime || ''].join('|');
            const mapping = shiftMap[key];
            if (!mapping) {
                return slot;
            }
            return {
                teamEvent: slot.teamEvent,
                scheduledDate: mapping.scheduledDate,
                scheduledTime: mapping.scheduledTime,
                scheduledLen: normalizeScheduledLen(slot.scheduledLen)
            };
        });

        let newSlotDate = referenceForInsert.scheduledDate;
        let newSlotTime = referenceForInsert.scheduledTime;
        if (safeIndex === timeslots.length) {
            const afterLast = addMinutesToTimeslot(referenceForInsert, slotDurationMinutes);
            newSlotDate = afterLast.scheduledDate;
            newSlotTime = afterLast.scheduledTime;
        }

        const newSlot = {
            teamEvent: referenceForInsert.teamEvent,
            scheduledDate: newSlotDate,
            scheduledTime: newSlotTime,
            scheduledLen: slotDurationMinutes
        };

        const newKey = [newSlot.teamEvent, newSlot.scheduledDate || '', newSlot.scheduledTime || ''].join('|');
        const existsInRows = state.rows.some(function(row) {
            return row.timeslotKey === newKey;
        });
        const existsInManual = state.manualTimeslots.some(function(slot) {
            return [slot.teamEvent, slot.scheduledDate || '', slot.scheduledTime || ''].join('|') === newKey;
        });

        if (!existsInRows && !existsInManual) {
            state.manualTimeslots.push(newSlot);
        }

        recomputeHasChanges();
        syncValidationWarnings(getTimeslots(), buildBundles(state.rows));
        renderAll();
    }

    function removeTimeslot(timeslotKey) {
        const key = (timeslotKey || '').toString();
        if (!key) {
            return;
        }

        const removedTimeslot = getTimeslotByKey(key);
        const removedSort = removedTimeslot
            ? buildTimeslotSortValue(removedTimeslot.scheduledDate, removedTimeslot.scheduledTime)
            : null;
        const removedTeamEvent = removedTimeslot ? (parseInt(removedTimeslot.teamEvent, 10) || 0) : null;

        let affectedRows = 0;
        state.rows.forEach(function(row) {
            if (row.timeslotKey !== key) {
                return;
            }

            row.target = '';
            row.scheduledDate = '';
            row.scheduledTime = '';
            row.scheduledLen = 0;
            refreshRowPlacementKeys(row);
            affectedRows++;
        });

        state.manualTimeslots = state.manualTimeslots.filter(function(slot) {
            const slotKey = [slot.teamEvent, slot.scheduledDate || '', slot.scheduledTime || ''].join('|');
            return slotKey !== key;
        });

        let shiftedTimeslots = 0;
        if (removedSort !== null && removedTeamEvent !== null) {
            const shiftMap = {};
            const followingSlots = getTimeslots().filter(function(slot) {
                const slotTeamEvent = parseInt(slot.teamEvent, 10) || 0;
                if (slotTeamEvent !== removedTeamEvent) {
                    return false;
                }

                const slotSort = buildTimeslotSortValue(slot.scheduledDate, slot.scheduledTime);
                return slotSort !== null && slotSort > removedSort;
            });

            let shiftMinutes = 0;
            if (followingSlots.length) {
                followingSlots.sort(function(a, b) {
                    const as = buildTimeslotSortValue(a.scheduledDate, a.scheduledTime) || '';
                    const bs = buildTimeslotSortValue(b.scheduledDate, b.scheduledTime) || '';
                    return as.localeCompare(bs);
                });

                const removedDateObj = parseTimeslotDateTime({
                    scheduledDate: removedTimeslot ? removedTimeslot.scheduledDate : '',
                    scheduledTime: removedTimeslot ? removedTimeslot.scheduledTime : ''
                });
                const nextDateObj = parseTimeslotDateTime({
                    scheduledDate: followingSlots[0].scheduledDate,
                    scheduledTime: followingSlots[0].scheduledTime
                });

                if (removedDateObj && nextDateObj) {
                    shiftMinutes = Math.round((nextDateObj.getTime() - removedDateObj.getTime()) / 60000);
                }
            }

            if (shiftMinutes > 0) {
                followingSlots.forEach(function(slot) {
                    const shifted = addMinutesToTimeslot(slot, -shiftMinutes);
                    shiftMap[slot.key] = shifted;
                });

                shiftedTimeslots = Object.keys(shiftMap).length;
            }

            state.rows.forEach(function(row) {
                const mapping = shiftMap[row.timeslotKey];
                if (!mapping) {
                    return;
                }

                row.scheduledDate = mapping.scheduledDate;
                row.scheduledTime = mapping.scheduledTime;
                refreshRowPlacementKeys(row);
            });

            state.manualTimeslots = state.manualTimeslots.map(function(slot) {
                const slotKey = [slot.teamEvent, slot.scheduledDate || '', slot.scheduledTime || ''].join('|');
                const mapping = shiftMap[slotKey];
                if (!mapping) {
                    return slot;
                }

                return {
                    teamEvent: slot.teamEvent,
                    scheduledDate: mapping.scheduledDate,
                    scheduledTime: mapping.scheduledTime,
                    scheduledLen: normalizeScheduledLen(slot.scheduledLen)
                };
            });
        }

        recomputeHasChanges();
        syncValidationWarnings(getTimeslots(), buildBundles(state.rows));
        renderAll();

        if (affectedRows > 0) {
            const shiftSuffix = shiftedTimeslots > 0
                ? ('; ' + laTn('finals.shiftedUp', shiftedTimeslots))
                : '';
            showStatus(laTn('finals.removedTimeslotRows', affectedRows) + shiftSuffix, 'info');
        } else {
            const shiftSuffix = shiftedTimeslots > 0
                ? ('; ' + laTn('finals.shiftedUp', shiftedTimeslots))
                : '';
            showStatus(laT('finals.removedEmptyTimeslot') + shiftSuffix, 'info');
        }
    }

    function getAutoAssignOptions() {
        const medalMode = ($('#auto-medal-mode').val() || 'earliest').toString();
        const scheduleStyle = ($('#auto-schedule-style').val() || 'compact').toString();
        return {
            medalMode: medalMode,
            separateStreams: $('#auto-separate-streams').is(':checked'),
            scheduleStyle: (scheduleStyle === 'alternating' ? 'alternating' : 'compact'),
            restrictDistanceLanes: $('#auto-restrict-distance-lanes').is(':checked')
        };
    }

    function isDistanceLaneRestrictionEnabled() {
        const $toggle = $('#auto-restrict-distance-lanes');
        if (!$toggle.length) {
            return true;
        }
        return $toggle.is(':checked');
    }

    function getNumericTargetNumbers() {
        return state.availableTargets
            .map(parseTargetNumber)
            .filter(function(value) { return value !== null; })
            .sort(function(a, b) { return a - b; });
    }

    function getBundleDistanceKey(bundle) {
        const key = (bundle.distanceProfile || '').toString().trim();
        return key !== '' ? key : 'unknown';
    }

    function getBundlePrimaryDistance(bundle) {
        const raw = (bundle && bundle.distanceProfile ? bundle.distanceProfile : '').toString().trim();
        if (!raw) {
            return '';
        }

        const first = raw.split('-')[0] || '';
        return first.toString().trim();
    }

    function buildAssignedTargetDistanceMap(excludeBundleKey) {
        const map = {};

        Object.keys(state.targetDistanceLocks || {}).forEach(function(targetKey) {
            const targetNo = parseInt(targetKey, 10);
            if (Number.isNaN(targetNo)) {
                return;
            }

            const distance = (state.targetDistanceLocks[targetKey] || '').toString().trim();
            if (!distance) {
                return;
            }

            map[targetNo] = distance;
        });

        const bundles = buildBundles(state.rows);

        bundles.forEach(function(bundle) {
            if (!bundle.isPlayable || bundle.startTargetNum === null) {
                return;
            }
            if (excludeBundleKey && bundle.key === excludeBundleKey) {
                return;
            }

            const distance = getBundlePrimaryDistance(bundle);
            if (!distance) {
                return;
            }

            for (let index = 0; index < bundle.targetsUsed; index++) {
                const targetNo = bundle.startTargetNum + index;
                if (!map[targetNo]) {
                    map[targetNo] = distance;
                }
            }
        });

        return map;
    }

    function buildTimeslotDistanceTimeline(timeslots) {
        const timeline = {
            byTimeslot: {},
            changedTargetsByTimeslot: {}
        };

        const laneState = {};
        Object.keys(state.targetDistanceLocks || {}).forEach(function(targetKey) {
            const targetNo = parseInt(targetKey, 10);
            if (Number.isNaN(targetNo)) {
                return;
            }

            const distance = (state.targetDistanceLocks[targetKey] || '').toString().trim();
            if (!distance) {
                return;
            }

            laneState[targetNo] = distance;
        });

        (timeslots || []).forEach(function(timeslot) {
            const changedTargets = {};
            const bundles = buildBundles(timeslot.rows || []);

            bundles.forEach(function(bundle) {
                if (!bundle.isPlayable || !bundle.isAssigned) {
                    return;
                }

                const distance = getBundlePrimaryDistance(bundle);
                if (!distance) {
                    return;
                }

                const targets = (Array.isArray(bundle.occupiedTargets) && bundle.occupiedTargets.length)
                    ? bundle.occupiedTargets.slice()
                    : (bundle.startTargetNum === null ? [] : Array.from({ length: bundle.targetsUsed }, function(_, index) {
                        return bundle.startTargetNum + index;
                    }));

                targets.forEach(function(targetNo) {
                    const numericTarget = parseInt(targetNo, 10);
                    if (Number.isNaN(numericTarget)) {
                        return;
                    }

                    const previousDistance = (laneState[numericTarget] || '').toString().trim();
                    if (previousDistance !== distance) {
                        if (previousDistance !== '') {
                            changedTargets[numericTarget] = true;
                        }
                        laneState[numericTarget] = distance;
                    }
                });
            });

            timeline.byTimeslot[timeslot.key] = Object.assign({}, laneState);
            timeline.changedTargetsByTimeslot[timeslot.key] = changedTargets;
        });

        return timeline;
    }

    function buildDistanceLocksFromLanePlans(lanePlanByTeam) {
        const locks = {};
        const plans = lanePlanByTeam || {};

        const teamKeys = Object.keys(plans);
        for (let i = 0; i < teamKeys.length; i++) {
            const plan = plans[teamKeys[i]];
            if (!plan || !plan.ranges) {
                continue;
            }

            const distanceKeys = Object.keys(plan.ranges);
            for (let d = 0; d < distanceKeys.length; d++) {
                const distanceKey = distanceKeys[d];
                if (distanceKey === 'unknown') {
                    continue;
                }

                const primaryDistance = (distanceKey.split('-')[0] || '').toString().trim();
                if (!primaryDistance) {
                    continue;
                }

                const range = plan.ranges[distanceKey];
                if (!range || typeof range.start === 'undefined' || typeof range.end === 'undefined') {
                    continue;
                }

                for (let targetNo = range.start; targetNo <= range.end; targetNo++) {
                    if (!locks[targetNo]) {
                        locks[targetNo] = primaryDistance;
                    } else if (locks[targetNo] !== primaryDistance) {
                        return {
                            valid: false,
                            message: laT('finals.conflictingDistanceLanes', { target: targetNo, a: locks[targetNo], b: primaryDistance })
                        };
                    }
                }
            }
        }

        return {
            valid: true,
            locks: locks
        };
    }

    function getDistancePlacementMismatch(bundle, startTargetNum, targetDistanceMap) {
        const bundleDistance = getBundlePrimaryDistance(bundle);
        if (!bundleDistance) {
            return null;
        }

        const map = targetDistanceMap || {};
        for (let index = 0; index < bundle.targetsUsed; index++) {
            const targetNo = startTargetNum + index;
            const fixedDistance = (map[targetNo] || '').toString().trim();
            if (!fixedDistance) {
                continue;
            }
            if (fixedDistance !== bundleDistance) {
                return {
                    targetNo: targetNo,
                    expected: fixedDistance,
                    actual: bundleDistance
                };
            }
        }

        return null;
    }

    function buildDistanceLanePlan(teamEvent, bundles, targetNumbers) {
        return distanceLanePlan.buildDistanceLanePlan(teamEvent, bundles, targetNumbers);
    }

    function canPlaceBundleForDistance(bundle, startTargetNum, lanePlan) {
        if (!lanePlan) {
            return true;
        }

        const distanceKey = getBundleDistanceKey(bundle);
        if (distanceKey === 'unknown') {
            return true;
        }

        const range = (lanePlan.placementRanges || lanePlan.ranges)[distanceKey] || null;
        if (!range) {
            return false;
        }

        const endTarget = startTargetNum + bundle.targetsUsed - 1;
        return startTargetNum >= range.start && endTarget <= range.end;
    }

    function buildRoundRobinBundleOrder(bundles, separateStreams, preferWiderFirst, preferAlternating) {
        if (!preferAlternating) {
            return (bundles || []).slice().sort(function(a, b) {
                const aw = parseInt(a.targetsUsed, 10) || 0;
                const bw = parseInt(b.targetsUsed, 10) || 0;
                if (bw !== aw) {
                    return bw - aw;
                }

                const ads = parseInt(a.distanceSort, 10);
                const bds = parseInt(b.distanceSort, 10);
                const aDist = Number.isNaN(ads) ? Number.MAX_SAFE_INTEGER : ads;
                const bDist = Number.isNaN(bds) ? Number.MAX_SAFE_INTEGER : bds;
                if (aDist !== bDist) {
                    return aDist - bDist;
                }

                const ae = (a.event || '').toString();
                const be = (b.event || '').toString();
                if (ae !== be) {
                    return ae.localeCompare(be);
                }

                return (parseInt(a.group, 10) || 0) - (parseInt(b.group, 10) || 0);
            });
        }

        const queues = {};
        const orderedKeys = [];

        bundles.forEach(function(bundle) {
            const teamEvent = parseInt(bundle.rows[0] ? bundle.rows[0].teamEvent : 0, 10) || 0;
            const key = (separateStreams ? (teamEvent + '|') : '') + (bundle.event || '');
            if (!queues[key]) {
                queues[key] = [];
                orderedKeys.push(key);
            }
            queues[key].push(bundle);
        });

        if (preferWiderFirst) {
            orderedKeys.forEach(function(key) {
                queues[key].sort(function(a, b) {
                    const aw = parseInt(a.targetsUsed, 10) || 0;
                    const bw = parseInt(b.targetsUsed, 10) || 0;
                    if (bw !== aw) {
                        return bw - aw;
                    }
                    const ae = (a.event || '').toString();
                    const be = (b.event || '').toString();
                    if (ae !== be) {
                        return ae.localeCompare(be);
                    }
                    return (parseInt(a.group, 10) || 0) - (parseInt(b.group, 10) || 0);
                });
            });
        }

        const output = [];
        let hasItems = true;
        while (hasItems) {
            hasItems = false;
            orderedKeys.forEach(function(key) {
                if (queues[key] && queues[key].length) {
                    output.push(queues[key].shift());
                    hasItems = true;
                }
            });
        }

        return output;
    }

    function buildAutoAssignDependencyList(options) {
        const dependencies = PHASE_DEPENDENCIES.slice();
        if (options.medalMode === 'bronze-first') {
            dependencies.push({ before: 1, after: 0 });
        }
        return dependencies;
    }

    function makeAutoTimeslotKey(teamEvent, dateValue, timeValue) {
        return [teamEvent, dateValue || '', timeValue || ''].join('|');
    }

    function createTimeslotFromReference(reference, teamEvent) {
        return {
            key: makeAutoTimeslotKey(teamEvent, reference.scheduledDate, reference.scheduledTime),
            teamEvent: teamEvent,
            scheduledDate: reference.scheduledDate,
            scheduledTime: reference.scheduledTime,
            scheduledLen: normalizeScheduledLen(reference.scheduledLen),
            rows: []
        };
    }

    function autoAssignFinals() {
        const options = getAutoAssignOptions();
        const targetNumbers = getNumericTargetNumbers();
        if (!targetNumbers.length) {
            showDragHint(laT('finals.noTargetsConfigured'));
            return;
        }

        const allBundles = buildBundles(state.rows);
        const playableBundles = allBundles.filter(function(bundle) {
            return bundle.isPlayable;
        });

        if (!playableBundles.length) {
            showStatus(laT('finals.noBundlesToAssign'), 'info');
            return;
        }

        const baseTimeslots = getTimeslots().map(function(slot) {
            return {
                key: slot.key,
                teamEvent: parseInt(slot.teamEvent, 10) || 0,
                scheduledDate: slot.scheduledDate,
                scheduledTime: slot.scheduledTime,
                scheduledLen: normalizeScheduledLen(slot.scheduledLen),
                rows: []
            };
        });

        if (!baseTimeslots.length) {
            showDragHint(laT('finals.createTimeslotFirst'));
            return;
        }

        const timeslotsByTeam = {};
        baseTimeslots.forEach(function(slot) {
            const teamEvent = parseInt(slot.teamEvent, 10) || 0;
            if (!timeslotsByTeam[teamEvent]) {
                timeslotsByTeam[teamEvent] = [];
            }
            timeslotsByTeam[teamEvent].push(slot);
        });

        Object.keys(timeslotsByTeam).forEach(function(teamEvent) {
            timeslotsByTeam[teamEvent].sort(function(a, b) {
                return (a.scheduledDate + ' ' + a.scheduledTime).localeCompare(b.scheduledDate + ' ' + b.scheduledTime);
            });
        });

        state.manualTimeslots = [];

        var savedRowState = state.rows.map(function(row) {
            return { key: row.key, target: row.target, scheduledDate: row.scheduledDate, scheduledTime: row.scheduledTime, scheduledLen: row.scheduledLen };
        });

        function restoreRowState() {
            var lookup = {};
            savedRowState.forEach(function(s) { lookup[s.key] = s; });
            state.rows.forEach(function(row) {
                var saved = lookup[row.key];
                if (saved) {
                    row.target = saved.target;
                    row.scheduledDate = saved.scheduledDate;
                    row.scheduledTime = saved.scheduledTime;
                    row.scheduledLen = saved.scheduledLen;
                    refreshRowPlacementKeys(row);
                }
            });
        }

        allBundles.forEach(function(bundle) {
            bundle.rows.forEach(function(row) {
                row.target = '';
                row.scheduledDate = '';
                row.scheduledTime = '';
                row.scheduledLen = 0;
                refreshRowPlacementKeys(row);
            });
        });

        const dependencies = buildAutoAssignDependencyList(options);
        const placedByTimeslot = {};
        const placedPhaseMaxIndex = {};
        const lanePlanByTeam = {};

        if (options.restrictDistanceLanes) {
            Object.keys(timeslotsByTeam).forEach(function(teamEvent) {
                const numericTeamEvent = parseInt(teamEvent, 10) || 0;
                const plan = buildDistanceLanePlan(numericTeamEvent, playableBundles, targetNumbers);
                lanePlanByTeam[numericTeamEvent] = plan;
            });

            const invalidPlanTeam = Object.keys(lanePlanByTeam).find(function(teamEvent) {
                return lanePlanByTeam[teamEvent] === null;
            });
            if (typeof invalidPlanTeam !== 'undefined') {
                showStatus((parseInt(invalidPlanTeam, 10) || 0) === 1
                    ? laT('finals.autoFailedLanesTeam')
                    : laT('finals.autoFailedLanesIndividual'), 'error');
                restoreRowState();
                return;
            }

            const distanceLocksResult = buildDistanceLocksFromLanePlans(lanePlanByTeam);
            if (!distanceLocksResult.valid) {
                showStatus(laT('finals.autoFailedReason', { reason: distanceLocksResult.message || laT('finals.distanceLaneConflict') }), 'error');
                restoreRowState();
                return;
            }

            state.targetDistanceLocks = distanceLocksResult.locks || {};
        } else {
            state.targetDistanceLocks = {};
        }

        function ensureTeamStream(teamEvent) {
            if (timeslotsByTeam[teamEvent] && timeslotsByTeam[teamEvent].length) {
                return timeslotsByTeam[teamEvent];
            }

            const anchor = baseTimeslots[0];
            if (!anchor) {
                return null;
            }

            const first = createTimeslotFromReference(anchor, teamEvent);
            timeslotsByTeam[teamEvent] = [first];
            state.manualTimeslots.push({
                teamEvent: teamEvent,
                scheduledDate: first.scheduledDate,
                scheduledTime: first.scheduledTime,
                scheduledLen: normalizeScheduledLen(first.scheduledLen)
            });
            return timeslotsByTeam[teamEvent];
        }

        function appendNextTimeslot(teamEvent) {
            const stream = ensureTeamStream(teamEvent);
            if (!stream || !stream.length) {
                return null;
            }

            const last = stream[stream.length - 1];
            const shifted = addMinutesToTimeslot(last, Math.max(1, parseInt(state.defaultFinalsLengthMinutes, 10) || 30));
            const next = {
                key: makeAutoTimeslotKey(teamEvent, shifted.scheduledDate, shifted.scheduledTime),
                teamEvent: teamEvent,
                scheduledDate: shifted.scheduledDate,
                scheduledTime: shifted.scheduledTime,
                scheduledLen: normalizeScheduledLen(last.scheduledLen),
                rows: []
            };
            stream.push(next);
            state.manualTimeslots.push({
                teamEvent: teamEvent,
                scheduledDate: next.scheduledDate,
                scheduledTime: next.scheduledTime,
                scheduledLen: normalizeScheduledLen(next.scheduledLen)
            });
            return next;
        }

        function getEventPhaseMinIndex(bundle) {
            const teamEvent = parseInt(bundle.rows[0] ? bundle.rows[0].teamEvent : 0, 10) || 0;
            const eventCode = (bundle.event || '').toString();
            const phase = parseInt(bundle.phase, 10) || 0;
            const eventKey = teamEvent + '|' + eventCode;
            const phaseMap = placedPhaseMaxIndex[eventKey] || {};
            let minIndex = 0;

            dependencies.forEach(function(dep) {
                if (dep.after !== phase) {
                    return;
                }
                if (typeof phaseMap[dep.before] === 'number') {
                    minIndex = Math.max(minIndex, phaseMap[dep.before] + 1);
                }
            });

            return minIndex;
        }

        function findFirstTargetStart(bundle, bundlesInSlot, lanePlan) {
            for (let index = 0; index < targetNumbers.length; index++) {
                const startTarget = targetNumbers[index];
                if (!canPlaceBundleForDistance(bundle, startTarget, lanePlan)) {
                    continue;
                }
                if (canPlaceBundle(bundle, startTarget, bundlesInSlot)) {
                    return startTarget;
                }
            }
            return null;
        }

        function placeBundle(bundle) {
            const teamEvent = parseInt(bundle.rows[0] ? bundle.rows[0].teamEvent : 0, 10) || 0;
            const stream = ensureTeamStream(teamEvent);
            if (!stream) {
                return { ok: false, message: laT('finals.noTimeslotStream', { type: teamEvent }) };
            }

            const minIndex = getEventPhaseMinIndex(bundle);
            let slotIndex = Math.max(0, minIndex);

            while (true) {
                if (slotIndex >= stream.length) {
                    const created = appendNextTimeslot(teamEvent);
                    if (!created) {
                        return { ok: false, message: laT('finals.cannotCreateTimeslots') };
                    }
                }

                const slot = stream[slotIndex];
                if (!placedByTimeslot[slot.key]) {
                    placedByTimeslot[slot.key] = [];
                }

                const lanePlan = lanePlanByTeam[teamEvent] || null;
                const startTarget = findFirstTargetStart(bundle, placedByTimeslot[slot.key], lanePlan);
                if (startTarget !== null) {
                    moveBundleToTimeslot(bundle, slot, bundle.rowsToPlace);
                    assignBundleToStart(bundle, startTarget);
                    placedByTimeslot[slot.key].push({
                        key: bundle.key,
                        startTargetNum: startTarget,
                        targetsUsed: bundle.targetsUsed
                    });

                    const eventKey = teamEvent + '|' + (bundle.event || '').toString();
                    const phase = parseInt(bundle.phase, 10) || 0;
                    if (!placedPhaseMaxIndex[eventKey]) {
                        placedPhaseMaxIndex[eventKey] = {};
                    }
                    placedPhaseMaxIndex[eventKey][phase] = Math.max(placedPhaseMaxIndex[eventKey][phase] || 0, slotIndex);
                    return { ok: true };
                }

                slotIndex++;
            }
        }

        const phaseBuckets = {};
        playableBundles.forEach(function(bundle) {
            const phase = parseInt(bundle.phase, 10) || 0;
            if (!phaseBuckets[phase]) {
                phaseBuckets[phase] = [];
            }
            phaseBuckets[phase].push(bundle);
        });

        const regularPhases = [64, 32, 16, 8, 4, 2];
        const preferWiderFirst = options.scheduleStyle === 'compact';
        const preferAlternating = options.scheduleStyle === 'alternating';
        for (let idx = 0; idx < regularPhases.length; idx++) {
            const phase = regularPhases[idx];
            const ordered = buildRoundRobinBundleOrder(phaseBuckets[phase] || [], options.separateStreams, preferWiderFirst, preferAlternating);
            for (let b = 0; b < ordered.length; b++) {
                const result = placeBundle(ordered[b]);
                if (!result.ok) {
                    showStatus(result.message || laT('finals.autoFailed'), 'error');
                    restoreRowState();
                    return;
                }
            }
        }

        if (options.medalMode === 'together') {
            const medals = [];
            (phaseBuckets[1] || []).forEach(function(bundle) { medals.push(bundle); });
            (phaseBuckets[0] || []).forEach(function(bundle) { medals.push(bundle); });
            const orderedMedals = buildRoundRobinBundleOrder(medals, options.separateStreams, preferWiderFirst, preferAlternating);
            for (let m = 0; m < orderedMedals.length; m++) {
                const result = placeBundle(orderedMedals[m]);
                if (!result.ok) {
                    showStatus(result.message || laT('finals.autoFailed'), 'error');
                    restoreRowState();
                    return;
                }
            }
        } else if (options.medalMode === 'bronze-first') {
            const bronzeFirst = [1, 0];
            for (let p = 0; p < bronzeFirst.length; p++) {
                const phase = bronzeFirst[p];
                const ordered = buildRoundRobinBundleOrder(phaseBuckets[phase] || [], options.separateStreams, preferWiderFirst, preferAlternating);
                for (let b = 0; b < ordered.length; b++) {
                    const result = placeBundle(ordered[b]);
                    if (!result.ok) {
                        showStatus(result.message || laT('finals.autoFailed'), 'error');
                        return;
                    }
                }
            }
        } else {
            const medals = [];
            (phaseBuckets[1] || []).forEach(function(bundle) { medals.push(bundle); });
            (phaseBuckets[0] || []).forEach(function(bundle) { medals.push(bundle); });
            const orderedMedals = buildRoundRobinBundleOrder(medals, options.separateStreams, preferWiderFirst, preferAlternating);
            for (let m = 0; m < orderedMedals.length; m++) {
                const result = placeBundle(orderedMedals[m]);
                if (!result.ok) {
                    showStatus(result.message || laT('finals.autoFailed'), 'error');
                    restoreRowState();
                    return;
                }
            }
        }

        state.manualTimeslots = state.manualTimeslots.filter(function(slot, index, arr) {
            const key = makeAutoTimeslotKey(slot.teamEvent, slot.scheduledDate, slot.scheduledTime);
            return arr.findIndex(function(other) {
                return makeAutoTimeslotKey(other.teamEvent, other.scheduledDate, other.scheduledTime) === key;
            }) === index;
        });

        recomputeHasChanges();
        syncValidationWarnings(getTimeslots(), buildBundles(state.rows));
        renderAll();
        if (state.validationWarnings.length) {
            showStatus(laTn('finals.autoDoneWithIssues', state.validationWarnings.length, { first: state.validationWarnings[0] }), 'error');
        } else {
            showStatus(laT('finals.autoDone'), 'success');
        }
    }

    function buildBundles(rows) {
        const map = {};

        rows.forEach(function(row) {
            if (!map[row.bundleKey]) {
                map[row.bundleKey] = {
                    key: row.bundleKey,
                    timeslotKey: row.timeslotKey,
                    teamEvent: row.teamEvent,
                    event: row.event,
                    eventName: row.eventName,
                    group: row.group,
                    phase: row.phase,
                    phaseLabel: row.phaseLabel,
                    distanceProfile: row.distanceProfile || '',
                    distanceSort: row.distanceSort,
                    division: row.division,
                    classCode: row.classCode,
                    rows: []
                };
            }
            map[row.bundleKey].rows.push(row);
        });

        return Object.values(map).map(function(bundle) {
            bundle.rows.sort(function(a, b) { return a.matchNo - b.matchNo; });
            bundle.size = bundle.rows.length;
            bundle.archersPerTarget = (bundle.rows[0] && bundle.rows[0].archersPerTarget === 2) ? 2 : 1;
            bundle.pairs = {};
            bundle.nonPlayableScheduledPairs = [];
            bundle.occupiedTargets = bundle.rows.map(function(row) {
                return parseTargetNumber(row.target);
            }).filter(function(targetNo) {
                return targetNo !== null;
            }).filter(function(targetNo, index, all) {
                return all.indexOf(targetNo) === index;
            }).sort(function(a, b) {
                return a - b;
            });
            bundle.isAssigned = bundle.occupiedTargets.length > 0;
            bundle.nonContiguousPlacement = false;

            bundle.rows.forEach(function(row) {
                const pairNo = Math.floor((parseInt(row.matchNo, 10) || 0) / 2);
                if (!bundle.pairs[pairNo]) {
                    bundle.pairs[pairNo] = [];
                }
                bundle.pairs[pairNo].push(row);
            });

            const playableRows = [];
            Object.keys(bundle.pairs).forEach(function(pairNo) {
                const pairRows = bundle.pairs[pairNo].sort(function(a, b) { return a.matchNo - b.matchNo; });

                const pairHasAssignedTarget = pairRows.some(function(row) {
                    return parseTargetNumber(row.target) !== null;
                });

                if (playability.isPairPlayable(pairRows)) {
                    pairRows.forEach(function(row) {
                        playableRows.push(row);
                    });
                } else if (pairHasAssignedTarget) {
                    bundle.nonPlayableScheduledPairs.push({
                        pairNo: parseInt(pairNo, 10),
                        rows: pairRows.slice()
                    });
                }
            });

            bundle.isPlayable = playableRows.length > 0;
            bundle.rowsToPlace = playableRows;

            if (!bundle.isPlayable) {
                bundle.totalArchers = bundle.occupiedTargets.length;
                bundle.matchCount = 0;
                bundle.targetsUsed = Math.max(1, bundle.occupiedTargets.length);
                bundle.startTargetNum = bundle.occupiedTargets.length ? bundle.occupiedTargets[0] : null;
                bundle.nonContiguousPlacement = bundle.occupiedTargets.length > 1;
                return bundle;
            }

            bundle.totalArchers = bundle.rowsToPlace.length;
            bundle.matchCount = Math.max(1, Math.ceil(bundle.rowsToPlace.length / 2));
            bundle.targetsUsed = Math.max(1, Math.ceil(bundle.totalArchers / bundle.archersPerTarget));

            const targetNumbers = bundle.rowsToPlace.map(function(row) {
                return parseTargetNumber(row.target);
            });

            const allAssigned = targetNumbers.every(function(value) { return value !== null; });
            if (!allAssigned) {
                bundle.startTargetNum = null;
                return bundle;
            }

            const uniqueSortedTargets = targetNumbers
                .filter(function(value) { return value !== null; })
                .filter(function(value, index, arr) { return arr.indexOf(value) === index; })
                .sort(function(a, b) { return a - b; });
            bundle.occupiedTargets = uniqueSortedTargets;
            bundle.isAssigned = uniqueSortedTargets.length > 0;

            const sorted = targetNumbers.slice().sort(function(a, b) { return a - b; });
            const startTarget = sorted[0];
            const expected = [];
            for (let index = 0; index < bundle.rowsToPlace.length; index++) {
                expected.push(startTarget + Math.floor(index / bundle.archersPerTarget));
            }

            const contiguousByCapacity = sorted.length === expected.length &&
                sorted.every(function(value, index) {
                    return value === expected[index];
                });

            if (contiguousByCapacity) {
                bundle.startTargetNum = startTarget;
                bundle.nonContiguousPlacement = false;
            } else {
                bundle.startTargetNum = uniqueSortedTargets.length ? uniqueSortedTargets[0] : null;
                bundle.nonContiguousPlacement = true;
            }
            return bundle;
        }).sort(function(a, b) {
            if (a.phase !== b.phase) {
                return a.phase - b.phase;
            }
            if (a.event !== b.event) {
                return a.event.localeCompare(b.event);
            }
            return a.group - b.group;
        });
    }

    function renderAll() {
        const filteredRows = getFilteredRows();
        const timeslots = getTimeslots(filteredRows);
        const allBundles = buildBundles(filteredRows);

        syncValidationWarnings(timeslots, allBundles);

        if (!state.hasChanges && state.serverValidationWarnings.length) {
            const merged = state.validationWarnings.slice();
            const seen = {};
            merged.forEach(function(message) {
                seen[message] = true;
            });

            state.serverValidationWarnings.forEach(function(message) {
                if (!seen[message]) {
                    seen[message] = true;
                    merged.push(message);
                }
            });

            state.validationWarnings = merged;
        }

        const hiddenBundlesCount = allBundles.filter(function(bundle) {
            return !bundle.isPlayable;
        }).length;

        renderUnassigned(allBundles);
        renderTargetsArea(timeslots);
        updateSummary(hiddenBundlesCount, filteredRows);
        updateUI();
        makeBundlesDraggable();
        makeDroppableZones();
        updateViewportLayout();
    }

    function renderUnassigned(bundles) {
        const $list = $('#unassigned-list');
        $list.empty();

        const unassignedBundles = bundles.filter(function(bundle) {
            return bundle.isPlayable && !bundle.isAssigned;
        });

        const invalidScheduledBundles = bundles.filter(function(bundle) {
            return !bundle.isPlayable && bundleHasAssignedTarget(bundle);
        });

        const invalidScheduledPairBundles = buildInvalidScheduledPairBundles(bundles);

        const combinedBundles = unassignedBundles.concat(invalidScheduledBundles, invalidScheduledPairBundles);

        $('#unassigned-count').text(combinedBundles.length);

        if (combinedBundles.length === 0) {
            $list.append($('<div class="empty"></div>').text(laT('finals.allGroupsAssigned')));
            return;
        }

        combinedBundles.forEach(function(bundle) {
            $list.append(createBundleCard(bundle));
        });
    }

    function buildInvalidScheduledPairBundles(bundles) {
        const invalidScheduledPairBundles = [];

        (bundles || []).forEach(function(bundle) {
            if (!bundle.isPlayable) {
                return;
            }

            const invalidPairs = Array.isArray(bundle.nonPlayableScheduledPairs) ? bundle.nonPlayableScheduledPairs : [];
            invalidPairs.forEach(function(pair, pairIndex) {
                const pairRows = (pair && Array.isArray(pair.rows)) ? pair.rows.slice() : [];
                if (!pairRows.length) {
                    return;
                }

                const occupiedTargets = pairRows.map(function(row) {
                    return parseTargetNumber(row.target);
                }).filter(function(targetNo) {
                    return targetNo !== null;
                }).filter(function(targetNo, index, all) {
                    return all.indexOf(targetNo) === index;
                }).sort(function(a, b) {
                    return a - b;
                });

                const rowKeys = pairRows.map(function(row) {
                    return row.key;
                }).filter(function(value) {
                    return !!value;
                });

                const syntheticKey = [bundle.key, 'nonPlayablePair', String(parseInt(pair.pairNo, 10) || pairIndex)].join('|');
                const warningText = laT('finals.pairNotPlayable');

                invalidScheduledPairBundles.push({
                    key: syntheticKey,
                    timeslotKey: bundle.timeslotKey,
                    teamEvent: bundle.teamEvent,
                    event: bundle.event,
                    eventName: bundle.eventName,
                    group: bundle.group,
                    phase: bundle.phase,
                    phaseLabel: bundle.phaseLabel,
                    distanceProfile: bundle.distanceProfile,
                    distanceSort: bundle.distanceSort,
                    division: bundle.division,
                    classCode: bundle.classCode,
                    rows: pairRows,
                    rowsToPlace: pairRows,
                    archersPerTarget: bundle.archersPerTarget,
                    totalArchers: pairRows.length,
                    matchCount: 0,
                    targetsUsed: Math.max(1, occupiedTargets.length),
                    occupiedTargets: occupiedTargets,
                    startTargetNum: occupiedTargets.length ? occupiedTargets[0] : null,
                    isAssigned: occupiedTargets.length > 0,
                    isPlayable: false,
                    nonContiguousPlacement: occupiedTargets.length > 1,
                    warningText: warningText,
                    clearRowKeys: rowKeys
                });
            });
        });

        return invalidScheduledPairBundles;
    }

    function renderTargetsArea(timeslots) {
        const $board = $('#visual-board');
        const previousSharedScroll = $board.find('.targets-scroll-sync').first().scrollLeft() || 0;
        const distanceTimeline = buildTimeslotDistanceTimeline(timeslots);

        const aggregatedDistanceMap = {};
        Object.keys(distanceTimeline.byTimeslot || {}).forEach(function(timeslotKey) {
            const map = distanceTimeline.byTimeslot[timeslotKey] || {};
            Object.keys(map).forEach(function(targetKey) {
                const distanceValue = (map[targetKey] || '').toString().trim();
                if (!distanceValue) {
                    return;
                }
                aggregatedDistanceMap[targetKey] = distanceValue;
            });
        });

        const uniqueDistances = Object.keys(aggregatedDistanceMap)
            .map(function(targetKey) {
                return (aggregatedDistanceMap[targetKey] || '').toString().trim();
            })
            .filter(function(distance, index, arr) {
                return distance !== '' && arr.indexOf(distance) === index;
            })
            .sort(function(a, b) {
                const an = parseInt(a, 10);
                const bn = parseInt(b, 10);
                if (!Number.isNaN(an) && !Number.isNaN(bn) && an !== bn) {
                    return an - bn;
                }
                return a.localeCompare(b);
            });

        const distanceColorMap = {};
        uniqueDistances.forEach(function(distance, index) {
            distanceColorMap[distance] = ColorBy.paletteAt(index);
        });

        $board.empty();

        if (timeslots.length === 0) {
            if (!state.rows.length) {
                $board.append($('<div class="empty"></div>').text(laT('finals.noData')));
                return;
            }

            const hasQualifierEnd = !!parseApiDateTime(state.lastQualificationEnd);
            const emptyHtml = [
                '<div class="empty">',
                '<div>' + escapeHtml(laT('finals.noTimeslots')) + '</div>',
                '<div style="margin-top:6px;">',
                escapeHtml(hasQualifierEnd ? laT('finals.createFirstFromQual') : laT('finals.setQualEnd')),
                '</div>',
                '<div style="margin-top:10px;">',
                '<button type="button" class="timeslot-empty-add-btn timeslot-bootstrap-btn" title="' + escapeHtml(laT('finals.addFirstTimeslotHelp')) + '" aria-label="' + escapeHtml(laT('finals.addFirstTimeslotHelp')) + '"><i class="fa fa-plus"></i> ' + escapeHtml(laT('finals.addFirstTimeslot')) + '</button>',
                '</div>',
                '</div>'
            ].join('');
            $board.append(emptyHtml);
            return;
        }

        if (state.availableTargets.length === 0) {
            $board.append($('<div class="empty"></div>').text(laT('finals.noTargetsDetected')));
            return;
        }

        $board.append('<div class="timeslot-insert-anchor"><button type="button" class="timeslot-insert-btn" data-insert-index="0" title="' + escapeHtml(laT('finals.insertBeforeFirst')) + '" aria-label="' + escapeHtml(laT('finals.insertBeforeFirst')) + '"><i class="fa fa-plus"></i></button></div>');

        let renderedSections = 0;

        timeslots.forEach(function(timeslot, index) {
            const bundles = buildBundles(timeslot.rows);
            const invalidPairBundlesInSlot = buildInvalidScheduledPairBundles(bundles).filter(function(bundle) {
                return bundle.timeslotKey === timeslot.key && bundle.isAssigned;
            });
            const playableBundles = bundles.filter(function(bundle) { return bundle.isPlayable; });
            const assignedNonPlayableBundles = bundles.filter(function(bundle) {
                return !bundle.isPlayable && bundle.isAssigned;
            });
            const showEmptyTimeslots = $('#show-empty-timeslots').is(':checked');

            if (!showEmptyTimeslots && playableBundles.length === 0 && assignedNonPlayableBundles.length === 0 && invalidPairBundlesInSlot.length === 0) {
                return;
            }

            const typeText = timeslot.teamEvent === 1 ? laT('finals.typeTeamOption') : laT('finals.typeIndividualOption');
            const slotLenMinutes = normalizeScheduledLen(timeslot.scheduledLen);
            const startTimeDisplay = ((timeslot.scheduledTime || '').toString().trim() || '00:00:00').substring(0, 5);
            const endTimeData = addMinutesToTimeslot({
                scheduledDate: timeslot.scheduledDate,
                scheduledTime: timeslot.scheduledTime
            }, slotLenMinutes);
            const endTimeDisplay = ((endTimeData.scheduledTime || '').toString().trim() || '00:00:00').substring(0, 5);
            const title = typeText + ' • ' + (timeslot.scheduledDate || '-') + ' ' + startTimeDisplay + '-' + endTimeDisplay + ' (' + slotLenMinutes + 'm)';
            const isEditing = (state.editingTimeslotKey === timeslot.key);
            const editDateValue = (timeslot.scheduledDate || '').toString().trim();
            const editTimeValue = ((timeslot.scheduledTime || '').toString().trim() || '00:00:00').substring(0, 5);
            const editLengthValue = String(slotLenMinutes);

            const $section = $('<div class="timeslot-section"></div>').attr('data-timeslot-key', timeslot.key);
            renderedSections++;
            const $title = $('<div class="timeslot-title"></div>');
            $title.append('<span class="timeslot-title-text">' + escapeHtml(title) + '</span>');

            let actionsHtml = '';
            if (isEditing) {
                actionsHtml = [
                    '<div class="timeslot-actions">',
                    '<div class="timeslot-edit-inline">',
                    '<input type="date" class="timeslot-edit-date" value="' + escapeHtml(editDateValue) + '">',
                    '<input type="time" class="timeslot-edit-time" value="' + escapeHtml(editTimeValue) + '">',
                    '<input type="number" min="1" step="1" class="timeslot-edit-length" value="' + escapeHtml(editLengthValue) + '" title="' + escapeHtml(laT('finals.lengthMinutes')) + '">',
                    '<button type="button" class="timeslot-edit-save-btn" data-timeslot-key="' + escapeHtml(timeslot.key) + '" title="' + escapeHtml(laT('finals.saveTimeslot')) + '"><i class="fa fa-check"></i></button>',
                    '<button type="button" class="timeslot-edit-cancel-btn" data-timeslot-key="' + escapeHtml(timeslot.key) + '" title="' + escapeHtml(laT('finals.cancelEdit')) + '"><i class="fa fa-times"></i></button>',
                    '</div>',
                    '<button type="button" class="timeslot-delete-btn" data-timeslot-key="' + escapeHtml(timeslot.key) + '" title="' + escapeHtml(laT('finals.removeTimeslot')) + '"><i class="fa fa-trash"></i></button>',
                    '</div>'
                ].join('');
            } else {
                actionsHtml = [
                    '<div class="timeslot-actions">',
                    '<button type="button" class="timeslot-edit-btn" data-timeslot-key="' + escapeHtml(timeslot.key) + '" title="' + escapeHtml(laT('finals.editTimeslot')) + '"><i class="fa fa-pencil"></i></button>',
                    '<button type="button" class="timeslot-delete-btn" data-timeslot-key="' + escapeHtml(timeslot.key) + '" title="' + escapeHtml(laT('finals.removeTimeslot')) + '"><i class="fa fa-trash"></i></button>',
                    '</div>'
                ].join('');
            }
            $title.append(actionsHtml);
            $section.append($title);

            const $targetsScroll = $('<div class="targets-scroll-sync"></div>');

            const $targetsHeader = $('<div class="targets-header-grid"></div>')
                .css('--target-count', state.availableTargets.length);

            const $targetsDistance = $('<div class="targets-distance-grid"></div>')
                .css('--target-count', state.availableTargets.length);

            const $targetsAssign = $('<div class="targets-assign-grid"></div>')
                .attr('data-timeslot-key', timeslot.key)
                .css('--target-count', state.availableTargets.length);

            const targetDistanceMap = distanceTimeline.byTimeslot[timeslot.key] || {};
            const changedTargetsMap = distanceTimeline.changedTargetsByTimeslot[timeslot.key] || {};

            const occupancy = {};
            const visualBundles = bundles.concat(invalidPairBundlesInSlot);
            visualBundles.forEach(function(bundle) {
                if (!bundle.isAssigned) {
                    return;
                }

                const occupiedTargets = Array.isArray(bundle.occupiedTargets) && bundle.occupiedTargets.length
                    ? bundle.occupiedTargets
                    : [];

                occupiedTargets.forEach(function(targetNo, index) {
                    occupancy[targetNo] = {
                        bundleKey: bundle.key,
                        start: index === 0,
                        size: occupiedTargets.length
                    };
                });
            });

            const targetPositions = {};
            state.availableTargets.forEach(function(targetCode, index) {
                const targetNumber = parseTargetNumber(targetCode);
                if (targetNumber !== null) {
                    targetPositions[targetNumber] = index + 1;
                }
            });

            // 1. Render all background slots (droppable zones)
            state.availableTargets.forEach(function(targetCode, index) {
                const targetNumber = parseTargetNumber(targetCode);
                const label = targetNumber === null ? targetCode : String(targetNumber);
                const gridStart = index + 1;
                const warningKey = timeslot.key + '|' + (targetNumber === null ? String(targetCode) : String(targetNumber));
                const warningText = state.targetWarnings[warningKey] || '';
                const laneDistanceChanged = targetNumber !== null && !!changedTargetsMap[targetNumber];

                const $headerCell = $('<div class="target-header-cell"></div>').text(label);
                if (warningText) {
                    $headerCell.addClass('has-warning').attr('title', warningText);
                }
                if (laneDistanceChanged) {
                    $headerCell.addClass('distance-changed-lane');
                    if (!warningText) {
                        $headerCell.attr('title', laT('finals.distanceChanged'));
                    }
                }
                $targetsHeader.append($headerCell);

                const $slot = $('<div class="target-slot droppable-zone"></div>')
                    .attr('data-timeslot-key', timeslot.key)
                    .attr('data-target-number', targetNumber)
                    .css({
                        'grid-column': gridStart + ' / span 1',
                        'grid-row': '1'
                    });

                if (occupancy[targetNumber]) {
                    $slot.addClass('occupied');
                }

                $targetsAssign.append($slot);
            });

            const distanceSegments = [];
            let currentSegment = null;
            const rawDistanceByIndex = state.availableTargets.map(function(targetCode) {
                const targetNumber = parseTargetNumber(targetCode);
                return targetNumber === null ? '' : ((targetDistanceMap[targetNumber] || '').toString().trim());
            });
            const displayDistanceByIndex = rawDistanceByIndex.slice();

            for (let gapStart = 0; gapStart < rawDistanceByIndex.length; gapStart++) {
                if (rawDistanceByIndex[gapStart] !== '') {
                    continue;
                }

                let gapEnd = gapStart;
                while (gapEnd < rawDistanceByIndex.length && rawDistanceByIndex[gapEnd] === '') {
                    gapEnd++;
                }

                const leftDistance = gapStart > 0 ? rawDistanceByIndex[gapStart - 1] : '';
                const rightDistance = gapEnd < rawDistanceByIndex.length ? rawDistanceByIndex[gapEnd] : '';

                if (leftDistance !== '' && leftDistance === rightDistance) {
                    for (let fillIndex = gapStart; fillIndex < gapEnd; fillIndex++) {
                        displayDistanceByIndex[fillIndex] = leftDistance;
                    }
                }

                gapStart = gapEnd - 1;
            }

            state.availableTargets.forEach(function(targetCode, targetIndex) {
                const targetNumber = parseTargetNumber(targetCode);
                const distanceValue = (displayDistanceByIndex[targetIndex] || '').toString().trim();
                const changedNow = targetNumber === null ? false : !!changedTargetsMap[targetNumber];

                if (!currentSegment || currentSegment.distance !== distanceValue) {
                    currentSegment = {
                        distance: distanceValue,
                        changedNowAny: changedNow,
                        span: 1,
                        start: targetIndex + 1
                    };
                    distanceSegments.push(currentSegment);
                } else {
                    currentSegment.span++;
                    currentSegment.changedNowAny = currentSegment.changedNowAny || changedNow;
                }
            });

            const showDistanceTransitions = !isDistanceLaneRestrictionEnabled();

            distanceSegments.forEach(function(segment, segmentIndex) {
                const $distanceCell = $('<div class="target-distance-cell"></div>')
                    .css({
                        'grid-column': segment.start + ' / span ' + segment.span,
                        'grid-row': '1'
                    });

                if (showDistanceTransitions && segmentIndex > 0) {
                    const previous = distanceSegments[segmentIndex - 1];
                    if ((previous.distance || '') !== (segment.distance || '')) {
                        $distanceCell.addClass('distance-change-start');
                    }
                }

                if (showDistanceTransitions && segment.changedNowAny) {
                    $distanceCell.addClass('distance-changed-now');
                }

                if (segment.distance) {
                    $distanceCell.addClass('has-distance').text(segment.distance);
                    const color = distanceColorMap[segment.distance] || null;
                    if (color) {
                        const borderColor = color.color;
                        const bgColor = color.bg || '#f8fbff';
                        $distanceCell.css({
                            'background': bgColor,
                            'border-color': '#e6edf7',
                            'border-top-color': borderColor,
                            'color': '#66788f'
                        });
                    }
                }

                $targetsDistance.append($distanceCell);
            });

            // 2. Render the cards on top
            visualBundles.forEach(function(bundle) {
                if (!bundle.isAssigned) {
                    return;
                }

                const anchorTarget = (Array.isArray(bundle.occupiedTargets) && bundle.occupiedTargets.length)
                    ? bundle.occupiedTargets[0]
                    : bundle.startTargetNum;
                const gridStart = targetPositions[anchorTarget];
                if (gridStart) {
                    const gridSpan = bundle.nonContiguousPlacement ? 1 : bundle.targetsUsed;
                    const $cardWrapper = $('<div class="bundle-card-wrapper"></div>')
                        .css({
                            'grid-column': gridStart + ' / span ' + gridSpan,
                            'grid-row': '1',
                            'z-index': '2',
                            'pointer-events': 'none'
                        });
                    
                    const $card = createBundleCard(bundle);
                    $card.css('pointer-events', 'auto');
                    $cardWrapper.append($card);
                    $targetsAssign.append($cardWrapper);

                    if (bundle.nonContiguousPlacement && Array.isArray(bundle.occupiedTargets) && bundle.occupiedTargets.length > 1) {
                        bundle.occupiedTargets.forEach(function(targetNo) {
                            if (targetNo === anchorTarget) {
                                return;
                            }

                            const splitGridStart = targetPositions[targetNo];
                            if (!splitGridStart) {
                                return;
                            }

                            const $splitMarkerWrapper = $('<div class="bundle-card-wrapper bundle-split-marker-wrapper"></div>')
                                .css({
                                    'grid-column': splitGridStart + ' / span 1',
                                    'grid-row': '1',
                                    'z-index': '2',
                                    'pointer-events': 'none'
                                });

                            const $splitMarker = createBundleCard(bundle)
                                .addClass('bundle-split-ghost drop-pass-through')
                                .attr('title', laT('finals.existingSplit'));
                            $splitMarkerWrapper.append($splitMarker);
                            $targetsAssign.append($splitMarkerWrapper);
                        });
                    }
                }
            });

            $targetsScroll.append($targetsHeader);
            $targetsScroll.append($targetsDistance);
            $targetsScroll.append($targetsAssign);
            $section.append($targetsScroll);
            $board.append($section);

            $board.append('<div class="timeslot-insert-anchor"><button type="button" class="timeslot-insert-btn" data-insert-index="' + (index + 1) + '" title="' + escapeHtml(laT('finals.insertHere')) + '" aria-label="' + escapeHtml(laT('finals.insertHere')) + '"><i class="fa fa-plus"></i></button></div>');
        });

        if (renderedSections === 0) {
            const showEmptyTimeslots = $('#show-empty-timeslots').is(':checked');
            const hintText = showEmptyTimeslots
                ? laT('finals.noVisibleTimeslots')
                : laT('finals.noPlayableBundles');
            $board.append('<div class="empty">' + escapeHtml(hintText) + '</div>');
        }

        const restoreScroll = (typeof state.sharedTargetsScrollLeft === 'number')
            ? state.sharedTargetsScrollLeft
            : previousSharedScroll;

        window.setTimeout(function() {
            state.syncingTargetsScroll = true;
            $board.find('.targets-scroll-sync').scrollLeft(restoreScroll);
            state.syncingTargetsScroll = false;
            state.sharedTargetsScrollLeft = restoreScroll;
            bindSynchronizedTimeslotScroll();
        }, 0);
    }

    function getBundleEventKey(bundle) {
        const teamEvent = parseInt(bundle.rows[0] ? bundle.rows[0].teamEvent : 0, 10) || 0;
        return teamEvent + '|' + (bundle.event || '');
    }

    function clearEventHighlight() {
        $('.bundle-card.event-highlight').removeClass('event-highlight');
    }

    function handleBundleCardEnter() {
        clearEventHighlight();
        if (activeDragSourceCard) {
            return;
        }
        const eventKey = ($(this).attr('data-event-key') || '').toString();
        if (!eventKey) {
            return;
        }
        $('.bundle-card').filter(function() {
            return $(this).attr('data-event-key') === eventKey;
        }).addClass('event-highlight');
    }

    function createBundleCard(bundle) {
        const eventLabel = bundle.eventName ? (bundle.event + ' - ' + bundle.eventName) : bundle.event;
        const $card = $('<div class="bundle-card"></div>')
            .attr('data-bundle-key', bundle.key)
            .attr('data-timeslot-key', bundle.timeslotKey)
            .attr('data-size', bundle.targetsUsed)
            .attr('data-event-key', getBundleEventKey(bundle));

        if (Array.isArray(bundle.clearRowKeys) && bundle.clearRowKeys.length) {
            $card.attr('data-row-keys', bundle.clearRowKeys.join(','));
        }

        const colorGrouping = getBundleColorGrouping(bundle);
        const color = colorGrouping && colorGrouping.key !== null
            ? colorIndex.colorFor(colorGrouping.group, colorGrouping.key)
            : null;
        if (color) {
            $card.attr('style', 'border-color:' + color.color + ';background:' + color.bg + ';');
        }
        if (colorGrouping) {
            $card.attr('title', colorGrouping.label);
        }

        const targetsUsed = Math.max(1, parseInt(bundle.targetsUsed, 10) || 1);
        const laneCounts = [];
        for (let lane = 0; lane < targetsUsed; lane++) {
            laneCounts.push(0);
        }

        let startTarget = null;
        if (bundle.startTargetNum !== null && typeof bundle.startTargetNum !== 'undefined') {
            startTarget = parseInt(bundle.startTargetNum, 10);
            if (Number.isNaN(startTarget)) {
                startTarget = null;
            }
        }

        if (startTarget === null) {
            const assignedTargets = (bundle.rowsToPlace || []).map(function(row) {
                return parseTargetNumber(row.target);
            }).filter(function(targetNo) {
                return targetNo !== null;
            });
            if (assignedTargets.length) {
                assignedTargets.sort(function(a, b) { return a - b; });
                startTarget = assignedTargets[0];
            }
        }

        if (startTarget !== null) {
            (bundle.rowsToPlace || []).forEach(function(row) {
                const targetNo = parseTargetNumber(row.target);
                if (targetNo === null) {
                    return;
                }

                const laneIndex = targetNo - startTarget;
                if (laneIndex >= 0 && laneIndex < laneCounts.length) {
                    laneCounts[laneIndex]++;
                }
            });
        }

        const totalArchers = Math.max(0, parseInt(bundle.totalArchers, 10) || 0);
        const assignedArchers = laneCounts.reduce(function(sum, count) {
            return sum + count;
        }, 0);

        if (assignedArchers < totalArchers) {
            let remaining = totalArchers - assignedArchers;
            for (let lane = 0; lane < laneCounts.length && remaining > 0; lane++) {
                const capacity = Math.max(1, parseInt(bundle.archersPerTarget, 10) || 1);
                const freeCapacity = Math.max(0, capacity - laneCounts[lane]);
                if (freeCapacity <= 0) {
                    continue;
                }
                const extra = Math.min(freeCapacity, remaining);
                laneCounts[lane] += extra;
                remaining -= extra;
            }
        }

        const $top = $('<div class="card-top"></div>');
        const $targetsStrip = $('<div class="card-targets-strip"></div>').css('--lane-count', String(targetsUsed));
        laneCounts.forEach(function(count) {
            const iconCount = Math.max(0, parseInt(count, 10) || 0);
            const $lane = $('<div class="target-lane-icons"></div>');
            for (let iconIndex = 0; iconIndex < iconCount; iconIndex++) {
                $lane.append('<i class="fa fa-user"></i>');
            }
            $targetsStrip.append($lane);
        });
        $top.append($targetsStrip);

        $card.append($top);
        $card.append('<div class="card-phase-row"><div class="card-phase">' + escapeHtml(bundle.phaseLabel) + '</div></div>');
        $card.append('<div class="card-event">' + escapeHtml(eventLabel) + '</div>');
        if ((bundle.distanceProfile || '').toString().trim() !== '') {
            $card.append('<div class="card-distance">' + escapeHtml(bundle.distanceProfile) + '</div>');
        }

        const changed = bundle.rows.some(function(row) {
            const original = state.originalRowsByKey[row.key] || { target: '' };
            return row.target !== original.target;
        });
        $card.toggleClass('changed', changed);

        if (!bundle.isPlayable) {
            $card.addClass('not-playable');
        }

        const warningText = state.bundleWarnings[bundle.key] || (bundle.warningText || '');
        if (!bundle.isPlayable && bundleHasAssignedTarget(bundle)) {
            $card.addClass('has-warning');
            const nonPlayableMsg = laT('finals.matchNotPlayable');
            if (warningText) {
                $card.attr('title', warningText + ' | ' + nonPlayableMsg);
            } else {
                $card.attr('title', nonPlayableMsg);
            }

            const clearKeyAttr = Array.isArray(bundle.clearRowKeys) && bundle.clearRowKeys.length
                ? ' data-row-keys="' + escapeHtml(bundle.clearRowKeys.join(',')) + '"'
                : '';
            $card.append('<div class="card-warning-actions"><button type="button" class="bundle-clear-btn" data-bundle-key="' + escapeHtml(bundle.key) + '"' + clearKeyAttr + ' title="' + escapeHtml(laT('finals.removeInvalidMatch')) + '" aria-label="' + escapeHtml(laT('finals.removeInvalidMatch')) + '"><i class="fa fa-times"></i></button></div>');
        }

        if (warningText) {
            $card.addClass('has-warning');
            $card.attr('title', warningText);
        } else {
            if (bundle.isPlayable || !bundleHasAssignedTarget(bundle)) {
                $card.removeClass('has-warning');
                $card.removeAttr('title');
            }
        }

        return $card;
    }

    function syncValidationWarnings(timeslots, allBundles) {
        const warnings = [];
        const bundleWarnings = {};
        const targetWarnings = {};

        function addTargetWarning(timeslotKey, targetNo, message) {
            if (!timeslotKey || targetNo === null || typeof targetNo === 'undefined') {
                return;
            }

            const key = timeslotKey + '|' + String(targetNo);
            if (!targetWarnings[key]) {
                targetWarnings[key] = [];
            }
            targetWarnings[key].push(message);
        }

        const bundleList = allBundles || [];

        bundleList.forEach(function(bundle) {
            if (bundle.isPlayable || !bundleHasAssignedTarget(bundle)) {
                return;
            }

            const slotLabel = normalizeDateTimeValue(
                bundle.rows[0] ? bundle.rows[0].scheduledDate : '',
                bundle.rows[0] ? bundle.rows[0].scheduledTime : ''
            );
            const message = laT('finals.scheduledNotPlayable', { match: (bundle.event || '') + ' ' + (bundle.phaseLabel || '') + ' G' + (bundle.group || 0), slot: slotLabel });
            warnings.push(message);

            if (!bundleWarnings[bundle.key]) {
                bundleWarnings[bundle.key] = [];
            }
            bundleWarnings[bundle.key].push(laT('finals.matchNotPlayableNow'));

            if (Array.isArray(bundle.occupiedTargets) && bundle.occupiedTargets.length) {
                bundle.occupiedTargets.forEach(function(targetNo) {
                    addTargetWarning(bundle.timeslotKey, targetNo, laT('finals.assignedMatchNotPlayable'));
                });
            }
        });

        bundleList.forEach(function(bundle) {
            if (!bundle.isPlayable) {
                return;
            }

            const invalidPairs = Array.isArray(bundle.nonPlayableScheduledPairs) ? bundle.nonPlayableScheduledPairs : [];
            invalidPairs.forEach(function(pair) {
                const pairRows = (pair && Array.isArray(pair.rows)) ? pair.rows : [];
                if (!pairRows.length) {
                    return;
                }

                const slotLabel = normalizeDateTimeValue(
                    pairRows[0] ? pairRows[0].scheduledDate : '',
                    pairRows[0] ? pairRows[0].scheduledTime : ''
                );
                const message = laT('finals.scheduledPairNotPlayable', { match: (bundle.event || '') + ' ' + (bundle.phaseLabel || '') + ' G' + (bundle.group || 0), slot: slotLabel });
                warnings.push(message);

                const syntheticKey = [bundle.key, 'nonPlayablePair', String(parseInt(pair.pairNo, 10) || 0)].join('|');
                if (!bundleWarnings[syntheticKey]) {
                    bundleWarnings[syntheticKey] = [];
                }
                bundleWarnings[syntheticKey].push(laT('finals.pairNotPlayableNow'));

                pairRows.forEach(function(row) {
                    const targetNo = parseTargetNumber(row.target);
                    if (targetNo === null) {
                        return;
                    }
                    addTargetWarning(bundle.timeslotKey, targetNo, laT('finals.assignedPairNotPlayable'));
                });
            });
        });

        const eventPhaseMap = {};
        bundleList.forEach(function(bundle) {
            if (!bundle.isPlayable) {
                return;
            }

            const eventKey = (bundle.rows[0] ? bundle.rows[0].teamEvent : 0) + '|' + (bundle.event || '');
            const slotValue = buildTimeslotSortValue(
                bundle.rows[0] ? bundle.rows[0].scheduledDate : '',
                bundle.rows[0] ? bundle.rows[0].scheduledTime : ''
            );

            if (!eventPhaseMap[eventKey]) {
                eventPhaseMap[eventKey] = {
                    event: bundle.event || '',
                    teamEvent: bundle.rows[0] ? bundle.rows[0].teamEvent : 0,
                    phaseSlots: {},
                    phaseBundleKeys: {}
                };
            }

            const phaseValue = parseInt(bundle.phase, 10);
            if (!slotValue || PHASE_ORDER_TRACKED.indexOf(phaseValue) === -1) {
                return;
            }

            if (!eventPhaseMap[eventKey].phaseSlots[phaseValue]) {
                eventPhaseMap[eventKey].phaseSlots[phaseValue] = [];
            }
            eventPhaseMap[eventKey].phaseSlots[phaseValue].push(slotValue);

            if (!eventPhaseMap[eventKey].phaseBundleKeys[phaseValue]) {
                eventPhaseMap[eventKey].phaseBundleKeys[phaseValue] = [];
            }
            eventPhaseMap[eventKey].phaseBundleKeys[phaseValue].push(bundle.key);
        });

        Object.keys(eventPhaseMap).forEach(function(eventKey) {
            const phaseInfo = eventPhaseMap[eventKey];
            for (let index = 0; index < PHASE_DEPENDENCIES.length; index++) {
                const dependency = PHASE_DEPENDENCIES[index];
                const earlierPhase = dependency.before;
                const laterPhase = dependency.after;
                const earlierSlots = phaseInfo.phaseSlots[earlierPhase] || [];
                const laterSlots = phaseInfo.phaseSlots[laterPhase] || [];

                if (!earlierSlots.length || !laterSlots.length) {
                    continue;
                }

                const latestEarlier = earlierSlots.slice().sort().pop();
                const earliestLater = laterSlots.slice().sort()[0];
                if (latestEarlier >= earliestLater) {
                    const message = laT('finals.phaseOrder', {
                        event: phaseInfo.event,
                        type: parseInt(phaseInfo.teamEvent, 10) ? laT('finals.typeTeam') : laT('finals.typeIndividual'),
                        before: formatPhaseLabel(earlierPhase),
                        after: formatPhaseLabel(laterPhase)
                    });
                    warnings.push(message);

                    const impactedBundles = [];
                    (phaseInfo.phaseBundleKeys[earlierPhase] || []).forEach(function(key) {
                        impactedBundles.push(key);
                    });
                    (phaseInfo.phaseBundleKeys[laterPhase] || []).forEach(function(key) {
                        impactedBundles.push(key);
                    });

                    impactedBundles.forEach(function(bundleKey) {
                        if (!bundleWarnings[bundleKey]) {
                            bundleWarnings[bundleKey] = [];
                        }
                        const phaseMessage = laT('finals.phaseOrderConflict', { before: formatPhaseLabel(earlierPhase), after: formatPhaseLabel(laterPhase) });
                        bundleWarnings[bundleKey].push(phaseMessage);

                        const impactedBundle = bundleList.find(function(item) { return item.key === bundleKey; });
                        if (impactedBundle && impactedBundle.startTargetNum !== null) {
                            for (let t = 0; t < impactedBundle.targetsUsed; t++) {
                                addTargetWarning(impactedBundle.timeslotKey, impactedBundle.startTargetNum + t, phaseMessage);
                            }
                        }
                    });
                }
            }
        });

        const slotList = timeslots || [];
        slotList.forEach(function(timeslot) {
            const bundles = buildBundles(timeslot.rows).filter(function(bundle) {
                return bundle.isPlayable && bundle.startTargetNum !== null;
            });

            const targetUsage = {};
            bundles.forEach(function(bundle) {
                for (let index = 0; index < bundle.targetsUsed; index++) {
                    const targetNo = bundle.startTargetNum + index;
                    if (!targetUsage[targetNo]) {
                        targetUsage[targetNo] = [];
                    }
                    targetUsage[targetNo].push(bundle);
                }
            });

            Object.keys(targetUsage).forEach(function(targetKey) {
                const usedBy = targetUsage[targetKey];
                if (usedBy.length <= 1) {
                    return;
                }

                const slotLabel = normalizeDateTimeValue(timeslot.scheduledDate, timeslot.scheduledTime);
                const warningMessage = laT('finals.targetConflict', { target: targetKey, slot: slotLabel });
                warnings.push(warningMessage);
                addTargetWarning(timeslot.key, targetKey, warningMessage);

                usedBy.forEach(function(bundle) {
                    if (!bundleWarnings[bundle.key]) {
                        bundleWarnings[bundle.key] = [];
                    }
                    bundleWarnings[bundle.key].push(laT('finals.targetConflictShort', { target: targetKey, slot: slotLabel }));
                });
            });
        });

        const normalizedWarnings = [];
        const seen = {};
        warnings.forEach(function(message) {
            const key = (message || '').toString();
            if (!key || seen[key]) {
                return;
            }
            seen[key] = true;
            normalizedWarnings.push(key);
        });

        const normalizedBundleWarnings = {};
        Object.keys(bundleWarnings).forEach(function(bundleKey) {
            const parts = [];
            const seenPart = {};
            bundleWarnings[bundleKey].forEach(function(part) {
                const text = (part || '').toString();
                if (!text || seenPart[text]) {
                    return;
                }
                seenPart[text] = true;
                parts.push(text);
            });
            if (parts.length) {
                normalizedBundleWarnings[bundleKey] = parts.join(' | ');
            }
        });

        const normalizedTargetWarnings = {};
        Object.keys(targetWarnings).forEach(function(key) {
            const parts = [];
            const seenPart = {};
            targetWarnings[key].forEach(function(part) {
                const text = (part || '').toString();
                if (!text || seenPart[text]) {
                    return;
                }
                seenPart[text] = true;
                parts.push(text);
            });

            if (parts.length) {
                normalizedTargetWarnings[key] = parts.join(' | ');
            }
        });

        state.validationWarnings = normalizedWarnings;
        state.bundleWarnings = normalizedBundleWarnings;
        state.targetWarnings = normalizedTargetWarnings;
    }

    function splitColorValues(value) {
        return (value || '').toString().split(',').map(function(part) {
            return part.trim();
        }).filter(function(part) {
            return part !== '';
        });
    }

    /**
     * Color-by grouping for a finals bundle: { group, key, label }. An event can span several
     * divisions/classes (e.g. merged by Change finals events); then key is null and the card
     * stays uncolored as "Mixed" rather than borrowing the first value's color.
     */
    function getBundleColorGrouping(bundle) {
        const mode = state.colorBy || 'none';
        const divisions = splitColorValues(bundle.division);
        const classes = splitColorValues(bundle.classCode);

        if (mode === 'event') {
            if (!bundle.event) {
                return null;
            }
            return {
                group: 'event',
                key: (parseInt(bundle.teamEvent, 10) ? 1 : 0) + '|' + String(bundle.event).trim(),
                label: laT('colorBy.event') + ': ' + (bundle.eventName || bundle.event) + (parseInt(bundle.teamEvent, 10) ? ' (' + laT('finals.typeTeam') + ')' : '')
            };
        }

        if (mode === 'phase') {
            if (bundle.phase === null || bundle.phase === undefined || bundle.phase === '') {
                return null;
            }
            return { group: 'phase', key: String(bundle.phase), label: laT('colorBy.phase') + ': ' + (bundle.phaseLabel || bundle.phase) };
        }

        let key = null;
        let label = '';
        if (mode === 'bow') {
            key = ColorBy.keyForMulti(divisions.map(function(division) {
                return ColorBy.extractBowType(divisionDescriptions[division], division);
            }));
            label = laT('colorBy.bow') + ': ';
        } else if (mode === 'class') {
            key = ColorBy.keyForMulti(classes);
            label = laT('colorBy.class') + ': ';
        } else if (mode === 'category') {
            const division = ColorBy.keyForMulti(divisions);
            const classCode = ColorBy.keyForMulti(classes);
            key = (division !== null && classCode !== null) ? division + classCode : null;
            label = laT('colorBy.category') + ': ';
        } else {
            return null;
        }

        if (key === null && divisions.length === 0 && classes.length === 0) {
            return null;
        }
        return { group: mode, key: key, label: label + (key === null ? laT('colorBy.mixed') : key) };
    }

    let activeDropGrid = null;
    let currentDropEvaluation = null;
    let activeDragSourceCard = null;
    let activeDragSourceWrapper = null;

    function makeBundlesDraggable() {
        var allBundles = buildBundles(state.rows);
        var playableKeys = {};
        allBundles.forEach(function(bundle) {
            if (bundle.isPlayable) {
                playableKeys[bundle.key] = true;
            }
        });

        $('.bundle-card').filter(function() {
            return !!playableKeys[$(this).data('bundle-key')];
        }).draggable({
            helper: function() {
                const $clone = $(this).clone();
                $clone.data('mf-source-card', this);
                return $clone;
            },
            revert: 'invalid',
            opacity: 0.75,
            zIndex: 2500,
            appendTo: 'body',
            start: function(event, ui) {
                const sourceCardEl = ui.helper.data('mf-source-card') || this;
                const $sourceCard = $(sourceCardEl);
                const $sourceWrapper = $sourceCard.closest('.bundle-card-wrapper');
                const size = Math.max(parseInt($(this).attr('data-size'), 10) || 1, 1);
                const rootStyle = getComputedStyle(document.documentElement);
                const targetWidth = parseFloat(rootStyle.getPropertyValue('--mf-target-width')) || 74;
                const gap = parseFloat(rootStyle.getPropertyValue('--mf-target-gap')) || 0;
                const width = (targetWidth * size) + (gap * (size - 1)) - 6; // Subtract 6px for wrapper padding

                ui.helper.css({
                    width: width + 'px'
                });

                clearEventHighlight();
                activeDragSourceCard = $sourceCard;
                activeDragSourceWrapper = $sourceWrapper.length ? $sourceWrapper : null;
                $sourceCard.addClass('drag-source-hidden');
                if ($sourceWrapper.length) {
                    $sourceWrapper.addClass('drag-source-hidden');
                }

                $('.bundle-card').addClass('drop-pass-through');
                ui.helper.removeClass('drop-pass-through');
                activeDropGrid = null;
                currentDropEvaluation = null;
                showInitialDropHints(ui.helper);
            },
            drag: function(event, ui) {
                if (activeDropGrid) {
                    updateDragPreview(ui.helper, activeDropGrid, $(this));
                }
            },
            stop: function() {
                if (activeDragSourceCard && activeDragSourceCard.length) {
                    activeDragSourceCard.removeClass('drag-source-hidden');
                }
                if (activeDragSourceWrapper && activeDragSourceWrapper.length) {
                    activeDragSourceWrapper.removeClass('drag-source-hidden');
                }

                $('.bundle-card').removeClass('drop-pass-through');
                clearDragZoneHints();
                clearDropPreview();
                hideZoneTooltip();
                activeDropGrid = null;
                currentDropEvaluation = null;
                activeDragSourceCard = null;
                activeDragSourceWrapper = null;
            }
        });
    }

    function clearDragZoneHints() {
        $('.droppable-zone').removeClass('hint-valid hint-invalid');
    }

    function showInitialDropHints($dragged) {
        clearDragZoneHints();

        const span = Math.max(parseInt($dragged.attr('data-size'), 10) || 1, 1);

        $('.targets-assign-grid').each(function() {
            const $grid = $(this);
            const zoneTargets = [];
            const legalStartTargets = [];
            const coveredTargets = {};

            $grid.find('.droppable-zone').each(function() {
                const targetNum = parseInt($(this).attr('data-target-number'), 10);
                if (!Number.isNaN(targetNum)) {
                    zoneTargets.push(targetNum);
                }
            });

            zoneTargets.forEach(function(startTargetNum) {
                const evaluation = evaluateDropForGrid($grid, $dragged, startTargetNum);
                if (evaluation.valid) {
                    legalStartTargets.push(startTargetNum);
                }
            });

            legalStartTargets.forEach(function(startTargetNum) {
                const startIndex = zoneTargets.indexOf(startTargetNum);
                if (startIndex === -1) {
                    return;
                }

                for (let offset = 0; offset < span; offset++) {
                    const coveredTarget = zoneTargets[startIndex + offset];
                    if (typeof coveredTarget === 'number') {
                        coveredTargets[coveredTarget] = true;
                    }
                }
            });

            $grid.find('.droppable-zone').each(function() {
                const $zone = $(this);
                const targetNum = parseInt($zone.attr('data-target-number'), 10);
                if (Number.isNaN(targetNum)) {
                    return;
                }

                if (coveredTargets[targetNum]) {
                    $zone.addClass('hint-valid').removeClass('hint-invalid');
                } else {
                    $zone.addClass('hint-invalid').removeClass('hint-valid');
                }
            });
        });
    }

    function updateDragPreview($helper, $grid, $dragged) {
        const relativeX = $helper.offset().left - $grid.offset().left;
        const rootStyle = getComputedStyle(document.documentElement);
        const targetWidth = parseFloat(rootStyle.getPropertyValue('--mf-target-width')) || 74;
        const gap = parseFloat(rootStyle.getPropertyValue('--mf-target-gap')) || 0;
        const colWidth = targetWidth + gap;
        
        let colIndex = Math.round(relativeX / colWidth);
        colIndex = Math.max(0, Math.min(colIndex, state.availableTargets.length - 1));
        
        const targetCode = state.availableTargets[colIndex];
        const startTargetNum = parseTargetNumber(targetCode);
        
        if (startTargetNum === null) return;

        if (currentDropEvaluation && currentDropEvaluation.startTargetNum === startTargetNum && currentDropEvaluation.grid[0] === $grid[0]) {
            return;
        }

        currentDropEvaluation = evaluateDropForGrid($grid, $dragged, startTargetNum);
        currentDropEvaluation.grid = $grid;

        showGridDropPreview($grid, startTargetNum, $dragged.attr('data-size'), currentDropEvaluation.valid);
    }

    function evaluateDropForGrid($grid, $dragged, startTargetNum) {
        const bundleKey = $dragged.data('bundle-key');
        const bundle = buildBundles(state.rows).find(function(item) { return item.key === bundleKey; });
        if (!bundle) {
            return { valid: false, message: laT('finals.invalidDragItem') };
        }

        const targetTimeslot = $grid.data('timeslot-key');
        const timeslotInfo = getTimeslotByKey(targetTimeslot);
        if (!timeslotInfo) {
            return { valid: false, message: laT('finals.invalidTimeslot') };
        }

        const sourceTeamEvent = parseInt(bundle.rows[0] ? bundle.rows[0].teamEvent : bundle.teamEvent, 10);
        if (sourceTeamEvent !== parseInt(timeslotInfo.teamEvent, 10)) {
            return { valid: false, message: laT('finals.cannotMixTeamIndividual') };
        }

        const phaseValidationMessage = validatePhaseOrderAfterMove(bundle, timeslotInfo);
        if (phaseValidationMessage) {
            return { valid: false, message: phaseValidationMessage };
        }

        const bundles = getBundlesForTimeslot(targetTimeslot);
        const freshBundle = bundles.find(function(item) { return item.key === bundle.key; });
        const candidateBundle = freshBundle || bundle;
        const placement = getDropPlacementInfo(candidateBundle, startTargetNum, bundles, timeslotInfo);

        if (!placement.valid) {
            return {
                valid: false,
                message: placement.message,
                startTargetNum: placement.startTargetNum,
                timeslotInfo: timeslotInfo,
                candidateBundle: candidateBundle
            };
        }

        return {
            valid: true,
            message: null,
            startTargetNum: placement.startTargetNum,
            timeslotInfo: timeslotInfo,
            candidateBundle: candidateBundle
        };
    }

    function showGridDropPreview($grid, startTargetNum, span, isValid) {
        clearDropPreview();
        
        const $startZone = $grid.find('.target-slot').filter(function() {
            return parseInt($(this).data('target-number'), 10) === startTargetNum;
        }).first();

        if (!$startZone.length) return;

        $startZone
            .addClass('drop-preview-start')
            .addClass(isValid ? 'preview-valid' : 'preview-invalid')
            .css('--drop-preview-span', String(Math.max(parseInt(span, 10) || 1, 1)));
            
        if (!isValid && currentDropEvaluation && currentDropEvaluation.message) {
            showZoneTooltip($startZone, currentDropEvaluation.message);
        } else {
            hideZoneTooltip();
        }
    }

    function getDropPlacementInfo(bundle, hoveredTargetNum, bundles, timeslotInfo) {
        const preferredStart = hoveredTargetNum;
        const targetDistanceMap = buildAssignedTargetDistanceMap(bundle.key);

        if (isDistanceLaneRestrictionEnabled()) {
            const distanceMismatch = getDistancePlacementMismatch(bundle, preferredStart, targetDistanceMap);
            if (distanceMismatch) {
                return {
                    valid: false,
                    startTargetNum: preferredStart,
                    message: laT('finals.targetFixedDistance', { target: distanceMismatch.targetNo, expected: distanceMismatch.expected, actual: distanceMismatch.actual })
                };
            }
        }

        if (!canPlaceBundle(bundle, preferredStart, bundles)) {
            return {
                valid: false,
                startTargetNum: preferredStart,
                message: getPlacementErrorMessage(bundle, preferredStart, bundles, timeslotInfo) || laT('finals.targetsInUse')
            };
        }

        return {
            valid: true,
            startTargetNum: preferredStart,
            message: null
        };
    }

    function getBundlesForTimeslot(timeslotKey) {
        return buildBundles(state.rows.filter(function(row) {
            return row.timeslotKey === timeslotKey;
        }));
    }

    function canPlaceBundle(bundle, startTargetNum, bundles) {
        const targetNumbers = state.availableTargets.map(parseTargetNumber).filter(function(v) { return v !== null; });
        if (targetNumbers.length === 0) {
            return false;
        }

        const minTarget = Math.min.apply(null, targetNumbers);
        const maxTarget = Math.max.apply(null, targetNumbers);

        if (startTargetNum < minTarget || (startTargetNum + bundle.targetsUsed - 1) > maxTarget) {
            return false;
        }

        for (let index = 0; index < bundle.targetsUsed; index++) {
            const t = startTargetNum + index;
            const occupied = bundles.some(function(other) {
                if (other.key === bundle.key || other.startTargetNum === null) {
                    return false;
                }
                return t >= other.startTargetNum && t < (other.startTargetNum + other.targetsUsed);
            });
            if (occupied) {
                return false;
            }
        }

        return true;
    }

    function getPlacementErrorMessage(bundle, startTargetNum, bundles, timeslotInfo) {
        const targetNumbers = state.availableTargets.map(parseTargetNumber).filter(function(v) { return v !== null; });
        if (targetNumbers.length === 0) {
            return laT('finals.noTargetsConfigured');
        }

        if (isDistanceLaneRestrictionEnabled()) {
            const targetDistanceMap = buildAssignedTargetDistanceMap(bundle.key);
            const distanceMismatch = getDistancePlacementMismatch(bundle, startTargetNum, targetDistanceMap);
            if (distanceMismatch) {
                return laT('finals.targetFixedDistance', { target: distanceMismatch.targetNo, expected: distanceMismatch.expected, actual: distanceMismatch.actual });
            }
        }

        const minTarget = Math.min.apply(null, targetNumbers);
        const maxTarget = Math.max.apply(null, targetNumbers);

        if (startTargetNum < minTarget || (startTargetNum + bundle.targetsUsed - 1) > maxTarget) {
            return laT('finals.notEnoughFreeTargets', { from: startTargetNum, needed: bundle.targetsUsed });
        }

        const conflicts = getPlacementConflictDetails(bundle, startTargetNum, bundles);
        if (!conflicts.length) {
            return null;
        }

        const targetList = conflicts.map(function(item) { return item.targetNo; }).filter(function(v, i, arr) {
            return arr.indexOf(v) === i;
        });
        const firstConflict = conflicts[0].conflict;
        const conflictLabel = (firstConflict.event || '') + ' ' + (firstConflict.phaseLabel || '') + ' G' + (firstConflict.group || '');
        const slotLabel = timeslotInfo ? normalizeDateTimeValue(timeslotInfo.scheduledDate, timeslotInfo.scheduledTime) : laT('finals.selectedTimeslot');

        return laT('finals.targetsUsedBy', { targets: targetList.join(', '), slot: slotLabel, by: conflictLabel });
    }

    function normalizeDateTimeValue(dateValue, timeValue) {
        const datePart = (dateValue || '').toString().trim();
        const timePart = (timeValue || '').toString().trim();
        if (!datePart) {
            return '-';
        }
        return datePart + ' ' + (timePart || '00:00');
    }

    function getPlacementConflictDetails(bundle, startTargetNum, bundles) {
        const occupiedTargets = [];

        for (let index = 0; index < bundle.targetsUsed; index++) {
            const targetNo = startTargetNum + index;
            const conflictingBundle = bundles.find(function(other) {
                if (other.key === bundle.key || other.startTargetNum === null) {
                    return false;
                }
                return targetNo >= other.startTargetNum && targetNo < (other.startTargetNum + other.targetsUsed);
            });

            if (conflictingBundle) {
                occupiedTargets.push({
                    targetNo: targetNo,
                    conflict: conflictingBundle
                });
            }
        }

        return occupiedTargets;
    }

    function showDragHint(message) {
        const $hint = $('#drag-hint-message');
        if (!$hint.length) {
            return;
        }

        clearStatus();

        if (dragHintTimer) {
            clearTimeout(dragHintTimer);
            dragHintTimer = null;
        }

        $hint.removeClass('hidden').text(message || laT('finals.invalidMove'));

        dragHintTimer = setTimeout(function() {
            $hint.addClass('hidden').text('');
            dragHintTimer = null;
        }, 4500);
    }

    function clearStatus() {
        const $status = $('#status-message');
        if (!$status.length) {
            return;
        }

        $status.addClass('hidden')
            .removeClass('status-info status-success status-error')
            .text('');
    }

    function assignBundleToStart(bundle, startTargetNum) {
        bundle.rows.forEach(function(row) {
            row.target = '';
        });

        bundle.rowsToPlace.forEach(function(row, index) {
            row.target = padTargetNumber(startTargetNum + Math.floor(index / bundle.archersPerTarget));
        });
    }

    function unassignBundle(bundle) {
        bundle.rows.forEach(function(row) {
            row.target = '';
        });
    }

    function makeDroppableZones() {
        $('#unassigned-list').droppable({
            accept: '.bundle-card',
            hoverClass: 'drop-hover',
            tolerance: 'pointer',
            drop: function(event, ui) {
                const bundleKey = ui.draggable.data('bundle-key');
                const bundle = buildBundles(state.rows).find(function(item) { return item.key === bundleKey; });
                if (!bundle) {
                    return;
                }
                unassignBundle(bundle);
                recomputeHasChanges();
                renderAll();
            }
        });

        $('.droppable-zone').droppable({
            accept: '.bundle-card',
            tolerance: 'pointer',
            over: function(event, ui) {
                const $zone = $(this);
                activeDropGrid = $zone.closest('.targets-assign-grid');
                if (!activeDropGrid.length) {
                    activeDropGrid = $zone;
                }
                updateDragPreview(ui.helper, activeDropGrid, ui.draggable);

                const hoveredTargetNum = parseInt($zone.attr('data-target-number'), 10);
                if (!Number.isNaN(hoveredTargetNum)) {
                    const hoveredEvaluation = evaluateDropForGrid(activeDropGrid, ui.draggable, hoveredTargetNum);
                    if (!hoveredEvaluation.valid && hoveredEvaluation.message) {
                        showZoneTooltip($zone, hoveredEvaluation.message);
                    } else {
                        hideZoneTooltip();
                    }
                }
            },
            out: function(event, ui) {
                if (activeDropGrid && activeDropGrid.length) {
                    activeDropGrid = null;
                    currentDropEvaluation = null;
                    clearDropPreview();
                    hideZoneTooltip();
                }
            },
            drop: function(event, ui) {
                clearDropPreview();
                hideZoneTooltip();

                if (!currentDropEvaluation) {
                    const $grid = $(this).closest('.targets-assign-grid');
                    const $effectiveGrid = $grid.length ? $grid : $(this);
                    updateDragPreview(ui.helper, $effectiveGrid, ui.draggable);
                }
                
                if (!currentDropEvaluation) return;

                if (!currentDropEvaluation.valid) {
                    const message = currentDropEvaluation.message || laT('finals.targetsInUse');
                    showDragHint(message);
                    syncValidationWarnings(getTimeslots(), buildBundles(state.rows));
                    renderAll();
                    return;
                }

                moveBundleToTimeslot(currentDropEvaluation.candidateBundle, currentDropEvaluation.timeslotInfo);
                assignBundleToStart(currentDropEvaluation.candidateBundle, currentDropEvaluation.startTargetNum);
                recomputeHasChanges();
                syncValidationWarnings(getTimeslots(), buildBundles(state.rows));
                renderAll();
                
                activeDropGrid = null;
                currentDropEvaluation = null;
            }
        });
    }

    function showZoneTooltip($zone, text) {
        hideZoneTooltip();
        const offset = $zone.offset();
        const tooltip = $('<div id="zone-tooltip" class="zone-tooltip"></div>').text(text);
        $('body').append(tooltip);
        tooltip.css({
            top: offset.top - tooltip.outerHeight() - 8,
            left: offset.left + ($zone.outerWidth() - tooltip.outerWidth()) / 2
        });
    }

    function hideZoneTooltip() {
        $('#zone-tooltip').remove();
    }

    function clearDropPreview() {
        $('.target-slot').removeClass('drop-preview-start preview-valid preview-invalid').css('--drop-preview-span', '');
    }

    function recomputeHasChanges() {
        state.hasChanges = state.rows.some(function(row) {
            return hasRowChanged(row);
        });
    }

    function updateSummary(hiddenBundlesCount, filteredRows) {
        const sourceRows = Array.isArray(filteredRows) ? filteredRows : getFilteredRows();
        const changedCount = sourceRows.filter(function(row) {
            return hasRowChanged(row);
        }).length;

        $('#row-count').text(sourceRows.length);
        $('#visible-count').text(sourceRows.length);
        $('#total-count').text(state.rows.length);
        $('#changed-count').text(changedCount);
        $('#hidden-count').text(hiddenBundlesCount || 0);
    }

    function updateUI() {
        const changedCount = state.rows.filter(function(row) {
            return hasRowChanged(row);
        }).length;

        const hasRows = state.rows.length > 0;
        $('#btn-reset').prop('disabled', !state.hasChanges);
        $('#btn-apply').prop('disabled', !state.hasChanges);
        $('#btn-unassign-all').prop('disabled', !hasRows);
        $('#btn-auto-assign').prop('disabled', !hasRows || state.availableTargets.length === 0 || getTimeslots().length === 0);
        $('#btn-run-auto').prop('disabled', !hasRows || state.availableTargets.length === 0 || getTimeslots().length === 0);

        if (state.hasChanges) {
            const summaryHtml = '<div class="line-1">' + escapeHtml(laTn('finals.willSave', changedCount)) + '</div>';
            $('#changes-summary').show();
            $('#changes-list').html(summaryHtml);
        } else {
            $('#changes-summary').hide();
        }

        renderValidationWarnings();
    }

    function unassignAll() {
        if (!state.rows.length) {
            return;
        }

        state.rows.forEach(function(row) {
            row.target = '';
        });
        state.targetDistanceLocks = {};

        recomputeHasChanges();
        renderAll();
        showStatus(laT('finals.allUnassigned'), 'info');
    }

    function resetChanges() {
        if (!state.hasChanges) {
            return;
        }

        state.rows.forEach(function(row) {
            const original = state.originalRowsByKey[row.key] || { target: '', scheduledDate: '', scheduledTime: '', scheduledLen: normalizeScheduledLen(state.defaultFinalsLengthMinutes) };
            row.target = original.target;
            row.scheduledDate = original.scheduledDate;
            row.scheduledTime = original.scheduledTime;
            row.scheduledLen = normalizeScheduledLen(original.scheduledLen);
            refreshRowPlacementKeys(row);
        });

        state.targetDistanceLocks = {};

        state.hasChanges = false;
        renderAll();
        showStatus(laT('finals.resetDone'), 'info');
    }

    function applyChanges() {
        const changes = state.rows
            .filter(function(row) {
                return hasRowChanged(row);
            })
            .map(function(row) {
                return {
                    teamEvent: row.teamEvent,
                    event: row.event,
                    matchNo: row.matchNo,
                    target: row.target,
                    scheduledDate: row.scheduledDate,
                    scheduledTime: row.scheduledTime,
                    scheduledLen: row.scheduledDate ? normalizeScheduledLen(row.scheduledLen) : 0
                };
            });

        if (changes.length === 0) {
            showStatus(laT('finals.noChangesToApply'), 'info');
            return;
        }

        $.ajax({
            url: ROOT_DIR + 'Modules/Custom/LaneAssist/ManageFinals/api.php',
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'validateChanges',
                changes: JSON.stringify(changes)
            },
            success: function(validationResponse) {
                if (validationResponse.error) {
                    showStatus(validationResponse.message || laT('finals.validationFailed'), 'error');
                    return;
                }

                clearValidationWarnings();
                if (!validationResponse.valid) {
                    const errors = validationResponse.errors || [];
                    if (!errors.length) {
                        showStatus(laT('finals.validationFailed'), 'error');
                        return;
                    }

                    showStatus(errors[0].message || laT('finals.validationFailed'), 'error');
                    renderAll();
                    return;
                }

                showStatus(laT('finals.applying'), 'info');

                $.ajax({
                    url: ROOT_DIR + 'Modules/Custom/LaneAssist/ManageFinals/api.php',
                    method: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'apply',
                        changes: JSON.stringify(changes)
                    },
                    success: function(response) {
                        if (response.error) {
                            const errors = response.errors || [];
                            clearValidationWarnings();
                            if (errors.length) {
                                showStatus(errors[0].message || laT('finals.applyFailed'), 'error');
                                renderAll();
                            } else {
                                showStatus(response.message || laT('finals.applyFailed'), 'error');
                            }
                            return;
                        }

                        state.rows.forEach(function(row) {
                            state.originalRowsByKey[row.key] = {
                                target: row.target,
                                scheduledDate: row.scheduledDate,
                                scheduledTime: row.scheduledTime,
                                scheduledLen: row.scheduledLen
                            };
                        });

                        state.hasChanges = false;
                        clearValidationWarnings();
                        renderAll();
                        showStatus(laTn('finals.appliedRows', response.updated || 0, { message: response.message || laT('finals.applied') }), 'success');
                    },
                    error: function() {
                        showStatus(laT('finals.applyFailed'), 'error');
                    }
                });
            },
            error: function() {
                showStatus(laT('finals.validationFailed'), 'error');
            }
        });
    }

    function showStatus(text, type) {
        const $status = $('#status-message');
        $status.removeClass('hidden status-info status-success status-error')
            .addClass('status-' + type)
            .text(text);
        updateViewportLayout();

        if (type === 'success') {
            setTimeout(function() {
                $status.addClass('hidden');
                updateViewportLayout();
            }, 3000);
        }
    }

    function clearValidationWarnings() {
        state.validationWarnings = [];
        state.bundleWarnings = {};
        state.targetWarnings = {};
        renderValidationWarnings();
    }

    function renderValidationWarnings() {
        const $summary = $('#targets-validation-summary');
        const $text = $('#targets-validation-text');
        const $details = $('#targets-validation-details');

        if (!$summary.length || !$text.length || !$details.length) {
            return;
        }

        $details.empty();

        if (!state.validationWarnings.length) {
            $summary.hide().removeClass('open single-line').attr('data-error-count', '0');
            $('#targets-validation-toggle').attr('aria-expanded', 'false');
            $details.hide();
            return;
        }

        if (state.validationWarnings.length === 1) {
            $summary.addClass('single-line').removeClass('open');
            $text.text(state.validationWarnings[0]);
            $('#targets-validation-toggle').attr('aria-expanded', 'false');
            $details.hide();
        } else {
            $summary.removeClass('single-line');
            $text.text(laT('finals.issuesClickToView', { count: state.validationWarnings.length }));
            state.validationWarnings.forEach(function(message) {
                const $item = $('<div class="error-item"></div>');
                $item.append('<i class="fa fa-exclamation-triangle"></i> ');
                $item.append(escapeHtml(message));
                $details.append($item);
            });
        }

        $summary.attr('data-error-count', String(state.validationWarnings.length)).show();
    }

    function updateFiltersIndicator() {
        const divisionCount = getFilterValues($('#division-filter').val()).length;
        const classCount = getFilterValues($('#class-filter').val()).length;
        const teamTypeCount = getFilterValues($('#team-event-filter').val()).length;
        const dateCount = getFilterValues($('#date-filter').val()).length;
        const activeCount = divisionCount + classCount + teamTypeCount + dateCount;
        const $indicator = $('#filters-active-count');
        const $button = $('#btn-filters');

        if (activeCount > 0) {
            $indicator.text(activeCount).show();
            $button.addClass('has-active');
        } else {
            $indicator.hide();
            $button.removeClass('has-active');
        }
    }

    function updateViewportLayout() {
        const $contentArea = $('.content-area');
        if (!$contentArea.length) {
            return;
        }

        $contentArea.css('--content-area-height', 'auto');
    }

    function escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return (text || '').toString().replace(/[&<>"']/g, function(m) { return map[m]; });
    }

    $(document).ready(init);

})(jQuery);
