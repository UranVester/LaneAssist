<?php

require_once(dirname(__FILE__, 3) . '/config.php');

if (!CheckTourSession()) {
    $PAGE_TITLE = 'LaneAssist Live View - No Tournament Selected';
    include('Common/Templates/head.php');
    echo '<div style="padding:20px;text-align:center"><h2>No Competition Selected</h2>';
    echo '<p><a class="btn btn-primary" href="' . $CFG->ROOT_DIR . 'index.php">Select Tournament</a></p></div>';
    include(dirname(__FILE__, 2) . '/Common/disclaimer.php');
    include('Common/Templates/tail.php');
    exit;
}

checkFullACL(AclCompetition, '', AclReadOnly);
require_once('Common/Fun_Sessions.inc.php');

$PAGE_TITLE = 'LaneAssist Live View';
$IncludeJquery = true;
$IncludeFA = true;
$sessions = GetSessions('Q');

$divisionMeta = array();
$divRs = safe_r_sql("SELECT DivId, DivDescription FROM Divisions
           WHERE DivTournament=" . StrSafe_DB($_SESSION['TourId']) . "
           AND DivAthlete=1
           ORDER BY DivViewOrder");
while ($div = safe_fetch($divRs)) {
    $divisionMeta[$div->DivId] = array(
        'id' => $div->DivId,
        'description' => $div->DivDescription
    );
}

$classMeta = array();
$clsRs = safe_r_sql("SELECT ClId, ClDescription FROM Classes
           WHERE ClTournament=" . StrSafe_DB($_SESSION['TourId']) . "
           AND ClAthlete=1
           ORDER BY ClViewOrder");
while ($cls = safe_fetch($clsRs)) {
    $classMeta[$cls->ClId] = array(
        'id' => $cls->ClId,
        'description' => $cls->ClDescription
    );
}

$styleVersion = filemtime(__DIR__ . '/css/style.css');
$scriptVersion = filemtime(__DIR__ . '/js/app.js');
$badgeRenderVersion = filemtime(dirname(__DIR__) . '/Common/js/badge-render.js');
$colorByVersion = filemtime(dirname(__DIR__) . '/Common/js/color-by.js');
$JS_SCRIPT = [
    '<script>var ROOT_DIR=' . json_encode($CFG->ROOT_DIR, JSON_HEX_TAG | JSON_HEX_AMP) . ';
        var DivisionMeta = ' . json_encode($divisionMeta, JSON_HEX_TAG | JSON_HEX_AMP) . ';
        var ClassMeta = ' . json_encode($classMeta, JSON_HEX_TAG | JSON_HEX_AMP) . ';
    </script>',
    '<link href="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/LiveView/css/style.css?v=' . $styleVersion . '" rel="stylesheet" type="text/css">',
    '<script src="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/Common/js/badge-render.js?v='
        . $badgeRenderVersion . '"></script>',
    '<script src="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/Common/js/color-by.js?v='
        . $colorByVersion . '"></script>',
    '<script src="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/Common/js/finals-playability.js"></script>',
    '<script src="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/Common/js/status-logic.js"></script>',
    '<script src="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/LiveView/js/app.js?v=' . $scriptVersion . '"></script>',
];

include('Common/Templates/head.php');
?>
<main class="live-view">
    <header class="live-toolbar">
        <div>
            <div class="live-heading">
                <div>
                    <h2>Live View</h2>
                    <div id="live-summary" class="live-summary">Loading tournament status...</div>
                </div>
                <div id="live-progress" class="live-progress" aria-live="polite">
                    <span>Current end</span>
                    <strong>-</strong>
                </div>
            </div>
        </div>
        <div class="live-controls">
            <label class="session-control" for="color-by">
                <span>Color by</span>
                <select id="color-by"></select>
            </label>
            <label class="session-control" for="session-select">
                <span><?php echo get_text('Session'); ?></span>
                <select id="session-select">
                    <?php foreach ($sessions as $session): ?>
                        <option value="<?php echo intval($session->SesOrder); ?>">
                            <?php echo intval($session->SesOrder) . ' - ' . htmlspecialchars($session->Descr, ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="session-control round-control" id="round-control" hidden>
                <label for="round-select">
                    <span>Round</span>
                    <select id="round-select"></select>
                </label>
                <div class="round-nav">
                    <button type="button" id="round-prev" class="goto-current-button" title="Previous round">&laquo; Prev</button>
                    <button type="button" id="round-goto-current" class="goto-current-button current-action" title="Go to current round">Go to current</button>
                    <button type="button" id="round-next" class="goto-current-button" title="Next round">Next &raquo;</button>
                </div>
            </div>
            <div class="mode-switch" role="group" aria-label="Competition phase">
                <button type="button" class="mode-button active" data-mode="qualification">Qualification</button>
                <button type="button" class="mode-button" data-mode="finals">Finals <span id="final-count" class="count-badge">0</span></button>
                <button type="button" class="mode-button" data-mode="status">Status <span id="status-issue-count" class="count-badge" hidden>0</span></button>
            </div>
            <button type="button" id="refresh-button" class="icon-button" title="Refresh now" aria-label="Refresh now">
                <i class="fa fa-refresh" aria-hidden="true"></i>
            </button>
        </div>
    </header>

    <section class="status-strip" aria-label="Status legend">
        <span><i class="status-dot healthy"></i> On pace</span>
        <span><i class="status-dot warning"></i> Needs attention</span>
        <span><i class="status-dot danger"></i> Behind or missing arrows</span>
        <span id="last-updated">Waiting for first update</span>
    </section>

    <div id="live-notices" class="live-notices" aria-live="polite"></div>
    <section id="qualification-view" class="live-grid" aria-label="Qualification targets"></section>
    <section id="finals-view" class="live-grid" aria-label="Final matches" hidden></section>
    <section id="status-view" class="live-grid" aria-label="Tournament status" hidden></section>
    <div id="empty-state" class="empty-state" hidden>No live data is available for this view.</div>
</main>
<?php
include(dirname(__FILE__, 2) . '/Common/disclaimer.php');
include('Common/Templates/tail.php');
