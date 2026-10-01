<?php

// LANEASSIST_LIVEVIEW_API_TEST_MODE lets a DB integration test load this
// file's functions (statusSnapshot() and friends) without running the real
// request plumbing below: config.php bootstraps a real HTTP session, and
// CheckTourSession()/checkFullACL() either exit() or die() outside a real
// IANSEO request context, which would abort the whole PHPUnit process. A
// test defines the constant and pre-loads the dependencies itself (see
// tests/StatusSnapshotIntegrationTest.php) before requiring this file. The
// constant is never defined in production, so normal dispatch is unchanged.
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
    require_once(dirname(__FILE__, 2) . '/Common/status-logic.php');

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
    } elseif ($action === 'statusSnapshot') {
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

function qualificationSnapshot($session) {
    global $CFG;

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

    $sql = "SELECT EnId, EnFirstName, EnName, CoCode, EnClass, EnDivision, EnStatus,
            QuTarget, QuLetter, QuScore, EnTargetFace, ev.EvCode as EventCode,
            tf.TfT1 as TargetFaceId, tf.TfW1 as TargetDiameter,
            QuD1Arrowstring, QuD2Arrowstring, QuD3Arrowstring, QuD4Arrowstring,
            QuD5Arrowstring, QuD6Arrowstring, QuD7Arrowstring, QuD8Arrowstring
        FROM Qualifications
        INNER JOIN Entries ON EnId=QuId AND EnTournament=" . StrSafe_DB($_SESSION['TourId']) . "
        LEFT JOIN Countries ON CoId=EnCountry
        LEFT JOIN TargetFaces tf ON EnTournament = tf.TfTournament AND EnTargetFace = tf.TfId
        LEFT JOIN EventClass ec ON ec.EcTournament=EnTournament AND ec.EcTeamEvent=0
            AND ec.EcDivision=EnDivision AND ec.EcClass=EnClass
            AND IF(ec.EcSubClass='', 1, ec.EcSubClass=EnSubClass)
        LEFT JOIN Events ev ON ev.EvTournament=ec.EcTournament AND ev.EvCode=ec.EcCode AND ev.EvTeamEvent=0
        WHERE QuSession=" . StrSafe_DB($session) . " AND EnAthlete=1 AND (EnStatus<=1 OR EnStatus=6)
          AND QuTarget<>'' AND QuTarget<>'0'
        ORDER BY QuTarget, QuLetter";
    $rs = safe_r_sql($sql);
    $mats = [];
    $targetFaceIds = [];
    $targetDiameters = [];
    while ($row = safe_fetch($rs)) {
        if ($row->TargetFaceId) {
            $targetFaceIds[$row->TargetFaceId] = true;
            if ($row->TargetDiameter) {
                $targetDiameters[$row->TargetFaceId] = intval($row->TargetDiameter);
            }
        }
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
            'firstName' => trim((string)$row->EnFirstName),
            'lastName' => trim((string)$row->EnName),
            'name' => trim((string)$row->EnName . ' ' . (string)$row->EnFirstName),
            'club' => trim((string)$row->CoCode),
            'event' => trim((string)$row->EventCode),
            'position' => trim((string)$row->QuLetter),
            'class' => trim((string)$row->EnClass),
            'division' => trim((string)$row->EnDivision),
            'targetFaceId' => $row->TargetFaceId,
            'distance' => $latestDistance,
            'completedEnds' => laneAssistCompletedEnds($arrowString, $arrowsPerEnd),
            'completedTotalEnds' => $completedTotalEnds,
            'totalEnds' => $totalEnds,
            'arrowsShot' => $arrowsShot,
            'totalArrows' => $totalArrows,
            'lastEndPoints' => laneAssistLastEndPoints($arrowString, $arrowsPerEnd, function($arrow) {
                return DecodeFromLetter($arrow);
            }),
            'totalPoints' => intval($row->QuScore),
            'retired' => intval($row->EnStatus) === 6,
        ];
    }

    $targetFaceImages = [];
    if (!empty($targetFaceIds)) {
        $tfSql = "SELECT TarId, TarDescr, TarFullSize FROM Targets WHERE TarId IN ("
            . implode(',', array_map('intval', array_keys($targetFaceIds))) . ")";
        $tfRs = safe_r_sql($tfSql);
        while ($tfRow = safe_fetch($tfRs)) {
            $imgPath = $CFG->DOCUMENT_PATH . 'Common/Images/Targets/' . $tfRow->TarId . '.svg';
            $imgUrl = $CFG->ROOT_DIR . 'Common/Images/Targets/' . $tfRow->TarId . '.svg';

            if (!file_exists($imgPath)) {
                $imgPath = $CFG->DOCUMENT_PATH . 'Common/Images/Targets/' . $tfRow->TarId . '.svgz';
                $imgUrl = $CFG->ROOT_DIR . 'Common/Images/Targets/' . $tfRow->TarId . '.svgz';
            }

            if (file_exists($imgPath)) {
                $targetFaceImages[$tfRow->TarId] = [
                    'id' => $tfRow->TarId,
                    'description' => $tfRow->TarDescr,
                    'diameter' => $targetDiameters[$tfRow->TarId] ?? null,
                    'url' => $imgUrl,
                ];
            }
        }
    }

    $clubCodes = [];
    foreach ($mats as $mat) {
        foreach ($mat['archers'] as $archer) {
            if ($archer['club'] !== '') {
                $clubCodes[$archer['club']] = true;
            }
        }
    }

    $clubLogoUrls = [];
    if (!empty($clubCodes)) {
        $flSql = "SELECT FlCode, FlJPG, UNIX_TIMESTAMP(FlEntered) as FlModified
            FROM Flags
            WHERE FlCode IN (" . implode(',', array_map('StrSafe_DB', array_keys($clubCodes))) . ")
              AND FlTournament IN (-1, " . StrSafe_DB($_SESSION['TourId']) . ") AND FlJPG<>''
            ORDER BY FlTournament DESC";
        $flRs = safe_r_sql($flSql);
        // Tournament-specific rows sort before the shared -1 fallback, and only
        // the first row seen per code is kept, so an override always wins.
        $tourCodeSafe = $_SESSION['TourCodeSafe'] ?? preg_replace('/[^a-z0-9_.-]+/sim', '', (string)($_SESSION['TourCode'] ?? ''));
        while ($flRow = safe_fetch($flRs)) {
            if (isset($clubLogoUrls[$flRow->FlCode])) {
                continue;
            }
            $cacheName = 'TV/Photos/' . $tourCodeSafe . '-Fl-' . $flRow->FlCode . '.jpg';
            $cachePath = $CFG->DOCUMENT_PATH . $cacheName;
            if (!file_exists($cachePath) || filemtime($cachePath) < intval($flRow->FlModified)) {
                $image = @imagecreatefromstring(base64_decode($flRow->FlJPG));
                if ($image) {
                    @imagejpeg($image, $cachePath, 95);
                    imagedestroy($image);
                }
            }
            if (file_exists($cachePath)) {
                $clubLogoUrls[$flRow->FlCode] = $CFG->ROOT_DIR . $cacheName;
            }
        }
    }

    $allArchers = [];
    foreach ($mats as $mat) {
        $allArchers = array_merge($allArchers, $mat['archers']);
    }
    $allArchers = laneAssistAttachPersonalBests($allArchers, loadQualificationPersonalBestScores($allArchers));
    $pace = laneAssistMarkQualificationLag($allArchers);
    $paceByParticipant = [];
    foreach ($pace['archers'] as $archer) {
        $paceByParticipant[$archer['participantId']] = $archer;
    }
    $badges = laneAssistCollectBadges($allArchers, laneAssistLiveBadgeContext($session));
    $hasMultipleDistances = count($distanceInfo) > 1;
    foreach ($mats as &$mat) {
        $mat['expectedEnds'] = $pace['expectedEnds'];
        $mat['expectedDistance'] = $pace['expectedDistance'];
        $mat['hasMultipleDistances'] = $hasMultipleDistances;
        foreach ($mat['archers'] as &$archer) {
            // The pace pass returns rebuilt archer arrays, so read badges after
            // the replacement or they would be discarded again.
            $archer = $paceByParticipant[$archer['participantId']];
            $archer['badges'] = $badges[$archer['participantId']] ?? [];
            $archer['targetFaceUrl'] = $targetFaceImages[$archer['targetFaceId']]['url'] ?? null;
            $archer['targetDiameter'] = $targetFaceImages[$archer['targetFaceId']]['diameter'] ?? null;
            $archer['clubLogoUrl'] = $clubLogoUrls[$archer['club']] ?? null;
        }
        unset($archer);
    }
    unset($mat);
    return array_values($mats);
}

/**
 * Tournament facts a badge provider needs, so no provider has to query the
 * Tournament row itself.
 *
 * ToNumDist and ToMaxDistScore matter as much as the type name: in real data
 * Type_Indoor 18 exists as both 2x300 (=600) and 1x300 (=300), and
 * Type_2x70mRound is 4x360 (=1440), so a provider keyed on the name alone would
 * score against the wrong table.
 */
function laneAssistLiveBadgeContext($session) {
    $tourId = StrSafe_DB($_SESSION['TourId']);
    $row = safe_fetch(safe_r_sql("SELECT ToTypeName, ToNumDist, ToMaxDistScore, ToLocRule
        FROM Tournament WHERE ToId=$tourId"));

    return [
        'tourId' => intval($_SESSION['TourId']),
        'session' => intval($session),
        'phase' => 'qualification',
        'toTypeName' => $row ? (string)$row->ToTypeName : '',
        'toNumDist' => $row ? intval($row->ToNumDist) : 0,
        'toMaxDistScore' => $row ? intval($row->ToMaxDistScore) : 0,
        'toLocRule' => $row ? (string)$row->ToLocRule : '',
    ];
}

function loadQualificationPersonalBestScores(array $archers) {
    if (!$archers) {
        return [];
    }

    $tourId = StrSafe_DB($_SESSION['TourId']);
    $tournament = safe_fetch(safe_r_sql("SELECT ToLocRule, ToType FROM Tournament WHERE ToId=$tourId"));
    if (!$tournament) {
        return [];
    }

    $identityConditions = [];
    foreach ($archers as $archer) {
        $firstName = strtolower(trim((string)($archer['firstName'] ?? '')));
        $lastName = strtolower(trim((string)($archer['lastName'] ?? '')));
        $club = strtolower(trim((string)($archer['club'] ?? '')));
        $identityConditions[$firstName . '|' . $lastName . '|' . $club] = "(LOWER(TRIM(past.EnFirstName))=" . StrSafe_DB($firstName) . "
            AND LOWER(TRIM(past.EnName))=" . StrSafe_DB($lastName) . "
            AND LOWER(TRIM(COALESCE(pastClub.CoCode, '')))=" . StrSafe_DB($club) . ')';
    }

    $sql = "SELECT past.EnFirstName FirstName, past.EnName LastName, COALESCE(pastClub.CoCode, '') Club,
            history.QuScore Score, pastTournament.ToName CompetitionName
        FROM Qualifications history
        INNER JOIN Entries past ON past.EnId=history.QuId
        INNER JOIN Tournament pastTournament ON pastTournament.ToId=past.EnTournament
        LEFT JOIN Countries pastClub ON pastClub.CoId=past.EnCountry
        WHERE past.EnAthlete=1 AND past.EnStatus<=1 AND history.QuScore>0
          AND pastTournament.ToId<>$tourId
          AND pastTournament.ToLocRule=" . StrSafe_DB($tournament->ToLocRule) . '
          AND pastTournament.ToType=' . StrSafe_DB($tournament->ToType) . '
                    AND (' . implode(' OR ', $identityConditions) . ')
                ORDER BY history.QuScore DESC, pastTournament.ToWhenTo DESC, pastTournament.ToName';
    $scores = [];
    $rs = safe_r_sql($sql);
    while ($row = safe_fetch($rs)) {
        $scores[] = [
            'firstName' => (string)$row->FirstName,
            'lastName' => (string)$row->LastName,
            'club' => (string)$row->Club,
            'score' => intval($row->Score),
            'competitionName' => (string)$row->CompetitionName,
        ];
    }
    return $scores;
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
                IF((gr.GrPhase & ev.EvMatchArrowsNo), ev.EvElimEnds, ev.EvFinEnds) TotalEnds,
                co.CoCode Club,
                (SELECT MIN(ecDiv.EcDivision) FROM EventClass ecDiv
                    WHERE ecDiv.EcTournament=tf.TfTournament AND ecDiv.EcCode=tf.TfEvent
                      AND IF(ecDiv.EcTeamEvent!=0,1,0)=1) Division,
                (SELECT MIN(ecCls.EcClass) FROM EventClass ecCls
                    WHERE ecCls.EcTournament=tf.TfTournament AND ecCls.EcCode=tf.TfEvent
                      AND IF(ecCls.EcTeamEvent!=0,1,0)=1) Class
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
                fin.FinAthlete ParticipantId, CONCAT(en.EnName, ' ', en.EnFirstName) ParticipantName,
                fin.FinScore Score, fin.FinSetScore SetScore, fin.FinArrowstring ArrowString,
                fin.FinWinLose WinLose, fin.FinTie Tie, gr.GrPhase Phase, fs.FSTarget Target,
                fs.FSScheduledDate ScheduledDate, fs.FSScheduledTime ScheduledTime,
                ev.EvMatchMode MatchMode,
                IF((gr.GrPhase & ev.EvMatchArrowsNo), ev.EvElimArrows, ev.EvFinArrows) ArrowsPerEnd,
                IF((gr.GrPhase & ev.EvMatchArrowsNo), ev.EvElimEnds, ev.EvFinEnds) TotalEnds,
                co.CoCode Club, en.EnDivision Division, en.EnClass Class
            FROM Finals fin
            INNER JOIN Events ev ON ev.EvTournament=fin.FinTournament AND ev.EvCode=fin.FinEvent AND ev.EvTeamEvent=0
            INNER JOIN Grids gr ON gr.GrMatchNo=fin.FinMatchNo
            LEFT JOIN Entries en ON en.EnId=fin.FinAthlete
            LEFT JOIN Countries co ON co.CoId=en.EnCountry
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
            'club' => trim((string)$row->Club), 'division' => trim((string)$row->Division),
            'class' => trim((string)$row->Class),
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
    $finalRounds = laneAssistGroupFinalRounds(allFinalMatchesSnapshot());
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

    // 3. Per-event finals planning (individual / team / mixed, demand-gated)
    $evRs = safe_r_sql("SELECT EvCode, EvEventName, EvTeamEvent, EvMixedTeam, EvFinalFirstPhase, EvNumQualified
        FROM Events WHERE EvTournament=$tourId");
    $finalsRows = buildFinalsRows()['rows'];
    $scheduledByEvent = [];
    foreach ($finalsRows as $row) {
        if (trim((string)$row['scheduledDate']) !== '') {
            $scheduledByEvent[$row['teamEvent'] . '|' . $row['event']] = true;
        }
    }
    while ($ev = safe_fetch($evRs)) {
        $teamEvent = intval($ev->EvTeamEvent);
        $mixedTeam = intval($ev->EvMixedTeam) === 1;
        $label = $teamEvent === 0 ? 'individual' : ($mixedTeam ? 'mixed' : 'team');
        $finalFirstPhase = intval($ev->EvFinalFirstPhase);
        $expectedSize = $finalFirstPhase > 0 ? numQualifiedByPhase($finalFirstPhase) : 0;
        $eventKey = $teamEvent . '|' . $ev->EvCode;

        $issues = laneAssistFinalsPlanningIssues([
            'code' => $ev->EvCode,
            'label' => $label,
            'finalFirstPhase' => $finalFirstPhase,
            'rawEntrantCount' => getRawFinalistDemand($ev->EvCode, $teamEvent),
            'hasAnyScheduled' => !empty($scheduledByEvent[$eventKey]),
            'expectedSize' => $expectedSize,
        ]);

        $listPage = $teamEvent === 0
            ? 'Final/Individual/ListEvents.php'
            : 'Final/Team/ListEvents.php';
        foreach ($issues as $issue) {
            $items[] = [
                'key' => 'finalsPlanning_' . $eventKey . '_' . $issue['type'],
                'severity' => $issue['severity'],
                'title' => 'Per-event finals planning',
                'detail' => $issue['message'],
                'link' => $rootDir . ($issue['type'] === 'not_scheduled' ? 'Modules/Custom/LaneAssist/ManageFinals/index.php' : $listPage),
                'fix' => null,
            ];
        }
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
    $sessions = [];
    foreach (GetSessions() as $session) {
        $sessions[] = [
            'sessionOrder' => intval($session->SesOrder),
            'sessionId' => (string)$session->Id,
            'dtStart' => (string)$session->SesDtStart,
        ];
    }
    foreach (laneAssistDetectSessionsWithoutTimes($sessions) as $issue) {
        $items[] = [
            'key' => 'sessionTime_' . $issue['sessionId'],
            'severity' => 'warning',
            'title' => 'Sessions without times',
            'detail' => "Session {$issue['sessionOrder']} has no time set",
            'link' => null,
            'fix' => [
                'action' => 'applySessionDefaults', 'params' => ['sessionId' => $issue['sessionId']],
                'confirm' => "Set this session to the tournament's start date, 09:00-12:00",
            ],
        ];
    }

    // 7. Clubs missing logos
    $clubRs = safe_r_sql("SELECT DISTINCT co.CoCode AS Code FROM Entries e
        INNER JOIN Countries co ON co.CoId=e.EnCountry
        WHERE e.EnTournament=$tourId");
    $flagRs = safe_r_sql("SELECT FlCode, FlTournament FROM Flags WHERE FlTournament IN (-1, $tourId)");
    $tournamentFlags = [];
    $globalFlags = [];
    while ($flag = safe_fetch($flagRs)) {
        if (intval($flag->FlTournament) === -1) {
            $globalFlags[(string)$flag->FlCode] = true;
        } else {
            $tournamentFlags[(string)$flag->FlCode] = true;
        }
    }
    while ($club = safe_fetch($clubRs)) {
        $code = (string)$club->Code;
        if ($code === '') {
            continue;
        }
        $classification = laneAssistClassifyClubLogo($code, isset($tournamentFlags[$code]), isset($globalFlags[$code]));
        if ($classification === null) {
            continue;
        }
        if ($classification['type'] === 'linkable') {
            $items[] = [
                'key' => 'clubLogo_' . $code,
                'severity' => 'info',
                'title' => 'Clubs missing logos',
                'detail' => "Club {$code}'s logo is available but not linked to this tournament",
                'link' => null,
                'fix' => [
                    'action' => 'pullClubLogo', 'params' => ['clubCode' => $code],
                    'confirm' => "Copy club {$code}'s logo from another tournament's record of this club code into this tournament",
                ],
            ];
        } else {
            $items[] = [
                'key' => 'clubLogo_' . $code,
                'severity' => 'info',
                'title' => 'Clubs missing logos',
                'detail' => "Club {$code} has no logo on file anywhere",
                'link' => null,
                'fix' => null,
            ];
        }
    }

    return $items;
}

function applySessionDefaults() {
    if (IsBlocked(BIT_BLOCK_TOURDATA)) {
        echo json_encode(['error' => 1, 'message' => 'Tournament data is locked']);
        return;
    }
    $sessionId = trim((string)($_POST['sessionId'] ?? ''));
    if ($sessionId === '' || !preg_match('/^\d+_[QEF]$/', $sessionId)) {
        echo json_encode(['error' => 1, 'message' => 'Invalid session']);
        return;
    }
    if (!applySessionDefaultsForTest($sessionId)) {
        echo json_encode(['error' => 1, 'message' => 'Session not found']);
        return;
    }
    echo json_encode(['error' => 0, 'message' => 'Session time set']);
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
    $clubCode = trim((string)($_POST['clubCode'] ?? ''));
    if ($clubCode === '') {
        echo json_encode(['error' => 1, 'message' => 'Invalid club code']);
        return;
    }
    pullClubLogoForTest($clubCode);
    echo json_encode(['error' => 0, 'message' => 'Logo linked']);
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
    safe_w_sql("INSERT IGNORE INTO Flags (FlCode, FlTournament, FlIocCode, FlJPG, FlSVG, FlContAssoc)
        SELECT FlCode, $tourId, FlIocCode, FlJPG, FlSVG, FlContAssoc FROM Flags WHERE FlCode=$clubCode AND FlTournament=-1");
}
