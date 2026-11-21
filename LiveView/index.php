<?php

require_once(dirname(__FILE__, 3) . '/config.php');
require_once(dirname(__FILE__, 2) . '/Common/i18n.php');

if (!CheckTourSession()) {
    $PAGE_TITLE = la_t('menu.liveView') . ' - ' . la_t('common.noTournamentSelected');
    include('Common/Templates/head.php');
    echo '<div style="padding:20px;text-align:center"><h2>' . la_h('common.noTournamentSelected') . '</h2>';
    echo '<p><a class="btn btn-primary" href="' . $CFG->ROOT_DIR . 'index.php">' . la_h('common.selectTournament') . '</a></p></div>';
    include(dirname(__FILE__, 2) . '/Common/disclaimer.php');
    include('Common/Templates/tail.php');
    exit;
}

checkFullACL(AclCompetition, '', AclReadOnly);
require_once('Common/Fun_Sessions.inc.php');
require_once(dirname(__FILE__, 2) . '/Common/color-logic.php');

$PAGE_TITLE = la_t('menu.liveView');
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
$printLayoutVersion = filemtime(dirname(__DIR__) . '/Common/js/print-layout.js');
$finalScoresVersion = filemtime(dirname(__DIR__) . '/Common/js/final-scores.js');
$JS_SCRIPT = [
    '<script>var ROOT_DIR=' . json_encode($CFG->ROOT_DIR, JSON_HEX_TAG | JSON_HEX_AMP) . ';
        var DivisionMeta = ' . json_encode($divisionMeta, JSON_HEX_TAG | JSON_HEX_AMP) . ';
        var ClassMeta = ' . json_encode($classMeta, JSON_HEX_TAG | JSON_HEX_AMP) . ';
        var ColorGroups = ' . json_encode(laLoadColorGroups(intval($_SESSION['TourId'])), JSON_HEX_TAG | JSON_HEX_AMP) . ';
    </script>',
    laJsCatalogScript(['common.', 'live.', 'print.', 'colorBy.', 'status.']),
    '<script src="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/Common/js/i18n.js?v=' . filemtime(dirname(__DIR__) . '/Common/js/i18n.js') . '"></script>',
    '<link href="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/LiveView/css/style.css?v=' . $styleVersion . '" rel="stylesheet" type="text/css">',
    '<script src="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/Common/js/badge-render.js?v='
        . $badgeRenderVersion . '"></script>',
    '<script src="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/Common/js/color-by.js?v='
        . $colorByVersion . '"></script>',
    '<script src="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/Common/js/print-layout.js?v='
        . $printLayoutVersion . '"></script>',
    '<script src="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/Common/js/final-scores.js?v='
        . $finalScoresVersion . '"></script>',
    '<script src="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/LiveView/js/app.js?v=' . $scriptVersion . '"></script>',
];

include('Common/Templates/head.php');
?>
<main class="live-view">
    <header class="live-toolbar">
        <div>
            <div class="live-heading">
                <div>
                    <h2><?php echo la_h('live.title'); ?></h2>
                    <div id="live-summary" class="live-summary"><?php echo la_h('tournament.loadingStatus'); ?></div>
                </div>
                <div id="live-progress" class="live-progress" aria-live="polite">
                    <span><?php echo la_h('live.currentEnd'); ?></span>
                    <strong>-</strong>
                </div>
            </div>
        </div>
        <div class="live-controls">
            <label class="session-control" for="color-by">
                <span><?php echo la_h('live.colorBy'); ?></span>
                <select id="color-by"></select>
            </label>
            <label class="session-control" for="total-mode" id="total-mode-control">
                <span><?php echo la_h('live.total'); ?></span>
                <select id="total-mode">
                    <option value="combined" selected><?php echo la_h('live.totalCombined'); ?></option>
                    <option value="distance"><?php echo la_h('live.totalDistance'); ?></option>
                </select>
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
                    <span><?php echo la_h('live.round'); ?></span>
                    <select id="round-select"></select>
                </label>
                <div class="round-nav">
                    <button type="button" id="round-prev" class="goto-current-button" title="<?php echo la_h('live.prevRoundHelp'); ?>">&laquo; <?php echo la_h('live.prevRound'); ?></button>
                    <button type="button" id="round-goto-current" class="goto-current-button current-action" title="<?php echo la_h('live.currentRoundHelp'); ?>"><?php echo la_h('live.currentRound'); ?></button>
                    <button type="button" id="round-next" class="goto-current-button" title="<?php echo la_h('live.nextRoundHelp'); ?>"><?php echo la_h('live.nextRound'); ?> &raquo;</button>
                </div>
            </div>
            <div class="mode-switch" role="group" aria-label="<?php echo la_h('live.phaseLabel'); ?>">
                <button type="button" class="mode-button active" data-mode="qualification"><?php echo la_h('tournament.stageQualification'); ?></button>
                <button type="button" class="mode-button" data-mode="finals"><?php echo la_h('tournament.stageFinals'); ?> <span id="final-count" class="count-badge">0</span></button>
            </div>
            <button type="button" id="print-qual" class="icon-button" title="<?php echo la_h('live.printTargets'); ?>" aria-label="<?php echo la_h('live.printTargets'); ?>">
                <i class="fa fa-print" aria-hidden="true"></i>
            </button>
            <button type="button" id="print-finals" class="icon-button" title="<?php echo la_h('print.finalsReady'); ?>" aria-label="<?php echo la_h('print.finalsReady'); ?>" hidden>
                <i class="fa fa-print" aria-hidden="true"></i>
            </button>
            <button type="button" id="refresh-button" class="icon-button" title="<?php echo la_h('tournament.refreshNow'); ?>" aria-label="<?php echo la_h('tournament.refreshNow'); ?>">
                <i class="fa fa-refresh" aria-hidden="true"></i>
            </button>
        </div>
    </header>

    <section class="status-strip" aria-label="<?php echo la_h('live.legend'); ?>">
        <span><i class="status-dot healthy"></i> <?php echo la_h('live.onPace'); ?></span>
        <span><i class="status-dot warning"></i> <?php echo la_h('live.needsAttention'); ?></span>
        <span><i class="status-dot danger"></i> <?php echo la_h('live.behind'); ?></span>
        <span id="last-updated"><?php echo la_h('live.waitingFirst'); ?></span>
    </section>

    <div id="live-notices" class="live-notices" aria-live="polite"></div>
    <section id="qualification-view" class="live-grid" aria-label="<?php echo la_h('live.qualTargets'); ?>"></section>
    <section id="finals-view" class="live-grid" aria-label="<?php echo la_h('live.finalMatches'); ?>" hidden></section>
    <div id="empty-state" class="empty-state" hidden><?php echo la_h('live.noData'); ?></div>
</main>
<?php
include(dirname(__FILE__, 2) . '/Common/disclaimer.php');
include('Common/Templates/tail.php');
