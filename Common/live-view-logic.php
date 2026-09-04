<?php

function laneAssistCompletedEnds($arrowString, $arrowsPerEnd) {
    $arrowsPerEnd = max(1, intval($arrowsPerEnd));
    return intdiv(strlen(rtrim((string)$arrowString)), $arrowsPerEnd);
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
    foreach ($archers as $archer) {
        $ends = intval($archer['completedEnds'] ?? 0);
        $counts[$ends] = ($counts[$ends] ?? 0) + 1;
    }

    $expectedEnds = 0;
    $largestGroup = 0;
    foreach ($counts as $ends => $count) {
        if ($count > $largestGroup || ($count === $largestGroup && intval($ends) > $expectedEnds)) {
            $expectedEnds = intval($ends);
            $largestGroup = $count;
        }
    }

    foreach ($archers as &$archer) {
        $archer['isBehind'] = intval($archer['completedEnds'] ?? 0) < $expectedEnds;
        $archer['isAhead'] = intval($archer['completedEnds'] ?? 0) > $expectedEnds;
    }
    unset($archer);

    return ['expectedEnds' => $expectedEnds, 'archers' => $archers];
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
