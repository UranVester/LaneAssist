<?php
/**
 * Finals lane print: pure rules (no DB, no get_text).
 *
 * The sheet is printed before a round starts, so it may only be printed once
 * every archer in the round is placed: a wrong early print means the crew hangs
 * the wrong faces. An empty side is only a real bye when nobody can ever reach
 * it, which means looking all the way down its bracket subtree, not just at the
 * match directly feeding it.
 *
 * Grid numbering (consistent with laneAssistFinalWinnerDestination()): gold is
 * 0/1, bronze 2/3, semis 4-7, then each side s is fed by the pair (2s, 2s+1).
 * Gold is fed by the semi winners, bronze by the semi losers.
 *
 * $eventSides is [matchNo => bool placed] for every Finals/TeamFinals row of one event.
 */

/**
 * The pair feeding a side, and whether the side takes that pair's loser.
 *
 * @return array{pair: int[], loser: bool}|null
 */
function fpFeedingPair(int $side): ?array
{
    if ($side < 0) {
        return null;
    }
    if ($side < 4) {
        $base = ($side % 2 === 0) ? 4 : 6;
        return ['pair' => [$base, $base + 1], 'loser' => $side >= 2];
    }
    return ['pair' => [2 * $side, 2 * $side + 1], 'loser' => false];
}

/** Whether an archer is on this side now or can still arrive at it. */
function fpSideCanBeFilled(int $side, array $eventSides): bool
{
    if (!empty($eventSides[$side])) {
        return true;
    }
    $feeding = fpFeedingPair($side);
    if ($feeding === null) {
        return false;
    }
    [$a, $b] = $feeding['pair'];
    if (!array_key_exists($a, $eventSides) && !array_key_exists($b, $eventSides)) {
        return false;
    }
    $fillA = array_key_exists($a, $eventSides) && fpSideCanBeFilled($a, $eventSides);
    $fillB = array_key_exists($b, $eventSides) && fpSideCanBeFilled($b, $eventSides);
    // A loser only exists if the feeding match is actually shot by two archers
    return $feeding['loser'] ? ($fillA && $fillB) : ($fillA || $fillB);
}

function fpSideIsBye(int $side, array $eventSides): bool
{
    return empty($eventSides[$side]) && !fpSideCanBeFilled($side, $eventSides);
}

function fpEventKey(array $row): string
{
    return intval($row['teamEvent'] ?? 0) . '|' . (string)($row['event'] ?? '');
}

/**
 * @param array $roundMatches [['teamEvent'=>int,'event'=>string,'matchNo'=>int (even base)], ...]
 * @param array $sidesByEvent "team|event" => [matchNo => bool placed]
 * @param array $projectedByEvent "team|event" => projected finalists; 0 means nobody is
 *   entered (phantom bracket, skipped). Missing means unknown and is treated as waiting.
 * @return array{ready: bool, waiting: int}
 */
function fpRoundReadiness(array $roundMatches, array $sidesByEvent, array $projectedByEvent = []): array
{
    $waiting = 0;
    $real = 0;
    foreach ($roundMatches as $match) {
        $key = fpEventKey($match);
        $sides = $sidesByEvent[$key] ?? [];
        // Core creates an event's whole empty bracket once it has a first phase,
        // so before it is moved to finals every side would look like a bye.
        if (!in_array(true, $sides, true)) {
            if (array_key_exists($key, $projectedByEvent) && intval($projectedByEvent[$key]) === 0) {
                continue;
            }
            $real++;
            $waiting++;
            continue;
        }
        $real++;
        $base = intval($match['matchNo']) - (intval($match['matchNo']) % 2);
        foreach ([$base, $base + 1] as $side) {
            if (empty($sides[$side]) && !fpSideIsBye($side, $sides)) {
                $waiting++;
                break;
            }
        }
    }
    return ['ready' => $waiting === 0 && $real > 0, 'waiting' => $waiting];
}

/**
 * Group the round's placed sides into lanes. Bye matches are dropped entirely
 * (nobody shoots them); every other placed side is one face on its own lane.
 *
 * @param array $roundSides side rows with raw FSTarget ('target') and FSLetter ('letter')
 * @return array{lanes: array, unassigned: array}
 */
function fpBuildLanes(array $roundSides, array $sidesByEvent): array
{
    $byLane = [];
    $unassigned = [];
    foreach ($roundSides as $row) {
        $sides = $sidesByEvent[fpEventKey($row)] ?? [];
        $matchNo = intval($row['matchNo']);
        $base = $matchNo - ($matchNo % 2);
        if (empty($sides[$matchNo]) || fpSideIsBye($base, $sides) || fpSideIsBye($base + 1, $sides)) {
            continue;
        }
        $lane = intval($row['target'] ?? 0);
        if ($lane <= 0) {
            $unassigned[] = $row;
            continue;
        }
        $byLane[$lane][] = $row;
    }

    $order = function ($a, $b) {
        return [intval($a['teamEvent']), (string)$a['event'], intval($a['matchNo'])]
            <=> [intval($b['teamEvent']), (string)$b['event'], intval($b['matchNo'])];
    };
    ksort($byLane, SORT_NUMERIC);
    usort($unassigned, $order);

    $lanes = [];
    foreach ($byLane as $lane => $targets) {
        usort($targets, $order);
        $used = [];
        foreach ($targets as &$target) {
            $target['letterLabel'] = '';
            if (preg_match('/^\d+([A-Z])$/', (string)($target['letter'] ?? ''), $m)) {
                $target['letterLabel'] = $m[1];
                $used[$m[1]] = true;
            }
        }
        unset($target);
        // LaneAssist's ManageFinals stores FSLetter = FSTarget (no letter)
        $next = 'A';
        foreach ($targets as &$target) {
            if ($target['letterLabel'] !== '') {
                continue;
            }
            while (isset($used[$next])) {
                $next = chr(ord($next) + 1);
            }
            $target['letterLabel'] = $next;
            $used[$next] = true;
        }
        unset($target);
        $lanes[] = ['lane' => $lane, 'targets' => $targets];
    }
    return ['lanes' => $lanes, 'unassigned' => $unassigned];
}

/**
 * The face to hang for one side: the archer's own chosen face (Entries.EnTargetFace)
 * when there is one, else the event's finals face (team sides, unplaced sides, or an
 * archer whose face record has nothing for distance 1).
 *
 * @param array $eventFace ['id' => int TarId, 'size' => int cm]
 * @param array|null $archerFace same shape, or null when no archer is resolved
 * @return array ['id' => int, 'size' => int]
 */
function fpSideFace(array $eventFace, ?array $archerFace): array
{
    if ($archerFace === null || intval($archerFace['id'] ?? 0) <= 0) {
        return ['id' => intval($eventFace['id'] ?? 0), 'size' => intval($eventFace['size'] ?? 0)];
    }
    $size = intval($archerFace['size'] ?? 0);
    return [
        'id' => intval($archerFace['id']),
        'size' => $size > 0 ? $size : intval($eventFace['size'] ?? 0),
    ];
}

/**
 * Lane sheet body: one card per lane (number on top, faces side by side), all in
 * one wrapping container so short lanes share a line instead of a row each.
 *
 * @param array $lanes fpBuildLanes()['lanes']
 * @param callable $phaseLabel phase id => display label
 */
function fpRenderLanesHtml(array $lanes, callable $phaseLabel): string
{
    $h = function ($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); };
    $html = '<div class="lanes">';
    foreach ($lanes as $lane) {
        $html .= '<div class="lane-card"><div class="lane-no">' . intval($lane['lane']) . '</div><div class="targets">';
        foreach ($lane['targets'] as $target) {
            $html .= '<div class="target"><div class="letter">' . $h($target['letterLabel']) . '</div>';
            if ((string)($target['faceUrl'] ?? '') !== '') {
                $html .= '<img src="' . $h($target['faceUrl']) . '" alt="">';
            }
            $html .= '<div class="face">' . $h($target['faceLabel']) . '</div>'
                . '<div class="meta">' . $h($target['eventName'] . ' · ' . $phaseLabel($target['phase'])) . '</div>';
            if ((string)($target['distance'] ?? '') !== '') {
                $html .= '<div class="meta">' . $h($target['distance']) . ' m</div>';
            }
            $html .= '</div>';
        }
        $html .= '</div></div>';
    }
    return $html . '</div>';
}
