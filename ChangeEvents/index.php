<?php
/**
 * Change finals events: replace the selected tournament's individual final
 * events with a freshly generated set, grouped by the criteria picked here.
 */

require_once(dirname(__FILE__, 3) . '/config.php');
require_once(dirname(__FILE__, 2) . '/Common/i18n.php');

if (!CheckTourSession()) {
    $PAGE_TITLE = la_t('tournament.changeEventsTitle') . ' - ' . la_t('common.noTournamentSelected');
    include('Common/Templates/head.php');
    echo '<div style="padding: 20px; text-align: center;">';
    echo '<h2>' . la_h('common.noTournamentSelected') . '</h2>';
    echo '<p>' . la_h('common.selectTournamentFirst') . '</p>';
    echo '<p><a href="' . $CFG->ROOT_DIR . 'index.php" class="btn btn-primary">' . la_h('common.selectTournament') . '</a></p>';
    echo '</div>';
    include(dirname(__FILE__, 2) . '/Common/disclaimer.php');
    include('Common/Templates/tail.php');
    exit;
}

checkFullACL(AclCompetition, 'cData', AclReadWrite);

$isBlocked = IsBlocked(BIT_BLOCK_TOURDATA);

$PAGE_TITLE = la_t('tournament.changeEventsTitle');
$IncludeJquery = true;
$IncludeFA = true;

$JS_SCRIPT = array(
    '<script type="text/javascript">var ROOT_DIR = "' . $CFG->ROOT_DIR . '";</script>',
    laJsCatalogScript(['common.', 'events.', 'status.']),
    '<script src="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/Common/js/i18n.js?v=' . filemtime(dirname(__DIR__) . '/Common/js/i18n.js') . '"></script>',
    '<link href="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/Common/css/shared.css" rel="stylesheet" type="text/css">',
    '<link href="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/ChangeEvents/css/style.css" rel="stylesheet" type="text/css">',
    '<script src="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/ChangeEvents/js/app.js?v=' . filemtime(dirname(__DIR__) . '/ChangeEvents/js/app.js') . '"></script>',
);

include('Common/Templates/head.php');
?>

<div class="change-events-container">
    <div class="toolbar">
        <div class="toolbar-left">
            <h2><?php echo la_h('tournament.changeEventsTitle'); ?></h2>
            <div class="subtitle"><?php echo str_replace('{tournament}', '<strong>' . htmlspecialchars($_SESSION['TourName'] ?? '', ENT_QUOTES, 'UTF-8') . '</strong>', la_h('events.subtitle')); ?></div>
        </div>
    </div>

    <div id="status-message" class="status-message hidden"></div>

    <?php if ($isBlocked): ?>
        <div class="status-message status-error"><?php echo la_h('events.blockedCanPreview'); ?></div>
    <?php endif; ?>

    <div class="panel grouping-panel">
        <h3><i class="fa fa-sliders"></i> <?php echo la_h('events.separateBy'); ?></h3>
        <div class="criteria">
            <label><input type="checkbox" id="ce-gender" class="ce-criterion" checked> <?php echo la_h('events.gender'); ?></label>
            <label><input type="checkbox" id="ce-class" class="ce-criterion" checked> <?php echo la_h('events.classAgeGroup'); ?></label>
            <label><input type="checkbox" id="ce-division" class="ce-criterion" checked> <?php echo la_h('events.division'); ?></label>
            <label id="ce-distance-label"><input type="checkbox" id="ce-distance" class="ce-criterion"> <?php echo la_h('events.distance'); ?> <i id="ce-distance-hint"><?php echo la_h('events.distanceHint'); ?></i></label>
        </div>
        <div class="actions">
            <button id="btn-preview" class="btn btn-info"><i class="fa fa-eye"></i> <?php echo la_h('events.preview'); ?></button>
        </div>
    </div>

    <div id="preview" class="hidden">
        <div class="content-grid">
            <div class="panel">
                <h3><i class="fa fa-trash"></i> <?php echo la_h('events.currentEvents'); ?></h3>
                <div id="old-events"></div>
            </div>
            <div class="panel">
                <h3><i class="fa fa-plus-circle"></i> <?php echo la_h('events.newEvents'); ?></h3>
                <div id="new-events"></div>
            </div>
        </div>

        <div id="warning-box" class="warning-box"></div>

        <div class="panel confirm-panel">
            <label><input type="checkbox" id="ce-confirm"> <?php echo la_h('events.confirmDelete'); ?></label>
            <div class="actions">
                <button id="btn-apply" class="btn btn-danger" disabled><i class="fa fa-random"></i> <?php echo la_h('events.replace'); ?></button>
            </div>
        </div>
    </div>

    <div id="result" class="panel result-panel hidden"></div>
</div>

<?php
include(dirname(__FILE__, 2) . '/Common/disclaimer.php');
include('Common/Templates/tail.php');
?>
