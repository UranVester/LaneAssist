<?php

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

$action = $_REQUEST['action'] ?? 'snapshot';
if ($action === 'advance') {
    laneAssistRequirePost();
    advanceLiveMatch();
} elseif ($action === 'snapshot') {
    liveSnapshot();
} else {
    echo json_encode(['error' => 1, 'message' => 'Invalid action']);
}

function qualificationSnapshot($session) {
    $distanceInfo = [];
    $distanceRs = safe_r_sql("SELECT DiDistance, DiEnds, DiArrows
        FROM DistanceInformation
        WHERE DiTournament=" . StrSafe_DB($_SESSION['TourId']) . "
          AND DiSession=" . StrSafe_DB($session) . " AND DiType='Q'");
    while ($distance = safe_fetch($distanceRs)) {
        $distanceInfo[intval($distance->DiDistance)] = [
            'ends' => max(0, intval($distance->DiEnds)),
            'arrows' => max(1, intval($distance->DiArrows)),
        ];
    }

    $sql = "SELECT EnId, EnFirstName, EnName, CoCode, QuTarget, QuLetter, QuScore,
            QuD1Arrowstring, QuD2Arrowstring, QuD3Arrowstring, QuD4Arrowstring,
            QuD5Arrowstring, QuD6Arrowstring, QuD7Arrowstring, QuD8Arrowstring
        FROM Qualifications
        INNER JOIN Entries ON EnId=QuId AND EnTournament=" . StrSafe_DB($_SESSION['TourId']) . "
        LEFT JOIN Countries ON CoId=EnCountry
        WHERE QuSession=" . StrSafe_DB($session) . " AND EnAthlete=1 AND EnStatus<=1
          AND QuTarget<>'' AND QuTarget<>'0'
        ORDER BY QuTarget, QuLetter";
    $rs = safe_r_sql($sql);
    $mats = [];
    while ($row = safe_fetch($rs)) {
        $latestDistance = 0;
        $arrowString = '';
        $arrowsShot = 0;
        $completedTotalEnds = 0;
        $totalArrows = 0;
        $totalEnds = 0;
        for ($distance = 1; $distance <= 8; $distance++) {
            $field = 'QuD' . $distance . 'Arrowstring';
            $distanceArrowString = (string)$row->{$field};
            if (strlen(rtrim($distanceArrowString)) > 0) {
                $latestDistance = $distance;
                $arrowString = $distanceArrowString;
            }
            if (isset($distanceInfo[$distance])) {
                $distanceArrows = $distanceInfo[$distance]['arrows'];
                $shot = strlen(str_replace(' ', '', rtrim($distanceArrowString)));
                $arrowsShot += $shot;
                $completedTotalEnds += intdiv($shot, $distanceArrows);
                $totalEnds += $distanceInfo[$distance]['ends'];
                $totalArrows += $distanceInfo[$distance]['ends'] * $distanceArrows;
            }
        }
        $arrowsPerEnd = $distanceInfo[$latestDistance]['arrows'] ?? ($distanceInfo[1]['arrows'] ?? 3);
        $target = strtoupper(trim((string)$row->QuTarget));
        if (!isset($mats[$target])) {
            $mats[$target] = ['target' => ltrim($target, '0') ?: '0', 'archers' => []];
        }
        $mats[$target]['archers'][] = [
            'participantId' => intval($row->EnId),
            'name' => trim((string)$row->EnFirstName . ' ' . (string)$row->EnName),
            'club' => trim((string)$row->CoCode),
            'position' => trim((string)$row->QuLetter),
            'completedEnds' => laneAssistCompletedEnds($arrowString, $arrowsPerEnd),
            'completedTotalEnds' => $completedTotalEnds,
            'totalEnds' => $totalEnds,
            'arrowsShot' => $arrowsShot,
            'totalArrows' => $totalArrows,
            'lastEndPoints' => laneAssistLastEndPoints($arrowString, $arrowsPerEnd, function($arrow) {
                return DecodeFromLetter($arrow);
            }),
            'totalPoints' => intval($row->QuScore),
        ];
    }

    $allArchers = [];
    foreach ($mats as $mat) {
        $allArchers = array_merge($allArchers, $mat['archers']);
    }
    $pace = laneAssistMarkQualificationLag($allArchers);
    $paceByParticipant = [];
    foreach ($pace['archers'] as $archer) {
        $paceByParticipant[$archer['participantId']] = $archer;
    }
    foreach ($mats as &$mat) {
        $mat['expectedEnds'] = $pace['expectedEnds'];
        foreach ($mat['archers'] as &$archer) {
            $archer = $paceByParticipant[$archer['participantId']];
        }
        unset($archer);
    }
    unset($mat);
    return array_values($mats);
}

function loadFinalSides($teamEvent) {
    if ($teamEvent) {
        $sql = "SELECT tf.TfEvent EventCode, ev.EvEventName EventName, tf.TfMatchNo MatchNo,
                tf.TfTeam ParticipantId, CONCAT(co.CoName, IF(tf.TfSubTeam>1, CONCAT(' (', tf.TfSubTeam, ')'), '')) ParticipantName,
                tf.TfScore Score, tf.TfSetScore SetScore, tf.TfArrowstring ArrowString,
                tf.TfWinLose WinLose, tf.TfTie Tie, gr.GrPhase Phase, fs.FSTarget Target,
                fs.FSScheduledDate ScheduledDate, fs.FSScheduledTime ScheduledTime,
                ev.EvMatchMode MatchMode,
                IF((gr.GrPhase & ev.EvMatchArrowsNo), ev.EvElimArrows, ev.EvFinArrows) ArrowsPerEnd,
                IF((gr.GrPhase & ev.EvMatchArrowsNo), ev.EvElimEnds, ev.EvFinEnds) TotalEnds
            FROM TeamFinals tf
            INNER JOIN Events ev ON ev.EvTournament=tf.TfTournament AND ev.EvCode=tf.TfEvent AND ev.EvTeamEvent=1
            INNER JOIN Grids gr ON gr.GrMatchNo=tf.TfMatchNo
            LEFT JOIN Countries co ON co.CoId=tf.TfTeam
            LEFT JOIN FinSchedule fs ON fs.FSTournament=tf.TfTournament AND fs.FSTeamEvent=1 AND fs.FSEvent=tf.TfEvent AND fs.FSMatchNo=tf.TfMatchNo
                        WHERE tf.TfTournament=" . StrSafe_DB($_SESSION['TourId']) . "
              AND EXISTS (
                  SELECT 1 FROM TeamFinals participantTf
                  WHERE participantTf.TfTournament=tf.TfTournament AND participantTf.TfEvent=tf.TfEvent
                    AND FLOOR(participantTf.TfMatchNo/2)=FLOOR(tf.TfMatchNo/2)
                    AND participantTf.TfTeam>0
              )
            ORDER BY fs.FSTarget, tf.TfEvent, tf.TfMatchNo";
    } else {
        $sql = "SELECT fin.FinEvent EventCode, ev.EvEventName EventName, fin.FinMatchNo MatchNo,
                fin.FinAthlete ParticipantId, CONCAT(en.EnFirstName, ' ', en.EnName) ParticipantName,
                fin.FinScore Score, fin.FinSetScore SetScore, fin.FinArrowstring ArrowString,
                fin.FinWinLose WinLose, fin.FinTie Tie, gr.GrPhase Phase, fs.FSTarget Target,
                fs.FSScheduledDate ScheduledDate, fs.FSScheduledTime ScheduledTime,
                ev.EvMatchMode MatchMode,
                IF((gr.GrPhase & ev.EvMatchArrowsNo), ev.EvElimArrows, ev.EvFinArrows) ArrowsPerEnd,
                IF((gr.GrPhase & ev.EvMatchArrowsNo), ev.EvElimEnds, ev.EvFinEnds) TotalEnds
            FROM Finals fin
            INNER JOIN Events ev ON ev.EvTournament=fin.FinTournament AND ev.EvCode=fin.FinEvent AND ev.EvTeamEvent=0
            INNER JOIN Grids gr ON gr.GrMatchNo=fin.FinMatchNo
            LEFT JOIN Entries en ON en.EnId=fin.FinAthlete
            LEFT JOIN FinSchedule fs ON fs.FSTournament=fin.FinTournament AND fs.FSTeamEvent=0 AND fs.FSEvent=fin.FinEvent AND fs.FSMatchNo=fin.FinMatchNo
                        WHERE fin.FinTournament=" . StrSafe_DB($_SESSION['TourId']) . "
              AND EXISTS (
                  SELECT 1 FROM Finals participantFin
                  WHERE participantFin.FinTournament=fin.FinTournament AND participantFin.FinEvent=fin.FinEvent
                    AND FLOOR(participantFin.FinMatchNo/2)=FLOOR(fin.FinMatchNo/2)
                    AND participantFin.FinAthlete>0
              )
            ORDER BY fs.FSTarget, fin.FinEvent, fin.FinMatchNo";
    }

    $matches = [];
    $rs = safe_r_sql($sql);
    while ($row = safe_fetch($rs)) {
        $baseMatch = intval($row->MatchNo) - (intval($row->MatchNo) % 2);
        $key = intval($teamEvent) . '|' . $row->EventCode . '|' . $baseMatch;
        if (!isset($matches[$key])) {
            $matches[$key] = [
                'teamEvent' => intval($teamEvent), 'event' => (string)$row->EventCode,
                'eventName' => (string)$row->EventName, 'matchNo' => $baseMatch,
                'phase' => intval($row->Phase), 'target' => ltrim((string)$row->Target, '0'),
                'scheduledSlot' => '',
                'matchMode' => intval($row->MatchMode),
                'arrowsPerEnd' => max(1, intval($row->ArrowsPerEnd)),
                'totalEnds' => max(1, intval($row->TotalEnds)), 'sides' => [],
            ];
        }
        if (trim((string)$row->Target) !== '') {
            $matches[$key]['target'] = ltrim((string)$row->Target, '0') ?: '0';
        }
        $scheduledDate = trim((string)$row->ScheduledDate);
        $scheduledTime = trim((string)$row->ScheduledTime);
        if ($scheduledDate !== '' && $scheduledDate !== '0000-00-00') {
            $matches[$key]['scheduledSlot'] = $scheduledDate . ' ' . ($scheduledTime ?: '00:00:00');
        }
        $matches[$key]['sides'][] = [
            'matchNo' => intval($row->MatchNo),
            'participantId' => intval($row->ParticipantId), 'name' => trim((string)$row->ParticipantName),
            'score' => intval($row->Score), 'setScore' => intval($row->SetScore),
            'setPoints' => laneAssistFinalSetPoints($row->ArrowString, $row->ArrowsPerEnd, function($arrow) {
                return DecodeFromLetter($arrow);
            }),
            'arrowString' => rtrim((string)$row->ArrowString), 'winLose' => intval($row->WinLose),
            'tie' => intval($row->Tie),
        ];
    }
    return $matches;
}

function loadFinalParticipantIndex($teamEvent) {
    $table = $teamEvent ? 'TeamFinals' : 'Finals';
    $tournamentField = $teamEvent ? 'TfTournament' : 'FinTournament';
    $eventField = $teamEvent ? 'TfEvent' : 'FinEvent';
    $matchField = $teamEvent ? 'TfMatchNo' : 'FinMatchNo';
    $participantField = $teamEvent ? 'TfTeam' : 'FinAthlete';
    $sql = "SELECT $eventField EventCode, $matchField MatchNo, $participantField ParticipantId
        FROM $table WHERE $tournamentField=" . StrSafe_DB($_SESSION['TourId']);
    $index = [];
    $rs = safe_r_sql($sql);
    while ($row = safe_fetch($rs)) {
        $index[(string)$row->EventCode][intval($row->MatchNo)] = intval($row->ParticipantId);
    }
    return $index;
}

function allFinalMatchesSnapshot() {
    $matches = array_merge(loadFinalSides(0), loadFinalSides(1));
    $participantIndexes = [loadFinalParticipantIndex(0), loadFinalParticipantIndex(1)];
    $visibleMatches = [];
    foreach ($matches as &$match) {
        while (count($match['sides']) < 2) {
            $match['sides'][] = ['matchNo' => $match['matchNo'] + count($match['sides']), 'participantId' => 0, 'name' => '', 'score' => 0, 'setScore' => 0, 'arrowString' => '', 'winLose' => 0, 'tie' => 0];
        }
        $participantCount = count(array_filter($match['sides'], function($side) {
            return !empty($side['participantId']);
        }));
        if ($participantCount === 0) {
            continue;
        }
        $match['status'] = laneAssistFinalMatchStatus($match['sides'], $match['arrowsPerEnd']);
        foreach ($match['sides'] as &$side) {
            $side['completedEnds'] = laneAssistCompletedEnds($side['arrowString'], $match['arrowsPerEnd']);
        }
        unset($side);
        $eventParticipants = $participantIndexes[$match['teamEvent']][$match['event']] ?? [];
        $match['advanced'] = laneAssistFinalMatchAdvanced($match, $eventParticipants);
        $hasDestination = laneAssistFinalWinnerDestination($match['phase'], $match['matchNo']) !== null;
        $match['canMarkBye'] = laneAssistFinalMatchCanMarkBye($match);
        $match['canAdvance'] = !$match['advanced'] && $hasDestination && in_array($match['status'], ['bye', 'complete'], true);
        if ($match['advanced']) {
            $match['status'] = 'advanced';
        }
        $visibleMatches[] = $match;
    }
    unset($match);
    return $visibleMatches;
}

function currentFinalBlockSnapshot() {
    return laneAssistSelectCurrentFinalMatches(allFinalMatchesSnapshot());
}

function finalsSnapshot() {
    $block = currentFinalBlockSnapshot();
    return $block['matches'];
}

function finalsBracketsInitialized() {
    $tourId = StrSafe_DB($_SESSION['TourId']);
    $individual = safe_fetch(safe_r_sql("SELECT 1 Initialized FROM Finals WHERE FinTournament=$tourId LIMIT 1"));
    if ($individual) {
        return true;
    }
    return (bool)safe_fetch(safe_r_sql("SELECT 1 Initialized FROM TeamFinals WHERE TfTournament=$tourId LIMIT 1"));
}

function liveSnapshot() {
    $session = max(1, intval($_REQUEST['session'] ?? 1));
    $qualification = qualificationSnapshot($session);
    $finalBlock = currentFinalBlockSnapshot();
    echo json_encode([
        'error' => 0, 'session' => $session,
        'qualification' => $qualification,
        'qualificationProgress' => laneAssistQualificationProgress($qualification),
        'finals' => $finalBlock['matches'], 'finalsSlot' => $finalBlock['slot'],
        'finalsProgress' => laneAssistFinalsProgress($finalBlock['matches']),
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
    foreach (finalsSnapshot() as $match) {
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
