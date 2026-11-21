<?php
/**
 * Manage Tournament: where the selected tournament stands (stage + checklist
 * with quickfixes) and the per-tournament tools.
 */

require_once(dirname(__FILE__, 3) . '/config.php');
require_once(dirname(__FILE__, 2) . '/Common/i18n.php');

if (!CheckTourSession()) {
    $PAGE_TITLE = la_t('menu.manageTournament') . ' - ' . la_t('common.noTournamentSelected');
    include('Common/Templates/head.php');
    echo '<div style="padding:20px;text-align:center"><h2>' . la_h('common.noTournamentSelected') . '</h2>';
    echo '<p><a class="btn btn-primary" href="' . $CFG->ROOT_DIR . 'index.php">' . la_h('common.selectTournament') . '</a></p></div>';
    include(dirname(__FILE__, 2) . '/Common/disclaimer.php');
    include('Common/Templates/tail.php');
    exit;
}

checkFullACL(AclCompetition, '', AclReadOnly);

// Settings/api.php (which loads and saves the tournament settings card) needs
// the same ACL Settings/index.php does.
$canEditSettings = hasFullACL(AclCompetition, 'cSchedule', AclReadWrite);
$canOpenTargetFaces = hasFullACL(AclParticipants, 'pEntries', AclReadOnly);
$competitionCode = getCodeFromId($_SESSION['TourId']);

$PAGE_TITLE = la_t('menu.manageTournament');
$IncludeJquery = true;
$IncludeFA = true;

$styleVersion = filemtime(__DIR__ . '/css/style.css');
$scriptVersion = filemtime(__DIR__ . '/js/app.js');
$statusLogicVersion = filemtime(dirname(__DIR__) . '/Common/js/status-logic.js');
$tournamentSettingsVersion = filemtime(dirname(__DIR__) . '/Common/js/tournament-settings.js');
$i18nVersion = filemtime(dirname(__DIR__) . '/Common/js/i18n.js');
$JS_SCRIPT = [
    '<script>var ROOT_DIR=' . json_encode($CFG->ROOT_DIR, JSON_HEX_TAG | JSON_HEX_AMP) . ';
        var LANEASSIST_CAN_EDIT_SETTINGS = ' . ($canEditSettings ? 'true' : 'false') . ';
    </script>',
    laJsCatalogScript(['common.', 'tournament.', 'status.']),
    '<script src="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/Common/js/i18n.js?v=' . $i18nVersion . '"></script>',
    '<link href="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/Common/css/shared.css" rel="stylesheet" type="text/css">',
    '<link href="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/ManageTournament/css/style.css?v=' . $styleVersion . '" rel="stylesheet" type="text/css">',
    '<script src="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/Common/js/finals-playability.js"></script>',
    '<script src="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/Common/js/status-logic.js?v=' . $statusLogicVersion . '"></script>',
    '<script src="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/Common/js/tournament-settings.js?v=' . $tournamentSettingsVersion . '"></script>',
    '<script src="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/ManageTournament/js/app.js?v=' . $scriptVersion . '"></script>',
];

include('Common/Templates/head.php');
?>
<main class="manage-tournament">
    <header class="mt-toolbar">
        <div>
            <h2><?php echo la_h('menu.manageTournament'); ?> <span class="mt-code"><?php echo htmlspecialchars($competitionCode, ENT_QUOTES, 'UTF-8'); ?></span></h2>
            <div id="mt-summary" class="mt-summary"><?php echo la_h('tournament.loadingStatus'); ?></div>
        </div>
        <div class="mt-toolbar-right">
            <span id="mt-stage" class="mt-stage" hidden></span>
            <button type="button" id="mt-refresh" class="mt-icon-button" title="<?php echo la_h('tournament.refreshNow'); ?>" aria-label="<?php echo la_h('tournament.refreshNow'); ?>">
                <i class="fa fa-refresh" aria-hidden="true"></i>
            </button>
        </div>
    </header>

    <div class="mt-layout">
        <section id="mt-checklist" class="mt-checklist" aria-label="<?php echo la_h('tournament.statusLabel'); ?>"></section>

        <aside class="mt-tools">
            <section class="settings-card">
                <h3><i class="fa fa-flag"></i> <?php echo la_h('tournament.settingsTitle'); ?></h3>
                <?php if ($canEditSettings): ?>
                    <div class="settings-field">
                        <label for="competition-default-finals-length"><?php echo la_h('tournament.finalsLength'); ?> <span id="finals-length-default-tag" class="mt-default-tag" hidden><?php echo la_h('tournament.defaultTag'); ?></span></label>
                        <div class="mt-field-row">
                            <input id="competition-default-finals-length" type="number" min="1" max="480" step="1" value="">
                            <button type="button" id="btn-reset-finals-length" class="btn btn-secondary mt-reset" hidden><i class="fa fa-undo"></i> <?php echo la_h('tournament.resetToDefault'); ?></button>
                        </div>
                        <small id="finals-length-default-note"><?php echo la_h('tournament.defaultFromSettings'); ?></small>
                    </div>
                    <div class="settings-field">
                        <label for="competition-manage-targets-layout"><?php echo la_h('tournament.targetsLayout'); ?> <span id="layout-default-tag" class="mt-default-tag" hidden><?php echo la_h('tournament.defaultTag'); ?></span></label>
                        <div class="mt-field-row">
                        <select id="competition-manage-targets-layout">
                            <option value=""><?php echo la_h('tournament.layoutAuto'); ?></option>
                            <option value="layout_fallback_stacked"><?php echo la_h('layout.none'); ?></option>
                            <option value="layout_60cm_3_abc"><?php echo la_h('layout.abc60'); ?></option>
                            <option value="layout_40cm_6_triangle"><?php echo la_h('layout.triangle40'); ?></option>
                            <option value="layout_60cm_4_split"><?php echo la_h('layout.split60'); ?></option>
                            <option value="layout_40cm_4_quad"><?php echo la_h('layout.quad40'); ?></option>
                            <option value="layout_outdoor_mixed_2"><?php echo la_h('layout.outdoorMixed2'); ?></option>
                            <option value="layout_outdoor_mixed_3"><?php echo la_h('layout.outdoorMixed3'); ?></option>
                            <option value="layout_outdoor_mixed_4"><?php echo la_h('layout.outdoorMixed4'); ?></option>
                        </select>
                            <button type="button" id="btn-reset-layout" class="btn btn-secondary mt-reset" hidden><i class="fa fa-undo"></i> <?php echo la_h('tournament.resetToDefault'); ?></button>
                        </div>
                        <small><?php echo la_h('tournament.layoutHelp'); ?></small>
                    </div>
                    <div class="settings-actions">
                        <button id="btn-save-competition-settings" class="btn btn-success"><i class="fa fa-save"></i> <?php echo la_h('tournament.saveSettings'); ?></button>
                        <span id="mt-settings-status" class="status-message mt-inline-status hidden" role="status"></span>
                    </div>
                <?php else: ?>
                    <p class="inline-note"><?php echo la_h('tournament.needScheduleAccess'); ?></p>
                <?php endif; ?>
            </section>

            <section class="settings-card">
                <h3><i class="fa fa-random"></i> <?php echo la_h('tournament.changeEventsTitle'); ?></h3>
                <p><?php echo la_h('tournament.changeEventsHelp'); ?></p>
                <div class="settings-actions">
                    <a href="<?php echo $CFG->ROOT_DIR; ?>Modules/Custom/LaneAssist/ChangeEvents/index.php" class="btn btn-info"><i class="fa fa-random"></i> <?php echo la_h('tournament.openChangeEvents'); ?></a>
                </div>
            </section>

            <section class="settings-card">
                <h3><i class="fa fa-bullseye"></i> <?php echo la_h('tournament.changeFacesTitle'); ?></h3>
                <p><?php echo la_h('tournament.changeFacesHelp'); ?></p>
                <div class="settings-actions">
                    <?php if ($canOpenTargetFaces): ?>
                        <a href="<?php echo $CFG->ROOT_DIR; ?>Modules/Custom/LaneAssist/ChangeTargetFaces/index.php" class="btn btn-info"><i class="fa fa-bullseye"></i> <?php echo la_h('tournament.openChangeFaces'); ?></a>
                    <?php else: ?>
                        <button type="button" class="btn btn-info" disabled><i class="fa fa-bullseye"></i> <?php echo la_h('tournament.openChangeFaces'); ?></button>
                    <?php endif; ?>
                </div>
                <?php if (!$canOpenTargetFaces): ?>
                    <p class="inline-note"><?php echo la_h('tournament.needParticipantAccess'); ?></p>
                <?php endif; ?>
            </section>
        </aside>
    </div>
</main>
<?php
include(dirname(__FILE__, 2) . '/Common/disclaimer.php');
include('Common/Templates/tail.php');
