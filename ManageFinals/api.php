<?php
/**
 * Manage Finals API
 */

require_once(dirname(__FILE__, 3) . '/config.php');
header('Content-Type: application/json');

if (!CheckTourSession()) {
    echo json_encode(['error' => 1, 'message' => get_text('CrackError')]);
    exit;
}

checkFullACL(AclCompetition, 'cSchedule', AclReadWrite, false);

$action = $_REQUEST['action'] ?? '';

switch ($action) {
    case 'getCurrent':
        getCurrent();
        break;
    case 'validateChanges':
        validateChanges();
        break;
    case 'apply':
        applyChanges();
        break;
    default:
        echo json_encode(['error' => 1, 'message' => 'Invalid action']);
}

function normalizeDateTimeValue($dateValue, $timeValue) {
    $date = trim((string)$dateValue);
    $time = trim((string)$timeValue);
    if ($date === '' || $date === '0000-00-00') {
        return '';
    }

    if ($time === '') {
        $time = '00:00:00';
    } elseif (strlen($time) === 5) {
        $time .= ':00';
    }

    return $date . ' ' . $time;
}

function normalizeScheduledDateForUi($dateValue) {
    $date = trim((string)$dateValue);
    if ($date === '' || $date === '0000-00-00') {
        return '';
    }
    return $date;
}

function normalizeScheduledTimeForUi($timeValue, $normalizedDate) {
    if ($normalizedDate === '') {
        return '';
    }

    $time = trim((string)$timeValue);
    if ($time === '' || $time === '00:00:00') {
        return '';
    }
    return $time;
}

function loadFinalRowsForValidation() {
    $sql = "SELECT
                fs.FSTeamEvent,
                fs.FSEvent,
                fs.FSMatchNo,
                fs.FSScheduledDate,
                fs.FSScheduledTime,
                fs.FSScheduledLen,
                fs.FSGroup,
                fs.FSTarget,
                gr.GrPhase,
                ev.EvFinalAthTarget,
                fi.FinAthlete,
                tf.TfTeam
            FROM FinSchedule fs
            LEFT JOIN Events ev
                ON ev.EvTournament=fs.FSTournament
                AND ev.EvCode=fs.FSEvent
                AND ev.EvTeamEvent=fs.FSTeamEvent
            LEFT JOIN Grids gr
                ON gr.GrMatchNo=fs.FSMatchNo
            LEFT JOIN Finals fi
                ON fi.FinTournament=fs.FSTournament
                AND fi.FinEvent=fs.FSEvent
                AND fi.FinMatchNo=fs.FSMatchNo
                AND fs.FSTeamEvent=0
            LEFT JOIN TeamFinals tf
                ON tf.TfTournament=fs.FSTournament
                AND tf.TfEvent=fs.FSEvent
                AND tf.TfMatchNo=fs.FSMatchNo
                AND fs.FSTeamEvent=1
            WHERE fs.FSTournament=" . StrSafe_DB($_SESSION['TourId']);

    $rows = [];
    $rs = safe_r_sql($sql);
    while ($row = safe_fetch($rs)) {
        $scheduledDate = normalizeScheduledDateForUi($row->FSScheduledDate);
        $scheduledTime = normalizeScheduledTimeForUi($row->FSScheduledTime, $scheduledDate);
        $phase = intval($row->GrPhase);
        $phaseBit = max(1, $phase * 2);
        $finalAthTargetMask = intval($row->EvFinalAthTarget);
        $archersPerTarget = (($finalAthTargetMask & $phaseBit) ? 2 : 1);

        $rows[] = [
            'teamEvent' => intval($row->FSTeamEvent),
            'event' => trim((string)$row->FSEvent),
            'matchNo' => intval($row->FSMatchNo),
            'scheduledDate' => $scheduledDate,
            'scheduledTime' => $scheduledTime,
            'scheduledLen' => intval($row->FSScheduledLen),
            'group' => intval($row->FSGroup),
            'target' => strtoupper(trim((string)$row->FSTarget)),
            'phase' => $phase,
            'archersPerTarget' => $archersPerTarget,
            'hasParticipant' => (intval($row->FSTeamEvent) === 1)
                ? (intval($row->TfTeam) > 0 ? 1 : 0)
                : (intval($row->FinAthlete) > 0 ? 1 : 0),
        ];
    }

    return $rows;
}

function applyChangesToRows($rows, $changes) {
    $indexed = [];
    foreach ($rows as $row) {
        $key = $row['teamEvent'] . '|' . $row['event'] . '|' . $row['matchNo'];
        $indexed[$key] = $row;
    }

    foreach ($changes as $change) {
        $teamEvent = intval($change['teamEvent'] ?? 0);
        $event = trim((string)($change['event'] ?? ''));
        $matchNo = intval($change['matchNo'] ?? 0);
        $target = strtoupper(trim((string)($change['target'] ?? '')));
        $scheduledDate = trim((string)($change['scheduledDate'] ?? ''));
        $scheduledTime = trim((string)($change['scheduledTime'] ?? ''));
        $scheduledLen = max(0, intval($change['scheduledLen'] ?? 0));

        $key = $teamEvent . '|' . $event . '|' . $matchNo;
        if (!isset($indexed[$key])) {
            continue;
        }

        if ($target !== '' && ctype_digit($target)) {
            $target = str_pad($target, TargetNoPadding, '0', STR_PAD_LEFT);
        }

        $indexed[$key]['target'] = $target;
        $indexed[$key]['scheduledDate'] = $scheduledDate;
        $indexed[$key]['scheduledTime'] = $scheduledTime;
        $indexed[$key]['scheduledLen'] = $scheduledLen;
    }

    return array_values($indexed);
}

function buildValidationFocusFromChanges($rowsAfter, $changes) {
    $focus = [
        'phasesByEvent' => []
    ];

    if (!is_array($changes) || empty($changes)) {
        return $focus;
    }

    $indexed = [];
    foreach ($rowsAfter as $row) {
        $key = intval($row['teamEvent']) . '|' . trim((string)$row['event']) . '|' . intval($row['matchNo']);
        $indexed[$key] = $row;
    }

    foreach ($changes as $change) {
        $key = intval($change['teamEvent'] ?? 0) . '|' . trim((string)($change['event'] ?? '')) . '|' . intval($change['matchNo'] ?? 0);
        if (!isset($indexed[$key])) {
            continue;
        }

        $row = $indexed[$key];
        $eventKey = intval($row['teamEvent']) . '|' . trim((string)$row['event']);
        $phase = intval($row['phase'] ?? 0);

        if (!isset($focus['phasesByEvent'][$eventKey])) {
            $focus['phasesByEvent'][$eventKey] = [];
        }
        $focus['phasesByEvent'][$eventKey][$phase] = true;
    }

    return $focus;
}

function validateFinalRows($rows, $focus = null) {
    $errors = [];

    $phaseLabels = [
        0 => 'Gold',
        1 => 'Bronze',
        2 => '1/2',
        4 => '1/4',
        8 => '1/8',
        16 => '1/16',
        32 => '1/32',
        64 => '1/64',
    ];

    $phaseDependencies = [
        ['before' => 64, 'after' => 32],
        ['before' => 32, 'after' => 16],
        ['before' => 16, 'after' => 8],
        ['before' => 8, 'after' => 4],
        ['before' => 4, 'after' => 2],
        ['before' => 2, 'after' => 1],
        ['before' => 2, 'after' => 0],
    ];

    $playablePairCounts = [];
    foreach ($rows as $row) {
        $hasParticipant = intval($row['hasParticipant'] ?? 0) > 0;
        if (!$hasParticipant) {
            continue;
        }

        $pairNo = intdiv(intval($row['matchNo'] ?? 0), 2);
        $pairKey = intval($row['teamEvent']) . '|' . trim((string)$row['event']) . '|' . intval($row['group']) . '|' . intval($row['phase']) . '|' . $pairNo;
        if (!isset($playablePairCounts[$pairKey])) {
            $playablePairCounts[$pairKey] = 0;
        }
        $playablePairCounts[$pairKey]++;
    }

    $isRowPlayable = function($row) use ($playablePairCounts) {
        $pairNo = intdiv(intval($row['matchNo'] ?? 0), 2);
        $pairKey = intval($row['teamEvent']) . '|' . trim((string)$row['event']) . '|' . intval($row['group']) . '|' . intval($row['phase']) . '|' . $pairNo;
        return intval($playablePairCounts[$pairKey] ?? 0) >= 2;
    };

    // Rule 1: In same event stream, required phase order must be respected
    $phaseByEvent = [];
    foreach ($rows as $row) {
        if (!$isRowPlayable($row)) {
            continue;
        }

        $eventKey = intval($row['teamEvent']) . '|' . trim((string)$row['event']);
        if (!isset($phaseByEvent[$eventKey])) {
            $phaseByEvent[$eventKey] = [
                'phaseSlots' => [],
            ];
        }

        $dateTime = normalizeDateTimeValue($row['scheduledDate'] ?? '', $row['scheduledTime'] ?? '');
        if ($dateTime === '') {
            continue;
        }

        $phase = intval($row['phase'] ?? 0);
        if (isset($phaseLabels[$phase])) {
            if (!isset($phaseByEvent[$eventKey]['phaseSlots'][$phase])) {
                $phaseByEvent[$eventKey]['phaseSlots'][$phase] = [];
            }
            $phaseByEvent[$eventKey]['phaseSlots'][$phase][] = $dateTime;
        }
    }

    foreach ($phaseByEvent as $eventKey => $phaseInfo) {
        foreach ($phaseDependencies as $dependency) {
            $beforePhase = intval($dependency['before']);
            $afterPhase = intval($dependency['after']);
            $beforeSlots = $phaseInfo['phaseSlots'][$beforePhase] ?? [];
            $afterSlots = $phaseInfo['phaseSlots'][$afterPhase] ?? [];

            if (empty($beforeSlots) || empty($afterSlots)) {
                continue;
            }

            sort($beforeSlots);
            sort($afterSlots);
            $latestBefore = $beforeSlots[count($beforeSlots) - 1];
            $earliestAfter = $afterSlots[0];

            if ($latestBefore >= $earliestAfter) {
                list($teamEvent, $event) = explode('|', $eventKey, 2);
                $beforeLabel = $phaseLabels[$beforePhase] ?? ('Phase ' . $beforePhase);
                $afterLabel = $phaseLabels[$afterPhase] ?? ('Phase ' . $afterPhase);
                $errors[] = [
                    'type' => 'phase_order',
                    'message' => 'Event ' . $event . ' (' . (intval($teamEvent) ? 'Team' : 'Individual') . '): ' . $beforeLabel . ' finals must be before ' . $afterLabel . ' finals',
                    '__priority' => (isset($focus['phasesByEvent'][$eventKey]) && (isset($focus['phasesByEvent'][$eventKey][$beforePhase]) || isset($focus['phasesByEvent'][$eventKey][$afterPhase]))) ? 0 : 1,
                ];
            }
        }
    }

    // Rule 2: No target sharing on same timeslot across different bundles
    $targetUsage = [];
    foreach ($rows as $row) {
        if (!$isRowPlayable($row)) {
            continue;
        }

        $target = trim((string)($row['target'] ?? ''));
        $dateTime = normalizeDateTimeValue($row['scheduledDate'] ?? '', $row['scheduledTime'] ?? '');
        if ($target === '' || $dateTime === '') {
            continue;
        }

        $targetNo = strtoupper($target);
        $slotKey = $dateTime . '|' . $targetNo;
        $bundleKey = intval($row['teamEvent']) . '|' . trim((string)$row['event']) . '|' . intval($row['group']) . '|' . intval($row['phase']) . '|' . $dateTime;

        if (!isset($targetUsage[$slotKey])) {
            $targetUsage[$slotKey] = [
                'dateTime' => $dateTime,
                'target' => $targetNo,
                'bundles' => [],
            ];
        }

        $targetUsage[$slotKey]['bundles'][$bundleKey] = true;
    }

    foreach ($targetUsage as $usage) {
        if (count($usage['bundles']) > 1) {
            $errors[] = [
                'type' => 'target_conflict',
                'message' => 'Target ' . $usage['target'] . ' is assigned more than once in timeslot ' . $usage['dateTime'],
                '__priority' => 1,
            ];
        }
    }

    usort($errors, function($a, $b) {
        $pa = intval($a['__priority'] ?? 1);
        $pb = intval($b['__priority'] ?? 1);
        if ($pa === $pb) {
            return 0;
        }
        return $pa < $pb ? -1 : 1;
    });

    foreach ($errors as &$error) {
        unset($error['__priority']);
    }
    unset($error);

    return $errors;
}

function validateChanges() {
    $changesRaw = $_REQUEST['changes'] ?? '[]';
    $changes = json_decode($changesRaw, true);

    if (!is_array($changes)) {
        echo json_encode(['error' => 1, 'message' => 'Invalid changes payload']);
        return;
    }

    $rows = loadFinalRowsForValidation();
    $rowsAfter = applyChangesToRows($rows, $changes);
    $focus = buildValidationFocusFromChanges($rowsAfter, $changes);
    $errors = validateFinalRows($rowsAfter, $focus);

    echo json_encode([
        'error' => 0,
        'valid' => count($errors) === 0,
        'errors' => $errors,
    ]);
}

function normalizeFilterValues($rawValue) {
    if (!is_array($rawValue)) {
        if ($rawValue === null || $rawValue === '') {
            return [];
        }
        $rawValue = explode(',', (string)$rawValue);
    }

    $values = [];
    foreach ($rawValue as $value) {
        $clean = trim((string)$value);
        if ($clean === '') {
            continue;
        }
        $values[] = $clean;
    }

    return array_values(array_unique($values));
}

function normalizeDistanceToken($token) {
    $token = trim((string)$token);
    if ($token === '' || $token === '-') {
        return '';
    }

    if (preg_match('/(\d+)/', $token, $matches)) {
        return intval($matches[1]) . 'm';
    }

    return '';
}

function getDistanceProfileForCategory($category) {
    static $cache = [];

    $category = trim((string)$category);
    if ($category === '') {
        return '';
    }

    if (array_key_exists($category, $cache)) {
        return $cache[$category];
    }

    $sql = "SELECT Td1, Td2, Td3, Td4, Td5, Td6, Td7, Td8
            FROM TournamentDistances
            WHERE TdType=" . StrSafe_DB($_SESSION['TourType']) . "
              AND TdTournament=" . StrSafe_DB($_SESSION['TourId']) . "
              AND " . StrSafe_DB($category) . " LIKE TdClasses
            ORDER BY TdTournament=0,
                     " . StrSafe_DB($category) . " = TdClasses DESC,
                     LEFT(TdClasses,1)!='_' AND LEFT(TdClasses,1)!='%' DESC,
                     LEFT(TdClasses,1)='_' DESC,
                     TdClasses DESC,
                     TdClasses='%'
            LIMIT 1";

    $rs = safe_r_sql($sql);
    $profile = '';

    if ($row = safe_fetch($rs)) {
        $distances = [];
        for ($index = 1; $index <= 8; $index++) {
            $fieldName = 'Td' . $index;
            $normalized = normalizeDistanceToken((string)($row->$fieldName ?? ''));
            if ($normalized !== '') {
                $distances[$normalized] = true;
            }
        }

        $distanceList = array_keys($distances);
        usort($distanceList, function($a, $b) {
            $na = intval($a);
            $nb = intval($b);
            if ($na === $nb) {
                return strcmp($a, $b);
            }
            return $na - $nb;
        });

        $profile = implode('-', $distanceList);
    }

    $cache[$category] = $profile;
    return $profile;
}

function getEventDistanceMeta($eventCode, $teamEvent) {
    static $cache = [];

    $eventCode = trim((string)$eventCode);
    $teamEvent = intval($teamEvent);
    $cacheKey = $teamEvent . '|' . $eventCode;

    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $meta = [
        'profile' => '',
        'sort' => null,
    ];

    if ($eventCode === '') {
        $cache[$cacheKey] = $meta;
        return $meta;
    }

    $sql = "SELECT ec.EcDivision, ec.EcClass, divs.DivViewOrder, cls.ClViewOrder
            FROM EventClass ec
            INNER JOIN Divisions divs
                ON divs.DivTournament=ec.EcTournament
                AND divs.DivId=ec.EcDivision
            INNER JOIN Classes cls
                ON cls.ClTournament=ec.EcTournament
                AND cls.ClId=ec.EcClass
            WHERE ec.EcTournament=" . StrSafe_DB($_SESSION['TourId']) . "
              AND ec.EcCode=" . StrSafe_DB($eventCode) . "
              AND IF(ec.EcTeamEvent!=0,1,0)=" . StrSafe_DB($teamEvent) . "
            ORDER BY divs.DivViewOrder, cls.ClViewOrder";

    $rs = safe_r_sql($sql);
    $profilesSeen = [];
    $profileList = [];

    while ($row = safe_fetch($rs)) {
        $category = trim((string)$row->EcDivision) . trim((string)$row->EcClass);
        $profile = getDistanceProfileForCategory($category);
        if ($profile !== '' && !isset($profilesSeen[$profile])) {
            $profilesSeen[$profile] = true;
            $profileList[] = $profile;
        }

        if ($meta['sort'] === null) {
            $meta['sort'] = intval($row->DivViewOrder) * 1000 + intval($row->ClViewOrder);
        }
    }

    if (!empty($profileList)) {
        $meta['profile'] = $profileList[0];
    }

    $cache[$cacheKey] = $meta;
    return $meta;
}

function getProjectedFinalistsForEvent($eventCode, $teamEvent, $eventNumQualified) {
    static $cache = [];

    $cacheKey = intval($_SESSION['TourId']) . '|' . intval($teamEvent) . '|' . trim((string)$eventCode) . '|' . intval($eventNumQualified);
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $team = intval($teamEvent) !== 0 ? 1 : 0;
    $numQualified = max(0, intval($eventNumQualified));
    $entrantCount = 0;

    if ($team === 0) {
        $sql = "SELECT COUNT(DISTINCT e.EnId) AS Cnt
                FROM Entries e
                INNER JOIN EventClass ec
                    ON ec.EcTournament=e.EnTournament
                    AND ec.EcCode=" . StrSafe_DB($eventCode) . "
                    AND IF(ec.EcTeamEvent!=0,1,0)=0
                    AND ec.EcDivision=e.EnDivision
                    AND ec.EcClass=e.EnClass
                WHERE e.EnTournament=" . StrSafe_DB($_SESSION['TourId']) . "
                  AND e.EnIndFEvent=1";
        $rs = safe_r_sql($sql);
        if ($row = safe_fetch($rs)) {
            $entrantCount = max(0, intval($row->Cnt));
        }

        // Planning fallback: if no athletes are finals-marked yet, still expose
        // potentially playable finals from registered entrants in the event classes.
        if ($entrantCount === 0) {
            $fallbackSql = "SELECT COUNT(DISTINCT e.EnId) AS Cnt
                            FROM Entries e
                            INNER JOIN EventClass ec
                                ON ec.EcTournament=e.EnTournament
                                AND ec.EcCode=" . StrSafe_DB($eventCode) . "
                                AND IF(ec.EcTeamEvent!=0,1,0)=0
                                AND ec.EcDivision=e.EnDivision
                                AND ec.EcClass=e.EnClass
                            WHERE e.EnTournament=" . StrSafe_DB($_SESSION['TourId']) . "";
            $fallbackRs = safe_r_sql($fallbackSql);
            if ($fallbackRow = safe_fetch($fallbackRs)) {
                $entrantCount = max(0, intval($fallbackRow->Cnt));
            }
        }
    } else {
        $sql = "SELECT COUNT(DISTINCT CONCAT(t.TeCoId,'-',t.TeSubTeam)) AS Cnt
                FROM Teams t
                INNER JOIN Events ev
                    ON ev.EvTournament=t.TeTournament
                    AND ev.EvCode=t.TeEvent
                    AND ev.EvTeamEvent=1
                WHERE t.TeTournament=" . StrSafe_DB($_SESSION['TourId']) . "
                  AND t.TeEvent=" . StrSafe_DB($eventCode) . "
                  AND t.TeFinEvent=1
                  AND t.TeSO!=0
                  AND EXISTS (
                      SELECT 1
                      FROM TeamFinComponent tfc
                      INNER JOIN Entries e
                          ON e.EnTournament=tfc.TfcTournament
                          AND e.EnId=tfc.TfcId
                      WHERE tfc.TfcTournament=t.TeTournament
                        AND tfc.TfcEvent=t.TeEvent
                        AND tfc.TfcCoId=t.TeCoId
                        AND tfc.TfcSubTeam=t.TeSubTeam
                        AND IF(ev.EvMixedTeam=0, e.EnTeamFEvent, e.EnTeamMixEvent)=1
                  )
                  AND NOT EXISTS (
                      SELECT 1
                      FROM TeamFinComponent tfc
                      INNER JOIN Entries e
                          ON e.EnTournament=tfc.TfcTournament
                          AND e.EnId=tfc.TfcId
                      WHERE tfc.TfcTournament=t.TeTournament
                        AND tfc.TfcEvent=t.TeEvent
                        AND tfc.TfcCoId=t.TeCoId
                        AND tfc.TfcSubTeam=t.TeSubTeam
                        AND IF(ev.EvMixedTeam=0, e.EnTeamFEvent, e.EnTeamMixEvent)=0
                  )";
        $rs = safe_r_sql($sql);
        if ($row = safe_fetch($rs)) {
            $entrantCount = max(0, intval($row->Cnt));
        }

        // Planning fallback: when team finals flags are cleared, count team entries
        // linked to the event so finals can still be planned.
        if ($entrantCount === 0) {
            $fallbackSql = "SELECT COUNT(DISTINCT CONCAT(t.TeCoId,'-',t.TeSubTeam)) AS Cnt
                            FROM Teams t
                            INNER JOIN Events ev
                                ON ev.EvTournament=t.TeTournament
                                AND ev.EvCode=t.TeEvent
                                AND ev.EvTeamEvent=1
                            WHERE t.TeTournament=" . StrSafe_DB($_SESSION['TourId']) . "
                              AND t.TeEvent=" . StrSafe_DB($eventCode) . "
                              AND t.TeSO!=0
                              AND EXISTS (
                                  SELECT 1
                                  FROM TeamFinComponent tfc
                                  WHERE tfc.TfcTournament=t.TeTournament
                                    AND tfc.TfcEvent=t.TeEvent
                                    AND tfc.TfcCoId=t.TeCoId
                                    AND tfc.TfcSubTeam=t.TeSubTeam
                              )";
            $fallbackRs = safe_r_sql($fallbackSql);
            if ($fallbackRow = safe_fetch($fallbackRs)) {
                $entrantCount = max(0, intval($fallbackRow->Cnt));
            }
        }
    }

    if ($numQualified > 0) {
        $entrantCount = min($entrantCount, $numQualified);
    }

    $cache[$cacheKey] = $entrantCount;
    return $entrantCount;
}

function getLastQualificationSessionEnd() {
    // Primary source: qualification distance schedule rows from Session page
    // (DiDay + DiStart + DiDuration).
    $sql = "SELECT MAX(
                DATE_ADD(TIMESTAMP(di.DiDay, di.DiStart), INTERVAL di.DiDuration MINUTE)
            ) AS LastQualEnd
            FROM DistanceInformation di
            WHERE di.DiTournament=" . StrSafe_DB($_SESSION['TourId']) . "
              AND di.DiType='Q'
              AND di.DiDay IS NOT NULL
              AND di.DiDay!='0000-00-00'
              AND di.DiStart IS NOT NULL
              AND di.DiStart!='00:00:00'";

    $rs = safe_r_sql($sql);
    if ($row = safe_fetch($rs)) {
        $value = trim((string)($row->LastQualEnd ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    // Fallback: session-level qualification datetimes.
    $fallbackSql = "SELECT MAX(
                        CASE
                            WHEN SesDtEnd IS NOT NULL AND SesDtEnd!='0000-00-00 00:00:00' THEN SesDtEnd
                            WHEN SesDtStart IS NOT NULL AND SesDtStart!='0000-00-00 00:00:00' THEN SesDtStart
                            ELSE NULL
                        END
                    ) AS LastQualEnd
                    FROM Session
                    WHERE SesTournament=" . StrSafe_DB($_SESSION['TourId']) . "
                      AND SesType='Q'";

    $fallbackRs = safe_r_sql($fallbackSql);
    if ($fallbackRow = safe_fetch($fallbackRs)) {
        return trim((string)($fallbackRow->LastQualEnd ?? ''));
    }

    return '';
}

function getCurrent() {
    $teamEvent = $_REQUEST['teamEvent'] ?? '';
    $dateFilter = trim($_REQUEST['dateFilter'] ?? '');
    $divisionFilter = normalizeFilterValues($_REQUEST['divisionFilter'] ?? []);
    $classFilter = normalizeFilterValues($_REQUEST['classFilter'] ?? []);

    $where = [
        'fs.FSTournament=' . StrSafe_DB($_SESSION['TourId'])
    ];

    if ($teamEvent !== '' && ($teamEvent === '0' || $teamEvent === '1')) {
        $where[] = 'fs.FSTeamEvent=' . StrSafe_DB(intval($teamEvent));
    }

    if ($dateFilter !== '') {
        $where[] = 'fs.FSScheduledDate=' . StrSafe_DB($dateFilter);
    }

    if (!empty($divisionFilter)) {
        $divList = array_map('StrSafe_DB', $divisionFilter);
        $where[] = "EXISTS (
            SELECT 1
            FROM EventClass ecDiv
            WHERE ecDiv.EcTournament=fs.FSTournament
              AND ecDiv.EcCode=fs.FSEvent
              AND IF(ecDiv.EcTeamEvent!=0,1,0)=fs.FSTeamEvent
              AND ecDiv.EcDivision IN (" . implode(',', $divList) . ")
        )";
    }

    if (!empty($classFilter)) {
        $clsList = array_map('StrSafe_DB', $classFilter);
        $where[] = "EXISTS (
            SELECT 1
            FROM EventClass ecCls
            WHERE ecCls.EcTournament=fs.FSTournament
              AND ecCls.EcCode=fs.FSEvent
              AND IF(ecCls.EcTeamEvent!=0,1,0)=fs.FSTeamEvent
              AND ecCls.EcClass IN (" . implode(',', $clsList) . ")
        )";
    }

    $sql = "SELECT
                fs.FSTeamEvent,
                fs.FSEvent,
                ev.EvEventName,
                fs.FSMatchNo,
                fs.FSScheduledDate,
                fs.FSScheduledTime,
                fs.FSScheduledLen,
                fs.FSGroup,
                fs.FSTarget,
                fs.FSLetter,
                gr.GrPhase,
                gr.GrPosition,
                gr.GrPosition2,
                ev.EvNumQualified,
                ev.EvFinalAthTarget,
                fi.FinAthlete,
                                tf.TfTeam,
                                (
                                        SELECT MIN(divs.DivViewOrder)
                                        FROM EventClass ecDivOrder
                                        INNER JOIN Divisions divs
                                            ON divs.DivTournament=ecDivOrder.EcTournament
                                            AND divs.DivId=ecDivOrder.EcDivision
                                        WHERE ecDivOrder.EcTournament=fs.FSTournament
                                            AND ecDivOrder.EcCode=fs.FSEvent
                                            AND IF(ecDivOrder.EcTeamEvent!=0,1,0)=fs.FSTeamEvent
                                ) AS EventDivisionOrder,
                                (
                                        SELECT MIN(cls.ClViewOrder)
                                        FROM EventClass ecClsOrder
                                        INNER JOIN Classes cls
                                            ON cls.ClTournament=ecClsOrder.EcTournament
                                            AND cls.ClId=ecClsOrder.EcClass
                                        WHERE ecClsOrder.EcTournament=fs.FSTournament
                                            AND ecClsOrder.EcCode=fs.FSEvent
                                            AND IF(ecClsOrder.EcTeamEvent!=0,1,0)=fs.FSTeamEvent
                                ) AS EventClassOrder,
                                (
                                        SELECT GROUP_CONCAT(DISTINCT ecDiv.EcDivision ORDER BY ecDiv.EcDivision SEPARATOR ',')
                                        FROM EventClass ecDiv
                                        WHERE ecDiv.EcTournament=fs.FSTournament
                                            AND ecDiv.EcCode=fs.FSEvent
                                            AND IF(ecDiv.EcTeamEvent!=0,1,0)=fs.FSTeamEvent
                                ) AS EvDivisions,
                                (
                                        SELECT GROUP_CONCAT(DISTINCT ecCls.EcClass ORDER BY ecCls.EcClass SEPARATOR ',')
                                        FROM EventClass ecCls
                                        WHERE ecCls.EcTournament=fs.FSTournament
                                            AND ecCls.EcCode=fs.FSEvent
                                            AND IF(ecCls.EcTeamEvent!=0,1,0)=fs.FSTeamEvent
                                ) AS EvClasses
            FROM FinSchedule fs
            LEFT JOIN Events ev
                ON ev.EvTournament=fs.FSTournament
                AND ev.EvCode=fs.FSEvent
                AND ev.EvTeamEvent=fs.FSTeamEvent
            LEFT JOIN Grids gr
                ON gr.GrMatchNo=fs.FSMatchNo
            LEFT JOIN Finals fi
                ON fi.FinTournament=fs.FSTournament
                AND fi.FinEvent=fs.FSEvent
                AND fi.FinMatchNo=fs.FSMatchNo
                AND fs.FSTeamEvent=0
            LEFT JOIN TeamFinals tf
                ON tf.TfTournament=fs.FSTournament
                AND tf.TfEvent=fs.FSEvent
                AND tf.TfMatchNo=fs.FSMatchNo
                AND fs.FSTeamEvent=1
            WHERE " . implode(' AND ', $where) . "
            ORDER BY fs.FSTeamEvent, fs.FSScheduledDate, fs.FSScheduledTime, fs.FSEvent, fs.FSMatchNo";

    $rs = safe_r_sql($sql);
    $rows = [];

    while ($row = safe_fetch($rs)) {
        $scheduledDate = normalizeScheduledDateForUi($row->FSScheduledDate);
        $scheduledTime = normalizeScheduledTimeForUi($row->FSScheduledTime, $scheduledDate);
        $phase = intval($row->GrPhase);
        $phaseBit = max(1, $phase * 2);
        $finalAthTargetMask = intval($row->EvFinalAthTarget);
        $archersPerTarget = (($finalAthTargetMask & $phaseBit) ? 2 : 1);
        $projectedParticipants = getProjectedFinalistsForEvent($row->FSEvent, $row->FSTeamEvent, $row->EvNumQualified);
        $distanceMeta = getEventDistanceMeta($row->FSEvent, $row->FSTeamEvent);

        $rows[] = [
            'teamEvent' => intval($row->FSTeamEvent),
            'event' => $row->FSEvent,
            'eventName' => $row->EvEventName,
            'matchNo' => intval($row->FSMatchNo),
            'scheduledDate' => $scheduledDate,
            'scheduledTime' => $scheduledTime,
            'scheduledLen' => intval($row->FSScheduledLen),
            'group' => intval($row->FSGroup),
            'target' => (string)$row->FSTarget,
            'letter' => (string)$row->FSLetter,
            'phase' => $phase,
            'gridPosition' => is_numeric($row->GrPosition) ? intval($row->GrPosition) : null,
            'gridPosition2' => is_numeric($row->GrPosition2) ? intval($row->GrPosition2) : null,
            'archersPerTarget' => $archersPerTarget,
            'projectedParticipants' => $projectedParticipants,
            'division' => trim((string)$row->EvDivisions),
            'class' => trim((string)$row->EvClasses),
            'eventDivisionOrder' => is_numeric($row->EventDivisionOrder) ? intval($row->EventDivisionOrder) : null,
            'eventClassOrder' => is_numeric($row->EventClassOrder) ? intval($row->EventClassOrder) : null,
            'distanceProfile' => trim((string)$distanceMeta['profile']),
            'distanceSort' => is_numeric($distanceMeta['sort']) ? intval($distanceMeta['sort']) : null,
            'hasParticipant' => (intval($row->FSTeamEvent) === 1)
                ? (intval($row->TfTeam) > 0 ? 1 : 0)
                : (intval($row->FinAthlete) > 0 ? 1 : 0),
        ];
    }

    // Planning fallback: if FinSchedule is empty, synthesize finals rows from
    // event bracket definitions so the day can still be planned.
    if (empty($rows) && $dateFilter === '') {
        $fallbackWhere = [
            'ev.EvTournament=' . StrSafe_DB($_SESSION['TourId']),
            'ev.EvFinalFirstPhase>0',
        ];

        if ($teamEvent !== '' && ($teamEvent === '0' || $teamEvent === '1')) {
            $fallbackWhere[] = 'ev.EvTeamEvent=' . StrSafe_DB(intval($teamEvent));
        }

        if (!empty($divisionFilter)) {
            $divList = array_map('StrSafe_DB', $divisionFilter);
            $fallbackWhere[] = "EXISTS (
                SELECT 1
                FROM EventClass ecDiv
                WHERE ecDiv.EcTournament=ev.EvTournament
                  AND ecDiv.EcCode=ev.EvCode
                  AND IF(ecDiv.EcTeamEvent!=0,1,0)=ev.EvTeamEvent
                  AND ecDiv.EcDivision IN (" . implode(',', $divList) . ")
            )";
        }

        if (!empty($classFilter)) {
            $clsList = array_map('StrSafe_DB', $classFilter);
            $fallbackWhere[] = "EXISTS (
                SELECT 1
                FROM EventClass ecCls
                WHERE ecCls.EcTournament=ev.EvTournament
                  AND ecCls.EcCode=ev.EvCode
                  AND IF(ecCls.EcTeamEvent!=0,1,0)=ev.EvTeamEvent
                  AND ecCls.EcClass IN (" . implode(',', $clsList) . ")
            )";
        }

        $fallbackSql = "SELECT
                            ev.EvTeamEvent AS FSTeamEvent,
                            ev.EvCode AS FSEvent,
                            ev.EvEventName,
                            gr.GrMatchNo AS FSMatchNo,
                            '' AS FSScheduledDate,
                            '' AS FSScheduledTime,
                            0 AS FSScheduledLen,
                            0 AS FSGroup,
                            '' AS FSTarget,
                            '' AS FSLetter,
                            gr.GrPhase,
                            gr.GrPosition,
                            gr.GrPosition2,
                            ev.EvNumQualified,
                            ev.EvFinalAthTarget,
                            fi.FinAthlete,
                            tf.TfTeam,
                            (
                                SELECT MIN(divs.DivViewOrder)
                                FROM EventClass ecDivOrder
                                INNER JOIN Divisions divs
                                    ON divs.DivTournament=ecDivOrder.EcTournament
                                    AND divs.DivId=ecDivOrder.EcDivision
                                WHERE ecDivOrder.EcTournament=ev.EvTournament
                                  AND ecDivOrder.EcCode=ev.EvCode
                                  AND IF(ecDivOrder.EcTeamEvent!=0,1,0)=ev.EvTeamEvent
                            ) AS EventDivisionOrder,
                            (
                                SELECT MIN(cls.ClViewOrder)
                                FROM EventClass ecClsOrder
                                INNER JOIN Classes cls
                                    ON cls.ClTournament=ecClsOrder.EcTournament
                                    AND cls.ClId=ecClsOrder.EcClass
                                WHERE ecClsOrder.EcTournament=ev.EvTournament
                                  AND ecClsOrder.EcCode=ev.EvCode
                                  AND IF(ecClsOrder.EcTeamEvent!=0,1,0)=ev.EvTeamEvent
                            ) AS EventClassOrder,
                            (
                                SELECT GROUP_CONCAT(DISTINCT ecDiv.EcDivision ORDER BY ecDiv.EcDivision SEPARATOR ',')
                                FROM EventClass ecDiv
                                WHERE ecDiv.EcTournament=ev.EvTournament
                                  AND ecDiv.EcCode=ev.EvCode
                                  AND IF(ecDiv.EcTeamEvent!=0,1,0)=ev.EvTeamEvent
                            ) AS EvDivisions,
                            (
                                SELECT GROUP_CONCAT(DISTINCT ecCls.EcClass ORDER BY ecCls.EcClass SEPARATOR ',')
                                FROM EventClass ecCls
                                WHERE ecCls.EcTournament=ev.EvTournament
                                  AND ecCls.EcCode=ev.EvCode
                                  AND IF(ecCls.EcTeamEvent!=0,1,0)=ev.EvTeamEvent
                            ) AS EvClasses
                        FROM Events ev
                        INNER JOIN Grids gr
                            ON gr.GrPhase IN (0,1,2,4,8,16,32,64)
                            AND gr.GrPhase<=ev.EvFinalFirstPhase
                        LEFT JOIN Finals fi
                            ON fi.FinTournament=ev.EvTournament
                            AND fi.FinEvent=ev.EvCode
                            AND fi.FinMatchNo=gr.GrMatchNo
                            AND ev.EvTeamEvent=0
                        LEFT JOIN TeamFinals tf
                            ON tf.TfTournament=ev.EvTournament
                            AND tf.TfEvent=ev.EvCode
                            AND tf.TfMatchNo=gr.GrMatchNo
                            AND ev.EvTeamEvent=1
                        WHERE " . implode(' AND ', $fallbackWhere) . "
                        ORDER BY ev.EvTeamEvent, ev.EvCode, gr.GrPhase, gr.GrMatchNo";

        $fallbackRs = safe_r_sql($fallbackSql);
        while ($row = safe_fetch($fallbackRs)) {
            $phase = intval($row->GrPhase);
            $phaseBit = max(1, $phase * 2);
            $finalAthTargetMask = intval($row->EvFinalAthTarget);
            $archersPerTarget = (($finalAthTargetMask & $phaseBit) ? 2 : 1);
            $projectedParticipants = getProjectedFinalistsForEvent($row->FSEvent, $row->FSTeamEvent, $row->EvNumQualified);
            $distanceMeta = getEventDistanceMeta($row->FSEvent, $row->FSTeamEvent);

            $rows[] = [
                'teamEvent' => intval($row->FSTeamEvent),
                'event' => $row->FSEvent,
                'eventName' => $row->EvEventName,
                'matchNo' => intval($row->FSMatchNo),
                'scheduledDate' => $row->FSScheduledDate,
                'scheduledTime' => $row->FSScheduledTime,
                'scheduledLen' => intval($row->FSScheduledLen),
                'group' => intval($row->FSGroup),
                'target' => (string)$row->FSTarget,
                'letter' => (string)$row->FSLetter,
                'phase' => $phase,
                'gridPosition' => is_numeric($row->GrPosition) ? intval($row->GrPosition) : null,
                'gridPosition2' => is_numeric($row->GrPosition2) ? intval($row->GrPosition2) : null,
                'archersPerTarget' => $archersPerTarget,
                'projectedParticipants' => $projectedParticipants,
                'division' => trim((string)$row->EvDivisions),
                'class' => trim((string)$row->EvClasses),
                'eventDivisionOrder' => is_numeric($row->EventDivisionOrder) ? intval($row->EventDivisionOrder) : null,
                'eventClassOrder' => is_numeric($row->EventClassOrder) ? intval($row->EventClassOrder) : null,
                'distanceProfile' => trim((string)$distanceMeta['profile']),
                'distanceSort' => is_numeric($distanceMeta['sort']) ? intval($distanceMeta['sort']) : null,
                'hasParticipant' => (intval($row->FSTeamEvent) === 1)
                    ? (intval($row->TfTeam) > 0 ? 1 : 0)
                    : (intval($row->FinAthlete) > 0 ? 1 : 0),
            ];
        }
    }

    // Build full available target list from qualification session setup
    $availableTargets = [];
    $rangeSql = "SELECT SesFirstTarget, SesTar4Session
                 FROM Session
                 WHERE SesTournament=" . StrSafe_DB($_SESSION['TourId']) . "
                   AND SesType='Q'";
    $rangeRs = safe_r_sql($rangeSql);

    $minTarget = null;
    $maxTarget = null;
    while ($sessionRow = safe_fetch($rangeRs)) {
        $first = intval($sessionRow->SesFirstTarget);
        $count = intval($sessionRow->SesTar4Session);
        if ($count <= 0) {
            continue;
        }

        $last = $first + $count - 1;
        $minTarget = $minTarget === null ? $first : min($minTarget, $first);
        $maxTarget = $maxTarget === null ? $last : max($maxTarget, $last);
    }

    // Ensure currently assigned finals targets are always visible in the grid
    foreach ($rows as $row) {
        $target = trim((string)$row['target']);
        if ($target === '' || !ctype_digit($target)) {
            continue;
        }

        $targetNo = intval($target);
        if ($targetNo <= 0) {
            continue;
        }

        $minTarget = $minTarget === null ? $targetNo : min($minTarget, $targetNo);
        $maxTarget = $maxTarget === null ? $targetNo : max($maxTarget, $targetNo);
    }

    if ($minTarget !== null && $maxTarget !== null) {
        for ($targetNo = $minTarget; $targetNo <= $maxTarget; $targetNo++) {
            $availableTargets[] = str_pad($targetNo, TargetNoPadding, '0', STR_PAD_LEFT);
        }
    }

    // Fallback: if no session range available, derive from finals rows
    if (empty($availableTargets)) {
        $derived = [];
        foreach ($rows as $row) {
            $target = trim($row['target']);
            if ($target !== '') {
                $derived[$target] = true;
            }
        }
        $availableTargets = array_keys($derived);
        sort($availableTargets);
    }

    $validationErrors = validateFinalRows($rows);
    $lastQualificationEnd = getLastQualificationSessionEnd();

    echo json_encode([
        'error' => 0,
        'rows' => $rows,
        'availableTargets' => $availableTargets,
        'lastQualificationEnd' => $lastQualificationEnd,
        'validationErrors' => $validationErrors,
    ]);
}

function applyChanges() {
    $changesRaw = $_REQUEST['changes'] ?? '[]';
    $changes = json_decode($changesRaw, true);

    if (!is_array($changes)) {
        echo json_encode(['error' => 1, 'message' => 'Invalid changes payload']);
        return;
    }

    if (count($changes) === 0) {
        echo json_encode(['error' => 0, 'message' => 'No changes to apply', 'updated' => 0]);
        return;
    }

    // Server-side validation before applying
    $rows = loadFinalRowsForValidation();
    $rowsAfter = applyChangesToRows($rows, $changes);
    $focus = buildValidationFocusFromChanges($rowsAfter, $changes);
    $validationErrors = validateFinalRows($rowsAfter, $focus);
    if (!empty($validationErrors)) {
        echo json_encode([
            'error' => 1,
            'message' => $validationErrors[0]['message'],
            'errors' => $validationErrors,
        ]);
        return;
    }

    $updated = 0;

    foreach ($changes as $change) {
        $teamEvent = intval($change['teamEvent'] ?? 0);
        $event = trim($change['event'] ?? '');
        $matchNo = intval($change['matchNo'] ?? 0);
        $target = strtoupper(trim($change['target'] ?? ''));
        $scheduledDate = trim($change['scheduledDate'] ?? '');
        $scheduledTime = trim($change['scheduledTime'] ?? '');
        $scheduledLen = max(0, intval($change['scheduledLen'] ?? 0));

        if ($scheduledDate === '') {
            $scheduledLen = 0;
        }

        if ($event === '' || $matchNo < 0) {
            continue;
        }

        // Keep formatting consistent with existing data
        if ($target !== '' && ctype_digit($target)) {
            $target = str_pad($target, TargetNoPadding, '0', STR_PAD_LEFT);
        }

        $letter = $target;

        $sql = "INSERT INTO FinSchedule
                    (FSTournament, FSTeamEvent, FSEvent, FSMatchNo, FSGroup, FSTarget, FSLetter, FSScheduledDate, FSScheduledTime, FSScheduledLen)
                VALUES
                    (" . StrSafe_DB($_SESSION['TourId']) . ", " . StrSafe_DB($teamEvent) . ", " . StrSafe_DB($event) . ", " . StrSafe_DB($matchNo) . ", 0, " . StrSafe_DB($target) . ", " . StrSafe_DB($letter) . ", " . StrSafe_DB($scheduledDate) . ", " . StrSafe_DB($scheduledTime) . ", " . StrSafe_DB($scheduledLen) . ")
                ON DUPLICATE KEY UPDATE
                    FSTarget=VALUES(FSTarget),
                    FSLetter=VALUES(FSLetter),
                    FSScheduledDate=VALUES(FSScheduledDate),
                    FSScheduledTime=VALUES(FSScheduledTime),
                    FSScheduledLen=VALUES(FSScheduledLen)";

        safe_w_sql($sql);
        if (safe_w_affected_rows() >= 0) {
            $updated++;
        }
    }

    echo json_encode([
        'error' => 0,
        'message' => 'Applied finals setup changes',
        'updated' => $updated
    ]);
}
