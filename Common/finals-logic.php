<?php
/**
 * LaneAssist Finals — match/validation logic.
 *
 * Most functions here are pure (no DB, no session) and safe to unit test
 * directly. EXCEPTION: getProjectedFinalistsForEvent() is DB- and
 * session-bound (reads $_SESSION['TourId'] and runs safe_r_sql). Defining it
 * needs no DB; CALLING it requires an IANSEO request context or a test that
 * bootstraps the DB layer. The pure tests never call it.
 */

if (!defined('TargetNoPadding')) {
    define('TargetNoPadding', 4);
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
    if ($time === '') {
        return '';
    }
    return $time;
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

/**
 * Apply the finalist-count rule: entrants clamped to >= 0, then capped by
 * numQualified when a positive cap is set. In planning mode the caller passes
 * the count of entrants carrying the finals-type flag (assumed to continue to
 * finals); post-qualification it passes the seated participant count.
 */
function projectFinalists($entrantCount, $numQualified)
{
    $entrantCount = max(0, intval($entrantCount));
    $numQualified = max(0, intval($numQualified));
    if ($numQualified > 0) {
        return min($entrantCount, $numQualified);
    }
    return $entrantCount;
}

function getProjectedFinalistsForEvent($eventCode, $teamEvent, $eventNumQualified) {
    static $cache = [];

    $cacheKey = intval($_SESSION['TourId']) . '|' . intval($teamEvent) . '|' . trim((string)$eventCode) . '|' . intval($eventNumQualified);
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $team = intval($teamEvent) !== 0 ? 1 : 0;
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

        // Final fallback: count teams marked for finals directly (no TeamFinComponent yet)
        if ($entrantCount === 0) {
            $directSql = "SELECT COUNT(DISTINCT CONCAT(t.TeCoId,'-',t.TeSubTeam)) AS Cnt
                          FROM Teams t
                          WHERE t.TeTournament=" . StrSafe_DB($_SESSION['TourId']) . "
                            AND t.TeEvent=" . StrSafe_DB($eventCode) . "
                            AND t.TeFinEvent=1
                            AND t.TeSO!=0";
            $directRs = safe_r_sql($directSql);
            if ($directRow = safe_fetch($directRs)) {
                $entrantCount = max(0, intval($directRow->Cnt));
            }
        }
    }

    $entrantCount = projectFinalists($entrantCount, $eventNumQualified);

    $cache[$cacheKey] = $entrantCount;
    return $entrantCount;
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
