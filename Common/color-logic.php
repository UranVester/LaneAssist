<?php
/**
 * LaneAssist "Color by" groups: for the current tournament, the ordered key
 * list of every group a color-by control can use. Pages inject the result as
 * `var ColorGroups` and Common/js/color-by.js hands out palette colors by
 * position, so the same club/bow/category/event is the same color on every
 * page. laBuildColorGroups() is pure; laLoadColorGroups() does the SQL.
 */

require_once __DIR__ . '/distance-logic.php';

/** Trimmed, non-empty, de-duplicated (first wins) list of strings. */
function laColorUniqueStrings(array $values): array {
    $result = [];
    foreach ($values as $value) {
        $text = trim((string)$value);
        if ($text !== '' && !in_array($text, $result, true)) {
            $result[] = $text;
        }
    }
    return $result;
}

/**
 * $raw keys (all optional): countries (CoCode[]), divisions ([['id','description']] in
 * DivViewOrder), classes (ClId[] in ClViewOrder), categories ([['division','class']]),
 * events ([['teamEvent','code']]), distanceProfiles (string[]), targetFaces (TarId[]).
 * Returns the ColorGroups payload consumed by color-by.js createColorIndex().
 */
function laBuildColorGroups(array $raw): array {
    $countries = laColorUniqueStrings($raw['countries'] ?? []);
    sort($countries, SORT_STRING);

    $divisions = [];
    $divisionOrder = [];
    foreach ($raw['divisions'] ?? [] as $division) {
        $id = trim((string)($division['id'] ?? ''));
        if ($id === '' || isset($divisionOrder[$id])) {
            continue;
        }
        $divisionOrder[$id] = count($divisions);
        $divisions[] = ['id' => $id, 'description' => trim((string)($division['description'] ?? ''))];
    }

    $classes = laColorUniqueStrings($raw['classes'] ?? []);
    $classOrder = array_flip($classes);

    $categories = [];
    foreach ($raw['categories'] ?? [] as $category) {
        $division = trim((string)($category['division'] ?? ''));
        $class = trim((string)($category['class'] ?? ''));
        $code = $division . $class;
        if ($code === '' || isset($categories[$code])) {
            continue;
        }
        $categories[$code] = [
            $divisionOrder[$division] ?? PHP_INT_MAX,
            $classOrder[$class] ?? PHP_INT_MAX,
            $code,
        ];
    }
    uasort($categories, function($a, $b) {
        return [$a[0], $a[1]] <=> [$b[0], $b[1]] ?: strcmp($a[2], $b[2]);
    });

    $events = [[], []];
    foreach ($raw['events'] ?? [] as $event) {
        $code = trim((string)($event['code'] ?? ''));
        if ($code !== '') {
            $events[intval($event['teamEvent'] ?? 0) ? 1 : 0][] = $code;
        }
    }
    $eventKeys = [];
    foreach ($events as $teamEvent => $codes) {
        foreach (laColorUniqueStrings($codes) as $code) {
            $eventKeys[] = $teamEvent . '|' . $code;
        }
    }

    $distances = laColorUniqueStrings($raw['distanceProfiles'] ?? []);
    usort($distances, function($a, $b) {
        return intval($a) <=> intval($b) ?: strcmp($a, $b);
    });

    $targetFaces = array_values(array_filter(laColorUniqueStrings($raw['targetFaces'] ?? []), function($id) {
        return intval($id) > 0;
    }));
    usort($targetFaces, function($a, $b) {
        return intval($a) <=> intval($b);
    });

    return [
        'country' => $countries,
        'division' => $divisions,
        'class' => $classes,
        'category' => array_map('strval', array_keys($categories)),
        'event' => $eventKeys,
        'distance' => $distances,
        'target-face' => $targetFaces,
    ];
}

/**
 * Loads the raw lists for $tourId and builds the payload. Distance profiles come from
 * getDistanceProfilesByCategory(), which reads $_SESSION['TourId'] / ['TourType'], so
 * call this for the session's tournament.
 */
function laLoadColorGroups(int $tourId): array {
    $tour = StrSafe_DB($tourId);
    $raw = ['countries' => [], 'divisions' => [], 'classes' => [], 'categories' => [], 'events' => [], 'distanceProfiles' => [], 'targetFaces' => []];

    $rs = safe_r_sql("SELECT DISTINCT CoCode FROM Entries INNER JOIN Countries ON EnCountry=CoId
        WHERE EnTournament=$tour");
    while ($row = safe_fetch($rs)) {
        $raw['countries'][] = $row->CoCode;
    }

    $rs = safe_r_sql("SELECT DivId, DivDescription FROM Divisions
        WHERE DivTournament=$tour AND DivAthlete=1 ORDER BY DivViewOrder, DivId");
    while ($row = safe_fetch($rs)) {
        $raw['divisions'][] = ['id' => $row->DivId, 'description' => $row->DivDescription];
    }

    $rs = safe_r_sql("SELECT ClId FROM Classes
        WHERE ClTournament=$tour AND ClAthlete=1 ORDER BY ClViewOrder, ClId");
    while ($row = safe_fetch($rs)) {
        $raw['classes'][] = $row->ClId;
    }

    $categoryCodes = [];
    $rs = safe_r_sql("SELECT DISTINCT EnDivision, EnClass FROM Entries WHERE EnTournament=$tour");
    while ($row = safe_fetch($rs)) {
        $raw['categories'][] = ['division' => $row->EnDivision, 'class' => $row->EnClass];
        $categoryCodes[] = trim((string)$row->EnDivision) . trim((string)$row->EnClass);
    }

    $rs = safe_r_sql("SELECT EvTeamEvent, EvCode FROM Events
        WHERE EvTournament=$tour ORDER BY EvTeamEvent, EvProgr, EvCode");
    while ($row = safe_fetch($rs)) {
        $raw['events'][] = ['teamEvent' => intval($row->EvTeamEvent), 'code' => $row->EvCode];
    }

    $raw['distanceProfiles'] = array_values(getDistanceProfilesByCategory(laColorUniqueStrings($categoryCodes)));

    $rs = safe_r_sql("SELECT DISTINCT TfT1 FROM TargetFaces WHERE TfTournament=$tour AND TfT1>0");
    while ($row = safe_fetch($rs)) {
        $raw['targetFaces'][] = $row->TfT1;
    }

    return laBuildColorGroups($raw);
}
