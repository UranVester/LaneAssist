<?php

function laneAssistCompletedEnds($arrowString, $arrowsPerEnd) {
    $arrowsPerEnd = max(1, intval($arrowsPerEnd));
    return intdiv(strlen(rtrim((string)$arrowString)), $arrowsPerEnd);
}

function laneAssistPersonalBestKey($firstName, $lastName, $club, $division) {
    $normalize = function($value) {
        return preg_replace('/\s+/', ' ', strtolower(trim((string)$value)));
    };
    return $normalize($firstName) . '|' . $normalize($lastName) . '|' . $normalize($club) . '|' . $normalize($division);
}

function laneAssistAttachPersonalBests(array $archers, array $historicalScores) {
    $bestScores = [];
    foreach ($historicalScores as $score) {
        $key = laneAssistPersonalBestKey($score['firstName'] ?? '', $score['lastName'] ?? '', $score['club'] ?? '', $score['division'] ?? '');
        $points = max(0, intval($score['score'] ?? 0));
        if (!isset($bestScores[$key]) || $points > $bestScores[$key]['score']) {
            $bestScores[$key] = ['score' => $points, 'competitionName' => trim((string)($score['competitionName'] ?? ''))];
        }
    }

    foreach ($archers as &$archer) {
        $key = laneAssistPersonalBestKey($archer['firstName'] ?? '', $archer['lastName'] ?? '', $archer['club'] ?? '', $archer['division'] ?? '');
        $personalBest = $bestScores[$key] ?? null;
        $archer['personalBest'] = $personalBest['score'] ?? null;
        $archer['personalBestCompetitionName'] = $personalBest['competitionName'] ?? '';
        $archer['hasNewPersonalBest'] = $personalBest !== null && intval($archer['totalPoints'] ?? 0) > $personalBest['score'];
    }
    unset($archer);
    return $archers;
}

function laneAssistLastEndPoints($arrowString, $arrowsPerEnd, callable $decodeArrow) {
    $arrowString = rtrim((string)$arrowString);
    $arrowsPerEnd = max(1, intval($arrowsPerEnd));
    $completedEnds = intdiv(strlen($arrowString), $arrowsPerEnd);
    if ($completedEnds === 0) {
        return null;
    }

    $lastEnd = substr($arrowString, ($completedEnds - 1) * $arrowsPerEnd, $arrowsPerEnd);
    $points = 0;
    foreach (str_split($lastEnd) as $arrow) {
        $points += intval($decodeArrow($arrow));
    }
    return $points;
}

function laneAssistFinalSetPoints($arrowString, $arrowsPerEnd, callable $decodeArrow, $maximumSets = 5) {
    $maximumSets = max(0, intval($maximumSets));
    $arrowsPerEnd = max(1, intval($arrowsPerEnd));
    $arrowString = rtrim((string)$arrowString);
    $points = [];
    for ($set = 0; $set < $maximumSets; $set++) {
        $arrows = substr($arrowString, $set * $arrowsPerEnd, $arrowsPerEnd);
        if (strlen($arrows) < $arrowsPerEnd) {
            $points[] = null;
            continue;
        }
        $points[] = array_sum(array_map(function($arrow) use ($decodeArrow) {
            return intval($decodeArrow($arrow));
        }, str_split($arrows)));
    }
    return $points;
}

function laneAssistMarkQualificationLag(array $archers) {
    $counts = [];
    $distanceCounts = [];
    foreach ($archers as $archer) {
        if (!empty($archer['retired'])) {
            continue;
        }
        $ends = intval($archer['completedEnds'] ?? 0);
        $counts[$ends] = ($counts[$ends] ?? 0) + 1;

        $distance = max(1, intval($archer['distance'] ?? 1));
        $distanceCounts[$distance] = ($distanceCounts[$distance] ?? 0) + 1;
    }

    $expectedEnds = 0;
    $largestGroup = 0;
    foreach ($counts as $ends => $count) {
        if ($count > $largestGroup || ($count === $largestGroup && intval($ends) > $expectedEnds)) {
            $expectedEnds = intval($ends);
            $largestGroup = $count;
        }
    }

    // Same most-common-wins rule as $expectedEnds, so the header's "distance"
    // and "end" agree on which archers they were read from.
    $expectedDistance = 1;
    $largestDistanceGroup = 0;
    foreach ($distanceCounts as $distance => $count) {
        if ($count > $largestDistanceGroup || ($count === $largestDistanceGroup && intval($distance) > $expectedDistance)) {
            $expectedDistance = intval($distance);
            $largestDistanceGroup = $count;
        }
    }

    foreach ($archers as &$archer) {
        if (!empty($archer['retired'])) {
            $archer['isBehind'] = false;
            $archer['isAhead'] = false;
            continue;
        }
        $archer['isBehind'] = intval($archer['completedEnds'] ?? 0) < $expectedEnds;
        $archer['isAhead'] = intval($archer['completedEnds'] ?? 0) > $expectedEnds;
    }
    unset($archer);

    return ['expectedEnds' => $expectedEnds, 'expectedDistance' => $expectedDistance, 'archers' => $archers];
}

function laneAssistMostCommonProgress(array $values) {
    $counts = [];
    foreach ($values as $value) {
        $value = intval($value);
        $counts[$value] = ($counts[$value] ?? 0) + 1;
    }
    $progress = 0;
    $largestGroup = 0;
    foreach ($counts as $value => $count) {
        if ($count > $largestGroup || ($count === $largestGroup && intval($value) > $progress)) {
            $progress = intval($value);
            $largestGroup = $count;
        }
    }
    return $progress;
}

function laneAssistQualificationProgress(array $mats) {
    $archers = [];
    foreach ($mats as $mat) {
        $archers = array_merge($archers, $mat['archers'] ?? []);
    }
    $archers = array_values(array_filter($archers, function($archer) {
        return empty($archer['retired']);
    }));
    if (!$archers) {
        return ['end' => 0, 'arrowsShot' => 0, 'totalArrows' => 0, 'complete' => false];
    }

    $arrowsShot = laneAssistMostCommonProgress(array_column($archers, 'arrowsShot'));
    $totalArrows = max(array_map('intval', array_column($archers, 'totalArrows')));
    $endsCompleted = laneAssistMostCommonProgress(array_column($archers, 'completedTotalEnds'));
    $totalEnds = max(array_map('intval', array_column($archers, 'totalEnds')));

    return [
        'end' => min($totalEnds, $endsCompleted + 1),
        'arrowsShot' => $arrowsShot,
        'totalArrows' => $totalArrows,
        'complete' => $totalArrows > 0 && !array_filter($archers, function($archer) {
            return intval($archer['arrowsShot'] ?? 0) < intval($archer['totalArrows'] ?? 0);
        }),
    ];
}

/**
 * The per-mat pace fields (expectedDistance/expectedEnds/hasMultipleDistances)
 * are identical on every mat, so the first one speaks for the whole session.
 * Folded into qualificationProgress so the page header can show the same
 * "distance N end M" breakdown as the mat cards, alongside its own
 * unambiguous cumulative end count.
 */
function laneAssistAttachDistanceProgress(array $progress, array $mats) {
    if (empty($mats)) {
        return $progress;
    }
    $progress['hasMultipleDistances'] = !empty($mats[0]['hasMultipleDistances']);
    $progress['expectedDistance'] = intval($mats[0]['expectedDistance'] ?? 1);
    $progress['currentDistanceEnd'] = intval($mats[0]['expectedEnds'] ?? 0);
    return $progress;
}

function laneAssistFinalsProgress(array $matches) {
    $active = array_values(array_filter($matches, function($match) {
        return empty($match['advanced']) && !in_array($match['status'] ?? '', ['bye', 'complete'], true);
    }));
    if (!$active) {
        $totalEnds = 0;
        $complete = !empty($matches);
        foreach ($matches as $match) {
            $totalEnds = max($totalEnds, intval($match['totalEnds'] ?? 0));
            $complete = $complete && in_array($match['status'] ?? '', ['bye', 'complete', 'advanced'], true);
        }
        return ['end' => $complete ? $totalEnds : 0, 'totalEnds' => $totalEnds, 'complete' => $complete];
    }

    $completedEnds = [];
    $totalEnds = 0;
    foreach ($active as $match) {
        $totalEnds = max($totalEnds, intval($match['totalEnds'] ?? 0));
        foreach ($match['sides'] ?? [] as $side) {
            if (!empty($side['participantId'])) {
                $completedEnds[] = intval($side['completedEnds'] ?? 0);
            }
        }
    }

    return [
        'end' => min($totalEnds, laneAssistMostCommonProgress($completedEnds) + 1),
        'totalEnds' => $totalEnds,
        'complete' => false,
    ];
}

function laneAssistFinalMatchStatus(array $sides, $arrowsPerEnd) {
    $present = array_values(array_filter($sides, function($side) {
        return !empty($side['participantId']);
    }));
    $winnerSet = false;
    foreach ($sides as $side) {
        $winnerSet = $winnerSet || !empty($side['winLose']) || intval($side['tie'] ?? 0) === 2;
    }

    if (count($present) < 2) {
        return count($present) === 1 && !$winnerSet ? 'bye' : 'complete';
    }
    if ($winnerSet) {
        return 'complete';
    }

    $arrowsPerEnd = max(1, intval($arrowsPerEnd));
    $arrowCounts = array_map(function($side) {
        return strlen(rtrim((string)($side['arrowString'] ?? '')));
    }, $present);
    if (max($arrowCounts) === 0) {
        return 'unreported';
    }
    foreach ($arrowCounts as $count) {
        if ($count % $arrowsPerEnd !== 0) {
            return 'partial';
        }
    }
    if (count(array_unique($arrowCounts)) > 1) {
        return 'uneven';
    }
    return 'live';
}

function laneAssistFinalWinnerId(array $sides) {
    foreach ($sides as $side) {
        if (!empty($side['participantId']) && (!empty($side['winLose']) || intval($side['tie'] ?? 0) === 2)) {
            return intval($side['participantId']);
        }
    }

    $present = array_values(array_filter($sides, function($side) {
        return !empty($side['participantId']);
    }));
    return count($present) === 1 ? intval($present[0]['participantId']) : 0;
}

function laneAssistByeWinnerMatchNo(array $match) {
    if (($match['status'] ?? '') !== 'bye') {
        return null;
    }
    foreach ($match['sides'] ?? [] as $side) {
        if (!empty($side['participantId']) && isset($side['matchNo'])) {
            return intval($side['matchNo']);
        }
    }
    return null;
}

function laneAssistFinalWinnerDestination($phase, $matchNo) {
    $phase = intval($phase);
    if ($phase < 2) {
        return null;
    }
    $baseMatch = intval($matchNo) - (intval($matchNo) % 2);
    return $phase === 2 ? intdiv($baseMatch, 2) - 2 : intdiv($baseMatch, 2);
}

function laneAssistFinalMatchAdvanced(array $match, array $participantsByMatch) {
    $winnerId = laneAssistFinalWinnerId($match['sides'] ?? []);
    $destination = laneAssistFinalWinnerDestination($match['phase'] ?? 0, $match['matchNo'] ?? 0);
    return $winnerId > 0 && $destination !== null && intval($participantsByMatch[$destination] ?? 0) === $winnerId;
}

function laneAssistFinalMatchCanMarkBye(array $match) {
    return empty($match['advanced']) && ($match['status'] ?? '') === 'bye';
}

function laneAssistSelectCurrentFinalMatches(array $matches) {
    $scheduledSlots = [];
    foreach ($matches as $match) {
        $slot = trim((string)($match['scheduledSlot'] ?? ''));
        if ($slot !== '' && $slot !== '0000-00-00 00:00:00') {
            $scheduledSlots[$slot] = true;
        }
    }
    $scheduledSlots = array_keys($scheduledSlots);
    sort($scheduledSlots);

    foreach ($scheduledSlots as $slot) {
        $scopes = [];
        foreach ($matches as $match) {
            if (($match['scheduledSlot'] ?? '') === $slot) {
                $scopes[intval($match['teamEvent'] ?? 0) . '|' . ($match['event'] ?? '') . '|' . intval($match['phase'] ?? 0)] = true;
            }
        }

        $slotWork = array_values(array_filter($matches, function($match) use ($slot, $scopes) {
            if (($match['scheduledSlot'] ?? '') === $slot) {
                return true;
            }
            $scope = intval($match['teamEvent'] ?? 0) . '|' . ($match['event'] ?? '') . '|' . intval($match['phase'] ?? 0);
            return ($match['scheduledSlot'] ?? '') === ''
                && in_array(($match['status'] ?? ''), ['bye', 'advanced'], true)
                && isset($scopes[$scope]);
        }));

        foreach ($slotWork as $match) {
            if (empty($match['advanced'])) {
                $visibleBlock = array_values(array_filter($matches, function($blockMatch) use ($slot) {
                    return ($blockMatch['scheduledSlot'] ?? '') === $slot
                        || (($blockMatch['scheduledSlot'] ?? '') === ''
                            && ($blockMatch['status'] ?? '') === 'bye'
                            && empty($blockMatch['advanced']));
                }));
                return ['slot' => $slot, 'matches' => $visibleBlock];
            }
        }
    }

    return ['slot' => '', 'matches' => []];
}

/**
 * Groups all finals matches into per-scheduled-slot "rounds" for browsing,
 * chronologically ordered with any leftover unscheduled matches last. The
 * round that matches laneAssistSelectCurrentFinalMatches()'s slot is
 * replaced wholesale by its own match list (rather than the raw matches at
 * that slot), so its unscheduled-bye folding survives unchanged; those
 * folded-in matches are excluded from whichever raw slot they actually
 * belong to so they don't also appear a second time in another round.
 * When no scheduled slot exists at all, laneAssistSelectCurrentFinalMatches()
 * has nothing to report (its 'matches' is always empty in that case), so the
 * raw '' bucket is left untouched and simply flagged current instead.
 */
function laneAssistGroupFinalRounds(array $matches) {
    $identity = function($match) {
        return intval($match['teamEvent'] ?? 0) . '|' . ($match['event'] ?? '') . '|' . intval($match['matchNo'] ?? 0);
    };

    $current = laneAssistSelectCurrentFinalMatches($matches);
    $currentKeys = [];
    foreach ($current['matches'] as $match) {
        $currentKeys[$identity($match)] = true;
    }

    $buckets = [];
    foreach ($matches as $match) {
        if (isset($currentKeys[$identity($match)])) {
            continue;
        }
        $slot = trim((string)($match['scheduledSlot'] ?? ''));
        $buckets[$slot][] = $match;
    }
    if ($current['slot'] !== '') {
        $buckets[$current['slot']] = $current['matches'];
    }

    $slots = array_keys($buckets);
    usort($slots, function($a, $b) {
        if ($a === $b) return 0;
        if ($a === '') return 1;
        if ($b === '') return -1;
        return strcmp($a, $b);
    });

    $rounds = [];
    foreach ($slots as $slot) {
        $rounds[] = ['slot' => $slot, 'matches' => $buckets[$slot], 'isCurrent' => $slot === $current['slot']];
    }
    return $rounds;
}

/**
 * Whether this archer has any recorded arrows in this session, across all
 * distances. Enforced server-side so the "disable" action stays limited to
 * genuine no-shows even if a stale client sends a stale request.
 */
function archerHasQualificationArrows($enId, $session) {
    $row = safe_fetch(safe_r_sql("SELECT QuD1Arrowstring, QuD2Arrowstring, QuD3Arrowstring, QuD4Arrowstring,
            QuD5Arrowstring, QuD6Arrowstring, QuD7Arrowstring, QuD8Arrowstring
        FROM Qualifications
        WHERE QuId=" . StrSafe_DB($enId) . " AND QuSession=" . StrSafe_DB($session)));
    if (!$row) {
        return false;
    }
    for ($distance = 1; $distance <= 8; $distance++) {
        $field = 'QuD' . $distance . 'Arrowstring';
        if (strlen(str_replace(' ', '', rtrim((string)$row->{$field}))) > 0) {
            return true;
        }
    }
    return false;
}

/**
 * Validate and apply the "Pull Out" (EnStatus=6) / restore toggle for one
 * archer, mirroring Qualification/Went2Home.php's Forfeit action. Does NOT
 * recalculate ranks/teams — callers run that (tournament-wide) pass once,
 * after every archer in a batch has been updated, rather than once per archer.
 * Returns ['ok'=>bool,'message'=>string,'retired'=>bool,'participantId'=>int].
 */
function performArcherRetireToggle($enId, $session, $tourId) {
    $entry = safe_fetch(safe_r_sql("SELECT EnStatus FROM Entries
        WHERE EnTournament=" . StrSafe_DB($tourId) . " AND EnId=" . StrSafe_DB($enId)));
    if (!$entry) {
        return ['ok' => false, 'message' => 'Participant not found', 'participantId' => $enId];
    }

    $currentlyRetired = intval($entry->EnStatus) === 6;
    if (!$currentlyRetired && archerHasQualificationArrows($enId, $session)) {
        return ['ok' => false, 'message' => 'This archer has recorded arrows and cannot be forfeited here', 'participantId' => $enId];
    }

    if ($currentlyRetired) {
        safe_w_sql("UPDATE Entries e
            LEFT JOIN LookUpEntries l ON e.EnCode=l.LueCode AND e.EnTournament=" . StrSafe_DB($tourId) . "
            SET e.EnStatus=IFNULL(l.LueStatus,0)
            WHERE e.EnTournament=" . StrSafe_DB($tourId) . " AND e.EnId=" . StrSafe_DB($enId));
    } else {
        $zeroFields = '';
        for ($distance = 1; $distance <= 8; $distance++) {
            $zeroFields .= "QuD{$distance}Score='0', QuD{$distance}Gold='0', QuD{$distance}Xnine='0', ";
        }
        safe_w_sql("UPDATE Entries INNER JOIN Qualifications ON EnId=QuId
            SET EnStatus='6', $zeroFields QuScore='0', QuGold='0', QuXnine='0'
            WHERE EnTournament=" . StrSafe_DB($tourId) . " AND QuId=" . StrSafe_DB($enId));
    }

    return ['ok' => true, 'message' => '', 'retired' => !$currentlyRetired, 'participantId' => $enId];
}
