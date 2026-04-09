<?php
require_once(dirname(__FILE__, 3) . '/config.php');

CheckTourSession(true);
checkACL(AclParticipants, AclReadOnly);

$PAGE_TITLE = 'Manage Targets - Layout Playground';
$IncludeJquery = true;
$IncludeFA = true;

$targetLayouts = array(
    array(
        'id' => 'layout_fallback_stacked',
        'name' => 'Default (Fallback Stacked)',
        'archersPerLane' => 4,
        'targetSize' => 'mixed',
        'targetsPerMat' => 4,
        'lanesPerMat' => 1,
        'positions' => array('A', 'B', 'C', 'D'),
        'description' => 'Default no-layout view: A/B/C/D stacked per target'
    ),
    array(
        'id' => 'layout_60cm_3_abc',
        'name' => '60cm ABC (3 archers, 2 lanes/mat)',
        'archersPerLane' => 3,
        'targetSize' => '60cm',
        'targetsPerMat' => 2,
        'lanesPerMat' => 2,
        'positions' => array('A', 'B', 'C'),
        'description' => '1 shared 60cm target per lane, 2 lanes per mat, ABC on each lane'
    ),
    array(
        'id' => 'layout_40cm_6_triangle',
        'name' => '40cm Triangle (3 archers, 6 targets/mat)',
        'archersPerLane' => 3,
        'targetSize' => '40cm',
        'targetsPerMat' => 6,
        'lanesPerMat' => 2,
        'positions' => array('A', 'B', 'C'),
        'description' => '2 lanes per mat triangle arrangement'
    ),
    array(
        'id' => 'layout_60cm_4_split',
        'name' => '60cm Split (4 archers, 2 targets/mat)',
        'archersPerLane' => 4,
        'targetSize' => '60cm',
        'targetsPerMat' => 2,
        'lanesPerMat' => 1,
        'positions' => array('A', 'B', 'C', 'D'),
        'description' => '1 lane per mat, AC on left target, BD on right target'
    ),
    array(
        'id' => 'layout_40cm_4_quad',
        'name' => '40cm Quad (4 archers, 4 targets/mat)',
        'archersPerLane' => 4,
        'targetSize' => '40cm',
        'targetsPerMat' => 4,
        'lanesPerMat' => 1,
        'positions' => array('A', 'B', 'C', 'D'),
        'description' => '1 lane per mat. A/B top, C/D bottom'
    ),
    array(
        'id' => 'layout_outdoor_mixed_2',
        'name' => 'Outdoor Mixed (2 archers)',
        'archersPerLane' => 2,
        'targetSize' => 'mixed',
        'targetsPerMat' => 2,
        'lanesPerMat' => 1,
        'positions' => array('A', 'B'),
        'description' => '122 shared + 80 individual, 2 archers'
    ),
    array(
        'id' => 'layout_outdoor_mixed_3',
        'name' => 'Outdoor Mixed (3 archers)',
        'archersPerLane' => 3,
        'targetSize' => 'mixed',
        'targetsPerMat' => 3,
        'lanesPerMat' => 1,
        'positions' => array('A', 'B', 'C'),
        'description' => '122 shared + 80 individual, 3 archers'
    ),
    array(
        'id' => 'layout_outdoor_mixed_4',
        'name' => 'Outdoor Mixed (4 archers)',
        'archersPerLane' => 4,
        'targetSize' => 'mixed',
        'targetsPerMat' => 4,
        'lanesPerMat' => 1,
        'positions' => array('A', 'B', 'C', 'D'),
        'description' => '122 shared + 80 individual, 4 archers'
    )
);

$JS_SCRIPT = array(
    '<script type="text/javascript">var LayoutPlaygroundLayouts = ' . json_encode($targetLayouts) . '; var TargetLayouts = LayoutPlaygroundLayouts;</script>',
    '<link href="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/Common/css/shared.css" rel="stylesheet" type="text/css">',
    '<link href="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/ManageTargets/css/style.css" rel="stylesheet" type="text/css">',
    '<link href="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/ManageTargets/css/layout-playground.css" rel="stylesheet" type="text/css">',
    '<script src="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/ManageTargets/js/app.js"></script>',
    '<script src="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/ManageTargets/js/layout-playground.js"></script>',
);

include('Common/Templates/head.php');
?>

<div class="layout-playground-page">
    <div class="layout-playground-toolbar">
        <h2>Target Layout Playground</h2>
        <div class="layout-playground-controls">
            <label>
                Face state
                <select id="dbg-face-state">
                    <option value="auto">Good: layout default size</option>
                    <option value="same40">Good: same 40cm face</option>
                    <option value="same60">Good: same 60cm face</option>
                    <option value="same80">Good: same 80cm face</option>
                    <option value="same122">Good: same 122cm face</option>
                    <option value="mixed">Test: mixed 122cm + 80cm on same lane</option>
                </select>
            </label>
            <label>
                Name length state
                <select id="dbg-name-state">
                    <option value="short">Short names</option>
                    <option value="mixed">Mixed short/long names</option>
                    <option value="long">Long names</option>
                </select>
            </label>
            <label>
                Fill state
                <select id="dbg-fill-state">
                    <option value="full">All slots filled</option>
                    <option value="partial">Partial fill</option>
                </select>
            </label>
            <label>
                Distance state
                <select id="dbg-distance-state">
                    <option value="same">Good: same distance on mat</option>
                    <option value="mixed">Error: mixed distances on mat</option>
                </select>
            </label>
            <button id="dbg-rerender" class="btn btn-primary" type="button">Render</button>
        </div>
        <p class="layout-playground-note">
            This page renders all layouts at once for visual QA. Use distance/face state toggles to preview validation errors.
        </p>
    </div>

    <div id="layout-playground-grid" class="layout-playground-grid"></div>
</div>

<?php
include(dirname(__FILE__, 2) . '/Common/disclaimer.php');
include('Common/Templates/tail.php');
?>
