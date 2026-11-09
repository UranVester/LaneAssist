<?php
/**
 * LaneAssist "Change finals events" — DB layer: load the rebuild input,
 * summarise what a rebuild would delete, and apply a plan.
 *
 * The decisions themselves (grouping, brackets, codes, settings source)
 * live in the pure Common/events-logic.php; this file only reads the
 * tournament into the shapes ceBuildPlan() expects and writes its result.
 *
 * Every statement here is scoped by an explicit tournament id, and only
 * individual events (EvTeamEvent=0 / EcTeamEvent=0) are read or written.
 *
 * Needs the IANSEO DB layer (Common/Fun_DB.inc.php) loaded by the caller.
 * The core helpers it calls -- deleteEvent()/numQualifiedByPhase()
 * (Common/Lib/Fun_Phases.inc.php) and CreateEventNew()/CreateFinalsInd()
 * (Modules/Sets/lib.php) -- are pulled in below; neither file has include-
 * time side effects beyond function/constant definitions.
 */

require_once __DIR__ . '/events-logic.php';
require_once __DIR__ . '/finals-logic.php';
if (!function_exists('deleteEvent')) {
    require_once dirname(__DIR__, 4) . '/Common/Lib/Fun_Phases.inc.php';
}
if (!function_exists('CreateEventNew')) {
    require_once dirname(__DIR__, 4) . '/Modules/Sets/lib.php';
}

/** The old-event columns copied onto a new event as its match settings. */
const CE_COPIED_SETTINGS = [
    'EvMatchMode', 'EvMatchArrowsNo', 'EvFinalAthTarget', 'EvFinalTargetType', 'EvTargetSize',
    'EvElimEnds', 'EvElimArrows', 'EvElimSO', 'EvFinEnds', 'EvFinArrows', 'EvFinSO',
    'EvMedals', 'EvWinnerFinalRank',
];

/**
 * Gender words/codes for generated event codes and names, by the
 * tournament's ToLocRule: Danish for 'DK', English for everything else.
 */
function ceLabelsFor(string $locRule): array
{
    if ($locRule === 'DK') {
        return [
            'genderCode' => [0 => 'H', 1 => 'D', -1 => 'U'],
            'genderName' => [0 => 'Herre', 1 => 'Dame', -1 => 'Åben'],
            'allCode' => 'ALL',
            'allName' => 'Alle',
        ];
    }

    return [
        'genderCode' => [0 => 'M', 1 => 'W', -1 => 'U'],
        'genderName' => [0 => 'Men', 1 => 'Women', -1 => 'Open'],
        'allCode' => 'ALL',
        'allName' => 'All',
    ];
}

/**
 * The tournament's current individual events, code => ['code','name',
 * 'progr','distance','count','settings'] in EvProgr order. 'count' is the
 * active individual-finals entries the event's EventClass rows cover, using
 * the same match as IANSEO's MakeIndividuals() (subclass + extras aware),
 * so it equals what the event's ranking holds.
 */
function ceLoadOldEvents(int $tourId): array
{
    $settingsCols = implode(', ', CE_COPIED_SETTINGS);
    $sql = "SELECT EvCode, EvEventName, EvProgr, EvDistance, $settingsCols,
                (SELECT COUNT(DISTINCT EnId)
                   FROM Entries
                   LEFT JOIN ExtraData ON EdId=EnId AND EdType='P'
                   INNER JOIN EventClass ON EcTournament=EnTournament AND EcTeamEvent=0 AND EcCode=EvCode
                        AND EcDivision=EnDivision AND EcClass=EnClass
                        AND IF(EcSubClass='', TRUE, EcSubClass=EnSubClass)
                        AND (IFNULL(EdExtra,0) & EcExtraAddons)=EcExtraAddons
                  WHERE EnTournament=$tourId AND EnIndFEvent=1 AND EnStatus<=1) AS Participants
            FROM Events
            WHERE EvTournament=$tourId AND EvTeamEvent=0
            ORDER BY EvProgr, EvCode";
    $rs = safe_r_sql($sql);

    $events = [];
    while ($row = safe_fetch($rs)) {
        $settings = [];
        foreach (CE_COPIED_SETTINGS as $col) {
            $settings[$col] = (int) $row->$col;
        }
        $events[$row->EvCode] = [
            'code' => $row->EvCode,
            'name' => $row->EvEventName,
            'progr' => (int) $row->EvProgr,
            'distance' => ceNormalizeDistance((string) $row->EvDistance),
            'count' => (int) $row->Participants,
            'settings' => $settings,
        ];
    }

    return $events;
}

/**
 * "div\tclass" => list of the current individual event codes that
 * division+class is mapped to (EvProgr order), ignoring subclass/extras.
 */
function ceLoadPairEventMap(int $tourId): array
{
    $sql = "SELECT EcDivision, EcClass, EcCode, MIN(EvProgr) AS Progr
            FROM EventClass
            INNER JOIN Events ON EvTournament=EcTournament AND EvCode=EcCode AND EvTeamEvent=0
            WHERE EcTournament=$tourId AND EcTeamEvent=0
            GROUP BY EcDivision, EcClass, EcCode
            ORDER BY Progr, EcCode";
    $rs = safe_r_sql($sql);

    $map = [];
    while ($row = safe_fetch($rs)) {
        $map[$row->EcDivision . "\t" . $row->EcClass][] = $row->EcCode;
    }

    return $map;
}

/**
 * Everything ceBuildPlan() needs for one tournament, in its input shapes,
 * plus the Tournament row's golds/X-nine and ToLocRule under 'tour'.
 *
 * Precondition: $tourId is the session's tournament. The qualification-
 * profile fallback distance comes from getDistanceProfileForCategory(),
 * which reads $_SESSION['TourId']/['TourType'] rather than taking an id.
 */
function ceLoadInput(int $tourId): array
{
    if ((int) ($_SESSION['TourId'] ?? 0) !== $tourId) {
        throw new LogicException('ceLoadInput() must run for the session tournament');
    }

    $tourRow = safe_fetch(safe_r_sql("SELECT ToGolds, ToXNine, ToGoldsChars, ToXNineChars, ToLocRule
        FROM Tournament WHERE ToId=$tourId"));
    $tour = [
        'golds' => $tourRow ? (string) $tourRow->ToGolds : '',
        'xnine' => $tourRow ? (string) $tourRow->ToXNine : '',
        'goldsChars' => $tourRow ? (string) $tourRow->ToGoldsChars : '',
        'xnineChars' => $tourRow ? (string) $tourRow->ToXNineChars : '',
        'locRule' => $tourRow ? (string) $tourRow->ToLocRule : '',
    ];

    $classes = [];
    $rs = safe_r_sql("SELECT ClId, ClDescription, ClSex, ClAgeFrom, ClAgeTo, ClViewOrder
        FROM Classes WHERE ClTournament=$tourId AND ClAthlete='1'
        ORDER BY ClViewOrder, ClId");
    while ($row = safe_fetch($rs)) {
        $classes[] = [
            'id' => $row->ClId,
            'name' => (string) $row->ClDescription,
            'sex' => (int) $row->ClSex,
            'ageFrom' => (int) $row->ClAgeFrom,
            'ageTo' => (int) $row->ClAgeTo,
            'order' => (int) $row->ClViewOrder,
        ];
    }

    $counts = [];
    $rs = safe_r_sql("SELECT EnDivision, EnClass, COUNT(*) AS Archers
        FROM Entries WHERE EnTournament=$tourId AND EnIndFEvent=1 AND EnStatus<=1
        GROUP BY EnDivision, EnClass");
    while ($row = safe_fetch($rs)) {
        $counts[$row->EnDivision . "\t" . $row->EnClass] = (int) $row->Archers;
    }

    $pairEvents = ceLoadPairEventMap($tourId);

    $pairs = [];
    $rs = safe_r_sql("SELECT DivId, DivDescription, DivViewOrder, ClId
        FROM Divisions
        INNER JOIN Classes ON ClTournament=DivTournament AND ClAthlete='1'
            AND (ClDivisionsAllowed='' OR FIND_IN_SET(DivId, REPLACE(ClDivisionsAllowed, ' ', '')))
        WHERE DivTournament=$tourId AND DivAthlete='1'
        ORDER BY DivViewOrder, DivId, ClViewOrder, ClId");
    while ($row = safe_fetch($rs)) {
        $key = $row->DivId . "\t" . $row->ClId;
        $profile = (string) getDistanceProfileForCategory($row->DivId . $row->ClId);
        $pairs[] = [
            'div' => $row->DivId,
            'divName' => (string) ($row->DivDescription ?? $row->DivId),
            'divOrder' => (int) $row->DivViewOrder,
            'class' => $row->ClId,
            'count' => $counts[$key] ?? 0,
            'oldEvents' => $pairEvents[$key] ?? [],
            'qualShortest' => ceNormalizeDistance(explode('-', $profile)[0]),
        ];
    }

    return [
        'pairs' => $pairs,
        'oldEvents' => ceLoadOldEvents($tourId),
        'classes' => $classes,
        'tour' => $tour,
    ];
}

/** Whether $table exists in the current IANSEO database. */
function ceTableExists(string $table): bool
{
    $rs = safe_r_sql('SHOW TABLES LIKE ' . StrSafe_DB($table));
    return safe_num_rows($rs) > 0;
}

/** First column of the first row of $sql, as an int. */
function ceCountQuery(string $sql): int
{
    $row = safe_fetch_assoc(safe_r_sql($sql));
    return $row ? (int) reset($row) : 0;
}

/**
 * What replacing the tournament's individual events would destroy or leave
 * stale, with real counts. Only rows belonging to a current individual
 * event count (deleteEvent() removes exactly those). See the spec's
 * warning box for how each figure is presented.
 *
 * - scoredMatches/scoredEvents: Finals rows with any score, set points, tie
 *   or arrows, counted as matches (a match is two FinMatchNo slots).
 * - scheduledMatches: individual FinSchedule matches with a date.
 * - rankedRows/shootOffRows: Individuals rows, and those carrying a
 *   shoot-off (IndSO > 0, a tie for a qualifying place, or shoot-off arrows
 *   entered). A negative IndSO is IANSEO's plain untied-rank marker
 *   (Obj_Rank_Abs_calc stores so * rank), not a shoot-off.
 * - collapsed: events whose mapping a rebuild cannot keep -- they use
 *   subclass/extras rows, or share a division+class with another event.
 * - stale: rows in tables a rebuild leaves pointing at old events (Awards,
 *   TV rules, pending ISK arrows, HHT finals config); 0 when the table is
 *   absent from this install.
 */
function ceDeletionSummary(int $tourId): array
{
    $oldEvents = ceLoadOldEvents($tourId);
    $codes = array_keys($oldEvents);
    $codeList = implode(',', array_map('StrSafe_DB', $codes));

    $events = array_map(function ($event) {
        return ['code' => $event['code'], 'name' => $event['name'], 'count' => $event['count']];
    }, array_values($oldEvents));

    $joinIndEvent = "INNER JOIN Events ON EvTournament=$tourId AND EvTeamEvent=0";
    $scoredWhere = "FinTournament=$tourId AND (FinScore>0 OR FinSetScore>0 OR FinTie>0 OR FinArrowstring<>'')";

    $scoredMatches = ceCountQuery("SELECT COUNT(DISTINCT FinEvent, FLOOR(FinMatchNo/2))
        FROM Finals $joinIndEvent AND EvCode=FinEvent WHERE $scoredWhere");

    $scoredEvents = [];
    $rs = safe_r_sql("SELECT DISTINCT FinEvent FROM Finals $joinIndEvent AND EvCode=FinEvent
        WHERE $scoredWhere ORDER BY FinEvent");
    while ($row = safe_fetch($rs)) {
        $scoredEvents[] = $row->FinEvent;
    }

    $scheduledMatches = ceCountQuery("SELECT COUNT(DISTINCT FSEvent, FLOOR(FSMatchNo/2))
        FROM FinSchedule $joinIndEvent AND EvCode=FSEvent
        WHERE FSTournament=$tourId AND FSTeamEvent=0 AND FSScheduledDate<>'0000-00-00'");

    $rankedRows = ceCountQuery("SELECT COUNT(*) FROM Individuals $joinIndEvent AND EvCode=IndEvent
        WHERE IndTournament=$tourId");
    $shootOffRows = ceCountQuery("SELECT COUNT(*) FROM Individuals $joinIndEvent AND EvCode=IndEvent
        WHERE IndTournament=$tourId AND (IndSO>0 OR IndTieBreak<>'')");
    $eliminationRows = ceCountQuery("SELECT COUNT(*) FROM Eliminations $joinIndEvent AND EvCode=ElEventCode
        WHERE ElTournament=$tourId");

    $collapsed = [];
    $rs = safe_r_sql("SELECT DISTINCT EcCode FROM EventClass $joinIndEvent AND EvCode=EcCode
        WHERE EcTournament=$tourId AND EcTeamEvent=0 AND (EcSubClass<>'' OR EcExtraAddons<>0)");
    while ($row = safe_fetch($rs)) {
        $collapsed[$row->EcCode] = true;
    }
    foreach (ceLoadPairEventMap($tourId) as $pairCodes) {
        if (count($pairCodes) > 1) {
            foreach ($pairCodes as $code) {
                $collapsed[$code] = true;
            }
        }
    }
    $collapsed = array_map('strval', array_keys($collapsed));
    sort($collapsed);

    $stale = ['awards' => 0, 'tvRules' => 0, 'iskPending' => 0, 'hhtEvents' => 0];
    if ($codes) {
        if (ceTableExists('Awards')) {
            $stale['awards'] = ceCountQuery("SELECT COUNT(*) FROM Awards
                WHERE AwTournament=$tourId AND AwTeam=0 AND AwFinEvent=1 AND AwEvent IN ($codeList)");
        }
        if (ceTableExists('TVParams')) {
            $rs = safe_r_sql("SELECT TVPEventInd FROM TVParams WHERE TVPTournament=$tourId AND TVPEventInd<>''");
            while ($row = safe_fetch($rs)) {
                if (array_intersect(explode('|', $row->TVPEventInd), $codes)) {
                    $stale['tvRules']++;
                }
            }
        }
        if (ceTableExists('IskData')) {
            $stale['iskPending'] = ceCountQuery("SELECT COUNT(*) FROM IskData
                WHERE IskDtTournament=$tourId AND IskDtTeamInd=0 AND IskDtEvent IN ($codeList)");
        }
        if (ceTableExists('HhtEvents')) {
            // HeEventCode holds a phase/schedule key, not an event code; rows
            // with a finals schedule time are individual-finals HHT config,
            // whose schedule the rebuild deletes.
            $stale['hhtEvents'] = ceCountQuery("SELECT COUNT(*) FROM HhtEvents
                WHERE HeTournament=$tourId AND HeTeamEvent=0 AND HeFinSchedule<>'0000-00-00 00:00:00'");
        }
    }

    return [
        'events' => $events,
        'scoredMatches' => $scoredMatches,
        'scoredEvents' => $scoredEvents,
        'scheduledMatches' => $scheduledMatches,
        'rankedRows' => $rankedRows,
        'shootOffRows' => $shootOffRows,
        'eliminationRows' => $eliminationRows,
        'collapsed' => $collapsed,
        'stale' => $stale,
    ];
}

/**
 * Replaces every individual event of $tourId with $plan's events (a
 * ceBuildPlan() result) in one write-connection transaction: deleteEvent()
 * each old one, create each new one with its match settings (copied from
 * 'settingsFrom', else ceDefaultEventSettings()), map its division+class
 * pairs, then build the finals grids. Team events are not touched.
 *
 * EventClass rows go in with a direct INSERT rather than InsertClassEvent():
 * that helper's existence checks use the separate read connection, which
 * cannot see this transaction's uncommitted events. A safe_error() mid-way
 * exit()s, closing the connection and so rolling back; a PHP exception is
 * rolled back explicitly. Rankings (MakeIndAbs) are the caller's job, after
 * commit.
 */
function ceApplyPlan(int $tourId, array $plan, array $tour): array
{
    $oldEvents = ceLoadOldEvents($tourId);

    safe_w_BeginTransaction();
    try {
        foreach (array_keys($oldEvents) as $code) {
            deleteEvent((string) $code, 0, $tourId);
        }

        $finalsCodes = [];
        foreach ($plan['events'] as $event) {
            $from = $event['settingsFrom'];
            $settings = ($from !== null && isset($oldEvents[$from]))
                ? $oldEvents[$from]['settings']
                : ceDefaultEventSettings($event['division'] ?? 'R');
            $phase = (int) $event['firstPhase'];

            CreateEventNew($tourId, $event['code'], $event['name'], (int) $event['order'], array_merge($settings, [
                'EvTeamEvent' => 0,
                'EvFinalFirstPhase' => $phase,
                'EvNumQualified' => $phase > 0 ? numQualifiedByPhase($phase) : 0,
                'EvDistance' => $event['distance'],
                'EvGolds' => $tour['golds'],
                'EvXNine' => $tour['xnine'],
                'EvGoldsChars' => $tour['goldsChars'],
                'EvXNineChars' => $tour['xnineChars'],
            ]));

            foreach ($event['pairs'] as $pair) {
                safe_w_sql("INSERT INTO EventClass
                    (EcCode, EcTeamEvent, EcTournament, EcDivision, EcClass, EcSubClass, EcExtraAddons, EcNumber, EcTourRules)
                    VALUES (" . StrSafe_DB($event['code']) . ", 0, $tourId, " . StrSafe_DB($pair['div']) . ", "
                    . StrSafe_DB($pair['class']) . ", '', 0, 1, '')");
            }

            if ($phase > 0) {
                $finalsCodes[] = StrSafe_DB($event['code']);
            }
        }

        // Guarded: an empty list would make CreateFinalsInd() build grids
        // for every individual event of the tournament.
        if ($finalsCodes) {
            CreateFinalsInd($tourId, implode(',', $finalsCodes));
        }

        safe_w_Commit();
    } catch (Throwable $e) {
        safe_w_Rollback();
        throw $e;
    }

    return ['created' => count($plan['events']), 'deleted' => count($oldEvents)];
}

/**
 * The preview payload for already-loaded input (see cePreview()).
 */
function cePreviewFromInput(int $tourId, array $input, array $criteria): array
{
    $criteria = ceNormalizeCriteria($criteria);
    $plan = ceBuildPlan($input['pairs'], $input['oldEvents'], $input['classes'], $criteria,
        ceLabelsFor($input['tour']['locRule']));
    $deletion = ceDeletionSummary($tourId);

    $newEvents = array_map(function ($event) {
        return [
            'code' => $event['code'],
            'name' => $event['name'],
            'count' => $event['count'],
            'firstPhase' => $event['firstPhase'],
            'distance' => $event['distance'],
        ];
    }, $plan['events']);

    return [
        'criteria' => $criteria,
        'oldEvents' => $deletion['events'],
        'newEvents' => $newEvents,
        'plan' => $plan,
        'deletion' => $deletion,
        'fingerprint' => cePlanFingerprint($plan, $deletion),
    ];
}

/**
 * What a rebuild of $tourId by $criteria would do: the normalised criteria,
 * current events (to be deleted), new events (to be created), the full
 * plan, the deletion summary, and the fingerprint apply must match.
 * Same session precondition as ceLoadInput().
 */
function cePreview(int $tourId, array $criteria): array
{
    return cePreviewFromInput($tourId, ceLoadInput($tourId), $criteria);
}

/**
 * Applies the rebuild only if a fresh preview still has $fingerprint (the
 * one the user confirmed). Otherwise nothing is written and the fresh
 * preview comes back as 'preview' so the user can re-check it.
 */
function ceGuardedApply(int $tourId, array $criteria, string $fingerprint): array
{
    $input = ceLoadInput($tourId);
    $preview = cePreviewFromInput($tourId, $input, $criteria);

    if (!hash_equals($preview['fingerprint'], $fingerprint)) {
        return ['error' => 1, 'stale' => true, 'preview' => $preview];
    }

    return ['error' => 0] + ceApplyPlan($tourId, $preview['plan'], $input['tour']);
}
