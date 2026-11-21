<?php
/**
 * Change target faces: DB reads and writes. The rules live in
 * target-face-logic.php; this file only feeds them and applies their result.
 */

require_once __DIR__ . '/target-face-logic.php';
require_once __DIR__ . '/i18n.php';

/**
 * Legal TfIds per division/class. The SQL is a verbatim copy of core
 * getTargets() in Participants/getRows.php (that file can't be included: it
 * runs code at load). Keep the two in step, or this page and the core
 * Participants list will disagree about which faces are legal.
 */
function tfLoadLegalSets(int $tourId): array
{
    $MySql = "select DivTournament, "
        . " DivId"
        . ", ClId"
        . ", TfId "
        . ", TfName "
        . ", TfDefault "
        . "from"
        . " Divisions"
        . " inner join Classes on DivTournament=ClTournament and DivAthlete=ClAthlete"
        . " inner join TargetFaces Tf on DivTournament=TfTournament and if(TfRegExp>'', concat(trim(DivId),trim(ClId)) REGEXP TfRegExp, concat(trim(DivId),trim(ClId)) like TfClasses) "
        . "WHERE"
        . " DivTournament in (" . intval($tourId) . ") "
        . " AND DivAthlete='1' "
        . " AND (ClDivisionsAllowed='' or find_in_set(DivId, ClDivisionsAllowed))"
        . "order by"
        . " DivViewOrder"
        . ", ClViewOrder"
        . ", TfDefault desc"
        . ", TfRegExp>'' desc"
        . ", concat(trim(DivId),trim(ClId)) = TfClasses desc"
        . ", left(TfClasses,1)!='_' and left(TfClasses,1)!='%' desc"
        . ", left(TfClasses,1)='_' desc"
        . ", TfClasses desc"
        . ", TfClasses='%' ";

    $rows = [];
    $q = safe_r_sql($MySql);
    while ($r = safe_fetch($q)) {
        $rows[] = ['DivId' => $r->DivId, 'ClId' => $r->ClId, 'TfId' => intval($r->TfId), 'TfDefault' => intval($r->TfDefault)];
    }
    return tfBuildLegalSets($rows);
}

/** [TfId => display name], translated the same way core does. */
function tfLoadFaceNames(int $tourId): array
{
    $names = [];
    $q = safe_r_sql('SELECT TfId, TfName FROM TargetFaces WHERE TfTournament=' . StrSafe_DB($tourId) . ' ORDER BY TfId');
    while ($r = safe_fetch($q)) {
        $names[intval($r->TfId)] = get_text($r->TfName, 'Tournament', '', true);
    }
    return $names;
}

/** Everything the rules need for one tournament: ['legal'=>…, 'groups'=>…]. */
function tfLoadContext(int $tourId): array
{
    return ['legal' => tfLoadLegalSets($tourId), 'groups' => tfBuildGroups(tfLoadFaceNames($tourId))];
}

/** Trimmed filter value, '' when absent. */
function tfFilterValue(array $filters, string $key): string
{
    $v = $filters[$key] ?? '';
    return is_array($v) ? '' : trim((string)$v);
}

/** Picked values of a multiselect filter; empty (or only the "All" option '') means no filter. */
function tfFilterList(array $filters, string $key): array
{
    $v = $filters[$key] ?? [];
    $list = array_map(fn($x) => trim((string)$x), is_array($v) ? $v : [$v]);
    return array_values(array_unique(array_filter($list, fn($x) => $x !== '')));
}

/** SQL "IN (...)" list of escaped values. */
function tfSqlIn(array $values): string
{
    return '(' . implode(',', array_map('StrSafe_DB', $values)) . ')';
}

/**
 * Archers (EnAthlete=1) matching the filters, with their row state.
 * `name` is a text search as in core Partecipants/ManEventAccess.php. Club
 * (CoId of EnCountry), div, class and event (individual EcCode) are
 * multiselects: any picked value matches, the fields combine with AND, and
 * nothing picked means no filter. `face` is a group key matched against the
 * archer's *current* face, legal or not.
 *
 * @param array $filters keys name (string); country, div, class, event (string[]); face (string)
 * @param int $onlyEnId limit to this one archer (0 = no limit)
 */
function tfLoadArchers(int $tourId, array $filters, array $ctx, int $onlyEnId = 0): array
{
    $tour = StrSafe_DB($tourId);
    $sql = "SELECT EnId, EnCode, EnFirstName, EnName, EnDivision, EnClass, EnTargetFace, CoCode, CoName
        FROM Entries
        LEFT JOIN Countries ON EnCountry=CoId AND EnTournament=CoTournament
        WHERE EnTournament=$tour AND EnAthlete=1";
    if (($v = tfFilterValue($filters, 'name')) !== '') {
        $sql .= ' AND ' . assembleWhereCondition(['EnFirstName', 'EnName'], [$v]);
    }
    if ($list = tfFilterList($filters, 'country')) {
        $sql .= ' AND EnCountry IN ' . tfSqlIn($list);
    }
    if ($list = tfFilterList($filters, 'div')) {
        $sql .= ' AND EnDivision IN ' . tfSqlIn($list);
    }
    if ($list = tfFilterList($filters, 'class')) {
        $sql .= ' AND EnClass IN ' . tfSqlIn($list);
    }
    if ($list = tfFilterList($filters, 'event')) {
        $sql .= " AND EXISTS (SELECT 1 FROM EventClass WHERE EcTournament=EnTournament AND EcDivision=EnDivision
            AND EcClass=EnClass AND EcTeamEvent=0 AND EcCode IN " . tfSqlIn($list) . ')';
    }
    if ($onlyEnId) {
        $sql .= ' AND EnId=' . StrSafe_DB($onlyEnId);
    }
    $sql .= ' ORDER BY EnFirstName, EnName, EnId';

    $faceFilter = tfFilterValue($filters, 'face');
    $rows = [];
    $q = safe_r_sql($sql);
    while ($r = safe_fetch($q)) {
        $face = intval($r->EnTargetFace);
        if ($faceFilter !== '' && tfGroupKeyForFace($ctx['groups'], $face) !== $faceFilter) {
            continue;
        }
        $legal = $ctx['legal'][$r->EnDivision][$r->EnClass] ?? [];
        $rows[] = [
            'id' => intval($r->EnId),
            'code' => (string)$r->EnCode,
            'name' => trim($r->EnFirstName . ' ' . $r->EnName),
            'coCode' => (string)$r->CoCode,
            'coName' => (string)$r->CoName,
            'div' => (string)$r->EnDivision,
            'cls' => (string)$r->EnClass,
            'face' => $face,
        ] + tfDescribeArcher($legal, $ctx['groups'], $face);
    }
    return $rows;
}

/**
 * Choices for the multiselect filters, as [['value'=>string,'label'=>string], ...]:
 * clubs that have athletes (by code), athlete divisions and classes (view
 * order), and individual events (by code).
 */
function tfLoadFilterOptions(int $tourId): array
{
    $tour = StrSafe_DB($tourId);
    $queries = [
        'country' => "SELECT DISTINCT CoId v, CoCode c, CoName n FROM Countries
            INNER JOIN Entries ON EnCountry=CoId AND EnTournament=CoTournament AND EnAthlete=1
            WHERE CoTournament=$tour ORDER BY CoCode, CoId",
        'div' => "SELECT DivId v, DivId c, DivDescription n FROM Divisions WHERE DivTournament=$tour AND DivAthlete='1' ORDER BY DivViewOrder, DivId",
        'class' => "SELECT ClId v, ClId c, ClDescription n FROM Classes WHERE ClTournament=$tour AND ClAthlete='1' ORDER BY ClViewOrder, ClId",
        'event' => "SELECT EvCode v, EvCode c, EvEventName n FROM Events WHERE EvTournament=$tour AND EvTeamEvent=0 ORDER BY EvCode",
    ];
    $options = [];
    foreach ($queries as $key => $sql) {
        $options[$key] = [];
        $q = safe_r_sql($sql);
        while ($r = safe_fetch($q)) {
            $options[$key][] = ['value' => (string)$r->v, 'label' => $r->c . ($r->n !== '' ? ' - ' . $r->n : '')];
        }
    }
    return $options;
}

/** One archer's fresh row (after a click), or null if not an athlete of this tournament. */
function tfLoadArcher(int $tourId, int $enId, array $ctx): ?array
{
    return tfLoadArchers($tourId, [], $ctx, $enId)[0] ?? null;
}

/** The one write this page makes; leaves EnTimestamp alone, like core lib.php. */
function tfWriteFace(int $tourId, int $enId, int $tfId): void
{
    safe_w_sql('UPDATE Entries SET EnTimestamp=EnTimestamp, EnTargetFace=' . StrSafe_DB($tfId)
        . ' WHERE EnId=' . StrSafe_DB($enId) . ' AND EnTournament=' . StrSafe_DB($tourId));
}

/** Set one archer to a face group. Refuses (no write) when it isn't legal for them. */
function tfSetOne(int $tourId, int $enId, string $groupKey, array $ctx): array
{
    try {
        $group = tfRequireGroup($ctx['groups'], $groupKey);
    } catch (InvalidArgumentException $e) {
        return ['error' => 1, 'message' => 'Unknown target face'];
    }
    $r = safe_fetch(safe_r_sql('SELECT EnDivision, EnClass, EnTargetFace FROM Entries WHERE EnId=' . StrSafe_DB($enId)
        . ' AND EnTournament=' . StrSafe_DB($tourId) . ' AND EnAthlete=1'));
    if (!$r) {
        return ['error' => 1, 'message' => 'Archer not found'];
    }
    $current = intval($r->EnTargetFace);
    $pick = tfPickFace($ctx['legal'][$r->EnDivision][$r->EnClass] ?? [], $group['tfIds'], $current);
    if ($pick === null) {
        return ['error' => 1, 'message' => la_t('faces.notAllowed')];
    }
    if ($pick !== $current) {
        tfWriteFace($tourId, $enId, $pick);
    }
    return ['error' => 0, 'face' => $pick];
}

/**
 * Set every archer in the filtered view to a face group, skipping those it's
 * illegal for. Refuses (no write) when the view no longer has the row count
 * the user confirmed.
 */
function tfSetAll(int $tourId, array $filters, string $groupKey, int $expectedCount, array $ctx): array
{
    $archers = tfLoadArchers($tourId, $filters, $ctx);
    if (count($archers) !== $expectedCount) {
        return ['error' => 1, 'stale' => true, 'message' => la_t('faces.viewChanged')];
    }
    try {
        $plan = tfPlanBulk($archers, $ctx['groups'], $groupKey, $ctx['legal']);
    } catch (InvalidArgumentException $e) {
        return ['error' => 1, 'message' => 'Unknown target face'];
    }
    foreach ($plan['changes'] as $enId => $tfId) {
        tfWriteFace($tourId, $enId, $tfId);
    }
    return ['error' => 0, 'changed' => count($plan['changes']), 'unchanged' => $plan['unchanged'], 'skipped' => $plan['skipped']];
}
