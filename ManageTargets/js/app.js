/**
 * Interactive Target Assignment - Client Application
 * Uses jQuery and jQuery UI for drag-and-drop functionality
 */

(function($) {
    'use strict';

    const divisionMetaMap = (typeof DivisionMeta !== 'undefined' && DivisionMeta) ? DivisionMeta : {};
    const classMetaMap = (typeof ClassMeta !== 'undefined' && ClassMeta) ? ClassMeta : {};
    const OUTDOOR_MIXED_CONFLICT_MESSAGE = 'Invalid move: 122cm and 80cm faces cannot be mixed on the same mat/lane.';

    // Application state
    const state = {
        currentSession: null,
        currentLayout: null,
        layoutAutoMode: true,
        savedLayoutId: 'layout_fallback_stacked',
        colorBy: 'none',
        participants: [],
        availableTargets: [],
        targetFaces: {},  // Map of targetFaceId to image info
        assignments: {}, // participantId -> {target, letter, targetFull}
        originalAssignments: {}, // Original state from server
        sessionInfo: null,
        hasChanges: false,
        originalArchersPerTarget: null,
        newArchersPerTarget: null
    };

    /**
     * Initialize the application
     */
    function init() {
        initMultiSelectDropdowns();
        setupEventHandlers();
        updateFiltersIndicator();
        updateUI();
        updateViewportLayout();
        
        // Auto-load data if there's a session preselected
        if ($('#session-select').val()) {
            state.currentSession = $('#session-select').val();
            loadData();
        }
    }

    function normalizeLayoutId(layoutId) {
        const id = (layoutId || '').toString();
        return id || 'layout_fallback_stacked';
    }

    function hasPendingLayoutPreferenceChange() {
        return normalizeLayoutId(state.currentLayout) !== normalizeLayoutId(state.savedLayoutId);
    }

    function refreshHasChangesFlag() {
        const assignmentChanges = countChanges();
        const pendingArchers = state.newArchersPerTarget !== null ? parseInt(state.newArchersPerTarget, 10) : null;
        const originalArchers = parseInt(state.originalArchersPerTarget, 10);
        const hasArchersChange = !!(pendingArchers && originalArchers && pendingArchers !== originalArchers);
        state.hasChanges = assignmentChanges > 0 || hasArchersChange || hasPendingLayoutPreferenceChange();
    }

    /**
     * Initialize compact multi-select dropdowns (Division/Class)
     */
    function initMultiSelectDropdowns() {
        if (window.LaneAssist && typeof window.LaneAssist.initMultiSelectDropdowns === 'function') {
            window.LaneAssist.initMultiSelectDropdowns({
                onDocumentClick: function() {
                    $('#targets-actions-menu').removeClass('open');
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
                $('#targets-actions-menu').removeClass('open');
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

    /**
     * Setup event handlers
     */
    function setupEventHandlers() {
        // Reset button
        $('#btn-reset').on('click', resetToOriginal);

        // Apply button
        $('#btn-apply').on('click', applyChanges);

        // Auto assign button
        $('#btn-auto-assign').on('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $('#filters-popup').hide();
            $('#btn-filters').attr('aria-expanded', 'false');
            $('#auto-assign-options').toggle();
            $('#auto-assign-info-tooltip').hide();
            $('#auto-assign-info-toggle').attr('aria-expanded', 'false');
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

        $('#auto-assign-info-toggle').on('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const $tooltip = $('#auto-assign-info-tooltip');
            const isOpen = $tooltip.is(':visible');
            $tooltip.toggle(!isOpen);
            $(this).attr('aria-expanded', isOpen ? 'false' : 'true');
        });

        // Unassign all button
        $('#btn-unassign-all').on('click', unassignAll);

        // Run auto assign
        $('#btn-run-auto').on('click', runAutoAssign);

        // Target actions menu
        $('#targets-actions-toggle').on('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $('#targets-actions-menu').toggleClass('open');
        });

        $('#action-flip-lanes').on('click', function() {
            flipAssignedLanes();
            $('#targets-actions-menu').removeClass('open');
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

        $(document).on('click', '.action-swap-letters', function() {
            const letterA = ($(this).data('letter-a') || '').toString();
            const letterB = ($(this).data('letter-b') || '').toString();
            swapAssignedArchers(letterA, letterB);
            $('#targets-actions-menu').removeClass('open');
        });
        
        // Cancel auto assign
        $('#btn-cancel-auto').on('click', function() {
            $('#auto-assign-options').hide();
            $('#auto-assign-info-tooltip').hide();
            $('#auto-assign-info-toggle').attr('aria-expanded', 'false');
            updateViewportLayout();
        });

        $(window).on('resize orientationchange', updateViewportLayout);

        // Session change
        $('#session-select').on('change', function() {
            state.currentSession = $(this).val();
            if (state.currentSession) {
                loadData();
            }
        });

        // Division and class filter changes - reload data automatically
        $('#division-filter, #class-filter').on('change', function() {
            updateFiltersIndicator();
            if (state.currentSession) {
                loadData();
            }
        });

        // Layout selection
        $('#layout-select').on('change', function() {
            const layoutId = $(this).val() || 'layout_fallback_stacked';
            const $selectedOption = $(this).find('option:selected');
            const layoutArchers = parseInt($selectedOption.data('archers'), 10);
            
            // Update layout immediately
            state.currentLayout = layoutId;
            state.layoutAutoMode = false;
            
            // Check if layout's archer count differs from original setting
            const originalArchers = parseInt(state.originalArchersPerTarget, 10);
            
            // Always check if we need to update the archers per target state
            if (layoutArchers && originalArchers) {
                if (layoutArchers === originalArchers) {
                    // Same as original - always clear any pending change
                    if (state.newArchersPerTarget !== null) {
                        // Unassign all participants when reverting archers count
                        Object.keys(state.assignments).forEach(function(participantId) {
                            state.assignments[participantId] = {
                                target: null,
                                letter: null,
                                targetFull: null
                            };
                        });
                        
                        state.newArchersPerTarget = null;
                        state.hasChanges = countChanges() > 0;
                        
                        const msg = 'Layout changed. Archers per target restored to original value (' + layoutArchers + '). All participants unassigned.';
                        showStatus(msg, 'info');
                        
                        renderParticipants();
                        updateUI();
                        validateCurrentState();
                    }
                } else {
                    // Different from original - need to apply a change
                    if (state.newArchersPerTarget !== layoutArchers) {
                        // Unassign all participants when changing archers count
                        Object.keys(state.assignments).forEach(function(participantId) {
                            state.assignments[participantId] = {
                                target: null,
                                letter: null,
                                targetFull: null
                            };
                        });
                        
                        state.newArchersPerTarget = parseInt(layoutArchers, 10);
                        state.hasChanges = true;
                        
                        const msg = 'Layout changed. Archers per target will be updated to ' + layoutArchers + '. ' +
                                  'All participants unassigned. Click "Apply Changes" to save.';
                        showStatus(msg, 'info');
                        
                        renderParticipants();
                        updateUI();
                        validateCurrentState();
                    }
                }
                
                // Update toolbar display
                updateSessionInfoDisplay();
            }
            
            // Re-render targets with new layout
            if (state.availableTargets.length > 0) {
                renderTargets();
            }

            refreshHasChangesFlag();
            updateUI();
        });

        $('#color-by').on('change', function() {
            state.colorBy = ($(this).val() || 'none').toString();
            renderParticipants();
            renderTargets();
        });

        // Enable Enter key on inputs
        $('#target-from, #target-to').on('keypress', function(e) {
            if (e.which === 13) {
                loadData();
            }
        });
    }

    /**
     * Filter layout options based on target diameter and archers per target
     */
    function filterLayoutsByDiameter(targetContext) {
        const $layoutSelect = $('#layout-select');
        const currentValue = $layoutSelect.val();
        const sessionArchers = state.sessionInfo ? parseInt(state.sessionInfo.SesAth4Target) : null;
        const context = (typeof targetContext === 'object' && targetContext !== null)
            ? targetContext
            : { diameter: parseInt(targetContext, 10) || null, mixedOutdoor: false };
        const targetDiameter = parseInt(context.diameter, 10) || null;
        const mixedOutdoor = !!context.mixedOutdoor;
        
        let firstMatchingLayout = null;
        let bestMatchingLayout = null; // Layout that matches both diameter and archers
        let exactMatchCount = 0;
        
        // Show/hide options based on target diameter
        $layoutSelect.find('option').each(function() {
            const $option = $(this);
            const optionValue = $option.val();
            const optionArchers = parseInt($option.data('archers'));
            const optionSize = $option.data('target-size');

            // Fallback stacked option is always shown and not used for auto-matching
            if (optionValue === 'layout_fallback_stacked') {
                $option.show();
                $option.prop('disabled', false);
                return;
            }
            
            const isMixedLayoutOption = isOutdoorMixedLayoutId(optionValue);

            let shouldShow = false;
            if (mixedOutdoor) {
                shouldShow = isMixedLayoutOption;
            } else if (targetDiameter) {
                const optionSizeNum = parseInt(optionSize, 10);
                shouldShow = !isMixedLayoutOption && optionSizeNum === targetDiameter;
            } else {
                shouldShow = true;
            }

            if (shouldShow) {
                $option.show();
                $option.prop('disabled', false);
                
                // Track first matching layout
                if (!firstMatchingLayout) {
                    firstMatchingLayout = $option.val();
                }
                
                // Track best matching layout (diameter + archers)
                if (sessionArchers && optionArchers === sessionArchers) {
                    exactMatchCount++;
                    if (!bestMatchingLayout) {
                        bestMatchingLayout = $option.val();
                    }
                }
            } else {
                $option.hide();
                $option.prop('disabled', true);
                if ($option.val() === currentValue) {
                    $layoutSelect.val('layout_fallback_stacked');
                    state.currentLayout = 'layout_fallback_stacked';
                }
            }
        });
        
        // Auto-select when auto mode is enabled (or when nothing is selected).
        // Use exact match when there is exactly one clear choice, otherwise use fallback stacked.
        if (state.layoutAutoMode || !$layoutSelect.val()) {
            let layoutToSelect = 'layout_fallback_stacked';
            if (exactMatchCount === 1 && bestMatchingLayout) {
                layoutToSelect = bestMatchingLayout;
            } else if (mixedOutdoor && firstMatchingLayout) {
                layoutToSelect = firstMatchingLayout;
            }
            $layoutSelect.val(layoutToSelect);
            state.currentLayout = layoutToSelect;
        }
    }

    /**
     * Load data from server
     */
    function loadData() {
        const session = $('#session-select').val();
        const targetFrom = $('#target-from').val() || '1';
        const targetTo = $('#target-to').val() || '99';
        
        // Build event pattern from division and class selects
        const event = buildEventPattern();

        if (!session) {
            showError('Please select a session');
            return;
        }

        showStatus('Loading data...', 'info');

        $.ajax({
            url: wwwdir + 'Modules/Custom/LaneAssist/ManageTargets/api.php',
            method: 'GET',
            dataType: 'json',
            data: {
                action: 'getCurrent',
                session: session,
                event: event,
                targetFrom: targetFrom,
                targetTo: targetTo
            },
            success: function(response) {
                if (response.error) {
                    showError(response.message || 'Error loading data');
                    return;
                }

                state.currentSession = session;
                state.participants = response.participants || [];
                state.availableTargets = response.availableTargets || [];
                state.sessionInfo = response.sessionInfo || {};
                state.originalArchersPerTarget = parseInt(state.sessionInfo.SesAth4Target, 10) || null;
                state.sessionInfo.SesAth4Target = state.originalArchersPerTarget;
                state.newArchersPerTarget = null;
                state.savedLayoutId = normalizeLayoutId(state.sessionInfo.savedLayoutId || 'layout_fallback_stacked');
                state.currentLayout = state.savedLayoutId;
                state.layoutAutoMode = !state.sessionInfo.savedLayoutId;
                $('#layout-select').val(state.currentLayout);
                
                // Store target face images
                state.targetFaces = {};
                var targetDiameter = null;
                var has80cm = false;
                var has122cm = false;
                if (response.targetFaces) {
                    const diameters = {};
                    response.targetFaces.forEach(function(tf) {
                        state.targetFaces[tf.id] = tf;
                        const faceDiameter = parseInt(tf.diameter, 10);
                        if (faceDiameter === 80) {
                            has80cm = true;
                        }
                        if (faceDiameter >= 120) {
                            has122cm = true;
                        }

                        // Count only 40cm and 60cm targets
                        if (faceDiameter === 40 || faceDiameter === 60) {
                            diameters[faceDiameter] = (diameters[faceDiameter] || 0) + 1;
                        }
                    });
                    
                    // Find most common diameter (40 or 60 only)
                    let maxCount = 0;
                    for (const diam in diameters) {
                        if (diameters[diam] > maxCount) {
                            maxCount = diameters[diam];
                            targetDiameter = parseInt(diam);
                        }
                    }
                }

                // Update toolbar session info display
                updateSessionInfoDisplay();
                
                // Show status info on page
                let statusMsg = 'Session loaded. Archers per target: ' + state.sessionInfo.SesAth4Target;
                if (state.sessionInfo.detectionMethod) {
                    statusMsg += ' (' + state.sessionInfo.detectionMethod + ')';
                }
                if (targetDiameter) {
                    statusMsg += ', Target size: ' + targetDiameter + 'cm';
                } else if (has80cm && has122cm) {
                    statusMsg += ', Target size: mixed outdoor (80cm + 122cm)';
                }
                statusMsg += ', Available targets: ' + state.availableTargets.length +
                           ', Participants: ' + state.participants.length;
                showStatus(statusMsg, 'info');

                // Filter layouts and auto-select based on target profile
                filterLayoutsByDiameter({
                    diameter: targetDiameter,
                    mixedOutdoor: has80cm && has122cm
                });

                // Initialize assignments from current state
                state.assignments = {};
                state.originalAssignments = {};
                state.participants.forEach(function(p) {
                    const assignment = {
                        target: p.target,
                        letter: p.letter,
                        targetFull: p.targetFull
                    };
                    state.assignments[p.id] = assignment;
                    state.originalAssignments[p.id] = JSON.parse(JSON.stringify(assignment));
                });

                state.hasChanges = false;
                renderParticipants();
                renderTargets();
                updateUI();
                
                // Validate on initial load to show any errors
                validateCurrentState();
                
                // Update target range defaults and values based on session
                if (state.sessionInfo) {
                    const sessionFirst = state.sessionInfo.firstTarget;
                    const sessionLast = sessionFirst + state.sessionInfo.targetCount - 1;
                    $('#target-from').attr('placeholder', sessionFirst).attr('min', sessionFirst);
                    $('#target-to').attr('placeholder', sessionLast).attr('min', sessionFirst);
                    
                    // Update values if they were defaults
                    if ($('#target-from').val() == '1') {
                        $('#target-from').val(sessionFirst);
                    }
                    if ($('#target-to').val() == '99') {
                        $('#target-to').val(sessionLast);
                    }
                }
                
                showStatus('Data loaded: ' + response.participants.length + ' participants, targets ' + 
                    (state.sessionInfo.loadedFrom || '') + '-' + (state.sessionInfo.loadedTo || ''), 'success');

                updateViewportLayout();
            },
            error: function() {
                showError('Failed to load data from server');
            }
        });
    }

    /**
     * Render participants list
     */
    function renderParticipants() {
        const $unassignedList = $('#unassigned-list');
        $unassignedList.empty();

        const unassigned = state.participants.filter(function(p) {
            const assignment = state.assignments[p.id];
            return !assignment || !assignment.targetFull;
        });

        $('#unassigned-count').text(unassigned.length);

        if (unassigned.length === 0) {
            $unassignedList.append('<div class="empty-message">All participants assigned</div>');
            return;
        }

        unassigned.forEach(function(participant) {
            const $card = createParticipantCard(participant);
            $unassignedList.append($card);
        });

        makeParticipantsDraggable($unassignedList);
    }

    /**
     * Render targets grid
     */
    function renderTargets(targetGridOverride, renderOptions) {
        const options = renderOptions || {};
        const $targetsGrid = (targetGridOverride && targetGridOverride.length)
            ? targetGridOverride
            : $('#targets-grid');
        $targetsGrid.empty();
        $targetsGrid.removeClass('fallback-stacked');

        if (state.availableTargets.length === 0) {
            $targetsGrid.append('<div class="empty-message">No targets available</div>');
            return;
        }

        // Get current layout
        const layoutId = state.currentLayout;

        if (layoutId === 'layout_fallback_stacked') {
            $targetsGrid.addClass('fallback-stacked');
            renderTargetsSimple($targetsGrid);
            if (!options.skipInteractions) {
                makeParticipantsDraggable($targetsGrid);
                makeTargetsDroppable();
                makeLanesDraggableDroppable();
            }

            if (!options.skipCounters) {
                const assigned = state.participants.filter(function(p) {
                    const assignment = state.assignments[p.id];
                    return assignment && assignment.targetFull;
                });
                $('#assigned-count').text(assigned.length);
            }
            return;
        }
        
        const layout = layoutId && typeof TargetLayouts !== 'undefined' ? 
            TargetLayouts.find(l => l.id === layoutId) : null;

        if (layout) {
            renderTargetsWithLayout($targetsGrid, layout);
        } else {
            renderTargetsSimple($targetsGrid);
        }

        if (!options.skipInteractions) {
            makeParticipantsDraggable($targetsGrid);
            makeTargetsDroppable();
            makeLanesDraggableDroppable();
        }

        if (!options.skipCounters) {
            // Update assigned count
            const assigned = state.participants.filter(function(p) {
                const assignment = state.assignments[p.id];
                return assignment && assignment.targetFull;
            });
            $('#assigned-count').text(assigned.length);
        }
    }

    /**
     * Render targets with visual layout
     */
    function renderTargetsWithLayout($container, layout) {
        // Group targets by target number
        const targetGroups = {};
        state.availableTargets.forEach(function(t) {
            if (!targetGroups[t.target]) {
                targetGroups[t.target] = [];
            }
            targetGroups[t.target].push(t);
        });

        const targetNums = Object.keys(targetGroups).sort(function(a, b) { return parseInt(a) - parseInt(b); });
        
        // Group into mats based on layout (lanesPerMat = how many target numbers per mat)
        const targetNumsPerMat = layout.lanesPerMat;
        const matSize = layout.targetsPerMat > 4 ? 'large' : (layout.targetsPerMat > 2 ? 'medium' : 'small');
        
        for (let i = 0; i < targetNums.length; i += targetNumsPerMat) {
            const matTargets = targetNums.slice(i, i + targetNumsPerMat);
            const matNumber = Math.floor(i / targetNumsPerMat) + 1;
            const $mat = createMatElement(matTargets, targetGroups, layout, matSize, matNumber);
            $container.append($mat);
        }
    }

    function buildTargetFull(targetNumber, letter) {
        const numericTarget = parseInt(targetNumber, 10) || 0;
        const padding = parseInt(typeof TargetNoPadding !== 'undefined' ? TargetNoPadding : 3, 10) || 3;
        return String(numericTarget).padStart(padding, '0') + String(letter || '');
    }

    function getEffectiveArchersPerTarget() {
        const effectiveArchers = parseInt(
            state.newArchersPerTarget ||
            state.originalArchersPerTarget ||
            (state.sessionInfo ? state.sessionInfo.SesAth4Target : 0),
            10
        ) || 0;

        return Math.max(1, Math.min(4, effectiveArchers || 4));
    }

    function getMixedExpectedPositions() {
        const count = getEffectiveArchersPerTarget();
        return ['A', 'B', 'C', 'D'].slice(0, count);
    }

    function findAssignedParticipantByTargetFull(targetFull) {
        return state.participants.find(function(participant) {
            const assignment = state.assignments[participant.id];
            return assignment && assignment.targetFull === targetFull;
        }) || null;
    }

    function getParticipantTargetDiameter(participant) {
        if (!participant || !participant.targetFaceId || !state.targetFaces[participant.targetFaceId]) {
            return null;
        }

        const diameter = parseInt(state.targetFaces[participant.targetFaceId].diameter, 10);
        return Number.isNaN(diameter) ? null : diameter;
    }

    function isLargeOutdoorFace(diameter) {
        return diameter !== null && diameter >= 120;
    }

    function isOutdoorMixedLayoutId(layoutId) {
        const id = (layoutId || '').toString();
        return id.indexOf('layout_outdoor_mixed_') === 0;
    }

    function isOutdoorMixedLayoutActive() {
        return isOutdoorMixedLayoutId(state.currentLayout);
    }

    function getParticipantById(participantId) {
        for (let index = 0; index < state.participants.length; index++) {
            if (String(state.participants[index].id) === String(participantId)) {
                return state.participants[index];
            }
        }

        return null;
    }

    function getParticipantSizeClass(participantId) {
        const participant = getParticipantById(participantId);
        const diameter = getParticipantTargetDiameter(participant);

        if (diameter === null) {
            return null;
        }

        return isLargeOutdoorFace(diameter) ? 'large' : 'small';
    }

    function cloneAssignmentsState() {
        const cloned = {};
        Object.keys(state.assignments).forEach(function(participantId) {
            const assignment = state.assignments[participantId] || {};
            cloned[participantId] = {
                target: assignment.target || 0,
                letter: assignment.letter || '',
                targetFull: assignment.targetFull || ''
            };
        });

        return cloned;
    }

    function hasOutdoorMixedConflict(assignmentsSnapshot) {
        if (!isOutdoorMixedLayoutActive()) {
            return false;
        }

        const laneFlags = {};

        Object.keys(assignmentsSnapshot || {}).forEach(function(participantId) {
            const assignment = assignmentsSnapshot[participantId];
            if (!assignment || !assignment.target) {
                return;
            }

            const laneNumber = parseInt(assignment.target, 10);
            if (Number.isNaN(laneNumber) || laneNumber <= 0) {
                return;
            }

            const sizeClass = getParticipantSizeClass(participantId);
            if (!sizeClass) {
                return;
            }

            if (!laneFlags[laneNumber]) {
                laneFlags[laneNumber] = { hasLarge: false, hasSmall: false };
            }

            if (sizeClass === 'large') {
                laneFlags[laneNumber].hasLarge = true;
            } else {
                laneFlags[laneNumber].hasSmall = true;
            }
        });

        return Object.keys(laneFlags).some(function(laneNumber) {
            const flags = laneFlags[laneNumber];
            return flags.hasLarge && flags.hasSmall;
        });
    }

    function buildDistanceMixErrorMessage(lanes, profiles) {
        const laneList = Array.isArray(lanes) ? lanes.map(function(item) { return String(item); }) : [];
        const profileList = Array.isArray(profiles) ? profiles.map(function(item) { return String(item); }) : [];
        return 'Mixed distances on the same mat (lanes ' + laneList.join('/') + '): ' + profileList.join(' vs ');
    }

    function getOutdoorPositionClass(letter, expectedCount) {
        const normalizedLetter = (letter || '').toUpperCase();

        if (expectedCount === 1) {
            return 'outdoor-pos-center';
        }

        if (expectedCount === 2) {
            if (normalizedLetter === 'A') {
                return 'outdoor-pos-top-left';
            }
            if (normalizedLetter === 'B') {
                return 'outdoor-pos-top-right';
            }
        }

        if (expectedCount === 3) {
            if (normalizedLetter === 'B') {
                return 'outdoor-pos-top-middle';
            }
            if (normalizedLetter === 'A') {
                return 'outdoor-pos-bottom-left';
            }
            if (normalizedLetter === 'C') {
                return 'outdoor-pos-bottom-right';
            }
        }

        if (normalizedLetter === 'A') {
            return 'outdoor-pos-top-left';
        }
        if (normalizedLetter === 'B') {
            return 'outdoor-pos-top-right';
        }
        if (normalizedLetter === 'C') {
            return 'outdoor-pos-bottom-left';
        }
        if (normalizedLetter === 'D') {
            return 'outdoor-pos-bottom-right';
        }

        return 'outdoor-pos-center';
    }

    function createLaneControl(laneNumber, alignClass) {
        const lane = parseInt(laneNumber, 10);
        const alignment = alignClass || '';
        return $('<span class="lane-control ' + alignment + '">' +
            '<span class="lane-drop-zone before" data-reference-lane="' + lane + '" data-drop-position="before" title="Move before"></span>' +
            '<span class="lane-chip" data-lane="' + lane + '" title="Drag lane">' + lane + '</span>' +
            '<span class="lane-drop-zone after" data-reference-lane="' + lane + '" data-drop-position="after" title="Move after"></span>' +
            '</span>');
    }

    function getVisibleLaneNumbers() {
        const laneMap = {};
        state.availableTargets.forEach(function(target) {
            const lane = parseInt(target.target, 10);
            if (!Number.isNaN(lane) && lane > 0) {
                laneMap[lane] = true;
            }
        });

        return Object.keys(laneMap)
            .map(function(value) { return parseInt(value, 10); })
            .sort(function(a, b) { return a - b; });
    }

    function applyLaneRemap(laneMap) {
        let changed = false;

        Object.keys(state.assignments).forEach(function(participantId) {
            const assignment = state.assignments[participantId];
            if (!assignment || !assignment.target) {
                return;
            }

            const currentLane = parseInt(assignment.target, 10);
            const mappedLane = laneMap[currentLane];
            if (!mappedLane || mappedLane === currentLane) {
                return;
            }

            assignment.target = mappedLane;
            assignment.targetFull = buildTargetFull(mappedLane, assignment.letter || '');
            changed = true;
        });

        if (!changed) {
            return;
        }

        state.hasChanges = true;
        renderParticipants();
        renderTargets();
        updateUI();
        validateCurrentState();
    }

    function swapLanes(sourceLane, targetLane) {
        if (!sourceLane || !targetLane || sourceLane === targetLane) {
            return;
        }

        const laneMap = {};
        laneMap[sourceLane] = targetLane;
        laneMap[targetLane] = sourceLane;
        applyLaneRemap(laneMap);
    }

    function moveLane(sourceLane, referenceLane, dropPosition) {
        if (!sourceLane || !referenceLane || sourceLane === referenceLane) {
            return;
        }

        const baseOrder = getVisibleLaneNumbers();
        const sourceIndex = baseOrder.indexOf(sourceLane);
        const referenceIndex = baseOrder.indexOf(referenceLane);
        if (sourceIndex === -1 || referenceIndex === -1) {
            return;
        }

        const movedOrder = baseOrder.filter(function(lane) { return lane !== sourceLane; });
        const insertReferenceIndex = movedOrder.indexOf(referenceLane);
        const insertAt = dropPosition === 'after' ? insertReferenceIndex + 1 : insertReferenceIndex;
        movedOrder.splice(insertAt, 0, sourceLane);

        const laneMap = {};
        for (let index = 0; index < baseOrder.length; index++) {
            const fromLane = movedOrder[index];
            const toLane = baseOrder[index];
            if (fromLane !== toLane) {
                laneMap[fromLane] = toLane;
            }
        }

        applyLaneRemap(laneMap);
    }

    function makeLanesDraggableDroppable() {
        function clearLaneCursorClasses() {
            $('body').removeClass('lane-cursor-swap lane-cursor-move');
        }

        function clearLaneDragTargetFiltering() {
            $('.lane-drop-zone').removeClass('lane-drop-invalid');
            $('.lane-chip').removeClass('lane-chip-self-drag');
        }

        function applyLaneDragTargetFiltering(sourceLane) {
            clearLaneDragTargetFiltering();

            const order = getVisibleLaneNumbers();
            const sourceIndex = order.indexOf(sourceLane);
            if (sourceIndex === -1) {
                return;
            }

            const previousLane = sourceIndex > 0 ? order[sourceIndex - 1] : null;
            const nextLane = sourceIndex < order.length - 1 ? order[sourceIndex + 1] : null;

            $('.lane-chip[data-lane="' + sourceLane + '"]').addClass('lane-chip-self-drag');

            $('.lane-drop-zone').each(function() {
                const $zone = $(this);
                const referenceLane = parseInt($zone.data('reference-lane'), 10);
                const dropPosition = ($zone.data('drop-position') || 'before').toString();

                let invalid = false;

                if (referenceLane === sourceLane) {
                    invalid = true;
                } else if (dropPosition === 'after' && previousLane !== null && referenceLane === previousLane) {
                    invalid = true;
                } else if (dropPosition === 'before' && nextLane !== null && referenceLane === nextLane) {
                    invalid = true;
                }

                if (invalid) {
                    $zone.addClass('lane-drop-invalid');
                }
            });
        }

        $('.lane-chip').draggable({
            helper: 'clone',
            appendTo: 'body',
            revert: 'invalid',
            zIndex: 11000,
            cursor: 'move',
            start: function(event, ui) {
                $('body').addClass('lane-dragging');
                ui.helper.addClass('lane-chip-drag-helper').css('z-index', 11000);
                const sourceLane = parseInt($(this).data('lane'), 10);
                applyLaneDragTargetFiltering(sourceLane);
            },
            stop: function() {
                $('body').removeClass('lane-dragging');
                clearLaneCursorClasses();
                clearLaneDragTargetFiltering();
            }
        });

        $('.lane-chip').droppable({
            accept: function(draggable) {
                const sourceLane = parseInt(draggable.data('lane'), 10);
                const targetLane = parseInt($(this).data('lane'), 10);
                return !Number.isNaN(sourceLane) && !Number.isNaN(targetLane) && sourceLane !== targetLane;
            },
            tolerance: 'pointer',
            hoverClass: 'lane-chip-hover',
            over: function() {
                clearLaneCursorClasses();
                $('body').addClass('lane-cursor-swap');
            },
            out: function() {
                clearLaneCursorClasses();
            },
            drop: function(event, ui) {
                clearLaneCursorClasses();
                const sourceLane = parseInt(ui.draggable.data('lane'), 10);
                const targetLane = parseInt($(this).data('lane'), 10);
                swapLanes(sourceLane, targetLane);
            }
        });

        $('.lane-drop-zone').droppable({
            accept: function(draggable) {
                return !$(this).hasClass('lane-drop-invalid') && draggable.hasClass('lane-chip');
            },
            tolerance: 'pointer',
            hoverClass: 'lane-drop-hover',
            over: function() {
                clearLaneCursorClasses();
                $('body').addClass('lane-cursor-move');
            },
            out: function() {
                clearLaneCursorClasses();
            },
            drop: function(event, ui) {
                clearLaneCursorClasses();
                const sourceLane = parseInt(ui.draggable.data('lane'), 10);
                const referenceLane = parseInt($(this).data('reference-lane'), 10);
                const dropPosition = ($(this).data('drop-position') || 'before').toString();
                moveLane(sourceLane, referenceLane, dropPosition);
            }
        });
    }

    function getActionLetters() {
        const count = getEffectiveArchersPerTarget();
        const letters = [];
        for (let index = 0; index < count; index++) {
            letters.push(String.fromCharCode(65 + index));
        }

        return letters;
    }

    function renderSwapArcherActions() {
        const $container = $('#swap-archers-items');
        if ($container.length === 0) {
            return;
        }

        const letters = getActionLetters();
        let html = '';

        for (let leftIndex = 0; leftIndex < letters.length; leftIndex++) {
            for (let rightIndex = leftIndex + 1; rightIndex < letters.length; rightIndex++) {
                const left = letters[leftIndex];
                const right = letters[rightIndex];
                html += '<button type="button" class="targets-action-item action-swap-letters" data-letter-a="' + left + '" data-letter-b="' + right + '">Swap ' + left + ' &amp; ' + right + ' archers</button>';
            }
        }

        if (!html) {
            html = '<div class="targets-action-item submenu-label">No swap actions available</div>';
        }

        $container.html(html);
    }

    function flipAssignedLanes() {
        const assignedLanes = [];

        Object.keys(state.assignments).forEach(function(participantId) {
            const assignment = state.assignments[participantId];
            if (!assignment || !assignment.targetFull) {
                return;
            }

            const lane = parseInt(assignment.target, 10);
            if (!Number.isNaN(lane) && assignedLanes.indexOf(lane) === -1) {
                assignedLanes.push(lane);
            }
        });

        assignedLanes.sort(function(a, b) { return a - b; });

        if (assignedLanes.length < 2) {
            showStatus('Not enough assigned lanes to flip', 'info');
            return;
        }

        const laneMap = {};
        for (let index = 0; index < assignedLanes.length; index++) {
            laneMap[assignedLanes[index]] = assignedLanes[assignedLanes.length - 1 - index];
        }

        applyLaneRemap(laneMap);
        showStatus('Assigned lanes flipped', 'success');
    }

    function swapAssignedArchers(letterA, letterB) {
        const left = (letterA || '').toUpperCase();
        const right = (letterB || '').toUpperCase();
        if (!left || !right || left === right) {
            return;
        }

        let changed = false;

        Object.keys(state.assignments).forEach(function(participantId) {
            const assignment = state.assignments[participantId];
            if (!assignment || !assignment.targetFull) {
                return;
            }

            const currentLetter = (assignment.letter || '').toUpperCase();
            if (currentLetter === left) {
                assignment.letter = right;
                assignment.targetFull = buildTargetFull(assignment.target, right);
                changed = true;
            } else if (currentLetter === right) {
                assignment.letter = left;
                assignment.targetFull = buildTargetFull(assignment.target, left);
                changed = true;
            }
        });

        if (!changed) {
            showStatus('No assigned archers to swap for ' + left + ' and ' + right, 'info');
            return;
        }

        state.hasChanges = true;
        renderParticipants();
        renderTargets();
        updateUI();
        validateCurrentState();
        showStatus('Swapped ' + left + ' and ' + right + ' archers', 'success');
    }

    /**
     * Create a mat element with target faces
     */
    function createMatElement(targetNums, targetGroups, layout, matSize, matNumber) {
        const $mat = $('<div class="target-mat ' + matSize + '" data-layout="' + layout.id + '"></div>');
        const matDistanceText = getMatDistanceText(targetNums);

        // Add mat label (running number)
        const matLabel = matNumber || 1;
        if (layout.lanesPerMat === 2 && targetNums.length > 1) {
            const leftLane = targetNums[0];
            const rightLane = targetNums[targetNums.length - 1];
            const $triangleHeader = $('<div class="mat-label triangle-label"></div>');
            $triangleHeader.append(createLaneControl(leftLane, 'lane-left'));
            $triangleHeader.append('<span class="mat-center">Mat ' + matLabel + '<span class="mat-distance">' + escapeHtml(matDistanceText) + '</span></span>');
            $triangleHeader.append(createLaneControl(rightLane, 'lane-right'));
            $mat.append($triangleHeader);
        } else {
            const $header = $('<div class="mat-label"></div>');
            if (targetNums.length > 0) {
                $header.append(createLaneControl(targetNums[0], 'lane-center'));
            }
            $header.append('<span class="mat-center"><span class="mat-distance">' + escapeHtml(matDistanceText) + '</span></span>');
            $mat.append($header);
        }

        // Create target faces container
        const mixedContainerClass = isOutdoorMixedLayoutId(layout.id) ? ' layout-outdoor-mixed' : '';
        const $facesContainer = $('<div class="target-faces layout-' + layout.id + mixedContainerClass + '"></div>');
        
        // Get expected positions for this layout (e.g., ['A', 'B', 'C'] for 3 archers)
        const expectedPositions = layout.positions || ['A', 'B', 'C', 'D'];

        if (layout.id === 'layout_60cm_3_abc') {
            targetNums.forEach(function(targetNum, idx) {
                const letters = targetGroups[targetNum] || [];
                const $face = createSharedAbcLaneFace(targetNum, letters, idx, expectedPositions);
                $facesContainer.append($face);
            });

            $mat.append($facesContainer);
            return $mat;
        }

        if (layout.id === 'layout_60cm_4_split') {
            targetNums.forEach(function(targetNum, idx) {
                const letters = targetGroups[targetNum] || [];
                $facesContainer.append(createSharedSplitFace(targetNum, letters, idx, 'left', ['A', 'C']));
                $facesContainer.append(createSharedSplitFace(targetNum, letters, idx, 'right', ['B', 'D']));
            });

            $mat.append($facesContainer);
            return $mat;
        }

        if (isOutdoorMixedLayoutId(layout.id)) {
            targetNums.forEach(function(targetNum, idx) {
                const letters = targetGroups[targetNum] || [];
                const $mixedLane = createOutdoorMixedLaneFaces(targetNum, letters, idx, expectedPositions);
                $facesContainer.append($mixedLane);
            });

            $mat.append($facesContainer);
            return $mat;
        }
        
        targetNums.forEach(function(targetNum, idx) {
            const letters = targetGroups[targetNum];
            
            // Iterate through expected positions and create faces
            expectedPositions.forEach(function(position) {
                // Find existing target data for this position, or create synthetic one
                let target = letters.find(function(t) { return t.letter === position; });
                
                if (!target) {
                    // Create synthetic target for positions that don't exist in data yet
                    target = {
                        target: parseInt(targetNum),
                        letter: position,
                        targetFull: buildTargetFull(targetNum, position),
                        synthetic: true
                    };
                }
                
                const $face = createTargetFace(target, layout, idx, position);
                $facesContainer.append($face);
            });
        });

        $mat.append($facesContainer);
        return $mat;
    }

    function createSharedAbcLaneFace(targetNum, letters, targetIdx, expectedPositions) {
        const laneNumber = parseInt(targetNum, 10);
        const $face = $('<div class="target-face shared-abc-face" data-target="' + laneNumber + '" data-target-idx="' + targetIdx + '"></div>');

        let targetFaceId = null;
        state.participants.some(function(p) {
            const assignment = state.assignments[p.id];
            if (!assignment || parseInt(assignment.target, 10) !== laneNumber) {
                return false;
            }
            if (p.targetFaceId) {
                targetFaceId = p.targetFaceId;
                return true;
            }
            return false;
        });

        if (targetFaceId && state.targetFaces[targetFaceId]) {
            const targetFace = state.targetFaces[targetFaceId];
            $face.css({
                'background-image': 'url("' + targetFace.url + '")',
                'background-size': 'contain',
                'background-repeat': 'no-repeat',
                'background-position': 'center'
            });
            $face.attr('data-target-face-id', targetFaceId);
        }

        const $slots = $('<div class="shared-abc-slots"></div>');

        expectedPositions.forEach(function(position) {
            let target = letters.find(function(t) { return t.letter === position; });
            if (!target) {
                target = {
                    target: laneNumber,
                    letter: position,
                    targetFull: buildTargetFull(targetNum, position),
                    synthetic: true
                };
            }

            const $dropZone = $('<div class="target-drop-zone shared-abc-slot droppable-zone" data-target="' + target.target + '" data-letter="' + target.letter + '" data-target-full="' + target.targetFull + '"></div>');
            $dropZone.append('<div class="dropzone-letter">' + position + '</div>');

            const assignedParticipant = state.participants.find(function(p) {
                const assignment = state.assignments[p.id];
                return assignment && assignment.targetFull === target.targetFull;
            });

            if (assignedParticipant) {
                const $card = createParticipantCard(assignedParticipant);
                $dropZone.append($card);
            }

            $slots.append($dropZone);
        });

        $face.append($slots);
        return $face;
    }

    function createSharedSplitFace(targetNum, letters, targetIdx, sideClass, sideLetters) {
        const laneNumber = parseInt(targetNum, 10);
        const $face = $('<div class="target-face shared-split-face shared-split-' + sideClass + '" data-target="' + laneNumber + '" data-target-idx="' + targetIdx + '"></div>');

        let targetFaceId = null;
        state.participants.some(function(p) {
            const assignment = state.assignments[p.id];
            if (!assignment || parseInt(assignment.target, 10) !== laneNumber) {
                return false;
            }
            if (sideLetters.indexOf((assignment.letter || '').toUpperCase()) === -1) {
                return false;
            }
            if (p.targetFaceId) {
                targetFaceId = p.targetFaceId;
                return true;
            }
            return false;
        });

        if (!targetFaceId) {
            state.participants.some(function(p) {
                const assignment = state.assignments[p.id];
                if (!assignment || parseInt(assignment.target, 10) !== laneNumber) {
                    return false;
                }
                if (p.targetFaceId) {
                    targetFaceId = p.targetFaceId;
                    return true;
                }
                return false;
            });
        }

        if (targetFaceId && state.targetFaces[targetFaceId]) {
            const targetFace = state.targetFaces[targetFaceId];
            $face.css({
                'background-image': 'url("' + targetFace.url + '")',
                'background-size': 'contain',
                'background-repeat': 'no-repeat',
                'background-position': 'center'
            });
            $face.attr('data-target-face-id', targetFaceId);
        }

        const $slots = $('<div class="shared-split-slots"></div>');

        sideLetters.forEach(function(position) {
            let target = letters.find(function(t) { return t.letter === position; });
            if (!target) {
                target = {
                    target: laneNumber,
                    letter: position,
                    targetFull: buildTargetFull(targetNum, position),
                    synthetic: true
                };
            }

            const $dropZone = $('<div class="target-drop-zone shared-split-slot shared-compact-slot droppable-zone" data-target="' + target.target + '" data-letter="' + target.letter + '" data-target-full="' + target.targetFull + '"></div>');
            $dropZone.append('<div class="dropzone-letter">' + position + '</div>');

            const assignedParticipant = state.participants.find(function(p) {
                const assignment = state.assignments[p.id];
                return assignment && assignment.targetFull === target.targetFull;
            });

            if (assignedParticipant) {
                const $card = createParticipantCard(assignedParticipant);
                $dropZone.append($card);
            }

            $slots.append($dropZone);
        });

        $face.append($slots);
        return $face;
    }

    function createOutdoorMixedLaneFaces(targetNum, letters, targetIdx, expectedPositions) {
        const laneNumber = parseInt(targetNum, 10);
        const $lane = $('<div class="outdoor-mixed-lane" data-target="' + laneNumber + '"></div>');
        const normalizedTargets = [];

        expectedPositions.forEach(function(position) {
            let target = letters.find(function(item) { return item.letter === position; });
            if (!target) {
                target = {
                    target: laneNumber,
                    letter: position,
                    targetFull: buildTargetFull(targetNum, position),
                    synthetic: true
                };
            }

            const assignedParticipant = findAssignedParticipantByTargetFull(target.targetFull);
            const diameter = getParticipantTargetDiameter(assignedParticipant);
            normalizedTargets.push({
                target: target,
                participant: assignedParticipant,
                diameter: diameter,
                isLarge: isLargeOutdoorFace(diameter)
            });
        });

        const laneHasLarge = normalizedTargets.some(function(item) {
            return item.participant && item.isLarge;
        });

        const sharedTargets = laneHasLarge
            ? normalizedTargets
            : normalizedTargets.filter(function(item) { return item.isLarge; });
        const individualTargets = laneHasLarge
            ? []
            : normalizedTargets.filter(function(item) { return !item.isLarge; });

        if (sharedTargets.length > 0) {
            $lane.append(createOutdoorSharedFace(targetNum, sharedTargets, targetIdx, expectedPositions.length));
        }

        if (individualTargets.length > 0) {
            const $smallFaces = $('<div class="outdoor-mixed-small-faces outdoor-count-' + expectedPositions.length + '"></div>');
            individualTargets.forEach(function(item) {
                const $face = createTargetFace(item.target, { id: 'layout_outdoor_mixed_generic' }, targetIdx, item.target.letter);
                $face.addClass('outdoor-mixed-small-face ' + getOutdoorPositionClass(item.target.letter, expectedPositions.length));
                $smallFaces.append($face);
            });
            $lane.append($smallFaces);
        }

        return $lane;
    }

    function createOutdoorSharedFace(targetNum, sharedTargets, targetIdx, expectedCount) {
        const laneNumber = parseInt(targetNum, 10);
        const $face = $('<div class="target-face outdoor-mixed-shared-face outdoor-count-' + expectedCount + '" data-target="' + laneNumber + '" data-target-idx="' + targetIdx + '"></div>');

        let targetFaceId = null;
        sharedTargets.some(function(item) {
            if (item.isLarge && item.participant && item.participant.targetFaceId) {
                targetFaceId = item.participant.targetFaceId;
                return true;
            }
            return false;
        });

        if (!targetFaceId) {
            sharedTargets.some(function(item) {
                if (item.participant && item.participant.targetFaceId) {
                    targetFaceId = item.participant.targetFaceId;
                    return true;
                }
                return false;
            });
        }

        if (targetFaceId && state.targetFaces[targetFaceId]) {
            const targetFace = state.targetFaces[targetFaceId];
            $face.css({
                'background-image': 'url("' + targetFace.url + '")',
                'background-size': 'contain',
                'background-repeat': 'no-repeat',
                'background-position': 'center'
            });
            $face.attr('data-target-face-id', targetFaceId);
        }

        const $slots = $('<div class="outdoor-mixed-shared-slots"></div>');

        sharedTargets.forEach(function(item) {
            const target = item.target;
            const $dropZone = $('<div class="target-drop-zone outdoor-mixed-shared-slot droppable-zone ' + getOutdoorPositionClass(target.letter, expectedCount) + '" data-target="' + target.target + '" data-letter="' + target.letter + '" data-target-full="' + target.targetFull + '"></div>');
            $dropZone.append('<div class="dropzone-letter">' + target.letter + '</div>');

            if (target.synthetic) {
                $dropZone.attr('title', 'Pending position from layout change. You can assign now; it will be created on apply.');
            }

            if (item.participant) {
                $dropZone.append(createParticipantCard(item.participant));
            }

            $slots.append($dropZone);
        });

        $face.append($slots);
        return $face;
    }

    function getMatDistanceText(targetNums) {
        const profiles = {};
        const targets = (targetNums || []).map(function(targetNum) {
            return parseInt(targetNum, 10);
        }).filter(function(targetNum) {
            return !Number.isNaN(targetNum);
        });

        state.participants.forEach(function(participant) {
            const assignment = state.assignments[participant.id];
            if (!assignment || !assignment.target) {
                return;
            }

            const targetNo = parseInt(assignment.target, 10);
            if (Number.isNaN(targetNo) || targets.indexOf(targetNo) === -1) {
                return;
            }

            const profile = (participant.distanceProfile || '').toString().trim();
            if (profile) {
                profiles[profile] = true;
            }
        });

        const profileList = Object.keys(profiles).sort();
        if (profileList.length === 0) {
            return 'Distance: -';
        }

        if (profileList.length === 1) {
            return 'Distance: ' + profileList[0];
        }

        return 'Distance: mixed (' + profileList.join(' vs ') + ')';
    }

    /**
     * Create an individual target face with position
     */
    function createTargetFace(target, layout, targetIdx, letter) {
        const $face = $('<div class="target-face" data-position="' + letter + '" data-target-idx="' + targetIdx + '"></div>');
        
        // Find a participant assigned to this target to get the target face image
        let targetFaceId = null;
        const assignedParticipant = state.participants.find(function(p) {
            const assignment = state.assignments[p.id];
            return assignment && assignment.targetFull === target.targetFull;
        });
        
        if (assignedParticipant && assignedParticipant.targetFaceId) {
            targetFaceId = assignedParticipant.targetFaceId;
        }
        
        // Apply target face background image if available
        if (targetFaceId && state.targetFaces[targetFaceId]) {
            const targetFace = state.targetFaces[targetFaceId];
            $face.css({
                'background-image': 'url("' + targetFace.url + '")',
                'background-size': 'contain',
                'background-repeat': 'no-repeat',
                'background-position': 'center'
            });
            $face.attr('data-target-face-id', targetFaceId);
        }
        
        // Create drop zone for this position
        const $dropZone = $('<div class="target-drop-zone droppable-zone" data-target="' + target.target + '" data-letter="' + target.letter + '" data-target-full="' + target.targetFull + '"></div>');
        $dropZone.append('<div class="dropzone-letter">' + letter + '</div>');
        
        // Mark synthetic targets (positions that don't exist in data yet)
        if (target.synthetic) {
            $dropZone.attr('title', 'Pending position from layout change. You can assign now; it will be created on apply.');
        }
        
        if (assignedParticipant) {
            const $card = createParticipantCard(assignedParticipant);
            $dropZone.append($card);
        }

        $face.append($dropZone);
        return $face;
    }

    /**
     * Simple target rendering (fallback when no layout selected)
     */
    function renderTargetsSimple($container) {
        // Group targets by target number
        const targetGroups = {};
        state.availableTargets.forEach(function(t) {
            if (!targetGroups[t.target]) {
                targetGroups[t.target] = [];
            }
            targetGroups[t.target].push(t);
        });

        const effectiveArchers = parseInt(state.newArchersPerTarget || state.originalArchersPerTarget || (state.sessionInfo ? state.sessionInfo.SesAth4Target : 0), 10) || 0;
        const lettersToRender = [];
        const letterCount = effectiveArchers > 0 ? effectiveArchers : 4;
        for (let index = 0; index < letterCount; index++) {
            lettersToRender.push(String.fromCharCode(65 + index));
        }

        // Render each target
        Object.keys(targetGroups).sort(function(a, b) { return parseInt(a) - parseInt(b); }).forEach(function(targetNum) {
            const letters = targetGroups[targetNum];
            const $targetBox = $('<div class="target-box"></div>');
            const $targetHeader = $('<div class="target-number"></div>');
            $targetHeader.append(createLaneControl(targetNum, 'lane-center'));
            $targetBox.append($targetHeader);

            const $lettersContainer = $('<div class="target-letters"></div>');

            lettersToRender.forEach(function(letter) {
                let target = letters.find(function(targetItem) {
                    return targetItem.letter === letter;
                });

                if (!target) {
                    target = {
                        target: parseInt(targetNum, 10),
                        letter: letter,
                        targetFull: buildTargetFull(targetNum, letter),
                        synthetic: true
                    };
                }

                const $face = createTargetFace(target, { id: 'layout_40cm_6_triangle' }, 0, letter);
                $lettersContainer.append($face);
            });

            $targetBox.append($lettersContainer);
            $container.append($targetBox);
        });
    }

    /**
     * Create participant card element
     */
    function createParticipantCard(participant) {
        const $card = $('<div class="participant-card draggable" data-id="' + participant.id + '"></div>');

        const grouping = getParticipantColorGrouping(participant);
        if (grouping) {
            const palette = getColorPalette(grouping.key);
            $card.addClass('colorized').attr('title', grouping.label);
            $card.css({
                '--group-color': palette.color,
                '--group-bg': palette.bg
            });
        }
        
        const isChanged = hasParticipantChanged(participant.id);
        if (isChanged) {
            $card.addClass('changed');
        }

        // Name at top (allows wrapping)
        const $name = $('<div class="participant-name"></div>')
            .text(participant.name);
        $card.append($name);
        
        // Bottom section with class/division and target face
        const $bottom = $('<div class="participant-bottom"></div>');
        
        // Class/Division info on left
        const infoText = escapeHtml(participant.event) + ' | ' + escapeHtml(participant.country);
        const $info = $('<div class="participant-info"></div>').html(infoText);
        $bottom.append($info);
        
        // Target face thumbnail on right if available
        if (participant.targetFaceId && state.targetFaces[participant.targetFaceId]) {
            const targetFace = state.targetFaces[participant.targetFaceId];
            const $thumbnail = $('<div class="target-thumbnail"></div>');
            $thumbnail.css({
                'background-image': 'url("' + targetFace.url + '")',
                'background-size': 'cover',
                'background-position': 'center'
            });
            $bottom.append($thumbnail);
        }
        
        $card.append($bottom);

        return $card;
    }

    function getParticipantColorGrouping(participant) {
        const mode = (state.colorBy || 'none').toString();

        function normalize(value, fallback) {
            const text = (value || '').toString().trim();
            return text !== '' ? text : (fallback || 'Unknown');
        }

        if (mode === 'none') {
            return null;
        }

        if (mode === 'country') {
            const key = normalize(participant.country || participant.countryName, 'No Country');
            const label = 'Country/Club: ' + normalize(participant.countryName || participant.country, 'No Country');
            return { key: 'country|' + key, label: label };
        }

        if (mode === 'class') {
            const classInfo = getClassInfo(participant.class);
            return {
                key: 'class|' + classInfo.id,
                label: 'Class: ' + classInfo.label
            };
        }

        if (mode === 'division') {
            const divisionInfo = getDivisionInfo(participant.division);
            const bowType = extractBowType(divisionInfo.description, divisionInfo.id);
            return {
                key: 'division|' + bowType,
                label: 'Division (Bow): ' + bowType
            };
        }

        if (mode === 'event') {
            const key = normalize(participant.event, 'No Event');
            return { key: 'event|' + key, label: 'Event: ' + key };
        }

        if (mode === 'distance') {
            const key = normalize(participant.distanceProfile, 'No Distance');
            return { key: 'distance|' + key, label: 'Distance: ' + key };
        }

        if (mode === 'target-face') {
            const key = normalize(participant.targetFaceId, 'No Face');
            return { key: 'target-face|' + key, label: 'Target Face: ' + key };
        }

        return null;
    }

    function getClassInfo(classId) {
        const id = (classId || '').toString().trim();
        const entry = id !== '' ? classMetaMap[id] : null;
        const description = entry && entry.description ? entry.description.toString().trim() : '';

        if (description && id) {
            return { id: id, label: description + ' (' + id + ')' };
        }

        if (description) {
            return { id: description, label: description };
        }

        if (id) {
            return { id: id, label: id };
        }

        return { id: 'No Class', label: 'No Class' };
    }

    function getDivisionInfo(divisionId) {
        const id = (divisionId || '').toString().trim();
        const entry = id !== '' ? divisionMetaMap[id] : null;
        const description = entry && entry.description ? entry.description.toString().trim() : '';

        if (description || id) {
            return {
                id: id || description,
                description: description || id
            };
        }

        return { id: 'No Division', description: 'No Division' };
    }

    function extractBowType(divisionDescription, fallbackId) {
        const text = (divisionDescription || '').toString().trim();
        const lower = text.toLowerCase();

        const bowTokens = [
            { token: 'recurve', label: 'Recurve' },
            { token: 'compound', label: 'Compound' },
            { token: 'barebow', label: 'Barebow' },
            { token: 'longbow', label: 'Longbow' },
            { token: 'traditional', label: 'Traditional' },
            { token: 'instinctive', label: 'Instinctive' }
        ];

        for (let i = 0; i < bowTokens.length; i++) {
            if (lower.indexOf(bowTokens[i].token) !== -1) {
                return bowTokens[i].label;
            }
        }

        if (text) {
            const firstWord = text.split(/\s+/)[0];
            if (firstWord) {
                return firstWord;
            }
        }

        const code = (fallbackId || '').toString().trim();
        return code || 'Unknown Bow';
    }

    function getColorPalette(key) {
        const seed = hashString((key || '').toString());
        const hue = seed % 360;
        const color = 'hsl(' + hue + ', 60%, 40%)';
        const bg = 'hsl(' + hue + ', 85%, 94%)';
        return { color: color, bg: bg };
    }

    function hashString(text) {
        let hash = 0;
        for (let i = 0; i < text.length; i++) {
            hash = ((hash << 5) - hash) + text.charCodeAt(i);
            hash |= 0;
        }
        return Math.abs(hash);
    }

    /**
     * Make participants draggable
     */
    function makeParticipantsDraggable($container) {
        $container.find('.participant-card').draggable({
            revert: 'invalid',
            helper: 'clone',
            appendTo: 'body',
            cursor: 'move',
            zIndex: 10000,
            opacity: 0.7,
            start: function(event, ui) {
                $(this).addClass('dragging');
                ui.helper.css({
                    'z-index': 10000,
                    'pointer-events': 'none'
                });
            },
            stop: function(event, ui) {
                $(this).removeClass('dragging');
            }
        });
    }

    /**
     * Make target slots droppable
     */
    function makeTargetsDroppable() {
        $('.droppable-zone').droppable({
            accept: function(draggable) {
                return draggable.hasClass('participant-card');
            },
            hoverClass: 'drop-hover',
            tolerance: 'pointer',
            over: function(event, ui) {
                const $targetSlot = $(this);
                if (!($targetSlot.hasClass('target-slot') || $targetSlot.hasClass('target-drop-zone'))) {
                    return;
                }

                const participantId = ui.draggable.data('id');
                const existingCard = $targetSlot.find('.participant-card').filter(function() {
                    return $(this).data('id') !== participantId;
                });

                if (existingCard.length > 0) {
                    $('body').addClass('swap-cursor');
                }
            },
            out: function(event, ui) {
                $('body').removeClass('swap-cursor');
            },
            drop: function(event, ui) {
                $('body').removeClass('swap-cursor');

                const $droppedCard = ui.draggable;
                const participantId = $droppedCard.data('id');
                const $targetSlot = $(this);

                // Get target info from either old or new structure
                let targetFull = '';
                let target = 0;
                let letter = '';

                if ($targetSlot.hasClass('target-slot') || $targetSlot.hasClass('target-drop-zone')) {
                    target = parseInt($targetSlot.data('target'));
                    letter = $targetSlot.data('letter');
                    targetFull = $targetSlot.data('target-full');
                }

                // Swap if slot already has another participant
                const existingCard = $targetSlot.find('.participant-card').filter(function() {
                    return $(this).data('id') !== participantId;
                });

                if (existingCard.length > 0 && targetFull) {
                    const otherParticipantId = existingCard.first().data('id');
                    const draggedPrevious = state.assignments[participantId] ? {
                        target: state.assignments[participantId].target,
                        letter: state.assignments[participantId].letter,
                        targetFull: state.assignments[participantId].targetFull
                    } : { target: 0, letter: '', targetFull: '' };

                    const previewAssignments = cloneAssignmentsState();
                    previewAssignments[participantId] = {
                        target: target,
                        letter: letter,
                        targetFull: targetFull
                    };

                    if (draggedPrevious.targetFull && draggedPrevious.targetFull !== targetFull) {
                        previewAssignments[otherParticipantId] = {
                            target: draggedPrevious.target,
                            letter: draggedPrevious.letter,
                            targetFull: draggedPrevious.targetFull
                        };
                    } else {
                        previewAssignments[otherParticipantId] = {
                            target: 0,
                            letter: '',
                            targetFull: ''
                        };
                    }

                    if (hasOutdoorMixedConflict(previewAssignments)) {
                        showStatus(OUTDOOR_MIXED_CONFLICT_MESSAGE, 'error');
                        return;
                    }

                    // Place dragged archer in target slot
                    updateAssignment(participantId, target, letter, targetFull, true);

                    // Move existing archer to dragged archer's previous slot (or unassign)
                    if (draggedPrevious.targetFull && draggedPrevious.targetFull !== targetFull) {
                        updateAssignment(otherParticipantId, draggedPrevious.target, draggedPrevious.letter, draggedPrevious.targetFull, true);
                    } else {
                        updateAssignment(otherParticipantId, 0, '', '', true);
                    }

                    renderParticipants();
                    renderTargets();
                    updateUI();
                    validateCurrentState();
                    showStatus('Swapped participants', 'info');
                    return;
                }

                const previewAssignments = cloneAssignmentsState();
                previewAssignments[participantId] = {
                    target: target,
                    letter: letter,
                    targetFull: targetFull
                };

                if (hasOutdoorMixedConflict(previewAssignments)) {
                    showStatus(OUTDOOR_MIXED_CONFLICT_MESSAGE, 'error');
                    return;
                }

                // Update assignment
                updateAssignment(participantId, target, letter, targetFull, true);

                // Re-render
                renderParticipants();
                renderTargets();
                updateUI();
                validateCurrentState();
            }
        });
    }

    /**
     * Update assignment for a participant
     */
    function updateAssignment(participantId, target, letter, targetFull) {
        state.assignments[participantId] = {
            target: target,
            letter: letter,
            targetFull: targetFull
        };

        // Check if this differs from original
        const original = state.originalAssignments[participantId];
        const hasChanged = !original || 
            original.target !== target || 
            original.letter !== letter;

        if (hasChanged) {
            state.hasChanges = true;
        }

        const skipValidation = arguments.length > 4 ? arguments[4] : false;
        if (!skipValidation) {
            validateCurrentState();
        }
    }

    /**
     * Check if participant assignment has changed
     */
    function hasParticipantChanged(participantId) {
        const current = state.assignments[participantId];
        const original = state.originalAssignments[participantId];

        if (!original) return false;

        return current.target !== original.target || 
               current.letter !== original.letter;
    }

    /**
     * Validate current state
     */
    function validateCurrentState() {
        const assignments = [];
        Object.keys(state.assignments).forEach(function(participantId) {
            const participant = state.participants.find(function(p) { return p.id == participantId; });
            if (participant) {
                assignments.push({
                    participantId: participantId,
                    name: participant.name,
                    targetFull: state.assignments[participantId].targetFull
                });
            }
        });

        $.ajax({
            url: wwwdir + 'Modules/Custom/LaneAssist/ManageTargets/api.php',
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'validate',
                session: state.currentSession,
                layoutId: state.currentLayout || 'layout_fallback_stacked',
                assignments: JSON.stringify(assignments),
                currentAssignments: JSON.stringify(assignments)
            },
            success: function(response) {
                if (response.error) {
                    return;
                }

                displayValidationErrors(response.errors || []);
            }
        });
    }

    /**
     * Display validation errors
     */
    function displayValidationErrors(errors) {
        const $headerSummary = $('#targets-validation-summary');
        const $headerSummaryText = $('#targets-validation-text');
        const $headerDetails = $('#targets-validation-details');
        $headerDetails.empty();
        
        clearValidationMarkers($(document));

        if (errors.length === 0) {
            $headerSummary.hide().removeClass('open').attr('data-error-count', '0');
            $headerSummary.removeClass('single-line');
            $('#targets-validation-toggle').attr('aria-expanded', 'false');
            $headerDetails.hide();
            return;
        }

        if (errors.length === 1) {
            $headerSummary.addClass('single-line');
            $headerSummaryText.text(errors[0].message || '1 issue');
            $('#targets-validation-toggle').attr('aria-expanded', 'false');
            $headerSummary.removeClass('open');
            $headerDetails.hide();
        } else {
            $headerSummary.removeClass('single-line');

            const typeCounts = {};
            errors.forEach(function(error) {
                const key = (error.type || 'other').toString();
                typeCounts[key] = (typeCounts[key] || 0) + 1;
            });

            const summaryParts = [];
            if (typeCounts.duplicate) summaryParts.push(typeCounts.duplicate + ' duplicate');
            if (typeCounts.unassigned) summaryParts.push(typeCounts.unassigned + ' unassigned');
            if (typeCounts.distance_mix) summaryParts.push(typeCounts.distance_mix + ' distance mix');
            const summaryText = summaryParts.length > 0
                ? summaryParts.join(', ')
                : (errors.length + ' issues');

            $headerSummaryText.text(summaryText + ' (click to view)');
        }

        $headerSummary.attr('data-error-count', String(errors.length)).show();

        errors.forEach(function(error) {
            const $errorItem = $('<div class="error-item"></div>');
            $errorItem.append('<i class="fa fa-exclamation-triangle"></i> ');
            $errorItem.append(escapeHtml(error.message));
            $headerDetails.append($errorItem.clone());
        });

        applyValidationMarkers(errors, $(document));
    }

    function clearValidationMarkers($scope) {
        const $root = ($scope && $scope.length) ? $scope : $(document);
        $root.find('.target-face').removeClass('has-error');
        $root.find('.error-icon').remove();
        $root.find('.distance-corner-badge').remove();
        $root.find('.target-slot.has-error, .target-drop-zone.has-error').removeClass('has-error');
    }

    function applyValidationMarkers(errors, $scope) {
        const $root = ($scope && $scope.length) ? $scope : $(document);
        if (!Array.isArray(errors) || errors.length === 0) {
            return;
        }

        errors.forEach(function(error) {
            if (error.type === 'duplicate' && error.target) {
                const $affectedZones = $root.find('.target-drop-zone[data-target-full="' + error.target + '"]');
                $affectedZones.each(function() {
                    const $zone = $(this);
                    const $targetFace = $zone.closest('.target-face');
                    $targetFace.addClass('has-error');

                    if ($targetFace.find('.error-icon').length === 0) {
                        const $errorIcon = $('<div class="error-icon" title="' + escapeHtml(error.message) + '"><i class="fa fa-exclamation-circle"></i></div>');
                        $targetFace.append($errorIcon);
                    }
                });
            }

            if (error.type === 'distance_mix' && Array.isArray(error.targets)) {
                error.targets.forEach(function(targetNo) {
                    const targetNumber = parseInt(targetNo, 10);
                    if (!targetNumber) {
                        return;
                    }

                    const $zones = $root.find('.target-drop-zone, .target-slot').filter(function() {
                        return parseInt($(this).data('target'), 10) === targetNumber;
                    });

                    $zones.each(function() {
                        const $zone = $(this);
                        const targetFull = ($zone.data('target-full') || '').toString();
                        const participant = getParticipantByTargetFull(targetFull);
                        const distanceText = participant && participant.distanceProfile ? participant.distanceProfile : '-';
                        const $targetFace = $zone.closest('.target-face');

                        if ($targetFace.length) {
                            $targetFace.addClass('has-error');

                            if ($targetFace.find('.error-icon').length === 0) {
                                const $errorIcon = $('<div class="error-icon" title="' + escapeHtml(error.message) + '"><i class="fa fa-exclamation-circle"></i></div>');
                                $targetFace.append($errorIcon);
                            }

                            if ($zone.find('.distance-corner-badge').length === 0) {
                                $zone.append('<div class="distance-corner-badge" title="Distance: ' + escapeHtml(distanceText) + '">' + escapeHtml(distanceText) + '</div>');
                            }
                        } else {
                            $zone.addClass('has-error');
                            if ($zone.find('.distance-corner-badge').length === 0) {
                                $zone.append('<div class="distance-corner-badge" title="Distance: ' + escapeHtml(distanceText) + '">' + escapeHtml(distanceText) + '</div>');
                            }
                        }
                    });
                });
            }
        });
    }

    function getParticipantByTargetFull(targetFull) {
        if (!targetFull) {
            return null;
        }

        for (let index = 0; index < state.participants.length; index++) {
            const participant = state.participants[index];
            const assignment = state.assignments[participant.id];
            if (assignment && assignment.targetFull === targetFull) {
                return participant;
            }
        }

        return null;
    }

    /**
     * Reset to original assignments
     */
    function resetToOriginal() {
        if (!state.hasChanges && !confirm('Reset all assignments to server state?')) {
            return;
        }

        state.assignments = JSON.parse(JSON.stringify(state.originalAssignments));
        state.hasChanges = false;
        
        // Reset archers per target to original
        state.newArchersPerTarget = null;
        
        // Reset layout to persisted preference
        state.currentLayout = normalizeLayoutId(state.savedLayoutId);
        state.layoutAutoMode = false;
        $('#layout-select').val(state.currentLayout);
        
        // Update toolbar display
        updateSessionInfoDisplay();

        renderParticipants();
        renderTargets();
        updateUI();
        validateCurrentState();
        showStatus('Reset to original assignments', 'info');
    }

    /**
     * Unassign all participants
     */
    function unassignAll() {
        // Clear all assignments
        Object.keys(state.assignments).forEach(function(participantId) {
            state.assignments[participantId] = {
                target: null,
                letter: null,
                targetFull: null
            };
        });

        state.hasChanges = true;

        renderParticipants();
        renderTargets();
        updateUI();
        validateCurrentState();
        showStatus('All participants unassigned', 'info');
    }

    /**
     * Run auto assign preview
     */
    function runAutoAssign() {
        // Get current session from dropdown (more reliable than state)
        const session = $('#session-select').val() || state.currentSession;
        const event = buildEventPattern();
        const targetFrom = $('#target-from').val() || '1';
        const targetTo = $('#target-to').val() || '99';
        const drawType = $('#draw-type').val();
        const groupByDiv = $('#group-by-div').is(':checked') ? 1 : 0;
        const groupByClass = $('#group-by-class').is(':checked') ? 1 : 0;
        const excludeAssigned = $('#exclude-assigned').is(':checked') ? 1 : 0;
        const packGroups = $('#pack-groups').is(':checked') ? 1 : 0;

        if (!session) {
            showError('Please select a session');
            return;
        }
        
        // Update state to match
        state.currentSession = session;

        showStatus('Running auto assignment...', 'info');
        $('#auto-assign-options').slideUp();

        // Prepare request data
        const requestData = {
            action: 'previewAutoAssign',
            session: session,
            event: event,
            targetFrom: targetFrom,
            targetTo: targetTo,
            drawType: drawType,
            groupByDiv: groupByDiv,
            groupByClass: groupByClass,
            excludeAssigned: excludeAssigned,
            packGroups: packGroups,
            layoutId: $('#layout-select').val() || 'layout_fallback_stacked'
        };

        const currentAssignments = state.participants.map(function(participant) {
            const assignment = state.assignments[participant.id] || {};
            return {
                participantId: participant.id,
                targetFull: assignment.targetFull || ''
            };
        });
        requestData.currentAssignments = JSON.stringify(currentAssignments);
        
        // Include pending archers per target if changed
        if (state.newArchersPerTarget) {
            requestData.archersPerTarget = state.newArchersPerTarget;
        }

        $.ajax({
            url: wwwdir + 'Modules/Custom/LaneAssist/ManageTargets/api.php',
            method: 'POST',
            dataType: 'json',
            cache: false,
            data: requestData,
            success: function(response) {
                if (response.error) {
                    showError(response.message || 'Error running auto assign');
                    return;
                }

                // Reset assignments for participants considered in this run
                (response.participantIdsInQuery || []).forEach(function(participantId) {
                    state.assignments[participantId] = {
                        target: null,
                        letter: null,
                        targetFull: ''
                    };
                });

                // Apply auto assignments to state
                (response.assignments || []).forEach(function(assignment) {
                    state.assignments[assignment.participantId] = {
                        target: assignment.target,
                        letter: assignment.letter,
                        targetFull: assignment.targetFull
                    };
                });

                state.hasChanges = true;

                renderParticipants();
                renderTargets();
                updateUI();
                validateCurrentState();
                showStatus('Auto assignment preview complete (' + response.participantCount + ' participants assigned)', 'success');
            },
            error: function() {
                showError('Failed to run auto assignment');
            }
        });
    }

    /**
     * Apply changes to database
     */
    function applyChanges() {
        refreshHasChangesFlag();
        if (!state.hasChanges) {
            showError('No changes to apply');
            return;
        }

        if (!confirm('Apply all changes to the database?')) {
            return;
        }

        // Collect changes
        const changes = [];
        Object.keys(state.assignments).forEach(function(participantId) {
            const current = state.assignments[participantId];
            const original = state.originalAssignments[participantId];

            if (!original || 
                current.target !== original.target || 
                current.letter !== original.letter) {
                changes.push({
                    participantId: participantId,
                    targetFull: current.targetFull
                });
            }
        });

        const layoutToPersist = normalizeLayoutId(state.currentLayout || $('#layout-select').val());
        const layoutPreferenceChanged = layoutToPersist !== normalizeLayoutId(state.savedLayoutId);
        const hasArchersChange = !!(state.newArchersPerTarget && state.newArchersPerTarget !== state.originalArchersPerTarget);

        if (changes.length === 0 && !hasArchersChange && !layoutPreferenceChanged) {
            showError('No changes detected');
            return;
        }

        showStatus('Applying ' + changes.length + ' change(s)...', 'info');
        
        // Prepare request data
        const requestData = {
            action: 'apply',
            session: state.currentSession,
            changes: JSON.stringify(changes),
            layoutId: layoutToPersist
        };
        
        // Include archers per target if it changed
        if (state.newArchersPerTarget && state.newArchersPerTarget !== state.originalArchersPerTarget) {
            requestData.archersPerTarget = state.newArchersPerTarget;
        }

        $.ajax({
            url: wwwdir + 'Modules/Custom/LaneAssist/ManageTargets/api.php',
            method: 'POST',
            dataType: 'json',
            data: requestData,
            success: function(response) {
                if (response.error) {
                    showError(response.message || 'Error applying changes');
                    return;
                }

                showStatus(response.message || 'Changes applied successfully', 'success');

                // Update original state
                state.originalAssignments = JSON.parse(JSON.stringify(state.assignments));
                state.savedLayoutId = layoutToPersist;
                state.currentLayout = layoutToPersist;
                state.layoutAutoMode = false;
                
                // Update archers per target if it changed
                if (state.newArchersPerTarget) {
                    state.originalArchersPerTarget = parseInt(state.newArchersPerTarget, 10);
                    state.sessionInfo.SesAth4Target = parseInt(state.newArchersPerTarget, 10);
                    state.newArchersPerTarget = null;
                }
                
                state.hasChanges = false;
                
                // Update toolbar display
                updateSessionInfoDisplay();

                renderParticipants();
                renderTargets();
                updateUI();
            },
            error: function() {
                showError('Failed to apply changes');
            }
        });
    }

    /**
     * Update session info display in toolbar
     */
    function updateSessionInfoDisplay() {
        if (!state.sessionInfo) {
            $('#archers-per-target').text('-').css('color', '');
            $('#targets-per-session').text('-');
            return;
        }
        
        // Show current or pending archers per target
        const originalArchers = parseInt(state.originalArchersPerTarget || state.sessionInfo.SesAth4Target, 10);
        const pendingArchers = state.newArchersPerTarget !== null ? parseInt(state.newArchersPerTarget, 10) : null;
        const archersPerTarget = pendingArchers || originalArchers;
        let archersText = String(archersPerTarget);
        let hasChange = false;
        
        // Only show arrow if there's actually a change (and prevent X->X)
        if (pendingArchers && originalArchers && pendingArchers !== originalArchers) {
            archersText = originalArchers + ' → ' + pendingArchers;
            hasChange = true;
        }
        
        $('#archers-per-target')
            .text(archersText)
            .css('color', hasChange ? '#d9534f' : ''); // Red color for pending changes
        
        // Show targets per session
        const targetsPerSession = state.sessionInfo.SesTar4Session || state.sessionInfo.targetCount || '-';
        $('#targets-per-session').text(targetsPerSession);
    }
    
    /**
     * Update UI state (enable/disable buttons, show changes summary)
     */
    function updateUI() {
        renderSwapArcherActions();

        // Enable/disable buttons
        $('#btn-apply').prop('disabled', !state.hasChanges);
        $('#btn-reset').prop('disabled', !state.hasChanges);

        // Show/hide changes summary
        if (state.hasChanges) {
            const changeCount = countChanges();
            let summaryHtml = '<div class="line-1">Will save: ' + changeCount + ' assignment change' + (changeCount === 1 ? '' : 's') + '</div>';

            const pendingArchers = state.newArchersPerTarget !== null ? parseInt(state.newArchersPerTarget, 10) : null;
            const originalArchers = parseInt(state.originalArchersPerTarget, 10);
            if (pendingArchers && originalArchers && pendingArchers !== originalArchers) {
                summaryHtml += '<div class="line-2">Archers/target: ' + originalArchers + ' → ' + pendingArchers + '</div>';
            }

            if (hasPendingLayoutPreferenceChange()) {
                const currentLayoutLabel = ($('#layout-select option:selected').text() || 'No layout').trim();
                summaryHtml += '<div class="line-2">Layout preference: ' + escapeHtml(currentLayoutLabel) + '</div>';
            }

            $('#changes-summary').show();
            $('#changes-list').html(summaryHtml);
        } else {
            $('#changes-summary').hide();
        }
    }

    /**
     * Count number of changes
     */
    function countChanges() {
        let count = 0;
        Object.keys(state.assignments).forEach(function(participantId) {
            if (hasParticipantChanged(participantId)) {
                count++;
            }
        });
        return count;
    }

    /**
     * Show status message
     */
    function showStatus(message, type) {
        if (window.console && console.log) {
            console.log('[ManageTargets][' + type + '] ' + message);
        }
    }

    /**
     * Show error message
     */
    function showError(message) {
        showStatus(message, 'error');
    }

    /**
     * Escape HTML
     */
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

    /**
     * Build event filter pattern from division and class selections
     */
    function buildEventPattern() {
        const divisions = $('#division-filter').val() || [];
        const classes = $('#class-filter').val() || [];
        
        // If "All" selected (empty string in array) or nothing selected, use wildcards
        const hasAllDivisions = divisions.length === 0 || divisions.includes('');
        const hasAllClasses = classes.length === 0 || classes.includes('');
        
        if (hasAllDivisions && hasAllClasses) {
            return '%';
        }
        
        // Build pattern combinations
        const patterns = [];
        const divList = hasAllDivisions ? ['%'] : divisions.filter(d => d !== '');
        const clsList = hasAllClasses ? ['%'] : classes.filter(c => c !== '');
        
        for (let div of divList) {
            for (let cls of clsList) {
                patterns.push(div + cls);
            }
        }
        
        // Return as OR pattern for SQL
        return patterns.length > 0 ? patterns.join('|') : '%';
    }

    function updateFiltersIndicator() {
        const divisions = ($('#division-filter').val() || []).filter(function(item) { return item !== ''; });
        const classes = ($('#class-filter').val() || []).filter(function(item) { return item !== ''; });
        const activeCount = divisions.length + classes.length;
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

    function cloneStateSnapshot() {
        return {
            currentSession: state.currentSession,
            currentLayout: state.currentLayout,
            colorBy: state.colorBy,
            participants: state.participants,
            availableTargets: state.availableTargets,
            targetFaces: state.targetFaces,
            assignments: state.assignments,
            originalAssignments: state.originalAssignments,
            sessionInfo: state.sessionInfo,
            hasChanges: state.hasChanges,
            originalArchersPerTarget: state.originalArchersPerTarget,
            newArchersPerTarget: state.newArchersPerTarget,
            layoutAutoMode: state.layoutAutoMode,
            savedLayoutId: state.savedLayoutId
        };
    }

    function applyPreviewState(previewState) {
        const preview = previewState || {};
        const participants = Array.isArray(preview.participants) ? preview.participants : [];
        const availableTargets = Array.isArray(preview.availableTargets) ? preview.availableTargets : [];
        const targetFacesList = Array.isArray(preview.targetFaces) ? preview.targetFaces : [];
        const targetFacesMap = {};

        targetFacesList.forEach(function(face) {
            if (face && face.id) {
                targetFacesMap[face.id] = face;
            }
        });

        state.currentLayout = preview.layoutId || 'layout_fallback_stacked';
        state.layoutAutoMode = false;
        state.currentSession = preview.session || null;
        state.colorBy = preview.colorBy || 'none';
        state.participants = participants;
        state.availableTargets = availableTargets;
        state.targetFaces = targetFacesMap;
        state.assignments = preview.assignments || {};
        state.originalAssignments = preview.originalAssignments || {};
        state.sessionInfo = preview.sessionInfo || { SesAth4Target: 4 };
        state.originalArchersPerTarget = parseInt(state.sessionInfo.SesAth4Target, 10) || 4;
        state.newArchersPerTarget = null;
        state.hasChanges = false;
        state.savedLayoutId = normalizeLayoutId(preview.layoutId || 'layout_fallback_stacked');
    }

    window.LaneAssist = window.LaneAssist || {};
    window.LaneAssist.ManageTargetsRules = {
        outdoorMixedConflictMessage: OUTDOOR_MIXED_CONFLICT_MESSAGE,
        buildDistanceMixErrorMessage: buildDistanceMixErrorMessage
    };
    window.LaneAssist.ManageTargetsDebug = {
        renderPreview: function($container, previewState, options) {
            const snapshot = cloneStateSnapshot();
            applyPreviewState(previewState);
            const renderOptions = options || { skipInteractions: true, skipCounters: true };
            renderTargets($container, renderOptions);
            if (renderOptions && typeof renderOptions.onRendered === 'function') {
                renderOptions.onRendered();
            }
            state.currentSession = snapshot.currentSession;
            state.currentLayout = snapshot.currentLayout;
            state.colorBy = snapshot.colorBy;
            state.participants = snapshot.participants;
            state.availableTargets = snapshot.availableTargets;
            state.targetFaces = snapshot.targetFaces;
            state.assignments = snapshot.assignments;
            state.originalAssignments = snapshot.originalAssignments;
            state.sessionInfo = snapshot.sessionInfo;
            state.hasChanges = snapshot.hasChanges;
            state.originalArchersPerTarget = snapshot.originalArchersPerTarget;
            state.newArchersPerTarget = snapshot.newArchersPerTarget;
            state.layoutAutoMode = snapshot.layoutAutoMode;
            state.savedLayoutId = snapshot.savedLayoutId;
        },
        applyValidationMarkers: applyValidationMarkers,
        clearValidationMarkers: clearValidationMarkers
    };

    // Initialize on document ready
    $(document).ready(init);

})(jQuery);
