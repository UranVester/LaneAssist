<?php
/**
 * Interactive Target Assignment Tool
 * Allows drag-and-drop assignment of participants to targets with preview and validation
 */

require_once(dirname(__FILE__, 3) . '/config.php');

// Check if a tournament is selected
if (!CheckTourSession()) {
    // No tournament selected - show friendly message
    $PAGE_TITLE = 'Manage Targets - No Tournament Selected';
    include('Common/Templates/head.php');
    echo '<div style="padding: 20px; text-align: center;">';
    echo '<h2>No Competition Selected</h2>';
    echo '<p>Please select a tournament first:</p>';
    echo '<p><a href="' . $CFG->ROOT_DIR . 'index.php" class="btn btn-primary">Select Tournament</a></p>';
    echo '</div>';
    include(dirname(__FILE__, 2) . '/Common/disclaimer.php');
    include('Common/Templates/tail.php');
    exit;
}

checkFullACL(AclParticipants, 'pTarget', AclReadWrite);
require_once('Common/Fun_Sessions.inc.php');

// Prepare page variables
$PAGE_TITLE = 'Manage Targets - Interactive';
$IncludeJquery = true;
$IncludeFA = true;

// Get sessions for dropdown
$sessions = GetSessions('Q');

// Get divisions and classes for filters
$divisions = array();
$divSql = "SELECT DivId, DivDescription FROM Divisions 
           WHERE DivTournament=" . StrSafe_DB($_SESSION['TourId']) . " 
           AND DivAthlete=1 
           ORDER BY DivViewOrder";
$divRs = safe_r_sql($divSql);
while ($div = safe_fetch($divRs)) {
    $divisions[] = $div;
}

$classes = array();
$clsSql = "SELECT ClId, ClDescription FROM Classes 
           WHERE ClTournament=" . StrSafe_DB($_SESSION['TourId']) . " 
           AND ClAthlete=1 
           ORDER BY ClViewOrder";
$clsRs = safe_r_sql($clsSql);
while ($cls = safe_fetch($clsRs)) {
    $classes[] = $cls;
}

$divisionMeta = array();
foreach ($divisions as $div) {
    $divisionMeta[$div->DivId] = array(
        'id' => $div->DivId,
        'description' => $div->DivDescription
    );
}

$classMeta = array();
foreach ($classes as $cls) {
    $classMeta[$cls->ClId] = array(
        'id' => $cls->ClId,
        'description' => $cls->ClDescription
    );
}

// Export PHP variables to JavaScript
// Define target layouts
$targetLayouts = array(
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
        'description' => '2 lanes per mat. Odd lane: A top-left, B top-middle, C bottom-left. Even lane: A top-right, B bottom-middle, C bottom-right'
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
        'description' => '1 lane per mat. A top-left, B top-right, C bottom-left, D bottom-right'
    ),
    // Outdoor mixed layouts: 122cm shared face per lane, 80cm per archer face
    array(
        'id' => 'layout_outdoor_mixed_2',
        'name' => 'Outdoor Mixed (2 archers)',
        'archersPerLane' => 2,
        'targetSize' => 'mixed',
        'targetsPerMat' => 2,
        'lanesPerMat' => 1,
        'positions' => array('A', 'B'),
        'description' => '1 lane per mat. 122cm target is drawn once and shared, 80cm targets are drawn per archer. Slots: A/B top.'
    ),
    array(
        'id' => 'layout_outdoor_mixed_3',
        'name' => 'Outdoor Mixed (3 archers)',
        'archersPerLane' => 3,
        'targetSize' => 'mixed',
        'targetsPerMat' => 3,
        'lanesPerMat' => 1,
        'positions' => array('A', 'B', 'C'),
        'description' => '1 lane per mat. 122cm target is drawn once and shared, 80cm targets are drawn per archer. Slots: B top middle, A bottom left, C bottom right.'
    ),
    array(
        'id' => 'layout_outdoor_mixed_4',
        'name' => 'Outdoor Mixed (4 archers)',
        'archersPerLane' => 4,
        'targetSize' => 'mixed',
        'targetsPerMat' => 4,
        'lanesPerMat' => 1,
        'positions' => array('A', 'B', 'C', 'D'),
        'description' => '1 lane per mat. 122cm target is drawn once and shared, 80cm targets are drawn per archer. Slots: A/B top, C/D bottom.'
    )
);

$JS_SCRIPT = array(
    '<script type="text/javascript">
        var ROOT_DIR = "' . $CFG->ROOT_DIR . '";
        var TourId = ' . intval($_SESSION['TourId']) . ';
        var TourCode = "' . (isset($_SESSION['TourCode']) ? $_SESSION['TourCode'] : '') . '";
        var TargetNoPadding = ' . intval(TargetNoPadding) . ';
        var DivisionMeta = ' . json_encode($divisionMeta) . ';
        var ClassMeta = ' . json_encode($classMeta) . ';
        var TargetLayouts = ' . json_encode($targetLayouts) . ';
    </script>',
    '<link href="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/Common/css/shared.css" rel="stylesheet" type="text/css">',
    '<link href="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/ManageTargets/css/style.css" rel="stylesheet" type="text/css">',
    '<script src="' . $CFG->ROOT_DIR . 'Common/jQuery/jquery-ui.min.js"></script>',
    '<script src="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/Common/js/jquery-ui-touch-bridge.js"></script>',
    '<script src="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/Common/js/shared.js"></script>',
    '<script src="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/ManageTargets/js/app.js"></script>',
);

include('Common/Templates/head.php');
?>

<div class="target-assignment-container">
    <!-- Toolbar -->
    <div class="toolbar">
        <div class="toolbar-top">
            <div class="toolbar-left">
                <h2>Manage Targets - Interactive</h2>
                <div style="margin-top: 6px;">
                    <a href="<?php echo $CFG->ROOT_DIR; ?>Modules/Custom/LaneAssist/ManageTargets/layout-playground.php" class="btn btn-secondary" target="_blank" rel="noopener">Layout Playground</a>
                </div>
            </div>
            <div class="toolbar-center">
                <div class="filter-group inline">
                    <label for="session-select"><?php echo get_text('Session'); ?>:</label>
                    <select id="session-select" name="session">
                        <?php if (count($sessions) > 1): ?>
                        <option value="">-- <?php echo get_text('Select', 'Tournament'); ?> --</option>
                        <?php endif; ?>
                        <?php foreach ($sessions as $s): ?>
                            <option value="<?php echo $s->SesOrder; ?>"<?php echo (count($sessions) == 1) ? ' selected="selected"' : ''; ?>>
                                <?php echo $s->SesOrder . ' - ' . $s->Descr; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group inline session-info">
                    <span class="info-label">Archers/Target:</span>
                    <span id="archers-per-target" class="info-value">-</span>
                </div>
                <div class="filter-group inline session-info">
                    <span class="info-label">Targets/Session:</span>
                    <span id="targets-per-session" class="info-value">-</span>
                </div>
                <div class="filter-group inline">
                    <label for="layout-select">Layout:</label>
                    <select id="layout-select" name="layout" title="Select how targets are arranged on mats">
                        <option value="layout_fallback_stacked">No layout</option>
                        <?php foreach ($targetLayouts as $layout): ?>
                            <option value="<?php echo $layout['id']; ?>" 
                                    data-archers="<?php echo $layout['archersPerLane']; ?>"
                                    data-targets-per-mat="<?php echo $layout['targetsPerMat']; ?>"
                                    data-lanes-per-mat="<?php echo $layout['lanesPerMat']; ?>"
                                    data-target-size="<?php echo $layout['targetSize']; ?>"
                                    title="<?php echo htmlspecialchars($layout['description']); ?>"
                                    data-description="<?php echo htmlspecialchars($layout['description']); ?>">
                                <?php echo $layout['name']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="toolbar-right">
                <div id="changes-summary" class="changes-summary compact" style="display:none;">
                    <div id="changes-list"></div>
                </div>
                <button id="btn-reset" class="btn btn-warning" title="Reset to current saved assignments">
                    <i class="fa fa-undo"></i> Reset
                </button>
                <button id="btn-apply" class="btn btn-success" title="Apply all changes to database">
                    <i class="fa fa-save"></i> Apply Changes
                </button>
            </div>
        </div>

        <div class="toolbar-controls-row">
            <div class="filter-group inline compact-control">
                <label for="color-by">Color by:</label>
                <select id="color-by" name="color-by">
                    <option value="none">None</option>
                    <option value="country">Country/Club</option>
                    <option value="class">Class (Age/Gender)</option>
                    <option value="division">Division (Bow type)</option>
                    <option value="event">Event</option>
                    <option value="distance">Distance</option>
                    <option value="target-face">Target Face</option>
                </select>
            </div>

            <div class="toolbar-popup-trigger">
                <button type="button" id="btn-filters" class="btn btn-secondary" aria-expanded="false">
                    <i class="fa fa-filter"></i> Filters <span id="filters-active-count" class="active-filter-indicator" style="display:none;">0</span>
                </button>
                <div id="filters-popup" class="filters-popup-panel" style="display:none;">
                    <h3>Participant Filters</h3>
                    <div class="filters-popup-grid">
                        <div class="filter-group">
                            <label for="division-filter"><?php echo get_text('Division'); ?>:</label>
                            <div class="multi-dropdown" data-select-id="division-filter">
                                <button type="button" class="multi-dropdown-toggle" data-default-label="<?php echo get_text('Division'); ?>">
                                    <?php echo get_text('AllEvents'); ?>
                                </button>
                                <div class="multi-dropdown-menu">
                                    <label class="multi-option all-option">
                                        <input type="checkbox" value="" checked>
                                        <span><?php echo get_text('AllEvents'); ?></span>
                                    </label>
                                    <?php foreach ($divisions as $div): ?>
                                    <label class="multi-option">
                                        <input type="checkbox" value="<?php echo $div->DivId; ?>">
                                        <span><?php echo $div->DivDescription . ' (' . $div->DivId . ')'; ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <select id="division-filter" name="division" multiple size="5" class="hidden-filter-select" aria-hidden="true" tabindex="-1">
                                <option value="" selected>-- <?php echo get_text('AllEvents'); ?> --</option>
                                <?php foreach ($divisions as $div): ?>
                                    <option value="<?php echo $div->DivId; ?>">
                                        <?php echo $div->DivDescription . ' (' . $div->DivId . ')'; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label for="class-filter"><?php echo get_text('Class'); ?>:</label>
                            <div class="multi-dropdown" data-select-id="class-filter">
                                <button type="button" class="multi-dropdown-toggle" data-default-label="<?php echo get_text('Class'); ?>">
                                    <?php echo get_text('AllEvents'); ?>
                                </button>
                                <div class="multi-dropdown-menu">
                                    <label class="multi-option all-option">
                                        <input type="checkbox" value="" checked>
                                        <span><?php echo get_text('AllEvents'); ?></span>
                                    </label>
                                    <?php foreach ($classes as $cls): ?>
                                    <label class="multi-option">
                                        <input type="checkbox" value="<?php echo $cls->ClId; ?>">
                                        <span><?php echo $cls->ClDescription . ' (' . $cls->ClId . ')'; ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <select id="class-filter" name="class" multiple size="5" class="hidden-filter-select" aria-hidden="true" tabindex="-1">
                                <option value="" selected>-- <?php echo get_text('AllEvents'); ?> --</option>
                                <?php foreach ($classes as $cls): ?>
                                    <option value="<?php echo $cls->ClId; ?>">
                                        <?php echo $cls->ClDescription . ' (' . $cls->ClId . ')'; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div class="auto-assign-trigger">
            <button id="btn-auto-assign" class="btn btn-info">
                <i class="fa fa-magic"></i> Auto Assign
            </button>

            <!-- Auto Assign Options (popup) -->
            <div id="auto-assign-options" class="auto-assign-panel" style="display:none;">
                <h3 class="auto-assign-title">
                    <span>Auto Assignment Options</span>
                    <button type="button" id="auto-assign-info-toggle" class="auto-assign-info-toggle" aria-label="How auto assign works" aria-expanded="false">
                        <span aria-hidden="true">i</span>
                    </button>
                    <div id="auto-assign-info-tooltip" class="auto-assign-info-tooltip" style="display:none;">
                        <strong>How this auto assign works</strong>
                        <p>
                            This uses the same core logic as the original IANSEO auto-assignment routine
                            (<code>SetTarget_auto.php</code> behavior), adapted to this interactive preview.
                        </p>
                        <ul>
                            <li>Builds target slots for the selected session and target range.</li>
                            <li>Groups archers by target-face/distance profile and optional division/class splits.</li>
                            <li>
                                Draw modes (same behavior as original auto):
                                <ul>
                                    <li><strong>Normal</strong>: standard forward fill through available slots.</li>
                                    <li><strong>Field 3D</strong>: uses stepped target progression (original field stepping).</li>
                                    <li><strong>ORIS</strong>: alternates group direction in zig-zag style to spread countries/groups.</li>
                                    <li><strong>ORIS 2</strong>: ORIS variant with the original offset/letter stepping used by original auto.</li>
                                </ul>
                            </li>
                            <li>Respects occupied targets and "Exclude assigned" based on your current unsaved grid state.</li>
                            <li>Returns a preview only; nothing is written to the database until you click Apply Changes.</li>
                        </ul>
                    </div>
                </h3>
                <div class="auto-options-grid">
                    <div class="option-group range-group">
                        <label for="target-from"><?php echo get_text('From', 'Tournament'); ?> Target:</label>
                        <input type="number" id="target-from" name="target-from" placeholder="1" value="1" min="1">
                    </div>
                    <div class="option-group range-group">
                        <label for="target-to"><?php echo get_text('To', 'Tournament'); ?> Target:</label>
                        <input type="number" id="target-to" name="target-to" placeholder="99" value="99" min="1">
                    </div>
                    <div class="option-group">
                        <label for="draw-type"><?php echo get_text('DrawType', 'Tournament'); ?>:</label>
                        <select id="draw-type" name="draw-type">
                            <option value="0"><?php echo get_text('DrawNormal', 'Tournament'); ?></option>
                            <option value="1"><?php echo get_text('DrawField3D', 'Tournament'); ?></option>
                            <option value="2"><?php echo get_text('DrawOris', 'Tournament'); ?></option>
                            <option value="3"><?php echo get_text('DrawOris', 'Tournament'); ?> 2</option>
                        </select>
                    </div>
                    <div class="option-group">
                        <label>
                            <input type="checkbox" id="group-by-div" name="group-by-div">
                            <?php echo get_text('SeparateDivisions', 'Tournament'); ?>
                        </label>
                    </div>
                    <div class="option-group">
                        <label>
                            <input type="checkbox" id="group-by-class" name="group-by-class">
                            <?php echo get_text('SeparateClasses', 'Tournament'); ?>
                        </label>
                    </div>
                    <div class="option-group">
                        <label>
                            <input type="checkbox" id="exclude-assigned" name="exclude-assigned" checked>
                            <?php echo get_text('TargetAssExclude', 'Tournament'); ?>
                        </label>
                    </div>
                    <div class="option-group">
                        <label>
                            <input type="checkbox" id="pack-groups" name="pack-groups">
                            Pack groups (experimental)
                        </label>
                    </div>
                </div>
                <div class="option-actions">
                    <button id="btn-run-auto" class="btn btn-success">
                        <i class="fa fa-play"></i> Run Auto Assign
                    </button>
                    <button id="btn-cancel-auto" class="btn btn-secondary">Cancel</button>
                </div>
            </div>
            </div>

            <button id="btn-unassign-all" class="btn btn-warning" title="Remove all target assignments">
                <i class="fa fa-times-circle"></i> Unassign All
            </button>
        </div>
    </div>

    <!-- Main Content Area -->
    <div class="content-area">
        <!-- Unassigned Participants Pool -->
        <div class="unassigned-pool">
            <h3>
                <i class="fa fa-users"></i> Unassigned Participants
                <span id="unassigned-count" class="badge">0</span>
            </h3>
            <div id="unassigned-list" class="participant-list droppable-zone">
                <div class="loading-message">Select a session to load participants</div>
            </div>
        </div>

        <!-- Target Grid -->
        <div class="targets-area">
            <h3>
                <span class="targets-title-main"><i class="fa fa-bullseye"></i> Target Assignments <span id="assigned-count" class="badge">0</span></span>
                <div class="targets-header-tools">
                    <div id="targets-validation-summary" class="targets-validation-summary" style="display:none;" data-error-count="0">
                        <button type="button" id="targets-validation-toggle" class="targets-validation-toggle" aria-expanded="false">
                            <i class="fa fa-exclamation-triangle"></i>
                            <span id="targets-validation-text">Validation issues</span>
                            <i class="fa fa-chevron-down toggle-icon"></i>
                        </button>
                        <div id="targets-validation-details" class="targets-validation-details" style="display:none;"></div>
                    </div>
                    <div class="targets-actions-menu" id="targets-actions-menu">
                        <button type="button" id="targets-actions-toggle" class="targets-actions-toggle" title="Actions" aria-label="Actions">
                            <i class="fa fa-ellipsis-v"></i>
                        </button>
                        <div class="targets-actions-dropdown" id="targets-actions-dropdown">
                            <button type="button" class="targets-action-item" id="action-flip-lanes">Flip assigned lanes</button>
                            <div class="targets-action-submenu">
                                <div class="targets-action-item submenu-label">Swap archers</div>
                                <div id="swap-archers-items"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </h3>
            <div id="targets-grid" class="targets-grid">
                <div class="loading-message">Select a session to load targets</div>
            </div>
        </div>
    </div>

</div>

<?php
include(dirname(__FILE__, 2) . '/Common/disclaimer.php');
include('Common/Templates/tail.php');
?>
