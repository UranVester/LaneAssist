<?php
/**
 * LaneAssist target-mat layout metadata.
 *
 * Shared between ManageTargets (which lets the user pick/save the layout)
 * and LiveView (which reads the saved choice to draw a matching mini
 * preview). Pure array lookups plus one DB-backed read/write pair for the
 * saved tournament preference.
 */

function getLayoutLanesPerMat($layoutId) {
    $map = array(
        'layout_60cm_3_abc' => 2,       // 2 lanes per mat, 3 archers per lane
        'layout_60cm_4_split' => 1,     // 1 lane per mat, 4 archers per lane
        'layout_40cm_4_quad' => 1,      // 1 lane per mat, 4 archers per lane
        'layout_40cm_6_triangle' => 2,  // 2 lanes per mat, 3 archers per lane
    );

    return isset($map[$layoutId]) ? intval($map[$layoutId]) : 0;
}

function getLayoutArchersPerTarget($layoutId) {
    $map = array(
        'layout_60cm_3_abc' => 3,
        'layout_40cm_6_triangle' => 3,
        'layout_60cm_4_split' => 4,
        'layout_40cm_4_quad' => 4,
        'layout_outdoor_mixed_2' => 2,
        'layout_outdoor_mixed_3' => 3,
        'layout_outdoor_mixed_4' => 4,
    );

    return isset($map[$layoutId]) ? intval($map[$layoutId]) : 0;
}

function getKnownLayoutIds() {
    return array(
        'layout_fallback_stacked',
        'layout_60cm_3_abc',
        'layout_40cm_6_triangle',
        'layout_60cm_4_split',
        'layout_40cm_4_quad',
        'layout_outdoor_mixed_2',
        'layout_outdoor_mixed_3',
        'layout_outdoor_mixed_4',
    );
}

function isKnownLayoutId($layoutId) {
    $layoutId = trim((string)$layoutId);
    if ($layoutId === '') {
        return false;
    }

    return in_array($layoutId, getKnownLayoutIds(), true);
}

function getSavedTournamentLayoutPreference($tourId) {
    $tourId = intval($tourId);
    if ($tourId <= 0 || !function_exists('getModuleParameter')) {
        return '';
    }

    $savedLayout = (string)getModuleParameter('LaneAssist', 'ManageTargetsLayout', '', $tourId);
    return isKnownLayoutId($savedLayout) ? $savedLayout : '';
}

function saveTournamentLayoutPreference($tourId, $layoutId) {
    $tourId = intval($tourId);
    $layoutId = trim((string)$layoutId);

    if ($tourId <= 0 || !function_exists('setModuleParameter')) {
        return;
    }

    if (!isKnownLayoutId($layoutId)) {
        return;
    }

    setModuleParameter('LaneAssist', 'ManageTargetsLayout', $layoutId, $tourId);
}
