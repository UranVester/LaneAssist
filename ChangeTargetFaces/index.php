<?php
/**
 * Change target faces: filter archers and set their target face one cell at a
 * time, or for everyone in the view. Faces an archer's division/class can't
 * use are greyed out (and refused by the API).
 */

require_once(dirname(__FILE__, 3) . '/config.php');
require_once(dirname(__FILE__, 2) . '/Common/i18n.php');

if (!CheckTourSession()) {
    $PAGE_TITLE = la_t('tournament.changeFacesTitle') . ' - ' . la_t('common.noTournamentSelected');
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

checkFullACL(AclParticipants, 'pEntries', AclReadOnly);

require_once(dirname(__FILE__, 2) . '/Common/target-faces-data.php');
$filterOptions = tfLoadFilterOptions(intval($_SESSION['TourId']));

/**
 * A multiselect filter: the shared LaneAssist dropdown plus the hidden
 * <select multiple> it drives (the select is what app.js reads).
 */
function changeTargetFacesMultiFilter(string $key, string $label, array $options, bool $shortFromCode = false): string
{
    $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $id = 'f-' . $key;
    $menu = '<label class="multi-option all-option"><input type="checkbox" value="" checked><span>' . la_h('common.all') . '</span></label>';
    $select = '<option value="" selected>' . la_h('common.all') . '</option>';
    foreach ($options as $o) {
        $short = $shortFromCode ? ' data-short="' . $h(strtok($o['label'], ' ')) . '"' : '';
        $menu .= '<label class="multi-option"><input type="checkbox" value="' . $h($o['value']) . '"' . $short . '><span>' . $h($o['label']) . '</span></label>';
        $select .= '<option value="' . $h($o['value']) . '">' . $h($o['label']) . '</option>';
    }
    return '<div class="filter-group"><label for="' . $id . '">' . $h($label) . '</label>'
        . '<div class="multi-dropdown" data-select-id="' . $id . '">'
        . '<button type="button" class="multi-dropdown-toggle">' . la_h('common.all') . '</button>'
        . '<div class="multi-dropdown-menu">' . $menu . '</div></div>'
        . '<select id="' . $id . '" class="tf-filter hidden-filter-select" data-key="' . $h($key) . '" multiple aria-hidden="true" tabindex="-1">' . $select . '</select>'
        . '</div>';
}

$PAGE_TITLE = la_t('tournament.changeFacesTitle');
$IncludeJquery = true;
$IncludeFA = true;

$styleVersion = filemtime(__DIR__ . '/css/style.css');
$scriptVersion = filemtime(__DIR__ . '/js/app.js');
$gateVersion = filemtime(__DIR__ . '/js/request-gate.js');
$sortVersion = filemtime(__DIR__ . '/js/sort.js');
$sharedVersion = filemtime(dirname(__DIR__) . '/Common/js/shared.js');
$JS_SCRIPT = array(
    '<script type="text/javascript">var ROOT_DIR = ' . json_encode($CFG->ROOT_DIR, JSON_HEX_TAG | JSON_HEX_AMP) . ';</script>',
    laJsCatalogScript(['common.', 'faces.']),
    '<script src="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/Common/js/i18n.js?v=' . filemtime(dirname(__DIR__) . '/Common/js/i18n.js') . '"></script>',
    '<link href="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/Common/css/shared.css?v=' . filemtime(dirname(__DIR__) . '/Common/css/shared.css') . '" rel="stylesheet" type="text/css">',
    '<link href="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/ChangeTargetFaces/css/style.css?v=' . $styleVersion . '" rel="stylesheet" type="text/css">',
    '<script src="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/Common/js/shared.js?v=' . $sharedVersion . '"></script>',
    '<script src="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/ChangeTargetFaces/js/request-gate.js?v=' . $gateVersion . '"></script>',
    '<script src="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/ChangeTargetFaces/js/sort.js?v=' . $sortVersion . '"></script>',
    '<script src="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/ChangeTargetFaces/js/app.js?v=' . $scriptVersion . '"></script>',
);

include('Common/Templates/head.php');
?>

<div class="change-faces-container">
    <div class="toolbar">
        <div class="toolbar-left">
            <h2><?php echo la_h('tournament.changeFacesTitle'); ?></h2>
            <div class="subtitle"><?php echo str_replace('{tournament}', '<strong>' . htmlspecialchars($_SESSION['TourName'] ?? '', ENT_QUOTES, 'UTF-8') . '</strong>', la_h('faces.subtitle')); ?></div>
        </div>
        <div class="toolbar-right">
            <a href="<?php echo $CFG->ROOT_DIR; ?>Modules/Custom/LaneAssist/ManageTournament/index.php" class="btn btn-secondary"><i class="fa fa-arrow-left"></i> <?php echo la_h('menu.manageTournament'); ?></a>
        </div>
    </div>

    <div id="readonly-note" class="status-message status-error hidden"></div>
    <div id="status-message" class="status-message hidden" role="status"></div>

    <div class="filters-panel">
        <div class="filter-group"><label for="f-name"><?php echo la_h('faces.archer'); ?></label><input type="text" id="f-name" class="tf-filter" data-key="name"></div>
        <?php
        echo changeTargetFacesMultiFilter('country', la_t('faces.club'), $filterOptions['country'], true);
        echo changeTargetFacesMultiFilter('div', la_t('faces.division'), $filterOptions['div']);
        echo changeTargetFacesMultiFilter('class', la_t('faces.class'), $filterOptions['class']);
        echo changeTargetFacesMultiFilter('event', la_t('faces.event'), $filterOptions['event']);
        ?>
        <div class="filter-group"><label for="f-face"><?php echo la_h('faces.currentFace'); ?></label><select id="f-face" class="tf-filter" data-key="face"><option value=""><?php echo la_h('common.all'); ?></option></select></div>
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
