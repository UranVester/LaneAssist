<?php
/**
 * DB readers shared by LiveView and Manage Tournament: qualification targets,
 * final matches, and whether finals have started. Callers require
 * live-view-logic.php, badge-providers.php, finals-logic.php and core's
 * ArrTargets/Fun_Phases/Fun_Sessions first.
 */

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
            'averageArrow' => laneAssistAverageArrow($row->QuScore, $arrowsShot),
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
        $division = strtolower(trim((string)($archer['division'] ?? '')));
        $identityConditions[$firstName . '|' . $lastName . '|' . $club . '|' . $division] = "(LOWER(TRIM(past.EnFirstName))=" . StrSafe_DB($firstName) . "
            AND LOWER(TRIM(past.EnName))=" . StrSafe_DB($lastName) . "
            AND LOWER(TRIM(COALESCE(pastClub.CoCode, '')))=" . StrSafe_DB($club) . "
            AND LOWER(TRIM(past.EnDivision))=" . StrSafe_DB($division) . ')';
    }

    $sql = "SELECT past.EnFirstName FirstName, past.EnName LastName, COALESCE(pastClub.CoCode, '') Club,
            past.EnDivision Division, history.QuScore Score, pastTournament.ToName CompetitionName
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
            'division' => (string)$row->Division,
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
            }, max(1, intval($row->TotalEnds))),
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

// Core creates the empty bracket grid as soon as events have a first phase, so a
// row existing proves nothing; finals have started once someone is placed in one.
function finalsBracketsInitialized() {
    $tourId = StrSafe_DB($_SESSION['TourId']);
    $individual = safe_fetch(safe_r_sql("SELECT 1 Initialized FROM Finals WHERE FinTournament=$tourId AND FinAthlete<>0 LIMIT 1"));
    if ($individual) {
        return true;
    }
    return (bool)safe_fetch(safe_r_sql("SELECT 1 Initialized FROM TeamFinals WHERE TfTournament=$tourId AND TfTeam<>0 LIMIT 1"));
}
