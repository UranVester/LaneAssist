<?php
/**
 * Change finals events: replace the selected tournament's individual final
 * events with a freshly generated set, grouped by the criteria picked here.
 */

require_once(dirname(__FILE__, 3) . '/config.php');

if (!CheckTourSession()) {
    $PAGE_TITLE = 'Change finals events - No Tournament Selected';
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

checkFullACL(AclCompetition, 'cData', AclReadWrite);

$isBlocked = IsBlocked(BIT_BLOCK_TOURDATA);

$PAGE_TITLE = 'Change finals events';
$IncludeJquery = true;
$IncludeFA = true;

$JS_SCRIPT = array(
    '<script type="text/javascript">var ROOT_DIR = "' . $CFG->ROOT_DIR . '";</script>',
    '<link href="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/Common/css/shared.css" rel="stylesheet" type="text/css">',
    '<link href="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/ChangeEvents/css/style.css" rel="stylesheet" type="text/css">',
    '<script src="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/ChangeEvents/js/app.js"></script>',
);

include('Common/Templates/head.php');
?>

<div class="change-events-container">
    <div class="toolbar">
        <div class="toolbar-left">
            <h2>Change finals events</h2>
            <div class="subtitle">Replace the individual final events of <strong><?php echo htmlspecialchars($_SESSION['TourName'] ?? '', ENT_QUOTES, 'UTF-8'); ?></strong> with a new set. Team events are not touched.</div>
        </div>
    </div>

    <div id="status-message" class="status-message hidden"></div>

    <?php if ($isBlocked): ?>
        <div class="status-message status-error">This tournament's data is blocked; events cannot be changed. You can still preview.</div>
    <?php endif; ?>

    <div class="panel grouping-panel">
        <h3><i class="fa fa-sliders"></i> Separate events by:</h3>
        <div class="criteria">
            <label><input type="checkbox" id="ce-gender" class="ce-criterion" checked> Gender</label>
            <label><input type="checkbox" id="ce-class" class="ce-criterion" checked> Class (age group)</label>
            <label><input type="checkbox" id="ce-division" class="ce-criterion" checked> Division</label>
            <label id="ce-distance-label"><input type="checkbox" id="ce-distance" class="ce-criterion"> Distance <i id="ce-distance-hint">(not used when Division is ticked)</i></label>
        </div>
        <div class="actions">
            <button id="btn-preview" class="btn btn-info"><i class="fa fa-eye"></i> Preview changes</button>
        </div>
    </div>

    <div id="preview" class="hidden">
        <div class="content-grid">
            <div class="panel">
                <h3><i class="fa fa-trash"></i> Current events (will be deleted)</h3>
                <div id="old-events"></div>
            </div>
            <div class="panel">
                <h3><i class="fa fa-plus-circle"></i> New events (will be created)</h3>
                <div id="new-events"></div>
            </div>
        </div>

        <div id="warning-box" class="warning-box"></div>

        <div class="panel confirm-panel">
            <label><input type="checkbox" id="ce-confirm"> I understand this data will be deleted</label>
            <div class="actions">
                <button id="btn-apply" class="btn btn-danger" disabled><i class="fa fa-random"></i> Replace events</button>
            </div>
        </div>
    </div>

    <div id="result" class="panel result-panel hidden"></div>
</div>

<?php
include(dirname(__FILE__, 2) . '/Common/disclaimer.php');
include('Common/Templates/tail.php');
?>
