<?php
/**
 * Change finals events API: preview and apply a rebuild of the selected
 * tournament's individual final events. Team events are never touched.
 *
 * Gate: the same one IANSEO uses for adding/deleting events (competition
 * data, read-write), and writes are refused while tournament data is
 * blocked. Not admin/debug-gated.
 */

require_once(dirname(__FILE__, 3) . '/config.php');
require_once(dirname(__FILE__, 2) . '/Common/i18n.php');
header('Content-Type: application/json');

if (!CheckTourSession()) {
    echo json_encode(['error' => 1, 'message' => get_text('CrackError')]);
    exit;
}

checkFullACL(AclCompetition, 'cData', AclReadWrite, false);

require_once(dirname(__FILE__, 2) . '/Common/csrf.php');
// Pulls in Common/events-logic.php, Common/finals-logic.php and the core
// helpers it calls (Common/Lib/Fun_Phases.inc.php, Modules/Sets/lib.php),
// each guarded so a core file that already loaded them is not re-included.
require_once(dirname(__FILE__, 2) . '/Common/events-rebuild.php');
// MakeIndAbs() for re-ranking qualification into the new events.
require_once(dirname(__FILE__, 5) . '/Qualification/Fun_Qualification.local.inc.php');

$action = $_REQUEST['action'] ?? '';

if ($action === 'apply') {
    laneAssistRequirePost();
}

switch ($action) {
    case 'preview':
        changeEventsPreview();
        break;
    case 'apply':
        changeEventsApply();
        break;
    default:
        echo json_encode(['error' => 1, 'message' => 'Invalid action']);
}

/** The four grouping criteria from the request (0/1 each), un-normalised. */
function changeEventsRequestedCriteria(): array
{
    $criteria = [];
    foreach (['gender', 'class', 'division', 'distance'] as $key) {
        $criteria[$key] = !empty($_REQUEST[$key]);
    }
    return $criteria;
}

/** A cePreview() result as JSON-safe output: everything but the full plan. */
function changeEventsPreviewPayload(array $preview): array
{
    unset($preview['plan']);
    $preview['blocked'] = IsBlocked(BIT_BLOCK_TOURDATA);
    return $preview;
}

function changeEventsPreview()
{
    $tourId = intval($_SESSION['TourId']);

    try {
        $preview = cePreview($tourId, changeEventsRequestedCriteria());
    } catch (Throwable $e) {
        echo json_encode(['error' => 1, 'message' => 'Preview failed: ' . $e->getMessage()]);
        return;
    }

    echo json_encode(['error' => 0] + changeEventsPreviewPayload($preview));
}

function changeEventsApply()
{
    $tourId = intval($_SESSION['TourId']);

    if (IsBlocked(BIT_BLOCK_TOURDATA)) {
        echo json_encode(['error' => 1, 'message' => la_t('events.blocked')]);
        return;
    }

    $criteria = changeEventsRequestedCriteria();
    $fingerprint = (string) ($_POST['fingerprint'] ?? '');
    if ($fingerprint === '') {
        echo json_encode(['error' => 1, 'message' => la_t('events.previewFirst')]);
        return;
    }

    try {
        // ceGuardedApply() also refuses, with nothing deleted, a plan that
        // would leave the tournament with no individual events at all.
        $result = ceGuardedApply($tourId, $criteria, $fingerprint);
    } catch (Throwable $e) {
        echo json_encode(['error' => 1, 'message' => 'Replacing events failed, nothing was changed: ' . $e->getMessage()]);
        return;
    }

    if (!empty($result['stale'])) {
        echo json_encode([
            'error' => 1,
            'stale' => true,
            'message' => la_t('events.stale'),
            'preview' => changeEventsPreviewPayload($result['preview']),
        ]);
        return;
    }

    if (!empty($result['error'])) {
        echo json_encode(['error' => 1, 'message' => (string) ($result['message'] ?? 'Replacing events failed, nothing was changed.')]);
        return;
    }

    $response = [
        'error' => 0,
        'created' => (int) $result['created'],
        'deleted' => (int) $result['deleted'],
        'placed' => (int) $result['placed'],
    ];
    if (!changeEventsRebuildRanking()) {
        $response['warning'] = la_t('events.rankingNotRebuilt');
    }

    echo json_encode($response);
}

/**
 * After commit: rank qualification into the new events, then refresh the
 * session's menu flags (finals/eliminations on, events to shoot off).
 * The events are already replaced, so a failure here must not turn the
 * response into an error: it returns false (-> a warning) when either call
 * throws, prints anything (which would also corrupt the JSON), or
 * MakeIndAbs() reports an error (it returns 0 on success, 1 on failure).
 * A failed query inside them goes through safe_error(), which exit()s and
 * cannot be caught here.
 */
function changeEventsRebuildRanking(): bool
{
    ob_start();
    try {
        $rankError = MakeIndAbs();
        set_qual_session_flags();
        $ok = empty($rankError);
    } catch (Throwable $e) {
        $ok = false;
    } finally {
        $output = ob_get_contents();
        ob_end_clean();
    }

    return $ok && ($output === '' || $output === false);
}
