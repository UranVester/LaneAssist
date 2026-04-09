(function($) {
    'use strict';

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
        colorBy: 'event',
        colorMaps: {
            event: {},
            phase: {},
            division: {},
            class: {}
        }
    };

    const COLOR_PALETTE = [
        { bg: '#e8f1ff', border: '#2f6fd3' },
        { bg: '#eaf7ec', border: '#2f9e55' },
        { bg: '#fff2e8', border: '#d96a1f' },
        { bg: '#f3ecff', border: '#7b4bc2' },
        { bg: '#e8fbfb', border: '#1f8f9a' },
        { bg: '#fff0f5', border: '#c44579' },
        { bg: '#f8f4e8', border: '#9a7b21' },
        { bg: '#eef3e8', border: '#5d8a2f' },
        { bg: '#f0ebff', border: '#5a54c8' },
        { bg: '#e8f7f2', border: '#218a6b' },
        { bg: '#fff1e6', border: '#c76a18' },
        { bg: '#f4f0e8', border: '#8a6f33' },
        { bg: '#e9eef9', border: '#375ea8' },
        { bg: '#f0f9e9', border: '#4d8a2f' },
        { bg: '#fdeef0', border: '#b33f54' },
        { bg: '#edf5ff', border: '#3e79b8' },
        { bg: '#eef8f1', border: '#3a8f58' },
        { bg: '#fff4ea', border: '#c97a32' },
        { bg: '#efeafd', border: '#6950b8' },
        { bg: '#eaf9f7', border: '#2b8f85' }
    ];

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
                showDragHint('Invalid date format. Use YYYY-MM-DD');
                return;
            }
            if (!normalizedTime) {
                showDragHint('Invalid time format. Use HH:MM');
                return;
            }

            if (normalizedLength <= 0) {
                showDragHint('Invalid finals length. Use minutes > 0');
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
                    showStatus('Removed invalid scheduled match from planning board', 'success');
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
            showStatus('Removed invalid scheduled match from planning board', 'success');
        }

        $('#unassigned-list').on('click', '.bundle-clear-btn', handleBundleClearClick);
        $('#visual-board').on('click', '.bundle-clear-btn', handleBundleClearClick);
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
            return 'Invalid timeslot selected';
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
                return 'Event ' + impactedEvent + ': ' + formatPhaseLabel(earlierPhase) + ' finals must be before ' + formatPhaseLabel(laterPhase) + ' finals (drop was to ' + slot + ')';
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
            0: 'Gold',
            1: 'Bronze',
            2: '1/2',
            4: '1/4',
            8: '1/8',
            16: '1/16',
            32: '1/32',
            64: '1/64'
        };
        return map[numericPhase] || ('Phase ' + numericPhase);
    }

    function loadData() {
        showStatus('Loading finals setup...', 'info');

        $.ajax({
            url: ROOT_DIR + 'Modules/Custom/LaneAssist/ManageFinals/api.php',
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'getCurrent'
            },
            success: function(response) {
                if (response.error) {
                    showStatus(response.message || 'Failed to load finals setup', 'error');
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
                    row.projectedParticipants = Math.max(0, parseInt(row.projectedParticipants, 10) || 0);
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
                rebuildColorMaps();
                renderAll();
                showStatus('Loaded ' + state.rows.length + ' finals matches', 'success');
            },
            error: function() {
                showStatus('Failed to load finals setup', 'error');
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
        if (!time || time === '00:00:00') {
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
            showDragHint('Timeslot not found');
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
            showDragHint('Timeslot not found');
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
            showDragHint('Nothing to update for this timeslot');
            return;
        }

        state.editingTimeslotKey = '';
        recomputeHasChanges();
        syncValidationWarnings(getTimeslots(), buildBundles(state.rows));
        renderAll();
        showStatus('Updated timeslot date/time/length', 'success');
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
            showDragHint('Timeslots already exist. Use + between rows to insert another one.');
            return;
        }

        const baseDate = parseApiDateTime(state.lastQualificationEnd);
        if (!baseDate) {
            showDragHint('Could not determine the end of qualification sessions');
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
            showDragHint('Initial timeslot already exists');
            return;
        }

        recomputeHasChanges();
        syncValidationWarnings(getTimeslots(), buildBundles(state.rows));
        renderAll();
        showStatus('Added ' + createdCount + ' initial finals timeslot' + (createdCount === 1 ? '' : 's'), 'success');
    }

    function insertTimeslotAt(insertIndex) {
        const timeslots = getTimeslots();
        if (!timeslots.length) {
            showDragHint('No timeslots available to insert around');
            return;
        }

        const slotDurationMinutes = Math.max(1, parseInt(state.defaultFinalsLengthMinutes, 10) || 30);
        const safeIndex = Math.max(0, Math.min(insertIndex, timeslots.length));
        const referenceForInsert = safeIndex < timeslots.length ? timeslots[safeIndex] : timeslots[timeslots.length - 1];

        if (!referenceForInsert) {
            showDragHint('Could not determine insert position');
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
                ? ('; shifted ' + shiftedTimeslots + ' following timeslot' + (shiftedTimeslots === 1 ? '' : 's') + ' up')
                : '';
            showStatus('Removed timeslot and unassigned ' + affectedRows + ' match row' + (affectedRows === 1 ? '' : 's') + shiftSuffix, 'info');
        } else {
            const shiftSuffix = shiftedTimeslots > 0
                ? ('; shifted ' + shiftedTimeslots + ' following timeslot' + (shiftedTimeslots === 1 ? '' : 's') + ' up')
                : '';
            showStatus('Removed empty timeslot' + shiftSuffix, 'info');
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
                            message: 'Conflicting distance lanes on target ' + targetNo + ' (' + locks[targetNo] + ' vs ' + primaryDistance + ')'
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
        const streamBundles = (bundles || []).filter(function(bundle) {
            const bundleTeamEvent = parseInt(bundle.rows[0] ? bundle.rows[0].teamEvent : 0, 10) || 0;
            return bundleTeamEvent === teamEvent;
        });

        if (!streamBundles.length || !targetNumbers.length) {
            return { targetToDistance: {}, ranges: {} };
        }

        const stats = {};
        streamBundles.forEach(function(bundle) {
            const distanceKey = getBundleDistanceKey(bundle);
            if (!stats[distanceKey]) {
                stats[distanceKey] = {
                    key: distanceKey,
                    minSort: Number.MAX_SAFE_INTEGER,
                    maxBundleTargets: 0,
                    weight: 0,
                    count: 0
                };
            }

            const distanceSort = parseInt(bundle.distanceSort, 10);
            if (!Number.isNaN(distanceSort)) {
                stats[distanceKey].minSort = Math.min(stats[distanceKey].minSort, distanceSort);
            }

            stats[distanceKey].maxBundleTargets = Math.max(stats[distanceKey].maxBundleTargets, Math.max(1, parseInt(bundle.targetsUsed, 10) || 1));
            stats[distanceKey].weight += Math.max(1, parseInt(bundle.targetsUsed, 10) || 1);
        });

        const groups = Object.keys(stats).map(function(key) {
            return stats[key];
        }).filter(function(group) {
            return group.key !== 'unknown';
        }).sort(function(a, b) {
            if (a.minSort !== b.minSort) {
                return a.minSort - b.minSort;
            }

            return a.key.localeCompare(b.key);
        });

        if (!groups.length) {
            return { ranges: {} };
        }

        const totalTargets = targetNumbers.length;
        const totalWeight = groups.reduce(function(sum, group) {
            return sum + group.weight;
        }, 0);

        const minSlotsByCapacity = Math.max(1, Math.ceil(totalWeight / totalTargets));
        let chosenCounts = null;

        for (let slotCount = minSlotsByCapacity; slotCount <= totalWeight; slotCount++) {
            const proposedCounts = [];
            let sumCounts = 0;

            groups.forEach(function(group) {
                const requiredByLoad = Math.ceil(group.weight / slotCount);
                const lanes = Math.max(group.maxBundleTargets, requiredByLoad);
                proposedCounts.push(lanes);
                sumCounts += lanes;
            });

            if (sumCounts <= totalTargets) {
                chosenCounts = proposedCounts;
                break;
            }
        }

        if (!chosenCounts) {
            return null;
        }

        let usedTargets = 0;
        groups.forEach(function(group, index) {
            group.count = chosenCounts[index];
            usedTargets += group.count;
        });

        let extraTargets = totalTargets - usedTargets;
        if (extraTargets > 0 && groups.length) {
            const ranked = groups.map(function(group, index) {
                const ratio = group.weight / Math.max(1, group.count);
                return {
                    index: index,
                    ratio: ratio,
                    load: group.weight
                };
            }).sort(function(a, b) {
                if (b.ratio !== a.ratio) {
                    return b.ratio - a.ratio;
                }
                if (b.load !== a.load) {
                    return b.load - a.load;
                }
                return a.index - b.index;
            });

            let pointer = 0;
            while (extraTargets > 0 && ranked.length) {
                const target = ranked[pointer % ranked.length];
                groups[target.index].count += 1;
                extraTargets--;
                pointer++;
            }
        }

        const ranges = {};
        let cursor = 0;

        groups.forEach(function(group) {
            const slice = targetNumbers.slice(cursor, cursor + group.count);
            cursor += group.count;
            if (!slice.length) {
                return;
            }

            ranges[group.key] = {
                start: slice[0],
                end: slice[slice.length - 1]
            };
        });

        return { ranges: ranges };
    }

    function canPlaceBundleForDistance(bundle, startTargetNum, lanePlan) {
        if (!lanePlan) {
            return true;
        }

        const distanceKey = getBundleDistanceKey(bundle);
        if (distanceKey === 'unknown') {
            return true;
        }

        const range = lanePlan.ranges[distanceKey] || null;
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
            showDragHint('No available targets configured');
            return;
        }

        const allBundles = buildBundles(state.rows);
        const playableBundles = allBundles.filter(function(bundle) {
            return bundle.isPlayable;
        });

        if (!playableBundles.length) {
            showStatus('No playable finals bundles to auto assign', 'info');
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
            showDragHint('Create at least one timeslot before auto assign');
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
                const label = (parseInt(invalidPlanTeam, 10) || 0) === 1 ? 'team' : 'individual';
                showStatus('Auto assign failed: not enough targets to keep fixed distance lanes for ' + label + ' finals', 'error');
                return;
            }

            const distanceLocksResult = buildDistanceLocksFromLanePlans(lanePlanByTeam);
            if (!distanceLocksResult.valid) {
                showStatus('Auto assign failed: ' + (distanceLocksResult.message || 'distance lane conflict'), 'error');
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
                return { ok: false, message: 'Could not determine timeslot stream for team type ' + teamEvent };
            }

            const minIndex = getEventPhaseMinIndex(bundle);
            let slotIndex = Math.max(0, minIndex);

            while (true) {
                if (slotIndex >= stream.length) {
                    const created = appendNextTimeslot(teamEvent);
                    if (!created) {
                        return { ok: false, message: 'Could not create additional timeslots for auto assign' };
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
                    showStatus(result.message || 'Auto assign failed', 'error');
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
                    showStatus(result.message || 'Auto assign failed', 'error');
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
                        showStatus(result.message || 'Auto assign failed', 'error');
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
                    showStatus(result.message || 'Auto assign failed', 'error');
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
            showStatus('Auto assignment completed with ' + state.validationWarnings.length + ' validation issue(s): ' + state.validationWarnings[0], 'error');
        } else {
            showStatus('Auto assignment complete', 'success');
        }
    }

    function buildBundles(rows) {
        const map = {};

        rows.forEach(function(row) {
            if (!map[row.bundleKey]) {
                map[row.bundleKey] = {
                    key: row.bundleKey,
                    timeslotKey: row.timeslotKey,
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
                const participants = pairRows.filter(function(row) { return row.hasParticipant > 0; }).length;
                const projectedParticipants = pairRows.reduce(function(maxValue, row) {
                    const value = parseInt(row.projectedParticipants, 10);
                    if (Number.isNaN(value)) {
                        return maxValue;
                    }
                    return Math.max(maxValue, value);
                }, 0);

                const seedPositions = [];
                pairRows.forEach(function(row) {
                    const seedFromRow = parseInt(row.gridPosition, 10);
                    if (!Number.isNaN(seedFromRow) && seedFromRow > 0 && seedPositions.indexOf(seedFromRow) === -1) {
                        seedPositions.push(seedFromRow);
                    }
                });

                if (seedPositions.length < 2) {
                    pairRows.forEach(function(row) {
                        const seedAlt = parseInt(row.gridPosition2, 10);
                        if (!Number.isNaN(seedAlt) && seedAlt > 0 && seedPositions.indexOf(seedAlt) === -1) {
                            seedPositions.push(seedAlt);
                        }
                    });
                }

                const projectedPlayable = projectedParticipants >= 2 &&
                    seedPositions.length >= 2 &&
                    seedPositions.slice(0, 2).every(function(seed) {
                        return seed <= projectedParticipants;
                    });

                const pairHasAssignedTarget = pairRows.some(function(row) {
                    return parseTargetNumber(row.target) !== null;
                });

                if (participants >= 2 || (participants < 2 && projectedPlayable)) {
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
            $list.append('<div class="empty">All match groups assigned</div>');
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
                const warningText = 'Scheduled pair should not be played from current participants';

                invalidScheduledPairBundles.push({
                    key: syntheticKey,
                    timeslotKey: bundle.timeslotKey,
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
            distanceColorMap[distance] = getPaletteColor(index);
        });

        $board.empty();

        if (timeslots.length === 0) {
            if (!state.rows.length) {
                $board.append('<div class="empty">No data loaded</div>');
                return;
            }

            const hasQualifierEnd = !!parseApiDateTime(state.lastQualificationEnd);
            const emptyHtml = [
                '<div class="empty">',
                '<div>No finals timeslots found.</div>',
                '<div style="margin-top:6px;">',
                hasQualifierEnd
                    ? 'Create first timeslot from qualification end + default finals length.'
                    : 'Set qualification session end time to auto-create first finals timeslot.',
                '</div>',
                '<div style="margin-top:10px;">',
                '<button type="button" class="timeslot-empty-add-btn timeslot-bootstrap-btn" title="Add first finals timeslot" aria-label="Add first finals timeslot"><i class="fa fa-plus"></i> Add first timeslot</button>',
                '</div>',
                '</div>'
            ].join('');
            $board.append(emptyHtml);
            return;
        }

        if (state.availableTargets.length === 0) {
            $board.append('<div class="empty">No available targets detected</div>');
            return;
        }

        $board.append('<div class="timeslot-insert-anchor"><button type="button" class="timeslot-insert-btn" data-insert-index="0" title="Insert timeslot before first" aria-label="Insert timeslot before first"><i class="fa fa-plus"></i></button></div>');

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

            const typeText = timeslot.teamEvent === 1 ? 'Team' : 'Individual';
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
                    '<input type="number" min="1" step="1" class="timeslot-edit-length" value="' + escapeHtml(editLengthValue) + '" title="Length (minutes)">',
                    '<button type="button" class="timeslot-edit-save-btn" data-timeslot-key="' + escapeHtml(timeslot.key) + '" title="Save date/time/length"><i class="fa fa-check"></i></button>',
                    '<button type="button" class="timeslot-edit-cancel-btn" data-timeslot-key="' + escapeHtml(timeslot.key) + '" title="Cancel edit"><i class="fa fa-times"></i></button>',
                    '</div>',
                    '<button type="button" class="timeslot-delete-btn" data-timeslot-key="' + escapeHtml(timeslot.key) + '" title="Remove timeslot and unassign cards"><i class="fa fa-trash"></i></button>',
                    '</div>'
                ].join('');
            } else {
                actionsHtml = [
                    '<div class="timeslot-actions">',
                    '<button type="button" class="timeslot-edit-btn" data-timeslot-key="' + escapeHtml(timeslot.key) + '" title="Edit timeslot date, time and length"><i class="fa fa-pencil"></i></button>',
                    '<button type="button" class="timeslot-delete-btn" data-timeslot-key="' + escapeHtml(timeslot.key) + '" title="Remove timeslot and unassign cards"><i class="fa fa-trash"></i></button>',
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
                        $headerCell.attr('title', 'Distance changed on this lane in this timeslot');
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
                        const borderColor = color.border;
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
                                .attr('title', 'Existing split placement');
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

            $board.append('<div class="timeslot-insert-anchor"><button type="button" class="timeslot-insert-btn" data-insert-index="' + (index + 1) + '" title="Insert timeslot here" aria-label="Insert timeslot here"><i class="fa fa-plus"></i></button></div>');
        });

        if (renderedSections === 0) {
            const showEmptyTimeslots = $('#show-empty-timeslots').is(':checked');
            const hintText = showEmptyTimeslots
                ? 'No visible finals timeslots for the current filters.'
                : 'No playable bundles in current timeslots. Enable "Show empty" to see and plan them.';
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

    function createBundleCard(bundle) {
        const eventLabel = bundle.eventName ? (bundle.event + ' - ' + bundle.eventName) : bundle.event;
        const $card = $('<div class="bundle-card"></div>')
            .attr('data-bundle-key', bundle.key)
            .attr('data-timeslot-key', bundle.timeslotKey)
            .attr('data-size', bundle.targetsUsed);

        if (Array.isArray(bundle.clearRowKeys) && bundle.clearRowKeys.length) {
            $card.attr('data-row-keys', bundle.clearRowKeys.join(','));
        }

        const colorStyle = getBundleColorStyle(bundle);
        if (colorStyle) {
            $card.attr('style', colorStyle);
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

        const warningText = state.bundleWarnings[bundle.key] || (bundle.warningText || '');
        if (!bundle.isPlayable && bundleHasAssignedTarget(bundle)) {
            $card.addClass('has-warning');
            const nonPlayableMsg = 'Scheduled match should not be played (likely bye). Remove it from planning.';
            if (warningText) {
                $card.attr('title', warningText + ' | ' + nonPlayableMsg);
            } else {
                $card.attr('title', nonPlayableMsg);
            }

            const clearKeyAttr = Array.isArray(bundle.clearRowKeys) && bundle.clearRowKeys.length
                ? ' data-row-keys="' + escapeHtml(bundle.clearRowKeys.join(',')) + '"'
                : '';
            $card.append('<div class="card-warning-actions"><button type="button" class="bundle-clear-btn" data-bundle-key="' + escapeHtml(bundle.key) + '"' + clearKeyAttr + ' title="Remove this invalid scheduled match" aria-label="Remove invalid scheduled match"><i class="fa fa-times"></i></button></div>');
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
            const message = 'Scheduled but not playable: ' + (bundle.event || '') + ' ' + (bundle.phaseLabel || '') + ' G' + (bundle.group || 0) + ' (' + slotLabel + ')';
            warnings.push(message);

            if (!bundleWarnings[bundle.key]) {
                bundleWarnings[bundle.key] = [];
            }
            bundleWarnings[bundle.key].push('This scheduled match should not be played from current participants');

            if (Array.isArray(bundle.occupiedTargets) && bundle.occupiedTargets.length) {
                bundle.occupiedTargets.forEach(function(targetNo) {
                    addTargetWarning(bundle.timeslotKey, targetNo, 'Assigned match should not be played');
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
                const message = 'Scheduled pair not playable: ' + (bundle.event || '') + ' ' + (bundle.phaseLabel || '') + ' G' + (bundle.group || 0) + ' (' + slotLabel + ')';
                warnings.push(message);

                const syntheticKey = [bundle.key, 'nonPlayablePair', String(parseInt(pair.pairNo, 10) || 0)].join('|');
                if (!bundleWarnings[syntheticKey]) {
                    bundleWarnings[syntheticKey] = [];
                }
                bundleWarnings[syntheticKey].push('This scheduled pair should not be played from current participants');

                pairRows.forEach(function(row) {
                    const targetNo = parseTargetNumber(row.target);
                    if (targetNo === null) {
                        return;
                    }
                    addTargetWarning(bundle.timeslotKey, targetNo, 'Assigned pair should not be played');
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
                    const message = 'Event ' + phaseInfo.event + ' (' + (parseInt(phaseInfo.teamEvent, 10) ? 'Team' : 'Individual') + '): ' + formatPhaseLabel(earlierPhase) + ' finals must be before ' + formatPhaseLabel(laterPhase) + ' finals';
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
                        const phaseMessage = 'Phase order conflict: ' + formatPhaseLabel(earlierPhase) + ' must be before ' + formatPhaseLabel(laterPhase);
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
                const warningMessage = 'Target ' + targetKey + ' is assigned more than once in timeslot ' + slotLabel;
                warnings.push(warningMessage);
                addTargetWarning(timeslot.key, targetKey, warningMessage);

                usedBy.forEach(function(bundle) {
                    if (!bundleWarnings[bundle.key]) {
                        bundleWarnings[bundle.key] = [];
                    }
                    bundleWarnings[bundle.key].push('Target ' + targetKey + ' conflicts in ' + slotLabel);
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

    function getBundleColorStyle(bundle) {
        const mode = state.colorBy || 'none';
        if (mode === 'none') {
            return '';
        }

        let key = '';
        if (mode === 'event') {
            key = bundle.event || '';
        } else if (mode === 'phase') {
            key = bundle.phaseLabel || '';
        } else if (mode === 'division') {
            key = bundle.division || '';
        } else if (mode === 'class') {
            key = bundle.classCode || '';
        }

        if (!key) {
            return '';
        }

        const color = state.colorMaps[mode][key] || null;
        if (!color) {
            return '';
        }

        return 'border-color:' + color.border + ';background:' + color.bg + ';';
    }

    function rebuildColorMaps() {
        state.colorMaps = {
            event: buildColorMapForMode('event'),
            phase: buildColorMapForMode('phase'),
            division: buildColorMapForMode('division'),
            class: buildColorMapForMode('class')
        };
    }

    function buildColorMapForMode(mode) {
        const keys = {};
        const eventOrder = {};

        state.rows.forEach(function(row) {
            let value = '';
            if (mode === 'event') {
                value = row.event;
            } else if (mode === 'phase') {
                value = row.phaseLabel;
            } else if (mode === 'division') {
                value = row.division;
            } else if (mode === 'class') {
                value = row.classCode;
            }

            value = (value || '').toString().trim();
            if (value !== '') {
                keys[value] = true;
                if (mode === 'event') {
                    const divisionOrder = (row.eventDivisionOrder === null) ? Number.MAX_SAFE_INTEGER : row.eventDivisionOrder;
                    const classOrder = (row.eventClassOrder === null) ? Number.MAX_SAFE_INTEGER : row.eventClassOrder;
                    if (!eventOrder[value]) {
                        eventOrder[value] = {
                            divisionOrder: divisionOrder,
                            classOrder: classOrder
                        };
                    } else {
                        eventOrder[value].divisionOrder = Math.min(eventOrder[value].divisionOrder, divisionOrder);
                        eventOrder[value].classOrder = Math.min(eventOrder[value].classOrder, classOrder);
                    }
                }
            }
        });

        const ordered = Object.keys(keys).sort(function(a, b) {
            if (mode !== 'event') {
                return a.localeCompare(b);
            }

            const aOrder = eventOrder[a] || { divisionOrder: Number.MAX_SAFE_INTEGER, classOrder: Number.MAX_SAFE_INTEGER };
            const bOrder = eventOrder[b] || { divisionOrder: Number.MAX_SAFE_INTEGER, classOrder: Number.MAX_SAFE_INTEGER };

            if (aOrder.divisionOrder !== bOrder.divisionOrder) {
                return aOrder.divisionOrder - bOrder.divisionOrder;
            }
            if (aOrder.classOrder !== bOrder.classOrder) {
                return aOrder.classOrder - bOrder.classOrder;
            }
            return a.localeCompare(b);
        });
        const map = {};
        ordered.forEach(function(key, index) {
            map[key] = getPaletteColor(index);
        });

        return map;
    }

    function getPaletteColor(index) {
        if (index < COLOR_PALETTE.length) {
            return COLOR_PALETTE[index];
        }

        const hue = (index * 137.508) % 360;
        return {
            bg: 'hsl(' + hue + ', 85%, 95%)',
            border: 'hsl(' + hue + ', 60%, 42%)'
        };
    }

    let activeDropGrid = null;
    let currentDropEvaluation = null;
    let activeDragSourceCard = null;
    let activeDragSourceWrapper = null;

    function makeBundlesDraggable() {
        $('.bundle-card').draggable({
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
            return { valid: false, message: 'Invalid dragged item' };
        }

        const targetTimeslot = $grid.data('timeslot-key');
        const timeslotInfo = getTimeslotByKey(targetTimeslot);
        if (!timeslotInfo) {
            return { valid: false, message: 'Invalid target timeslot' };
        }

        const sourceTeamEvent = parseInt(bundle.rows[0] ? bundle.rows[0].teamEvent : bundle.teamEvent, 10);
        if (sourceTeamEvent !== parseInt(timeslotInfo.teamEvent, 10)) {
            return { valid: false, message: 'Cannot move between individual and team finals timeslots' };
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
                    message: 'Target ' + distanceMismatch.targetNo + ' is fixed to ' + distanceMismatch.expected + ' (card is ' + distanceMismatch.actual + ')'
                };
            }
        }

        if (!canPlaceBundle(bundle, preferredStart, bundles)) {
            return {
                valid: false,
                startTargetNum: preferredStart,
                message: getPlacementErrorMessage(bundle, preferredStart, bundles, timeslotInfo) || 'Selected timeslot already uses one or more of those targets'
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
            return 'No available targets configured';
        }

        if (isDistanceLaneRestrictionEnabled()) {
            const targetDistanceMap = buildAssignedTargetDistanceMap(bundle.key);
            const distanceMismatch = getDistancePlacementMismatch(bundle, startTargetNum, targetDistanceMap);
            if (distanceMismatch) {
                return 'Target ' + distanceMismatch.targetNo + ' is fixed to ' + distanceMismatch.expected + ' (card is ' + distanceMismatch.actual + ')';
            }
        }

        const minTarget = Math.min.apply(null, targetNumbers);
        const maxTarget = Math.max.apply(null, targetNumbers);

        if (startTargetNum < minTarget || (startTargetNum + bundle.targetsUsed - 1) > maxTarget) {
            return 'Not enough free targets from ' + startTargetNum + ' (needs ' + bundle.targetsUsed + ' targets)';
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
        const slotLabel = timeslotInfo ? normalizeDateTimeValue(timeslotInfo.scheduledDate, timeslotInfo.scheduledTime) : 'selected timeslot';

        return 'Targets ' + targetList.join(', ') + ' are already used in ' + slotLabel + ' by ' + conflictLabel;
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

        $hint.removeClass('hidden').text(message || 'Invalid move');

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
                    const message = currentDropEvaluation.message || 'Selected timeslot already uses one or more of those targets';
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
            const summaryHtml = '<div class="line-1">Will save: ' + changedCount + ' finals change' + (changedCount === 1 ? '' : 's') + '</div>';
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
        showStatus('All finals match groups unassigned', 'info');
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
        showStatus('Reset to loaded values', 'info');
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
            showStatus('No changes to apply', 'info');
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
                    showStatus(validationResponse.message || 'Validation failed', 'error');
                    return;
                }

                clearValidationWarnings();
                if (!validationResponse.valid) {
                    const errors = validationResponse.errors || [];
                    if (!errors.length) {
                        showStatus('Validation failed', 'error');
                        return;
                    }

                    showStatus(errors[0].message || 'Validation failed', 'error');
                    renderAll();
                    return;
                }

                showStatus('Applying finals setup changes...', 'info');

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
                                showStatus(errors[0].message || 'Apply failed', 'error');
                                renderAll();
                            } else {
                                showStatus(response.message || 'Apply failed', 'error');
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
                        showStatus((response.message || 'Changes applied') + ' (' + (response.updated || 0) + ' rows)', 'success');
                    },
                    error: function() {
                        showStatus('Apply failed', 'error');
                    }
                });
            },
            error: function() {
                showStatus('Validation failed', 'error');
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
            $text.text(state.validationWarnings.length + ' validation issues (click to view)');
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
