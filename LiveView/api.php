<?php

// LANEASSIST_LIVEVIEW_API_TEST_MODE lets a DB integration test load this
// file's functions without running the real request plumbing below:
// config.php bootstraps a real HTTP session, and CheckTourSession()/
// checkFullACL() either exit() or die() outside a real IANSEO request
// context. Same pattern as ManageTournament/api.php (see
// tests/StatusSnapshotIntegrationTest.php). Never defined in production.
if (!defined('LANEASSIST_LIVEVIEW_API_TEST_MODE')) {
    require_once(dirname(__FILE__, 3) . '/config.php');
    header('Content-Type: application/json');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    if (!CheckTourSession()) {
        echo json_encode(['error' => 1, 'message' => get_text('CrackError')]);
        exit;
    }

    checkFullACL(AclCompetition, '', AclReadOnly, false);
    require_once('Common/Lib/ArrTargets.inc.php');
    require_once(dirname(__FILE__, 2) . '/Common/csrf.php');
    require_once(dirname(__FILE__, 2) . '/Common/live-view-logic.php');
    require_once(dirname(__FILE__, 2) . '/Common/badge-providers.php');
    require_once('Common/Lib/Fun_Phases.inc.php');
    require_once('Common/Fun_Sessions.inc.php');
    require_once(dirname(__FILE__, 2) . '/Common/finals-logic.php');
    require_once(dirname(__FILE__, 2) . '/Common/live-view-data.php');

    $action = $_REQUEST['action'] ?? 'snapshot';
    if ($action === 'advance') {
        laneAssistRequirePost();
        advanceLiveMatch();
    } elseif ($action === 'toggleArcherRetired') {
        laneAssistRequirePost();
        toggleArcherRetired();
    } elseif ($action === 'toggleArcherRetiredBulk') {
        laneAssistRequirePost();
        toggleArcherRetiredBulk();
    } elseif ($action === 'snapshot') {
        liveSnapshot();
    } else {
        echo json_encode(['error' => 1, 'message' => 'Invalid action']);
    }
}

function liveSnapshot() {
    $session = max(1, intval($_REQUEST['session'] ?? 1));
    $qualification = qualificationSnapshot($session);
    $finalRounds = laneAssistGroupFinalRounds(allFinalMatchesSnapshot());
    require_once(dirname(__FILE__, 2) . '/Common/finals-print-data.php');
    foreach ($finalRounds as &$round) {
        $readiness = ($round['slot'] ?? '') !== '' ? fpSlotReadiness($round['slot']) : ['ready' => false, 'waiting' => 0];
        $round['printReady'] = $readiness['ready'];
        $round['printWaiting'] = $readiness['waiting'];
    }
    unset($round);
    $currentRoundMatches = [];
    foreach ($finalRounds as $round) {
        if ($round['isCurrent']) {
            $currentRoundMatches = $round['matches'];
            break;
        }
    }
    echo json_encode([
        'error' => 0, 'session' => $session,
        'qualification' => $qualification,
        'qualificationProgress' => laneAssistAttachDistanceProgress(laneAssistQualificationProgress($qualification), $qualification),
        'finalsRounds' => $finalRounds,
        'finalsProgress' => laneAssistFinalsProgress($currentRoundMatches),
        'finalsInitialized' => finalsBracketsInitialized(),
        'updatedAt' => date('c'),
    ]);
}

function advanceLiveMatch() {
    $teamEvent = intval($_POST['teamEvent'] ?? 0);
    $event = trim((string)($_POST['event'] ?? ''));
    $matchNo = intval($_POST['matchNo'] ?? -1);
    if ($event === '' || $matchNo < 0 || IsBlocked($teamEvent ? BIT_BLOCK_TEAM : BIT_BLOCK_IND)) {
        echo json_encode(['error' => 1, 'message' => 'This match cannot be advanced']);
        return;
    }

    $eligibleMatch = null;
    foreach (allFinalMatchesSnapshot() as $match) {
        if ($match['teamEvent'] === $teamEvent && $match['event'] === $event && $match['matchNo'] === $matchNo) {
            $eligibleMatch = $match;
            break;
        }
    }
    if (!$eligibleMatch || (empty($eligibleMatch['canAdvance']) && empty($eligibleMatch['canMarkBye']))) {
        echo json_encode(['error' => 1, 'message' => 'The match is not complete and cannot be advanced']);
        return;
    }

    checkFullACL($teamEvent ? AclTeams : AclIndividuals, '', AclReadWrite, false);
    require_once('Final/Fun_ChangePhase.inc.php');
    if ($eligibleMatch['status'] === 'bye') {
        if (!markLiveMatchBye($eligibleMatch)) {
            echo json_encode(['error' => 1, 'message' => 'The bye could not be marked complete']);
            return;
        }
        if (empty($eligibleMatch['canAdvance'])) {
            echo json_encode(['error' => 0, 'message' => 'Bye marked complete', 'advanced' => false]);
            return;
        }
    }
    if ($teamEvent) {
        move2NextPhaseTeam(null, $event, $matchNo, intval($_SESSION['TourId']), true);
    } else {
        move2NextPhase(null, $event, $matchNo, intval($_SESSION['TourId']), true);
    }

    $participantIndex = loadFinalParticipantIndex($teamEvent);
    $eventParticipants = $participantIndex[$event] ?? [];
    $advanced = laneAssistFinalMatchAdvanced($eligibleMatch, $eventParticipants);
    if (!$advanced) {
        echo json_encode(['error' => 1, 'message' => 'IANSEO did not propagate this winner. Check that the result has a clear winner.']);
        return;
    }
    echo json_encode(['error' => 0, 'message' => 'Match advanced', 'advanced' => true]);
}

function markLiveMatchBye(array $match) {
    $teamEvent = intval($match['teamEvent'] ?? 0);
    $event = trim((string)($match['event'] ?? ''));
    $baseMatch = intval($match['matchNo'] ?? -1);
    $winnerMatch = laneAssistByeWinnerMatchNo($match);
    if ($event === '' || $baseMatch < 0 || $winnerMatch === null) {
        return false;
    }

    $table = $teamEvent ? 'TeamFinals' : 'Finals';
    $tournamentField = $teamEvent ? 'TfTournament' : 'FinTournament';
    $eventField = $teamEvent ? 'TfEvent' : 'FinEvent';
    $matchField = $teamEvent ? 'TfMatchNo' : 'FinMatchNo';
    $tieField = $teamEvent ? 'TfTie' : 'FinTie';
    $winField = $teamEvent ? 'TfWinLose' : 'FinWinLose';
    $closestField = $teamEvent ? 'TfTbClosest' : 'FinTbClosest';
    $irmField = $teamEvent ? 'TfIrmType' : 'FinIrmType';
    $dateField = $teamEvent ? 'TfDateTime' : 'FinDateTime';
    $tourId = intval($_SESSION['TourId']);
    $pairMatches = $baseMatch . ',' . ($baseMatch + 1);
    $now = StrSafe_DB(date('Y-m-d H:i:s'));

    safe_w_sql("UPDATE $table SET $tieField=0, $winField=0, $closestField=0, $dateField=$now
        WHERE $tournamentField=$tourId AND $eventField=" . StrSafe_DB($event) . " AND $matchField IN ($pairMatches)");
    safe_w_sql("UPDATE $table SET $tieField=2, $winField=1, $closestField=0, $irmField=0, $dateField=$now
        WHERE $tournamentField=$tourId AND $eventField=" . StrSafe_DB($event) . " AND $matchField=" . intval($winnerMatch));
    return true;
}

function recalcRanksAndTeamsAfterRetireToggle() {
    require_once('Qualification/Fun_Qualification.local.inc.php');
    for ($i = 0; $i <= 8; $i++) {
        CalcRank($i);
    }
    MakeTeams(NULL, NULL);
    MakeTeamsAbs(NULL, null, null);
}

/**
 * Toggle a single archer between "Pull Out" and their prior status.
 */
function toggleArcherRetired() {
    $enId = intval($_POST['participantId'] ?? 0);
    $session = max(1, intval($_POST['session'] ?? 0));
    if ($enId <= 0 || $session <= 0) {
        echo json_encode(['error' => 1, 'message' => 'Invalid participant']);
        return;
    }

    $tourId = intval($_SESSION['TourId']);
    if (IsBlocked(BIT_BLOCK_QUAL)) {
        echo json_encode(['error' => 1, 'message' => 'Qualification results are locked']);
        return;
    }

    checkFullACL(AclQualification, '', AclReadWrite, false);

    $result = performArcherRetireToggle($enId, $session, $tourId);
    if (!$result['ok']) {
        echo json_encode(['error' => 1, 'message' => $result['message']]);
        return;
    }

    recalcRanksAndTeamsAfterRetireToggle();

    echo json_encode(['error' => 0, 'retired' => $result['retired'], 'participantId' => $enId]);
}

/**
 * Toggle a batch of archers between "Pull Out" and their prior status in one
 * request, running the tournament-wide rank/team recalculation once for the
 * whole batch instead of once per archer.
 */
function toggleArcherRetiredBulk() {
    $session = max(1, intval($_POST['session'] ?? 0));
    $participantIds = array_unique(array_filter(array_map('intval', (array)($_POST['participantIds'] ?? []))));
    if ($session <= 0 || !count($participantIds)) {
        echo json_encode(['error' => 1, 'message' => 'Invalid participants']);
        return;
    }

    $tourId = intval($_SESSION['TourId']);
    if (IsBlocked(BIT_BLOCK_QUAL)) {
        echo json_encode(['error' => 1, 'message' => 'Qualification results are locked']);
        return;
    }

    checkFullACL(AclQualification, '', AclReadWrite, false);

    $results = [];
    $succeeded = 0;
    foreach ($participantIds as $enId) {
        $result = performArcherRetireToggle($enId, $session, $tourId);
        $results[] = $result;
        if ($result['ok']) {
            $succeeded++;
        }
    }

    if ($succeeded > 0) {
        recalcRanksAndTeamsAfterRetireToggle();
    }

    echo json_encode([
        'error' => 0,
        'results' => $results,
        'succeeded' => $succeeded,
        'failed' => count($results) - $succeeded,
    ]);
}
