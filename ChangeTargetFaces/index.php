<?php
/**
 * Change target faces: filter archers and set their target face one cell at a
 * time, or for everyone in the view. Faces an archer's division/class can't
 * use are greyed out (and refused by the API).
 */

require_once(dirname(__FILE__, 3) . '/config.php');

if (!CheckTourSession()) {
    $PAGE_TITLE = 'Change target faces - No Tournament Selected';
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

checkFullACL(AclParticipants, 'pEntries', AclReadOnly);

$PAGE_TITLE = 'Change target faces';
$IncludeJquery = true;
$IncludeFA = true;

$styleVersion = filemtime(__DIR__ . '/css/style.css');
$scriptVersion = filemtime(__DIR__ . '/js/app.js');
$JS_SCRIPT = array(
    '<script type="text/javascript">var ROOT_DIR = ' . json_encode($CFG->ROOT_DIR, JSON_HEX_TAG | JSON_HEX_AMP) . ';</script>',
    '<link href="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/Common/css/shared.css" rel="stylesheet" type="text/css">',
    '<link href="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/ChangeTargetFaces/css/style.css?v=' . $styleVersion . '" rel="stylesheet" type="text/css">',
    '<script src="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/ChangeTargetFaces/js/app.js?v=' . $scriptVersion . '"></script>',
);

include('Common/Templates/head.php');
?>

<div class="change-faces-container">
    <div class="toolbar">
        <div class="toolbar-left">
            <h2>Change target faces</h2>
            <div class="subtitle">Set the target face of archers in <strong><?php echo htmlspecialchars($_SESSION['TourName'] ?? '', ENT_QUOTES, 'UTF-8'); ?></strong>. Greyed-out faces aren't allowed for that archer's division and class.</div>
        </div>
        <div class="toolbar-right">
            <a href="<?php echo $CFG->ROOT_DIR; ?>Modules/Custom/LaneAssist/ManageTournament/index.php" class="btn btn-secondary"><i class="fa fa-arrow-left"></i> Manage Tournament</a>
        </div>
    </div>

    <div id="readonly-note" class="status-message status-error hidden"></div>
    <div id="status-message" class="status-message hidden" role="status"></div>

    <div class="filters-panel">
        <div class="filter-group"><label for="f-name">Archer</label><input type="text" id="f-name" class="tf-filter" data-key="name"></div>
        <div class="filter-group"><label for="f-country">Club</label><input type="text" id="f-country" class="tf-filter" data-key="country"></div>
        <div class="filter-group"><label for="f-div">Division</label><input type="text" id="f-div" class="tf-filter tf-short" data-key="div"></div>
        <div class="filter-group"><label for="f-class">Class</label><input type="text" id="f-class" class="tf-filter tf-short" data-key="class"></div>
        <div class="filter-group"><label for="f-event">Event</label><input type="text" id="f-event" class="tf-filter tf-short" data-key="event"></div>
        <div class="filter-group"><label for="f-face">Current face</label><select id="f-face" class="tf-filter" data-key="face"><option value="">All</option></select></div>
        <div class="filter-group"><span id="tf-count" class="tf-count"></span></div>
    </div>

    <table class="tf-table">
        <thead id="tf-head"></thead>
        <tbody id="tf-body"></tbody>
    </table>
</div>

<?php
include(dirname(__FILE__, 2) . '/Common/disclaimer.php');
include('Common/Templates/tail.php');
?>
