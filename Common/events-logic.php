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

/**
 * Normalizes the four grouping checkboxes to booleans. Distance is forced
 * off whenever Division is ticked: with Division on, distances always mix
 * (the club "all recurve against each other" case), so a stray truthy
 * 'distance' from the raw form data must never leak through.
 */
function ceNormalizeCriteria(array $raw): array
{
    $division = !empty($raw['division']);

    return [
        'gender' => !empty($raw['gender']),
        'class' => !empty($raw['class']),
        'division' => $division,
        'distance' => !$division && !empty($raw['distance']),
    ];
}

/**
 * The Ev* defaults for a brand-new event when no old event's settings can
 * be copied (see ceBuildPlan()'s 'settingsFrom'): recurve/barebow/longbow
 * use the set-system match mode and a single-spot (1) final target face;
 * compound is cumulative scoring (0) on the triple-spot (3) face, matching
 * the DK rule set's own defaults.
 */
function ceDefaultEventSettings(string $div): array
{
    $isCompound = $div === 'C';

    return [
        'EvMatchMode' => $isCompound ? 0 : 1,
        'EvElimEnds' => 5,
        'EvElimArrows' => 3,
        'EvElimSO' => 1,
        'EvFinEnds' => 5,
        'EvFinArrows' => 3,
        'EvFinSO' => 1,
        'EvMatchArrowsNo' => 0,
        'EvFinalAthTarget' => 248,
        'EvFinalTargetType' => $isCompound ? 3 : 1,
        'EvTargetSize' => 40,
    ];
}

/**
 * A raw EvDistance / distance-profile token reduced to bare digits, as the
 * plan's 'distance' fields expect: '18 m' -> '18', '12m' -> '12', '08' ->
 * '8'. '0' (CreateEventNew's "not set" default), '' and anything without
 * digits give '' (unknown).
 */
function ceNormalizeDistance(string $raw): string
{
    if (!preg_match('/(\d+)/', $raw, $matches)) {
        return '';
    }

    $meters = intval($matches[1]);
    return $meters > 0 ? (string) $meters : '';
}

/**
 * The finals distance a single division+class pair contributes to its
 * group: the distance of whichever of the pair's current individual events
 * has the most participants (ties: lowest EvProgr), since that's the event
 * whose finals distance the pair's archers actually know. Falls back to the
 * pair's own qualification-profile shortest distance when it's mapped to no
 * current event, and to '' when that's unknown too.
 */
function cePairDistance(array $pair, array $oldEvents): string
{
    $best = null;
    foreach ($pair['oldEvents'] as $code) {
        if (!isset($oldEvents[$code])) {
            continue;
        }
        $candidate = $oldEvents[$code];
        if (
            $best === null
            || $candidate['count'] > $best['count']
            || ($candidate['count'] === $best['count'] && $candidate['progr'] < $best['progr'])
        ) {
            $best = $candidate;
        }
    }

    if ($best !== null) {
        return $best['distance'];
    }

    return $pair['qualShortest'] !== '' ? $pair['qualShortest'] : '';
}

/**
 * Which old event a new group's Ev* match settings should be copied from:
 * the old event covering the most participants among the group's pairs,
 * restricted to pairs at the group's own finals distance (so a group that
 * mixes distances, e.g. Division-only grouping, takes its settings from the
 * same pairs that set the distance, not from a longer-distance pair with
 * more archers). When every candidate has 0 participants (an empty-
 * tournament preview, or all-zero pairs), falls back to whichever old event
 * covers the most of those pairs. Returns null when none of the group's
 * pairs-at-distance map to any old event at all.
 */
function ceSettingsFromForGroup(array $groupPairs, array $oldEvents, string $groupDistance): ?string
{
    $atDistance = array_values(array_filter(
        $groupPairs,
        function ($pair) use ($oldEvents, $groupDistance) {
            return cePairDistance($pair, $oldEvents) === $groupDistance;
        }
    ));

    $sumByCode = [];
    $pairCountByCode = [];
    foreach ($atDistance as $pair) {
        foreach ($pair['oldEvents'] as $code) {
            if (!isset($oldEvents[$code])) {
                continue;
            }
            $sumByCode[$code] = ($sumByCode[$code] ?? 0) + $pair['count'];
            $pairCountByCode[$code] = ($pairCountByCode[$code] ?? 0) + 1;
        }
    }

    if (empty($sumByCode)) {
        return null;
    }

    $scoreByCode = max($sumByCode) > 0 ? $sumByCode : $pairCountByCode;
    $bestScore = max($scoreByCode);

    $bestCode = null;
    $bestProgr = PHP_INT_MAX;
    foreach ($scoreByCode as $code => $score) {
        if ($score !== $bestScore) {
            continue;
        }
        $progr = $oldEvents[$code]['progr'];
        if ($bestCode === null || $progr < $bestProgr) {
            $bestCode = $code;
            $bestProgr = $progr;
        }
    }

    return $bestCode;
}

/**
 * The code/name parts for one group, built from a representative member
 * pair (any pair in the group works: the parts that vary by criterion are
 * the same for every member by construction of the grouping key). Division
 * and Distance share a "prefix" slot -- they can never both be ticked
 * (ceNormalizeCriteria forces Distance off whenever Division is on), and
 * Division always reads as the natural leading word/digits (e.g. "R"/
 * "Recurve", "18"/"18m"). Gender and Class share a "middle" slot: when both
 * are ticked the class itself is already gender-specific, so the class's
 * own id/description is used directly and no separate gender part is added
 * (avoids "RHDJ"/"Recurve Dame Dame Junior"-style duplication for class
 * systems, like DK's, whose ids/descriptions already encode gender).
 * Returns the *untruncated* code (ceBuildPlan truncates/dedupes it) and the
 * already length-capped name.
 */
function ceCodeAndNameForGroup(
    array $samplePair,
    array $classesById,
    array $ageGroups,
    array $criteria,
    string $groupDistance,
    array $labels
): array {
    $sex = $classesById[$samplePair['class']]['sex'];

    $prefixCode = '';
    $prefixName = '';
    if ($criteria['division']) {
        $prefixCode = $samplePair['div'];
        $prefixName = $samplePair['divName'];
    } elseif ($criteria['distance'] && $groupDistance !== '') {
        $prefixCode = preg_replace('/\D/', '', $groupDistance);
        $prefixName = $groupDistance . 'm';
    }

    $middleCode = '';
    $middleName = '';
    if ($criteria['gender'] && $criteria['class']) {
        $middleCode = $samplePair['class'];
        $middleName = $classesById[$samplePair['class']]['name'];
    } elseif ($criteria['gender']) {
        $middleCode = $labels['genderCode'][$sex];
        $middleName = $labels['genderName'][$sex];
    } elseif ($criteria['class']) {
        $ageGroup = $ageGroups[$samplePair['class']];
        $middleCode = $ageGroup['code'];
        $middleName = $ageGroup['name'];
    }

    $code = $prefixCode . $middleCode;
    $name = implode(' ', array_filter([$prefixName, $middleName], function ($part) {
        return $part !== '';
    }));

    if ($code === '') {
        $code = $labels['allCode'];
    }
    if ($name === '') {
        $name = $labels['allName'];
    }

    return ['codeBase' => $code, 'name' => substr($name, 0, 64)];
}

/**
 * Makes $base fit EvCode (<=10 chars) and not collide with a code already
 * handed out in this plan: first the plain truncation to 10 chars, then --
 * only on collision -- the tail is replaced with '2', '3', ... so the
 * result stays <=10 chars. $used is mutated with the chosen code.
 */
function ceUniqueCode(string $base, array &$used): string
{
    $code = substr($base, 0, 10);
    if (!isset($used[$code])) {
        $used[$code] = true;
        return $code;
    }

    for ($suffix = 2; ; $suffix++) {
        $suffixStr = (string) $suffix;
        $candidate = substr($base, 0, 10 - strlen($suffixStr)) . $suffixStr;
        if (!isset($used[$candidate])) {
            $used[$candidate] = true;
            return $candidate;
        }
    }
}

/**
 * Groups candidate division+class pairs into the new individual events a
 * "Change finals events" rebuild would create, per the four ticked
 * grouping criteria. See the doc comments on the helpers above for the
 * per-field rules (pair/group distance, settings source, code/name
 * building); this function is the grouping, filtering, sorting and
 * numbering that ties them together.
 *
 * tournamentEmpty (sum of all pair counts is 0) means the tournament has no
 * individual-finals-eligible entries anywhere yet, so every candidate group
 * is created (at a default 1/16 bracket) rather than only the ones with
 * archers.
 */
function ceBuildPlan(array $pairs, array $oldEvents, array $classes, array $criteria, array $labels): array
{
    $criteria = ceNormalizeCriteria($criteria);

    $totalCount = 0;
    foreach ($pairs as $pair) {
        $totalCount += $pair['count'];
    }
    $tournamentEmpty = $totalCount === 0;

    $classesById = array_column($classes, null, 'id');
    $ageGroups = ceAgeGroups($classes);

    $buckets = [];
    foreach ($pairs as $pair) {
        if (!isset($classesById[$pair['class']])) {
            continue; // pair's class isn't in this rule set's class list -- skip it
        }
        if (!$tournamentEmpty && $pair['count'] === 0) {
            // With archers anywhere, only pairs that have some are mapped;
            // an empty pair must not join a group (or set its distance).
            continue;
        }

        $keyParts = [];
        if ($criteria['division']) {
            $keyParts[] = 'div=' . $pair['div'];
        }
        if ($criteria['gender'] && $criteria['class']) {
            $keyParts[] = 'class=' . $pair['class'];
        } else {
            if ($criteria['gender']) {
                $keyParts[] = 'sex=' . $classesById[$pair['class']]['sex'];
            }
            if ($criteria['class']) {
                $keyParts[] = 'age=' . $ageGroups[$pair['class']]['key'];
            }
        }
        if ($criteria['distance']) {
            $keyParts[] = 'dist=' . cePairDistance($pair, $oldEvents);
        }

        $key = implode('|', $keyParts);
        $buckets[$key][] = $pair;
    }

    $events = [];
    foreach ($buckets as $groupPairs) {
        $count = array_sum(array_column($groupPairs, 'count'));
        if (!$tournamentEmpty && $count === 0) {
            continue;
        }

        $pairDistances = array_map(function ($pair) use ($oldEvents) {
            return cePairDistance($pair, $oldEvents);
        }, $groupPairs);
        $nonEmptyDistances = array_filter($pairDistances, function ($distance) {
            return $distance !== '';
        });
        $groupDistance = '';
        foreach ($nonEmptyDistances as $distance) {
            if ($groupDistance === '' || intval($distance) < intval($groupDistance)) {
                $groupDistance = $distance;
            }
        }

        $divs = array_unique(array_column($groupPairs, 'div'));
        $division = count($divs) === 1 ? $divs[0] : null;

        $minDivOrder = min(array_column($groupPairs, 'divOrder'));
        // When Gender+Class are both ticked, the grouping unit is the class
        // itself (see the key-building above), so the sort must use each
        // class's own order -- not ceAgeGroups()'s merged age-group order,
        // which can tie two such classes together (e.g. HJ/DJ sharing an
        // age range) and fall through to the sex tiebreak, reversing the
        // intended order. The merged order is only correct when Class is
        // grouping pairs *without* Gender, i.e. is actually doing the
        // age-group merging.
        $classOrderForSort = ($criteria['gender'] && $criteria['class'])
            ? function ($pair) use ($classesById) {
                return $classesById[$pair['class']]['order'];
            }
            : function ($pair) use ($ageGroups) {
                return $ageGroups[$pair['class']]['order'];
            };
        $minClassOrder = min(array_map($classOrderForSort, $groupPairs));
        $sortSex = $criteria['gender'] ? $classesById[$groupPairs[0]['class']]['sex'] : null;

        $settingsFrom = ceSettingsFromForGroup($groupPairs, $oldEvents, $groupDistance);
        $built = ceCodeAndNameForGroup($groupPairs[0], $classesById, $ageGroups, $criteria, $groupDistance, $labels);

        $events[] = [
            'codeBase' => $built['codeBase'],
            'name' => $built['name'],
            'firstPhase' => ceBracketForCount($count, $tournamentEmpty),
            'distance' => $groupDistance,
            'count' => $count,
            'pairs' => array_map(function ($pair) {
                return ['div' => $pair['div'], 'class' => $pair['class']];
            }, $groupPairs),
            'settingsFrom' => $settingsFrom,
            'division' => $division,
            'sortMinDivOrder' => $minDivOrder,
            'sortMinClassOrder' => $minClassOrder,
            'sortSex' => $sortSex,
        ];
    }

    usort($events, function ($a, $b) {
        if ($a['sortMinDivOrder'] !== $b['sortMinDivOrder']) {
            return $a['sortMinDivOrder'] <=> $b['sortMinDivOrder'];
        }
        if ($a['sortMinClassOrder'] !== $b['sortMinClassOrder']) {
            return $a['sortMinClassOrder'] <=> $b['sortMinClassOrder'];
        }

        $sexRank = function ($sex) {
            if ($sex === 1) {
                return 0;
            }
            return $sex === 0 ? 1 : 2;
        };
        if ($a['sortSex'] !== null && $b['sortSex'] !== null && $a['sortSex'] !== $b['sortSex']) {
            return $sexRank($a['sortSex']) <=> $sexRank($b['sortSex']);
        }

        $distanceRank = function ($distance) {
            return $distance === '' ? -1 : intval($distance);
        };
        return $distanceRank($b['distance']) <=> $distanceRank($a['distance']);
    });

    $usedCodes = [];
    foreach ($events as $index => &$event) {
        $event['code'] = ceUniqueCode($event['codeBase'], $usedCodes);
        $event['order'] = $index + 1;
        unset($event['codeBase'], $event['sortMinDivOrder'], $event['sortMinClassOrder'], $event['sortSex']);
    }
    unset($event);

    return ['events' => $events, 'tournamentEmpty' => $tournamentEmpty];
}

/**
 * A stable fingerprint of a preview's groups plus the deletion counts that
 * went with it. ceGuardedApply() recomputes both at confirm time and
 * refuses to apply when the fingerprint differs -- e.g. a finals score was
 * entered between preview and confirm, changing 'scoredMatches'.
 */
function cePlanFingerprint(array $plan, array $deletion): string
{
    $events = array_map(function ($event) {
        return [
            'code' => $event['code'],
            'pairs' => $event['pairs'],
            'firstPhase' => $event['firstPhase'],
            'distance' => $event['distance'],
            'settingsFrom' => $event['settingsFrom'],
        ];
    }, $plan['events']);

    return sha1(json_encode(['events' => $events, 'deletion' => $deletion]));
}
