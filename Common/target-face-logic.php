<?php
/**
 * Change target faces: pure rules (no DB, no get_text).
 *
 * Which faces an archer may use must match core's getTargets() in
 * Participants/getRows.php exactly: the core Participants list silently resets
 * any other face to the default the next time it loads.
 *
 * Face records are merged into columns by display name, because tournaments
 * commonly define one "Full Face" record per division group.
 */

/**
 * Legal TfIds per division and class, from rows in core getTargets()'s ORDER BY.
 * Same rule as core: a default face is kept only if it's the first match for
 * that div/class; non-default faces are always kept.
 *
 * @param array $rows [['DivId'=>string,'ClId'=>string,'TfId'=>int,'TfDefault'=>int], ...]
 * @return array [div][class] => int[] (ordered; the first entry is the effective default)
 */
function tfBuildLegalSets(array $rows): array
{
    $legal = [];
    foreach ($rows as $r) {
        $div = (string)$r['DivId'];
        $cls = (string)$r['ClId'];
        if (!intval($r['TfDefault']) || empty($legal[$div][$cls])) {
            $legal[$div][$cls][] = intval($r['TfId']);
        }
    }
    return $legal;
}

/** Group key for a face name: trimmed, inner whitespace collapsed, case folded. */
function tfMergeKey(string $name): string
{
    return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name)));
}

/**
 * Merge face records into columns by name.
 *
 * @param array $faceNames [TfId => display name]
 * @return array ordered by lowest TfId: [['key'=>string,'label'=>string,'tfIds'=>int[] asc], ...]
 */
function tfBuildGroups(array $faceNames): array
{
    ksort($faceNames);
    $groups = [];
    foreach ($faceNames as $tfId => $name) {
        $key = tfMergeKey((string)$name);
        if (!isset($groups[$key])) {
            $groups[$key] = ['key' => $key, 'label' => trim(preg_replace('/\s+/u', ' ', (string)$name)), 'tfIds' => []];
        }
        $groups[$key]['tfIds'][] = intval($tfId);
    }
    return array_values($groups);
}

/** Key of the group containing $tfId, or null. */
function tfGroupKeyForFace(array $groups, int $tfId): ?string
{
    foreach ($groups as $g) {
        if (in_array($tfId, $g['tfIds'], true)) {
            return $g['key'];
        }
    }
    return null;
}

/**
 * The TfId an archer gets when picking a group: their current face if it's a
 * legal member, else the group member that comes first in their legal list.
 *
 * @param int[] $legal the archer's ordered legal list
 * @param int[] $groupTfIds
 */
function tfPickFace(array $legal, array $groupTfIds, int $current): ?int
{
    if (in_array($current, $legal, true) && in_array($current, $groupTfIds, true)) {
        return $current;
    }
    foreach ($legal as $tfId) {
        if (in_array($tfId, $groupTfIds, true)) {
            return $tfId;
        }
    }
    return null;
}

/**
 * Per-row state for the table.
 *
 * @return array ['cells'=>[key=>bool], 'selected'=>?string, 'currentIllegal'=>bool, 'noFace'=>bool]
 */
function tfDescribeArcher(array $legal, array $groups, int $current): array
{
    $cells = [];
    foreach ($groups as $g) {
        $cells[$g['key']] = tfPickFace($legal, $g['tfIds'], $current) !== null;
    }
    $isLegal = in_array($current, $legal, true);
    return [
        'cells' => $cells,
        'selected' => $isLegal ? tfGroupKeyForFace($groups, $current) : null,
        'currentIllegal' => !empty($legal) && !$isLegal,
        'noFace' => empty($legal),
    ];
}

/** The group with this key, or throws. */
function tfRequireGroup(array $groups, string $groupKey): array
{
    foreach ($groups as $g) {
        if ($g['key'] === $groupKey) {
            return $g;
        }
    }
    throw new InvalidArgumentException('Unknown face group: ' . $groupKey);
}

/**
 * Split a bulk "set all to group" into writes, no-ops and skips.
 *
 * @param array $archers [['id'=>int,'div'=>string,'cls'=>string,'face'=>int], ...]
 * @return array ['changes'=>[enId=>tfId], 'unchanged'=>int, 'skipped'=>['<div><cls>'=>count]]
 */
function tfPlanBulk(array $archers, array $groups, string $groupKey, array $legalSets): array
{
    $group = tfRequireGroup($groups, $groupKey);
    $plan = ['changes' => [], 'unchanged' => 0, 'skipped' => []];
    foreach ($archers as $a) {
        $legal = $legalSets[$a['div']][$a['cls']] ?? [];
        $pick = tfPickFace($legal, $group['tfIds'], intval($a['face']));
        if ($pick === null) {
            $category = $a['div'] . $a['cls'];
            $plan['skipped'][$category] = ($plan['skipped'][$category] ?? 0) + 1;
        } elseif ($pick === intval($a['face'])) {
            $plan['unchanged']++;
        } else {
            $plan['changes'][intval($a['id'])] = $pick;
        }
    }
    return $plan;
}
