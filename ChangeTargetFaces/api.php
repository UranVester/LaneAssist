<?php
/**
 * Change target faces API: list archers with their legal faces, and set a
 * face for one archer or for everyone in the filtered view.
 *
 * Gate: the same one core uses for the participant list (Participants >
 * Entries); writes need read-write and are refused while participants are
 * blocked. The rules, including which faces are legal, are enforced here too,
 * not just greyed out in the page.
 */

require_once(dirname(__FILE__, 3) . '/config.php');
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!CheckTourSession()) {
    echo json_encode(['error' => 1, 'message' => get_text('CrackError')]);
    exit;
}

checkFullACL(AclParticipants, 'pEntries', AclReadOnly, false);

require_once(dirname(__FILE__, 2) . '/Common/csrf.php');
require_once(dirname(__FILE__, 2) . '/Common/target-faces-data.php');

$tourId = intval($_SESSION['TourId']);
$action = $_REQUEST['action'] ?? '';

if ($action === 'set' || $action === 'setAll') {
    laneAssistRequirePost();
    checkFullACL(AclParticipants, 'pEntries', AclReadWrite, false);
    if (IsBlocked(BIT_BLOCK_PARTICIPANT)) {
        echo json_encode(['error' => 1, 'message' => 'Participants are locked for this tournament.']);
        exit;
    }
}

switch ($action) {
    case 'load':
        changeTargetFacesLoad($tourId);
        break;
    case 'set':
        changeTargetFacesSet($tourId);
        break;
    case 'setAll':
        changeTargetFacesSetAll($tourId);
        break;
    default:
        echo json_encode(['error' => 1, 'message' => 'Invalid action']);
}

/** The filter fields from the request. */
function changeTargetFacesFilters(): array
{
    $filters = [];
    foreach (['name', 'country', 'div', 'class', 'event', 'face'] as $key) {
        $filters[$key] = (string)($_REQUEST[$key] ?? '');
    }
    return $filters;
}

function changeTargetFacesLoad(int $tourId): void
{
    $ctx = tfLoadContext($tourId);
    $reason = '';
    if (!hasFullACL(AclParticipants, 'pEntries', AclReadWrite)) {
        $reason = 'You need participant edit access.';
    } elseif (IsBlocked(BIT_BLOCK_PARTICIPANT)) {
        $reason = 'Participants are locked for this tournament.';
    }
    echo json_encode([
        'error' => 0,
        'groups' => array_map(fn($g) => ['key' => $g['key'], 'label' => $g['label']], $ctx['groups']),
        'archers' => tfLoadArchers($tourId, changeTargetFacesFilters(), $ctx),
        'canWrite' => $reason === '',
        'readOnlyReason' => $reason,
    ]);
}

function changeTargetFacesSet(int $tourId): void
{
    $ctx = tfLoadContext($tourId);
    $enId = intval($_POST['enId'] ?? 0);
    $res = tfSetOne($tourId, $enId, (string)($_POST['group'] ?? ''), $ctx);
    if (!$res['error']) {
        $res['archer'] = tfLoadArcher($tourId, $enId, $ctx);
    }
    echo json_encode($res);
}

function changeTargetFacesSetAll(int $tourId): void
{
    $ctx = tfLoadContext($tourId);
    echo json_encode(tfSetAll($tourId, changeTargetFacesFilters(), (string)($_POST['group'] ?? ''), intval($_POST['expectedCount'] ?? -1), $ctx));
}
