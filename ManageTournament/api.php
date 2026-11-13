<?php

// LANEASSIST_MANAGETOURNAMENT_API_TEST_MODE lets a DB integration test load
// this file's functions (statusSnapshot() and friends) without running the
// real request plumbing below: config.php bootstraps a real HTTP session, and
// CheckTourSession()/checkFullACL() either exit() or die() outside a real
// IANSEO request context, which would abort the whole PHPUnit process. A
// test defines the constant and pre-loads the dependencies itself (see
// tests/StatusSnapshotIntegrationTest.php) before requiring this file. The
// constant is never defined in production, so normal dispatch is unchanged.
if (!defined('LANEASSIST_MANAGETOURNAMENT_API_TEST_MODE')) {
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
    require_once(dirname(__FILE__, 2) . '/Common/status-logic.php');
    require_once(dirname(__FILE__, 2) . '/Common/live-view-data.php');

    $action = $_REQUEST['action'] ?? 'statusSnapshot';
    if ($action === 'statusSnapshot') {
        statusSnapshot();
    } elseif ($action === 'applySessionDefaults') {
        laneAssistRequirePost();
        checkFullACL(AclCompetition, '', AclReadWrite, false);
        applySessionDefaults();
    } elseif ($action === 'pullClubLogo') {
        laneAssistRequirePost();
        checkFullACL(AclCompetition, '', AclReadWrite, false);
        pullClubLogo();
    } else {
        echo json_encode(['error' => 1, 'message' => 'Invalid action']);
    }
}

function statusSnapshot() {
    echo json_encode([
        'error' => 0,
        'stage' => statusTournamentStage(),
        'updatedAt' => date('c'),
        'items' => statusChecklistItems(),
        'finalsRows' => buildFinalsRows()['rows'],
    ]);
}

function statusTournamentStage() {
    $tourId = StrSafe_DB($_SESSION['TourId']);

    $firstQualSessionStart = null;
    $rs = safe_r_sql("SELECT MIN(SesDtStart) AS FirstStart FROM Session
        WHERE SesTournament=$tourId AND SesType='Q' AND SesDtStart<>'0000-00-00 00:00:00'");
    if ($row = safe_fetch($rs)) {
        $value = trim((string)($row->FirstStart ?? ''));
        if ($value !== '') {
            $firstQualSessionStart = $value;
        }
    }

    $anyFinalsConfigured = (bool)safe_fetch(safe_r_sql(
        "SELECT 1 FROM Events WHERE EvTournament=$tourId AND EvFinalFirstPhase>0 LIMIT 1"
    ));

    return laneAssistTournamentStage(
        date('Y-m-d H:i:s'),
        $firstQualSessionStart,
        finalsBracketsInitialized(),
        statusFinalsAreComplete(),
        $anyFinalsConfigured,
        statusQualificationIsComplete()
    );
}

function statusFinalsAreComplete() {
    return laneAssistFinalsAreComplete(allFinalMatchesSnapshot());
}

function statusQualificationIsComplete() {
    $sessions = GetSessions('Q');
    if (empty($sessions)) {
        return false;
    }
    foreach ($sessions as $session) {
        $progress = laneAssistQualificationProgress(qualificationSnapshot(intval($session->SesOrder)));
        if (empty($progress['complete'])) {
            return false;
        }
    }
    return true;
}

function statusChecklistItems() {
    global $CFG;
    $tourId = StrSafe_DB($_SESSION['TourId']);
    $rootDir = $CFG->ROOT_DIR;
    $items = [];

    // 1. Participants entered
    $participantCount = 0;
    if ($row = safe_fetch(safe_r_sql("SELECT COUNT(*) AS Cnt FROM Entries WHERE EnTournament=$tourId AND EnAthlete=1"))) {
        $participantCount = intval($row->Cnt);
    }
    $items[] = [
        'key' => 'participants',
        'severity' => $participantCount > 0 ? 'info' : 'danger',
        'title' => 'Participants entered',
        'detail' => $participantCount > 0
            ? "{$participantCount} participant" . ($participantCount === 1 ? '' : 's') . ' entered'
            : 'No participants entered yet',
        'link' => $rootDir . 'Partecipants/index.php',
        'fix' => null,
    ];

    // 2. Target assignment errors, per qualification session
    $assignments = [];
    // Qualifications has no tournament column of its own; QuId is Entries.EnId,
    // so tournament scoping (and the active-entrant filter) goes through the
    // Entries join, exactly as qualificationSnapshot() above does it.
    $asRs = safe_r_sql("SELECT qu.QuSession AS SessionOrder, qu.QuTarget AS Target, qu.QuLetter AS Letter
        FROM Qualifications qu
        INNER JOIN Entries e ON e.EnId=qu.QuId AND e.EnTournament=$tourId
        WHERE e.EnStatus<=1 AND e.EnAthlete=1 AND qu.QuSession<>0");
    while ($row = safe_fetch($asRs)) {
        $assignments[] = ['sessionOrder' => intval($row->SessionOrder), 'target' => (string)$row->Target, 'letter' => (string)$row->Letter];
    }
    foreach (laneAssistDetectUnassignedArchers($assignments) as $issue) {
        $items[] = [
            'key' => 'unassignedTargets_' . $issue['sessionOrder'],
            'severity' => 'warning',
            'title' => 'Target assignment errors',
            'detail' => "{$issue['count']} archers unassigned on target in session {$issue['sessionOrder']}",
            'link' => $rootDir . 'Modules/Custom/LaneAssist/ManageTargets/index.php?session=' . $issue['sessionOrder'],
            'fix' => null,
        ];
    }

    // 3. Per-event finals planning (individual / team / mixed, demand-gated),
    // split into three cards by issue type.
    $evRs = safe_r_sql("SELECT EvCode, EvEventName, EvTeamEvent, EvMixedTeam, EvFinalFirstPhase, EvNumQualified
        FROM Events WHERE EvTournament=$tourId");
    $finalsRows = buildFinalsRows()['rows'];
    $scheduledByEvent = [];
    foreach ($finalsRows as $row) {
        if (trim((string)$row['scheduledDate']) !== '') {
            $scheduledByEvent[$row['teamEvent'] . '|' . $row['event']] = true;
        }
    }
    // Floored at phase 2 (SemiFinal): phases 0 (Gold) and 1 (Bronze) are the
    // medal matches themselves, never a valid bracket-size suggestion, even
    // though getStandardPhases()'s PhLevel IN(0,-1) filter includes both.
    $standardCapacities = [];
    $phaseByCapacity = [];
    foreach (getStandardPhases() as $phase) {
        $phase = intval($phase);
        if ($phase < 2) {
            continue;
        }
        $capacity = numQualifiedByPhase($phase);
        $standardCapacities[] = $capacity;
        $phaseByCapacity[$capacity] = $phase;
    }

    $finalsPlanningEvents = [];
    while ($ev = safe_fetch($evRs)) {
        $teamEvent = intval($ev->EvTeamEvent);
        $mixedTeam = intval($ev->EvMixedTeam) === 1;
        $label = $teamEvent === 0 ? 'individual' : ($mixedTeam ? 'mixed' : 'team');
        $finalFirstPhase = intval($ev->EvFinalFirstPhase);
        $expectedSize = $finalFirstPhase > 0 ? numQualifiedByPhase($finalFirstPhase) : 0;
        $eventKey = $teamEvent . '|' . $ev->EvCode;
        $listPage = $teamEvent === 0
            ? 'Final/Individual/ListEvents.php'
            : 'Final/Team/ListEvents.php';

        $finalsPlanningEvents[] = [
            'code' => $ev->EvCode,
            'label' => $label,
            'teamEvent' => $teamEvent,
            'finalFirstPhase' => $finalFirstPhase,
            'rawEntrantCount' => getRawFinalistDemand($ev->EvCode, $teamEvent),
            'hasAnyScheduled' => !empty($scheduledByEvent[$eventKey]),
            'expectedSize' => $expectedSize,
            'standardCapacities' => $standardCapacities,
            'phaseByCapacity' => $phaseByCapacity,
            'listLink' => $rootDir . $listPage,
            'scheduleLink' => $rootDir . 'Modules/Custom/LaneAssist/ManageFinals/index.php',
        ];
    }

    $groupedFinalsPlanning = laneAssistGroupFinalsPlanningIssues($finalsPlanningEvents);
    if (!empty($groupedFinalsPlanning['bracketSizeWrong'])) {
        $count = count($groupedFinalsPlanning['bracketSizeWrong']);
        $items[] = [
            'key' => 'bracketSizeWrong',
            'severity' => 'warning',
            'title' => 'Bracket size wrong (individual)',
            'detail' => $count . ' bracket size issue' . ($count === 1 ? '' : 's'),
            'link' => null,
            'fix' => null,
            'rows' => $groupedFinalsPlanning['bracketSizeWrong'],
        ];
    }
    if (!empty($groupedFinalsPlanning['unscheduledFinals'])) {
        $count = count($groupedFinalsPlanning['unscheduledFinals']);
        $items[] = [
            'key' => 'unscheduledFinals',
            'severity' => 'warning',
            'title' => 'Unscheduled finals',
            'detail' => $count . ' event' . ($count === 1 ? '' : 's') . ' not scheduled yet',
            'link' => null,
            'fix' => null,
            'rows' => $groupedFinalsPlanning['unscheduledFinals'],
        ];
    }
    if (!empty($groupedFinalsPlanning['noBracketConfigured'])) {
        $count = count($groupedFinalsPlanning['noBracketConfigured']);
        $items[] = [
            'key' => 'noBracketConfigured',
            'severity' => 'warning',
            'title' => 'No bracket configured',
            'detail' => $count . ' event' . ($count === 1 ? '' : 's') . ' with entrants but no bracket configured',
            'link' => null,
            'fix' => null,
            'rows' => $groupedFinalsPlanning['noBracketConfigured'],
        ];
    }

    // 4. Finals validation errors (phase order / target conflicts)
    foreach (validateFinalRows($finalsRows) as $error) {
        $items[] = [
            'key' => 'finalsValidation_' . $error['type'] . '_' . md5($error['message']),
            'severity' => 'danger',
            'title' => 'Finals validation errors',
            'detail' => $error['message'],
            'link' => $rootDir . 'Modules/Custom/LaneAssist/ManageFinals/index.php',
            'fix' => null,
        ];
    }

    // 6. Sessions without times
    // Scoped to Qualification sessions only: Session's real primary key is
    // (SesTournament, SesOrder, SesType), so an Elimination/Final session can
    // legitimately share a SesOrder with a real, correctly-timed Qualification
    // session. An unscoped GetSessions() picked up that E/F row and flagged
    // the pair as "no time set" even though the Qualification session the
    // user actually sees has a real start time.
    // Multi-distance Qualification rounds built via the "kiss" simplified
    // session UI store their real per-distance start times in
    // DistanceInformation, not in Session.SesDtStart (see
    // laneAssistDetectSessionsWithoutTimes()'s doc comment) -- gather which
    // SesOrders have a real distance day so those sessions aren't flagged.
    $distanceSessionsWithTime = [];
    $distRs = safe_r_sql("SELECT DISTINCT DiSession FROM DistanceInformation
        WHERE DiTournament=$tourId AND DiType='Q' AND DiDay<>'0000-00-00'");
    while ($dist = safe_fetch($distRs)) {
        $distanceSessionsWithTime[intval($dist->DiSession)] = true;
    }

    $sessions = [];
    foreach (GetSessions('Q') as $session) {
        $sessions[] = [
            'sessionOrder' => intval($session->SesOrder),
            'sessionId' => (string)$session->Id,
            'dtStart' => (string)$session->SesDtStart,
            'hasDistanceTime' => !empty($distanceSessionsWithTime[intval($session->SesOrder)]),
        ];
    }
    $sessionIssues = laneAssistDetectSessionsWithoutTimes($sessions);
    if (!empty($sessionIssues)) {
        $orders = array_map(fn($issue) => $issue['sessionOrder'], $sessionIssues);
        sort($orders);
        $multiple = count($orders) > 1;
        $items[] = [
            'key' => 'sessionsWithoutTimes',
            'severity' => 'warning',
            'title' => 'Sessions without times',
            'detail' => 'Session' . ($multiple ? 's ' : ' ') . implode(', ', $orders) . ($multiple ? ' have' : ' has') . ' no time set',
            'link' => null,
            'fix' => [
                'action' => 'applySessionDefaults',
                'params' => ['sessionId' => array_map(fn($issue) => $issue['sessionId'], $sessionIssues)],
                'confirm' => 'Set ' . ($multiple ? 'these sessions' : 'this session') . " to the tournament's start date, 09:00-12:00",
            ],
        ];
    }

    // 7. Clubs missing logos
    $clubRs = safe_r_sql("SELECT DISTINCT co.CoCode AS Code FROM Entries e
        INNER JOIN Countries co ON co.CoId=e.EnCountry
        WHERE e.EnTournament=$tourId");
    // A logo is reusable if it exists globally (FlTournament=-1, FITA/IOC
    // codes only) or in any other tournament -- club logos only ever exist
    // as per-tournament rows uploaded at earlier events.
    $flagRs = safe_r_sql("SELECT FlCode, FlTournament=$tourId AS Here FROM Flags
        WHERE FlCode IN (SELECT CoCode FROM Countries WHERE CoTournament=$tourId)
            AND (FlTournament=$tourId OR FlJPG>'' OR FlSVG>'')");
    $tournamentFlags = [];
    $reusableFlags = [];
    while ($flag = safe_fetch($flagRs)) {
        if (intval($flag->Here)) {
            $tournamentFlags[(string)$flag->FlCode] = true;
        } else {
            $reusableFlags[(string)$flag->FlCode] = true;
        }
    }
    $clubIssues = [];
    while ($club = safe_fetch($clubRs)) {
        $code = (string)$club->Code;
        if ($code === '') {
            continue;
        }
        $classification = laneAssistClassifyClubLogo($code, isset($tournamentFlags[$code]), isset($reusableFlags[$code]));
        if ($classification === null) {
            continue;
        }
        $clubIssues[] = $classification;
    }
    $clubLogoGroups = laneAssistGroupClubLogoIssues($clubIssues);
    if (!empty($clubLogoGroups['fixable'])) {
        $fixable = $clubLogoGroups['fixable'];
        $items[] = [
            'key' => 'clubLogosFixable',
            'severity' => 'info',
            'title' => 'Clubs missing logos',
            'detail' => count($fixable) . ' club logo' . (count($fixable) === 1 ? '' : 's')
                . ' missing that can be found: ' . implode(', ', $fixable),
            'link' => null,
            'fix' => [
                'action' => 'pullClubLogo', 'params' => ['clubCode' => $fixable],
                'confirm' => "Copy " . (count($fixable) === 1 ? 'this logo' : 'these logos')
                    . " from another tournament's record of the same club code into this tournament",
            ],
        ];
    }
    if (!empty($clubLogoGroups['missing'])) {
        $items[] = [
            'key' => 'clubLogosMissing',
            'severity' => 'info',
            'title' => 'Clubs missing logos',
            'detail' => 'Clubs missing logos: ' . implode(', ', $clubLogoGroups['missing']),
            'link' => $rootDir . 'Tournament/Countries.php',
            'fix' => null,
        ];
    }

    return $items;
}

function applySessionDefaults() {
    if (IsBlocked(BIT_BLOCK_TOURDATA)) {
        echo json_encode(['error' => 1, 'message' => 'Tournament data is locked']);
        return;
    }
    // sessionId may be a single composite id or (for the collapsed "fix all"
    // button) an array of them -- jQuery's default $.ajax serialization
    // already sends an array value as sessionId[], which PHP parses back
    // into a real array here with no client-side change needed.
    $sessionIds = $_POST['sessionId'] ?? '';
    if (!is_array($sessionIds)) {
        $sessionIds = [$sessionIds];
    }
    $applied = 0;
    foreach ($sessionIds as $sessionId) {
        $sessionId = trim((string)$sessionId);
        if ($sessionId === '' || !preg_match('/^\d+_[QEF]$/', $sessionId)) {
            continue;
        }
        if (applySessionDefaultsForTest($sessionId)) {
            $applied++;
        }
    }
    if ($applied === 0) {
        echo json_encode(['error' => 1, 'message' => 'No sessions were updated']);
        return;
    }
    echo json_encode(['error' => 0, 'message' => "{$applied} session(s) updated"]);
}

// $sessionId is the SesOrder_SesType composite string GetSessions() synthesizes
// as its Id (e.g. "1_Q") -- Session has no SesId column; its real primary key
// is (SesTournament, SesOrder, SesType).
function applySessionDefaultsForTest($sessionId) {
    if (!preg_match('/^(\d+)_([QEF])$/', (string)$sessionId, $m)) {
        return false;
    }
    $sessionOrder = StrSafe_DB(intval($m[1]));
    $sessionType = StrSafe_DB($m[2]);
    $tourId = StrSafe_DB($_SESSION['TourId']);

    $tourRow = safe_fetch(safe_r_sql("SELECT ToWhenFrom FROM Tournament WHERE ToId=$tourId"));
    if (!$tourRow) {
        return false;
    }
    $whenFrom = trim((string)$tourRow->ToWhenFrom);
    if ($whenFrom === '' || $whenFrom === '0000-00-00') {
        return false;
    }

    $sessionRow = safe_fetch(safe_r_sql("SELECT SesOrder FROM Session
        WHERE SesTournament=$tourId AND SesOrder=$sessionOrder AND SesType=$sessionType"));
    if (!$sessionRow) {
        return false;
    }

    $startValue = StrSafe_DB($whenFrom . ' 09:00:00');
    $endValue = StrSafe_DB($whenFrom . ' 12:00:00');
    safe_w_sql("UPDATE Session SET SesDtStart=$startValue, SesDtEnd=$endValue
        WHERE SesTournament=$tourId AND SesOrder=$sessionOrder AND SesType=$sessionType");
    return true;
}

function pullClubLogo() {
    if (IsBlocked(BIT_BLOCK_TOURDATA)) {
        echo json_encode(['error' => 1, 'message' => 'Tournament data is locked']);
        return;
    }
    // clubCode may be a single code or (for the collapsed "fix all" button)
    // an array of them -- see the matching comment on applySessionDefaults().
    $clubCodes = $_POST['clubCode'] ?? '';
    if (!is_array($clubCodes)) {
        $clubCodes = [$clubCodes];
    }
    $applied = 0;
    foreach ($clubCodes as $clubCode) {
        $clubCode = trim((string)$clubCode);
        if ($clubCode === '') {
            continue;
        }
        pullClubLogoForTest($clubCode);
        $applied++;
    }
    if ($applied === 0) {
        echo json_encode(['error' => 1, 'message' => 'Invalid club code']);
        return;
    }
    echo json_encode(['error' => 0, 'message' => "{$applied} logo(s) linked"]);
}

function pullClubLogoForTest($clubCode) {
    $tourId = StrSafe_DB($_SESSION['TourId']);
    $clubCode = StrSafe_DB($clubCode);

    // FlIocCode is part of Flags' real composite primary key
    // (FlTournament, FlIocCode, FlCode) and every core logo lookup joins on
    // FlIocCode='FITA' (the hard convention for global rows -- see
    // UpdateDb-2011.inc.php); omitting it from the column list would leave
    // the copied row with the NOT-NULL varchar's empty-string default,
    // invisible to every one of those lookups. FlContAssoc is carried over
    // for the same reason (NOT-NULL, no default) even though it isn't part
    // of a join condition anywhere found.
    //
    // Source: the global row if there is one, otherwise the most recently
    // entered copy from another tournament (where club logos actually live).
    safe_w_sql("INSERT IGNORE INTO Flags (FlCode, FlTournament, FlIocCode, FlJPG, FlSVG, FlContAssoc)
        SELECT FlCode, $tourId, FlIocCode, FlJPG, FlSVG, FlContAssoc FROM Flags
        WHERE FlCode=$clubCode AND FlTournament<>$tourId AND (FlJPG>'' OR FlSVG>'')
        ORDER BY FlTournament=-1 DESC, FlEntered DESC LIMIT 1");
}
