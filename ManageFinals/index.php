<?php
/**
 * Manage Finals - Interactive
 * Interactive setup of finals field of play target/letter assignments
 */

require_once(dirname(__FILE__, 3) . '/config.php');

if (!CheckTourSession()) {
    $PAGE_TITLE = 'Manage Finals - No Tournament Selected';
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

checkFullACL(AclCompetition, 'cSchedule', AclReadWrite);

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
                $decodedUser = @unserialize($rawUser);
                if ($decodedUser !== false) {
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
            $decoded = @unserialize($raw);
            if ($decoded !== false) {
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

$PAGE_TITLE = 'Manage Finals - Interactive';
$IncludeJquery = true;
$IncludeFA = true;

$JS_SCRIPT = array(
    '<script type="text/javascript">var ROOT_DIR = "' . $CFG->ROOT_DIR . '"; var LANEASSIST_DEFAULT_FINALS_LENGTH = ' . intval($defaultFinalsLength) . ';</script>',
    '<link href="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/Common/css/shared.css" rel="stylesheet" type="text/css">',
    '<link href="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/ManageFinals/css/style.css" rel="stylesheet" type="text/css">',
    '<script src="' . $CFG->ROOT_DIR . 'Common/jQuery/jquery-ui.min.js"></script>',
    '<script src="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/Common/js/jquery-ui-touch-bridge.js"></script>',
    '<script src="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/Common/js/shared.js"></script>',
    '<script src="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/ManageFinals/js/app.js"></script>',
);

include('Common/Templates/head.php');
?>

<div class="manage-finals-container">
    <div class="toolbar">
        <div class="toolbar-top">
            <div class="toolbar-left">
                <h2>Manage Finals - Interactive</h2>
            </div>
            <div class="toolbar-right">
                <div id="changes-summary" class="changes-summary compact" style="display:none;">
                    <div id="changes-list"></div>
                </div>
                <button id="btn-unassign-all" class="btn btn-warning" disabled>
                    <i class="fa fa-times-circle"></i> Unassign All
                </button>
                <button id="btn-reset" class="btn btn-warning" disabled>
                    <i class="fa fa-undo"></i> Reset
                </button>
                <button id="btn-apply" class="btn btn-success" disabled>
                    <i class="fa fa-save"></i> Apply Changes
                </button>
            </div>
        </div>

        <div class="toolbar-controls-row">
            <div class="toolbar-popup-trigger">
                <button type="button" id="btn-filters" class="btn btn-secondary" aria-expanded="false">
                    <i class="fa fa-filter"></i> Filters <span id="filters-active-count" class="active-filter-indicator" style="display:none;">0</span>
                </button>
                <div id="filters-popup" class="filters-popup-panel" style="display:none;">
                    <h3>Participant Filters</h3>
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
                                        <input type="checkbox" value="<?php echo $div->DivId; ?>">
                                        <span><?php echo $div->DivDescription . ' (' . $div->DivId . ')'; ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <select id="division-filter" name="division" multiple size="5" class="hidden-filter-select" aria-hidden="true" tabindex="-1">
                                <option value="" selected>-- <?php echo get_text('AllEvents'); ?> --</option>
                                <?php foreach ($divisions as $div): ?>
                                    <option value="<?php echo $div->DivId; ?>">
                                        <?php echo $div->DivDescription . ' (' . $div->DivId . ')'; ?>
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
                                        <input type="checkbox" value="<?php echo $cls->ClId; ?>">
                                        <span><?php echo $cls->ClDescription . ' (' . $cls->ClId . ')'; ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <select id="class-filter" name="class" multiple size="5" class="hidden-filter-select" aria-hidden="true" tabindex="-1">
                                <option value="" selected>-- <?php echo get_text('AllEvents'); ?> --</option>
                                <?php foreach ($classes as $cls): ?>
                                    <option value="<?php echo $cls->ClId; ?>">
                                        <?php echo $cls->ClDescription . ' (' . $cls->ClId . ')'; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

        <div class="filter-group inline compact-control">
            <label for="team-event-filter">Type:</label>
            <select id="team-event-filter">
                <option value="">All</option>
                <option value="0">Individual</option>
                <option value="1">Team</option>
            </select>
        </div>

        <div class="filter-group inline compact-control">
            <label for="date-filter">Date:</label>
            <input type="date" id="date-filter">
        </div>

        <div class="filter-group inline compact-control">
            <label for="color-by">Color by:</label>
            <select id="color-by">
                <option value="none" selected>none</option>
                <option value="event">event</option>
                <option value="phase">phase</option>
                <option value="division">division</option>
                <option value="class">class</option>
            </select>
        </div>

        <div class="filter-group inline checkbox-group compact-control">
            <label for="show-empty-timeslots">Timeslots:</label>
            <label class="checkbox-inline">
                <input type="checkbox" id="show-empty-timeslots" checked>
                Show empty
            </label>
        </div>

        <div class="auto-assign-trigger">
            <button id="btn-auto-assign" class="btn btn-info" title="Auto assign finals to timeslots and targets">
                <i class="fa fa-magic"></i> Auto Assign
            </button>

            <div id="auto-assign-options" class="auto-assign-panel" style="display:none;">
                <h3 class="auto-assign-title">Auto Assign Options</h3>
                <div class="auto-assign-note">
                    Default finals length: <strong id="auto-default-length">-</strong> min
                </div>

                <div class="auto-options-grid">
                    <div class="option-group">
                        <label for="auto-medal-mode">Medal slots:</label>
                        <select id="auto-medal-mode">
                            <option value="earliest" selected>Earliest possible</option>
                            <option value="together">Gold + Bronze together</option>
                            <option value="bronze-first">Bronze then Gold</option>
                        </select>
                    </div>

                    <div class="option-group">
                        <label for="auto-schedule-style">Scheduling:</label>
                        <select id="auto-schedule-style">
                            <option value="compact" selected>Compact</option>
                            <option value="alternating">Prefer alternating events</option>
                        </select>
                    </div>

                    <div class="option-group option-checkbox">
                        <label>
                            <input type="checkbox" id="auto-separate-streams" checked>
                            Separate team/individual streams
                        </label>
                    </div>

                    <div class="option-group option-checkbox">
                        <label>
                            <input type="checkbox" id="auto-restrict-distance-lanes" checked>
                            Restrict lane to specific distance
                        </label>
                    </div>
                </div>

                <div class="option-actions">
                    <button id="btn-run-auto" class="btn btn-success">
                        <i class="fa fa-play"></i> Run Auto Assign
                    </button>
                    <button id="btn-close-auto" class="btn btn-warning" type="button">
                        <i class="fa fa-times"></i> Close
                    </button>
                </div>
            </div>
        </div>

        </div>
    </div>

    <div class="summary-bar">
        <span>Rows: <strong id="row-count">0</strong></span>
        <span>Visible / total: <strong id="visible-count">0</strong> / <strong id="total-count">0</strong></span>
        <span>Changed: <strong id="changed-count">0</strong></span>
        <span>Hidden non-playable: <strong id="hidden-count">0</strong></span>
    </div>

    <div class="content-area">
        <div class="unassigned-pool">
            <h3><i class="fa fa-users"></i> Unassigned Match Groups (<span id="unassigned-count">0</span>)</h3>
            <div id="unassigned-list" class="match-list">
                <div class="empty">No data loaded</div>
            </div>
        </div>

        <div class="targets-area">
            <h3>
                <span class="targets-title-main"><i class="fa fa-bullseye"></i> Finals Targets</span>
                <div class="targets-header-tools">
                    <div id="targets-validation-summary" class="targets-validation-summary" style="display:none;" data-error-count="0">
                        <button type="button" id="targets-validation-toggle" class="targets-validation-toggle" aria-expanded="false">
                            <i class="fa fa-exclamation-triangle"></i>
                            <span id="targets-validation-text">Validation issues</span>
                            <i class="fa fa-chevron-down toggle-icon"></i>
                        </button>
                        <div id="targets-validation-details" class="targets-validation-details" style="display:none;"></div>
                    </div>
                    <div id="status-message" class="status-message hidden"></div>
                    <div class="targets-actions-menu">
                        <button type="button" class="targets-actions-toggle" disabled title="Actions (coming soon)" aria-label="Actions" aria-disabled="true">
                            <i class="fa fa-ellipsis-v"></i>
                        </button>
                    </div>
                </div>
            </h3>
            <div id="drag-hint-message" class="drag-hint-message hidden"></div>
            <div id="visual-board" class="visual-board">
                <div class="empty">No data loaded</div>
            </div>
        </div>
    </div>
</div>

<?php
include(dirname(__FILE__, 2) . '/Common/disclaimer.php');
include('Common/Templates/tail.php');
?>
