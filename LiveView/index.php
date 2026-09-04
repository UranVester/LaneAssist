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
$styleVersion = filemtime(__DIR__ . '/css/style.css');
$scriptVersion = filemtime(__DIR__ . '/js/app.js');
$JS_SCRIPT = [
    '<script>var ROOT_DIR=' . json_encode($CFG->ROOT_DIR, JSON_HEX_TAG | JSON_HEX_AMP) . ';</script>',
    '<link href="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/LiveView/css/style.css?v=' . $styleVersion . '" rel="stylesheet" type="text/css">',
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
            <div class="mode-switch" role="group" aria-label="Competition phase">
                <button type="button" class="mode-button active" data-mode="qualification">Qualification</button>
                <button type="button" class="mode-button" data-mode="finals">Finals <span id="final-count" class="count-badge">0</span></button>
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
    <div id="empty-state" class="empty-state" hidden>No live data is available for this view.</div>
</main>
<?php
include(dirname(__FILE__, 2) . '/Common/disclaimer.php');
include('Common/Templates/tail.php');
