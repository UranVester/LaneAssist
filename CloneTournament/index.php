<?php
/**
 * Clone Tournament
 */

require_once(dirname(__FILE__, 3) . '/config.php');

checkFullACL(AclRoot, '', AclReadWrite);

$PAGE_TITLE = 'Clone Tournament';
$IncludeJquery = true;
$IncludeFA = true;

$JS_SCRIPT = array(
    '<script type="text/javascript">var ROOT_DIR = "' . $CFG->ROOT_DIR . '";</script>',
    '<link href="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/Common/css/shared.css" rel="stylesheet" type="text/css">',
    '<link href="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/CloneTournament/css/style.css" rel="stylesheet" type="text/css">',
    '<script src="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/CloneTournament/js/app.js"></script>',
);

include('Common/Templates/head.php');
?>

<div class="clone-tournament-container">
    <div class="toolbar">
        <div class="toolbar-left">
            <h2>Clone Tournament</h2>
            <div class="subtitle">Create a new tournament from an existing one with selected setup parts</div>
        </div>
    </div>

    <div id="status-message" class="status-message hidden"></div>

    <div class="content-grid">
        <div class="panel tournaments-panel">
            <h3><i class="fa fa-trophy"></i> Tournaments</h3>
            <div id="tournaments-list" class="list-wrap">
                <div class="empty">Loading tournaments...</div>
            </div>
        </div>

        <div class="panel clone-panel">
            <h3><i class="fa fa-copy"></i> Clone Options</h3>

            <div id="selected-source" class="selected-source empty">Select a tournament on the left</div>

            <div class="field-row">
                <label for="new-name">New tournament name</label>
                <input type="text" id="new-name" maxlength="120" placeholder="e.g. Spring Cup 2027" disabled>
            </div>

            <div class="field-row">
                <label for="new-code">Competition code</label>
                <input type="text" id="new-code" maxlength="8" placeholder="e.g. CUP2027" disabled>
            </div>

            <div class="parts-block">
                <div class="parts-title">Clone these parts</div>
                <div id="parts-list" class="parts-list"></div>
            </div>

            <div class="actions">
                <button id="btn-clone" class="btn btn-success" disabled>
                    <i class="fa fa-clone"></i> Clone
                </button>
            </div>
        </div>
    </div>
</div>

<?php
include(dirname(__FILE__, 2) . '/Common/disclaimer.php');
include('Common/Templates/tail.php');
?>
