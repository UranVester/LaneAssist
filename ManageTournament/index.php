<?php
/**
 * Manage Tournament: where the selected tournament stands (stage + checklist
 * with quickfixes) and the per-tournament tools.
 */

require_once(dirname(__FILE__, 3) . '/config.php');

if (!CheckTourSession()) {
    $PAGE_TITLE = 'Manage Tournament - No Tournament Selected';
    include('Common/Templates/head.php');
    echo '<div style="padding:20px;text-align:center"><h2>No Competition Selected</h2>';
    echo '<p><a class="btn btn-primary" href="' . $CFG->ROOT_DIR . 'index.php">Select Tournament</a></p></div>';
    include(dirname(__FILE__, 2) . '/Common/disclaimer.php');
    include('Common/Templates/tail.php');
    exit;
}

checkFullACL(AclCompetition, '', AclReadOnly);

// Settings/api.php (which loads and saves the tournament settings card) needs
// the same ACL Settings/index.php does.
$canEditSettings = hasFullACL(AclCompetition, 'cSchedule', AclReadWrite);
$competitionCode = getCodeFromId($_SESSION['TourId']);

$PAGE_TITLE = 'Manage Tournament';
$IncludeJquery = true;
$IncludeFA = true;

$styleVersion = filemtime(__DIR__ . '/css/style.css');
$scriptVersion = filemtime(__DIR__ . '/js/app.js');
$statusLogicVersion = filemtime(dirname(__DIR__) . '/Common/js/status-logic.js');
$JS_SCRIPT = [
    '<script>var ROOT_DIR=' . json_encode($CFG->ROOT_DIR, JSON_HEX_TAG | JSON_HEX_AMP) . ';
        var LANEASSIST_CAN_EDIT_SETTINGS = ' . ($canEditSettings ? 'true' : 'false') . ';
    </script>',
    '<link href="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/Common/css/shared.css" rel="stylesheet" type="text/css">',
    '<link href="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/ManageTournament/css/style.css?v=' . $styleVersion . '" rel="stylesheet" type="text/css">',
    '<script src="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/Common/js/finals-playability.js"></script>',
    '<script src="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/Common/js/status-logic.js?v=' . $statusLogicVersion . '"></script>',
    '<script src="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/ManageTournament/js/app.js?v=' . $scriptVersion . '"></script>',
];

include('Common/Templates/head.php');
?>
<main class="manage-tournament">
    <header class="mt-toolbar">
        <div>
            <h2>Manage Tournament <span class="mt-code"><?php echo htmlspecialchars($competitionCode, ENT_QUOTES, 'UTF-8'); ?></span></h2>
            <div id="mt-summary" class="mt-summary">Loading tournament status...</div>
        </div>
        <div class="mt-toolbar-right">
            <span id="mt-stage" class="mt-stage" hidden></span>
            <button type="button" id="mt-refresh" class="mt-icon-button" title="Refresh now" aria-label="Refresh now">
                <i class="fa fa-refresh" aria-hidden="true"></i>
            </button>
        </div>
    </header>

    <div class="mt-layout">
        <section id="mt-checklist" class="mt-checklist" aria-label="Tournament status"></section>

        <aside class="mt-tools">
            <section class="settings-card">
                <h3><i class="fa fa-flag"></i> Tournament settings</h3>
                <div id="mt-settings-status" class="status-message hidden"></div>
                <?php if ($canEditSettings): ?>
                    <div class="settings-field">
                        <label for="competition-default-finals-length">Default finals length override (minutes)</label>
                        <input id="competition-default-finals-length" type="number" min="0" max="480" step="1" value="0">
                        <small>Set to 0 to use user/admin value.</small>
                    </div>
                    <div class="settings-field">
                        <label for="competition-manage-targets-layout">Manage Targets default layout</label>
                        <select id="competition-manage-targets-layout">
                            <option value="">Auto (no saved preference)</option>
                            <option value="layout_fallback_stacked">No layout</option>
                            <option value="layout_60cm_3_abc">60cm ABC (3 archers, 2 lanes/mat)</option>
                            <option value="layout_40cm_6_triangle">40cm Triangle (3 archers, 6 targets/mat)</option>
                            <option value="layout_60cm_4_split">60cm Split (4 archers, 2 targets/mat)</option>
                            <option value="layout_40cm_4_quad">40cm Quad (4 archers, 4 targets/mat)</option>
                            <option value="layout_outdoor_mixed_2">Outdoor Mixed (2 archers)</option>
                            <option value="layout_outdoor_mixed_3">Outdoor Mixed (3 archers)</option>
                            <option value="layout_outdoor_mixed_4">Outdoor Mixed (4 archers)</option>
                        </select>
                        <small>Preselects the Manage Targets layout for this competition.</small>
                    </div>
                    <div class="settings-actions">
                        <button id="btn-save-competition-settings" class="btn btn-success"><i class="fa fa-save"></i> Save Tournament Settings</button>
                    </div>
                <?php else: ?>
                    <p class="inline-note">You need schedule edit access to change these settings.</p>
                <?php endif; ?>
            </section>

            <section class="settings-card">
                <h3><i class="fa fa-random"></i> Change finals events</h3>
                <p>Replace the selected tournament's individual final events with a new set, separated by gender, class, division or distance. Preview first; team events are not touched.</p>
                <div class="settings-actions">
                    <a href="<?php echo $CFG->ROOT_DIR; ?>Modules/Custom/LaneAssist/ChangeEvents/index.php" class="btn btn-info"><i class="fa fa-random"></i> Open Change finals events</a>
                </div>
            </section>
        </aside>
    </div>
</main>
<?php
include(dirname(__FILE__, 2) . '/Common/disclaimer.php');
include('Common/Templates/tail.php');
