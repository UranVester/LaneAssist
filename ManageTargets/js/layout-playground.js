(function($) {
    'use strict';

    const layouts = Array.isArray(window.LayoutPlaygroundLayouts) ? window.LayoutPlaygroundLayouts : [];
    const letters = ['A', 'B', 'C', 'D'];
    const sharedRules = (window.LaneAssist && window.LaneAssist.ManageTargetsRules) ? window.LaneAssist.ManageTargetsRules : null;
    const debugApi = (window.LaneAssist && window.LaneAssist.ManageTargetsDebug) ? window.LaneAssist.ManageTargetsDebug : null;

    const faceCatalog = {
        '40': { id: 'dbg40', diameter: 40, url: buildFaceSvg('#ffffff', '#ffcf66', '#ff8f8f', '#c5e9ff') },
        '60': { id: 'dbg60', diameter: 60, url: buildFaceSvg('#ffffff', '#ffd878', '#ff7e7e', '#a8ddff') },
        '80': { id: 'dbg80', diameter: 80, url: buildFaceSvg('#ffffff', '#ffd878', '#ff7171', '#7fd8ff') },
        '122': { id: 'dbg122', diameter: 122, url: buildFaceSvg('#ffffff', '#ffd878', '#ff7171', '#7fd8ff') }
    };

    function init() {
        $('#dbg-rerender').on('click', renderAllLayouts);
        $('#dbg-face-state, #dbg-name-state, #dbg-fill-state, #dbg-distance-state').on('change', renderAllLayouts);
        renderAllLayouts();
    }

    function renderAllLayouts() {
        const $grid = $('#layout-playground-grid');
        $grid.empty();

        if (!debugApi || typeof debugApi.renderPreview !== 'function') {
            $grid.append('<div class="layout-playground-card has-sim-error"><div class="layout-playground-error"><i class="fa fa-exclamation-triangle"></i> ManageTargets debug API is unavailable.</div></div>');
            return;
        }

        layouts.forEach(function(layout) {
            $grid.append(renderLayoutCard(layout));
        });
    }

    function renderLayoutCard(layout) {
        const config = getScenarioConfig(layout);
        const preview = buildPreviewState(layout, config);
        const validationErrors = getSimulatedErrors(layout, config, preview);

        const $card = $('<section class="layout-playground-card"></section>');
        const $header = $('<header class="layout-playground-card-header"></header>');
        $header.append('<h3>' + escapeHtml(layout.name) + '</h3>');
        $header.append('<p>' + escapeHtml(layout.description || '') + '</p>');

        if (config.isMixedErrorState) {
            $header.append('<div class="dbg-warning">Mixed 122/80 test state</div>');
        }

        if (validationErrors.length > 0) {
            $card.addClass('has-sim-error');
            const $errors = $('<div class="layout-playground-errors"></div>');
            validationErrors.forEach(function(error) {
                const message = (error && error.message) ? error.message : '';
                $errors.append('<div class="layout-playground-error"><i class="fa fa-exclamation-triangle"></i> ' + escapeHtml(message) + '</div>');
            });
            $header.append($errors);
        }

        const $targetsGrid = $('<div class="targets-grid layout-playground-targets-grid"></div>');
        debugApi.renderPreview($targetsGrid, preview, {
            skipInteractions: true,
            skipCounters: true,
            onRendered: function() {
                if (typeof debugApi.clearValidationMarkers === 'function') {
                    debugApi.clearValidationMarkers($targetsGrid);
                }
                if (typeof debugApi.applyValidationMarkers === 'function') {
                    debugApi.applyValidationMarkers(validationErrors, $targetsGrid);
                }
            }
        });

        $card.append($header);
        $card.append($targetsGrid);
        return $card;
    }

    function buildPreviewState(layout, config) {
        const positions = (layout.positions || []).slice(0, layout.archersPerLane || 0);
        const laneCount = parseInt(layout.lanesPerMat, 10) || 1;
        const participants = [];
        const availableTargets = [];
        const assignments = {};
        const originalAssignments = {};
        const targetFaces = [];
        const usedFaceIds = {};
        const padding = 3;

        for (let lane = 1; lane <= laneCount; lane++) {
            positions.forEach(function(letter) {
                const targetFull = String(lane).padStart(padding, '0') + letter;
                availableTargets.push({
                    target: lane,
                    letter: letter,
                    targetFull: targetFull
                });

                if (!shouldRenderParticipant(letter, config.fillState)) {
                    return;
                }

                const faceType = config.getFaceType(letter);
                const face = faceCatalog[faceType] || faceCatalog['80'];
                usedFaceIds[face.id] = true;

                const participantId = 'dbg_' + layout.id + '_' + lane + '_' + letter;
                participants.push({
                    id: participantId,
                    name: getNameByMode(letter, config.nameMode),
                    event: 'R',
                    country: 'NATION',
                    countryName: 'NATION',
                    targetFaceId: face.id,
                    distanceProfile: config.getDistanceProfile(letter)
                });

                assignments[participantId] = {
                    target: lane,
                    letter: letter,
                    targetFull: targetFull
                };
                originalAssignments[participantId] = {
                    target: lane,
                    letter: letter,
                    targetFull: targetFull
                };
            });
        }

        Object.keys(usedFaceIds).forEach(function(faceId) {
            Object.keys(faceCatalog).forEach(function(typeKey) {
                const face = faceCatalog[typeKey];
                if (face.id === faceId) {
                    targetFaces.push(face);
                }
            });
        });

        return {
            session: 'debug',
            layoutId: layout.id,
            participants: participants,
            availableTargets: availableTargets,
            targetFaces: targetFaces,
            assignments: assignments,
            originalAssignments: originalAssignments,
            sessionInfo: {
                SesAth4Target: layout.archersPerLane,
                SesTar4Session: laneCount,
                firstTarget: 1,
                targetCount: laneCount
            }
        };
    }

    function shouldRenderParticipant(letter, fillState) {
        return !(fillState === 'partial' && (letter === 'C' || letter === 'D'));
    }

    function getScenarioConfig(layout) {
        const faceState = getFaceState();
        const nameMode = getNameState();
        const fillState = getFillState();
        const distanceState = getDistanceState();
        const isMixedErrorState = faceState === 'mixed';

        function getDefaultFaceType(layoutInfo, letter) {
            if (isOutdoorMixed(layoutInfo)) {
                if (layoutInfo.archersPerLane === 2) {
                    return letter === 'A' ? '122' : '80';
                }
                if (layoutInfo.archersPerLane === 3) {
                    return letter === 'B' ? '122' : '80';
                }
                return (letter === 'A' || letter === 'C') ? '122' : '80';
            }

            if (layoutInfo.id === 'layout_fallback_stacked') {
                return '40';
            }

            if (layoutInfo.id.indexOf('layout_40cm_') === 0) {
                return '40';
            }
            if (layoutInfo.id.indexOf('layout_60cm_') === 0) {
                return '60';
            }

            return '80';
        }

        function getFaceType(letter) {
            if (faceState === 'auto') {
                return getDefaultFaceType(layout, letter);
            }
            if (faceState === 'same40') {
                return isOutdoorMixed(layout) ? '80' : '40';
            }
            if (faceState === 'same60') {
                return isOutdoorMixed(layout) ? '80' : '60';
            }
            if (faceState === 'same122') {
                return '122';
            }
            if (faceState === 'same80') {
                return '80';
            }

            if (!isOutdoorMixed(layout)) {
                return (letter === 'A' || letter === 'C') ? '60' : '40';
            }

            return (letter === 'A' || letter === 'C') ? '122' : '80';
        }

        return {
            getFaceType: getFaceType,
            getDistanceProfile: function(letter) {
                if (distanceState === 'mixed') {
                    return letter === 'A' ? '70m' : '60m';
                }
                return '70m';
            },
            nameMode: nameMode,
            fillState: fillState,
            isMixedErrorState: isMixedErrorState
        };
    }

    function getSimulatedErrors(layout, config, preview) {
        const errors = [];
        const profiles = {};
        let has122 = false;
        let has80 = false;

        (preview.participants || []).forEach(function(participant) {
            if (participant.distanceProfile) {
                profiles[participant.distanceProfile] = true;
            }

            if ((participant.targetFaceId || '').toString().indexOf('122') !== -1) {
                has122 = true;
            }
            if ((participant.targetFaceId || '').toString().indexOf('80') !== -1) {
                has80 = true;
            }
        });

        const profileKeys = Object.keys(profiles).sort();
        if (profileKeys.length > 1) {
            const lanes = [];
            const laneCount = parseInt(layout.lanesPerMat, 10) || 1;
            for (let lane = 1; lane <= laneCount; lane++) {
                lanes.push(String(lane));
            }

            if (sharedRules && typeof sharedRules.buildDistanceMixErrorMessage === 'function') {
                errors.push({
                    type: 'distance_mix',
                    targets: lanes,
                    profiles: profileKeys,
                    message: sharedRules.buildDistanceMixErrorMessage(lanes, profileKeys)
                });
            }
        }

        if (isOutdoorMixed(layout) && has122 && has80) {
            if (sharedRules && sharedRules.outdoorMixedConflictMessage) {
                errors.push({
                    type: 'outdoor_mixed_conflict',
                    message: sharedRules.outdoorMixedConflictMessage
                });
            }
        }

        return errors;
    }

    function isOutdoorMixed(layout) {
        return layout && typeof layout.id === 'string' && layout.id.indexOf('layout_outdoor_mixed_') === 0;
    }

    function getNameByMode(letter, mode) {
        if (mode === 'short') {
            return letter + '. Lee';
        }
        if (mode === 'long') {
            if (letter === 'A') {
                return 'Alexandria Montgomery-Smith';
            }
            if (letter === 'B') {
                return 'Benedictus van der Heijden';
            }
            if (letter === 'C') {
                return 'Charlotte Evelyn Konstantinopoulos';
            }
            return 'Dominique-Elise Fernandez de la Torre';
        }

        if (letter === 'A') {
            return 'Ana';
        }
        if (letter === 'B') {
            return 'Benjamin Karlsson';
        }
        if (letter === 'C') {
            return 'Choi Min-ji';
        }
        return 'Domenico Alessandro Ricci';
    }

    function getFaceState() {
        return ($('#dbg-face-state').val() || 'auto').toString();
    }

    function getNameState() {
        return ($('#dbg-name-state').val() || 'short').toString();
    }

    function getFillState() {
        return ($('#dbg-fill-state').val() || 'full').toString();
    }

    function getDistanceState() {
        return ($('#dbg-distance-state').val() || 'same').toString();
    }

    function buildFaceSvg(center, ringA, ringB, ringC) {
        const svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="50" cy="50" r="49" fill="#f4f4f4"/><circle cx="50" cy="50" r="35" fill="' + ringC + '"/><circle cx="50" cy="50" r="25" fill="' + ringB + '"/><circle cx="50" cy="50" r="15" fill="' + ringA + '"/><circle cx="50" cy="50" r="8" fill="' + center + '"/></svg>';
        return 'data:image/svg+xml;utf8,' + encodeURIComponent(svg);
    }

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    $(init);
})(jQuery);
