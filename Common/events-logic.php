<?php
/**
 * LaneAssist "Change finals events" — pure decision logic (no DB, no session).
 *
 * Mirrors the status-logic.php / finals-logic.php split: everything here is
 * DB-free, so it's safe to unit test directly. Later tasks add plan-building
 * logic (ceBuildPlan et al.) to this same file.
 */

/**
 * Standard bracket phase ids, smallest first. Each phase's qualified-entrant
 * capacity is phase*2 (phase 2 -> 4 qualified, phase 4 -> 8, ... phase 64 ->
 * 128), matching numQualifiedByPhase()'s doubling scheme. 2 is the smallest
 * usable "first phase" -- 0/1 are the Gold/Bronze medal matches themselves,
 * not a bracket entry point.
 */
const CE_STANDARD_BRACKET_PHASES = [2, 4, 8, 16, 32, 64];

/**
 * Smallest standard bracket phase whose qualified-entrant capacity covers
 * $count, capped at the largest standard phase when $count exceeds them all.
 * A brand-new, totally empty tournament (no participants at all yet)
 * defaults to 16 rather than the bare minimum; otherwise 0 entrants means
 * no bracket at all. Fewer than 2 entrants can't fill even a medal match,
 * so that's also "no bracket" (0).
 */
function ceBracketForCount(int $count, bool $tournamentEmpty): int
{
    if ($count === 0) {
        return $tournamentEmpty ? 16 : 0;
    }

    if ($count < 2) {
        return 0;
    }

    foreach (CE_STANDARD_BRACKET_PHASES as $phase) {
        if ($phase * 2 >= $count) {
            return $phase;
        }
    }

    $phases = CE_STANDARD_BRACKET_PHASES;
    return end($phases);
}

/**
 * Buckets classes by (ageFrom, ageTo) and, within each bucket, merges them
 * into age groups: walking in ascending 'order', a class joins the first
 * group in its bucket that has no member of the same sex yet, otherwise it
 * starts a new group. Returns every input class id mapped to its group's
 * computed info -- single-class groups keep their own id/name/order,
 * merged groups get a combined code/name (see ceCommonCode()/ceCommonName())
 * and the minimum member order.
 */
function ceAgeGroups(array $classes): array
{
    $sorted = $classes;
    usort($sorted, function ($a, $b) {
        return $a['order'] <=> $b['order'];
    });

    $bucketGroups = [];
    foreach ($sorted as $class) {
        $bucketKey = $class['ageFrom'] . ':' . $class['ageTo'];
        if (!isset($bucketGroups[$bucketKey])) {
            $bucketGroups[$bucketKey] = [];
        }

        $placed = false;
        foreach ($bucketGroups[$bucketKey] as $groupIndex => $group) {
            $hasSameSex = false;
            foreach ($group as $member) {
                if ($member['sex'] === $class['sex']) {
                    $hasSameSex = true;
                    break;
                }
            }
            if (!$hasSameSex) {
                $bucketGroups[$bucketKey][$groupIndex][] = $class;
                $placed = true;
                break;
            }
        }

        if (!$placed) {
            $bucketGroups[$bucketKey][] = [$class];
        }
    }

    $result = [];
    foreach ($bucketGroups as $groups) {
        foreach ($groups as $group) {
            $groupInfo = ceGroupInfo($group);
            foreach ($group as $member) {
                $result[$member['id']] = $groupInfo;
            }
        }
    }

    return $result;
}

/**
 * Computes the ['key','code','name','order'] info shared by every member of
 * one age group (as produced by ceAgeGroups()'s bucketing/merging walk).
 */
function ceGroupInfo(array $group): array
{
    if (count($group) === 1) {
        $class = $group[0];
        return [
            'key' => 'age:' . $class['id'],
            'code' => $class['id'],
            'name' => $class['name'],
            'order' => $class['order'],
        ];
    }

    $ids = array_column($group, 'id');
    $names = array_column($group, 'name');
    $orders = array_column($group, 'order');

    $sortedIds = $ids;
    sort($sortedIds);

    return [
        'key' => 'age:' . implode('+', $sortedIds),
        'code' => ceCommonCode($ids),
        'name' => ceCommonName($names),
        'order' => min($orders),
    ];
}

/**
 * Characters common to every id at the same position (e.g. HJ/DJ -> "J",
 * U21M/U21W -> "U21"), trimmed; falls back to the first id if nothing
 * lines up (e.g. AB/XY share no position, so the code is just "AB").
 */
function ceCommonCode(array $ids): string
{
    $first = $ids[0];
    $minLength = min(array_map('strlen', $ids));

    $common = '';
    for ($position = 0; $position < $minLength; $position++) {
        $char = $first[$position];
        $sameAcrossAll = true;
        foreach ($ids as $id) {
            if ($id[$position] !== $char) {
                $sameAcrossAll = false;
                break;
            }
        }
        if ($sameAcrossAll) {
            $common .= $char;
        }
    }

    $common = trim($common);
    return $common !== '' ? $common : $first;
}

/**
 * The longest common trailing run of whole words across every description
 * (e.g. "Herre Junior"/"Dame Junior" -> "Junior"); if there is none, the
 * longest common leading run (e.g. "Under 21 Men"/"Under 21 Women" ->
 * "Under 21"); if neither exists, the first description verbatim.
 */
function ceCommonName(array $names): string
{
    $first = $names[0];
    $wordLists = array_map(function ($name) {
        return preg_split('/\s+/', trim($name));
    }, $names);

    $suffix = ceCommonWordsFromEnd($wordLists);
    if (!empty($suffix)) {
        return implode(' ', $suffix);
    }

    $prefix = ceCommonWordsFromStart($wordLists);
    if (!empty($prefix)) {
        return implode(' ', $prefix);
    }

    return $first;
}

function ceCommonWordsFromEnd(array $wordLists): array
{
    $minWordCount = min(array_map('count', $wordLists));

    $matched = [];
    for ($offset = 1; $offset <= $minWordCount; $offset++) {
        $word = $wordLists[0][count($wordLists[0]) - $offset];
        $sameAcrossAll = true;
        foreach ($wordLists as $words) {
            if ($words[count($words) - $offset] !== $word) {
                $sameAcrossAll = false;
                break;
            }
        }
        if (!$sameAcrossAll) {
            break;
        }
        array_unshift($matched, $word);
    }

    return $matched;
}

function ceCommonWordsFromStart(array $wordLists): array
{
    $minWordCount = min(array_map('count', $wordLists));

    $matched = [];
    for ($position = 0; $position < $minWordCount; $position++) {
        $word = $wordLists[0][$position];
        $sameAcrossAll = true;
        foreach ($wordLists as $words) {
            if ($words[$position] !== $word) {
                $sameAcrossAll = false;
                break;
            }
        }
        if (!$sameAcrossAll) {
            break;
        }
        $matched[] = $word;
    }

    return $matched;
}
