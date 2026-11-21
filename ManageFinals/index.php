<?php
/**
 * Manage Finals - Interactive
 * Interactive setup of finals field of play target/letter assignments
 */

require_once(dirname(__FILE__, 3) . '/config.php');
require_once(dirname(__FILE__, 2) . '/Common/i18n.php');

if (!CheckTourSession()) {
    $PAGE_TITLE = la_t('finals.pageTitle') . ' - ' . la_t('common.noTournamentSelected');
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

checkFullACL(AclCompetition, 'cSchedule', AclReadWrite);
require_once(dirname(__FILE__, 2) . '/Common/color-logic.php');

if (!function_exists('getModuleParameter')) {
    require_once(dirname(__FILE__, 5) . '/Common/Lib/Fun_Modules.php');
}

function getLaneAssistGlobalParameter($param, $defaultValue = '') {
    global $CFG;

    $userScopedParam = '';
    if (!empty($CFG->USERAUTH)) {
        $authUser = trim((string)($_SESSION['AUTH_User'] ?? ''));
        if ($authUser !== '') {
            $userScopedParam = 'user:' . strtolower($authUser) . ':' . $param;
        }
    }

    if ($userScopedParam !== '') {
        $queryUser = "SELECT MpValue
            FROM ModulesParameters
            WHERE MpModule='LaneAssist'
            AND MpParameter=" . StrSafe_DB($userScopedParam) . "
            AND MpTournament=0
            LIMIT 1";
        $resultUser = safe_r_sql($queryUser);
        if ($rowUser = safe_fetch($resultUser)) {
            $rawUser = $rowUser->MpValue;
            if ($rawUser !== '' && $rawUser !== null) {
                $decodedUser = @unserialize($rawUser, ['allowed_classes' => false]);
                if ($decodedUser !== false || $rawUser === 'b:0;') {
                    return $decodedUser;
                }
            }
            return $rawUser;
        }
    }

    $query = "SELECT MpValue
        FROM ModulesParameters
        WHERE MpModule='LaneAssist'
        AND MpParameter=" . StrSafe_DB($param) . "
        AND MpTournament=0
        LIMIT 1";
    $result = safe_r_sql($query);
    if ($row = safe_fetch($result)) {
        $raw = $row->MpValue;
        if ($raw !== '' && $raw !== null) {
            $decoded = @unserialize($raw, ['allowed_classes' => false]);
            if ($decoded !== false || $raw === 'b:0;') {
                return $decoded;
            }
        }
        return $raw;
    }
    return $defaultValue;
}

$defaultFinalsLength = intval(getModuleParameter('LaneAssist', 'DefaultFinalsLength', 0));
if ($defaultFinalsLength <= 0) {
    $defaultFinalsLength = intval(getLaneAssistGlobalParameter('DefaultFinalsLength', 30));
}
if ($defaultFinalsLength <= 0) {
    $defaultFinalsLength = 30;
}

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

$PAGE_TITLE = la_t('finals.pageTitle');
$IncludeJquery = true;
$IncludeFA = true;

$JS_SCRIPT = array(
    '<script type="text/javascript">var ROOT_DIR = "' . $CFG->ROOT_DIR . '"; var LANEASSIST_DEFAULT_FINALS_LENGTH = ' . intval($defaultFinalsLength) . '; var ColorGroups = ' . json_encode(laLoadColorGroups(intval($_SESSION['TourId'])), JSON_HEX_TAG | JSON_HEX_AMP) . ';</script>',
    laJsCatalogScript(['common.', 'finals.', 'colorBy.', 'status.']),
    '<script src="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/Common/js/i18n.js?v=' . filemtime(dirname(__DIR__) . '/Common/js/i18n.js') . '"></script>',
    '<link href="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/Common/css/shared.css" rel="stylesheet" type="text/css">',
    '<link href="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/ManageFinals/css/style.css" rel="stylesheet" type="text/css">',
    '<script src="' . $CFG->ROOT_DIR . 'Common/jQuery/jquery-ui.min.js"></script>',
    '<script src="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/Common/js/jquery-ui-touch-bridge.js"></script>',
    '<script src="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/Common/js/shared.js?v=' . filemtime(dirname(__DIR__) . '/Common/js/shared.js') . '"></script>',
    '<script src="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/Common/js/color-by.js?v=' . filemtime(dirname(__DIR__) . '/Common/js/color-by.js') . '"></script>',
    '<script src="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/Common/js/finals-playability.js"></script>',
    '<script src="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/Common/js/distance-lane-plan.js"></script>',
    '<script src="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/ManageFinals/js/app.js?v=' . filemtime(dirname(__DIR__) . '/ManageFinals/js/app.js') . '"></script>',
);

include('Common/Templates/head.php');
?>

<div class="manage-finals-container">
    <div class="toolbar">
        <div class="toolbar-top">
            <div class="toolbar-left">
                <h2><?php echo la_h('finals.pageTitle'); ?></h2>
            </div>
            <div class="toolbar-right">
                <div id="changes-summary" class="changes-summary compact" style="display:none;">
                    <div id="changes-list"></div>
                </div>
                <button id="btn-unassign-all" class="btn btn-warning" disabled>
                    <i class="fa fa-times-circle"></i> <?php echo la_h('finals.unassignAll'); ?>
                </button>
                <button id="btn-reset" class="btn btn-warning" disabled>
                    <i class="fa fa-undo"></i> <?php echo la_h('finals.reset'); ?>
                </button>
                <button id="btn-apply" class="btn btn-success" disabled>
                    <i class="fa fa-save"></i> <?php echo la_h('finals.applyChanges'); ?>
                </button>
            </div>
        </div>

        <div class="toolbar-controls-row">
            <div class="toolbar-popup-trigger">
                <button type="button" id="btn-filters" class="btn btn-secondary" aria-expanded="false">
                    <i class="fa fa-filter"></i> <?php echo la_h('finals.filters'); ?> <span id="filters-active-count" class="active-filter-indicator" style="display:none;">0</span>
                </button>
                <div id="filters-popup" class="filters-popup-panel" style="display:none;">
                    <h3><?php echo la_h('finals.participantFilters'); ?></h3>
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
                                        <input type="checkbox" value="<?php echo htmlspecialchars($div->DivId, ENT_QUOTES, 'UTF-8'); ?>">
                                        <span><?php echo htmlspecialchars($div->DivDescription, ENT_QUOTES, 'UTF-8') . ' (' . htmlspecialchars($div->DivId, ENT_QUOTES, 'UTF-8') . ')'; ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <select id="division-filter" name="division" multiple size="5" class="hidden-filter-select" aria-hidden="true" tabindex="-1">
                                <option value="" selected>-- <?php echo get_text('AllEvents'); ?> --</option>
                                <?php foreach ($divisions as $div): ?>
                                    <option value="<?php echo htmlspecialchars($div->DivId, ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo htmlspecialchars($div->DivDescription, ENT_QUOTES, 'UTF-8') . ' (' . htmlspecialchars($div->DivId, ENT_QUOTES, 'UTF-8') . ')'; ?>
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
                                        <input type="checkbox" value="<?php echo htmlspecialchars($cls->ClId, ENT_QUOTES, 'UTF-8'); ?>">
                                        <span><?php echo htmlspecialchars($cls->ClDescription, ENT_QUOTES, 'UTF-8') . ' (' . htmlspecialchars($cls->ClId, ENT_QUOTES, 'UTF-8') . ')'; ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <select id="class-filter" name="class" multiple size="5" class="hidden-filter-select" aria-hidden="true" tabindex="-1">
                                <option value="" selected>-- <?php echo get_text('AllEvents'); ?> --</option>
                                <?php foreach ($classes as $cls): ?>
                                    <option value="<?php echo htmlspecialchars($cls->ClId, ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo htmlspecialchars($cls->ClDescription, ENT_QUOTES, 'UTF-8') . ' (' . htmlspecialchars($cls->ClId, ENT_QUOTES, 'UTF-8') . ')'; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

        <div class="filter-group inline compact-control">
            <label for="team-event-filter"><?php echo la_h('finals.typeLabel'); ?></label>
            <select id="team-event-filter">
                <option value=""><?php echo la_h('common.all'); ?></option>
                <option value="0"><?php echo la_h('finals.typeIndividualOption'); ?></option>
                <option value="1"><?php echo la_h('finals.typeTeamOption'); ?></option>
            </select>
        </div>

        <div class="filter-group inline compact-control">
            <label for="date-filter"><?php echo la_h('finals.dateLabel'); ?></label>
            <input type="date" id="date-filter">
        </div>

        <div class="filter-group inline compact-control">
            <label for="color-by"><?php echo la_h('colorBy.label'); ?></label>
            <select id="color-by">
                <option value="none"><?php echo la_h('colorBy.none'); ?></option>
                <option value="bow"><?php echo la_h('colorBy.bow'); ?></option>
                <option value="class"><?php echo la_h('colorBy.class'); ?></option>
                <option value="category"><?php echo la_h('colorBy.category'); ?></option>
                <option value="event" selected><?php echo la_h('colorBy.event'); ?></option>
                <option value="phase"><?php echo la_h('colorBy.phase'); ?></option>
            </select>
        </div>

        <div class="filter-group inline checkbox-group compact-control">
            <label for="show-empty-timeslots"><?php echo la_h('finals.timeslotsLabel'); ?></label>
            <label class="checkbox-inline">
                <input type="checkbox" id="show-empty-timeslots" checked>
                <?php echo la_h('finals.showEmpty'); ?>
            </label>
        </div>

        <div class="auto-assign-trigger">
            <button id="btn-auto-assign" class="btn btn-info" title="<?php echo la_h('finals.autoAssignHelp'); ?>">
                <i class="fa fa-magic"></i> <?php echo la_h('finals.autoAssign'); ?>
            </button>

            <div id="auto-assign-options" class="auto-assign-panel" style="display:none;">
                <h3 class="auto-assign-title"><?php echo la_h('finals.autoOptions'); ?></h3>
                <div class="auto-assign-note">
                    <?php echo la_h('finals.defaultLength'); ?> <strong id="auto-default-length">-</strong> <?php echo la_h('finals.minutesShort'); ?>
                </div>

                <div class="auto-options-grid">
                    <div class="option-group">
                        <label for="auto-medal-mode"><?php echo la_h('finals.medalSlots'); ?></label>
                        <select id="auto-medal-mode">
                            <option value="earliest" selected><?php echo la_h('finals.medalEarliest'); ?></option>
                            <option value="together"><?php echo la_h('finals.medalTogether'); ?></option>
                            <option value="bronze-first"><?php echo la_h('finals.medalBronzeFirst'); ?></option>
                        </select>
                    </div>

                    <div class="option-group">
                        <label for="auto-schedule-style"><?php echo la_h('finals.scheduling'); ?></label>
                        <select id="auto-schedule-style">
                            <option value="compact" selected><?php echo la_h('finals.schedulingCompact'); ?></option>
                            <option value="alternating"><?php echo la_h('finals.schedulingAlternating'); ?></option>
                        </select>
                    </div>

                    <div class="option-group option-checkbox">
                        <label>
                            <input type="checkbox" id="auto-separate-streams" checked>
                            <?php echo la_h('finals.separateStreams'); ?>
                        </label>
                    </div>

                    <div class="option-group option-checkbox">
                        <label>
                            <input type="checkbox" id="auto-restrict-distance-lanes" checked>
                            <?php echo la_h('finals.restrictDistance'); ?>
                        </label>
                    </div>
                </div>

                <div class="option-actions">
                    <button id="btn-run-auto" class="btn btn-success">
                        <i class="fa fa-play"></i> <?php echo la_h('finals.runAutoAssign'); ?>
                    </button>
                    <button id="btn-close-auto" class="btn btn-warning" type="button">
                        <i class="fa fa-times"></i> <?php echo la_h('common.close'); ?>
                    </button>
                </div>
            </div>
        </div>

        </div>
    </div>

    <div class="summary-bar">
        <span><?php echo la_h('finals.summaryRows'); ?> <strong id="row-count">0</strong></span>
        <span><?php echo la_h('finals.summaryVisible'); ?> <strong id="visible-count">0</strong> / <strong id="total-count">0</strong></span>
        <span><?php echo la_h('finals.summaryChanged'); ?> <strong id="changed-count">0</strong></span>
        <span><?php echo la_h('finals.summaryHidden'); ?> <strong id="hidden-count">0</strong></span>
    </div>

    <div class="content-area">
        <div class="unassigned-pool">
            <h3><i class="fa fa-users"></i> <?php echo la_h('finals.unassignedGroups'); ?> (<span id="unassigned-count">0</span>)</h3>
            <div id="unassigned-list" class="match-list">
                <div class="empty"><?php echo la_h('finals.noData'); ?></div>
            </div>
        </div>

        <div class="targets-area">
            <h3>
                <span class="targets-title-main"><i class="fa fa-bullseye"></i> <?php echo la_h('finals.finalsTargets'); ?></span>
                <div class="targets-header-tools">
                    <div id="targets-validation-summary" class="targets-validation-summary" style="display:none;" data-error-count="0">
                        <button type="button" id="targets-validation-toggle" class="targets-validation-toggle" aria-expanded="false">
                            <i class="fa fa-exclamation-triangle"></i>
                            <span id="targets-validation-text"><?php echo la_h('finals.validationIssues'); ?></span>
                            <i class="fa fa-chevron-down toggle-icon"></i>
                        </button>
                        <div id="targets-validation-details" class="targets-validation-details" style="display:none;"></div>
                    </div>
                    <div id="status-message" class="status-message hidden"></div>
                    <div class="targets-actions-menu">
                        <button type="button" class="targets-actions-toggle" disabled title="<?php echo la_h('finals.actionsSoon'); ?>" aria-label="<?php echo la_h('finals.actions'); ?>" aria-disabled="true">
                            <i class="fa fa-ellipsis-v"></i>
                        </button>
                    </div>
                </div>
            </h3>
            <div id="drag-hint-message" class="drag-hint-message hidden"></div>
            <div id="visual-board" class="visual-board">
                <div class="empty"><?php echo la_h('finals.noData'); ?></div>
            </div>
        </div>
    </div>
</div>

<?php
include(dirname(__FILE__, 2) . '/Common/disclaimer.php');
include('Common/Templates/tail.php');
?>
