<?php
/**
 * Finals lane print: DB loading for one scheduled round (time slot).
 * The round is read from FinSchedule, not from LiveView's snapshot, because the
 * snapshot drops matches nobody is placed in yet and would make an undecided
 * round look complete. Rules live in finals-print-logic.php.
 */

require_once(__DIR__ . '/finals-print-logic.php');
require_once(__DIR__ . '/target-face-logic.php');
require_once(__DIR__ . '/status-logic.php');
require_once(__DIR__ . '/finals-logic.php');

function fpIsValidSlot($slot): bool
{
    return is_string($slot) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $slot) === 1;
}

/** Image URL for a face id, the same .svg/.svgz lookup as ManageTargets/api.php. */
function fpFaceImageUrl(int $tarId): string
{
    global $CFG;
    if ($tarId <= 0) {
        return '';
    }
    foreach (['svg', 'svgz'] as $ext) {
        if (file_exists($CFG->DOCUMENT_PATH . 'Common/Images/Targets/' . $tarId . '.' . $ext)) {
            return $CFG->ROOT_DIR . 'Common/Images/Targets/' . $tarId . '.' . $ext;
        }
    }
    return '';
}

/** Translated Targets.TarDescr for a face id, cached per request. */
function fpFaceName(int $tarId): string
{
    static $names = null;
    if ($names === null) {
        $names = [];
        $rs = safe_r_sql("SELECT TarId, TarDescr FROM Targets");
        while ($row = safe_fetch($rs)) {
            $names[intval($row->TarId)] = (string)$row->TarDescr;
        }
    }
    return !empty($names[$tarId]) ? get_text($names[$tarId]) : '';
}

/**
 * Side rows for every match scheduled at $slot (individual and team).
 * A 00:00:00 slot also matches a NULL time, as LiveView builds slots that way.
 */
function fpLoadRoundSides(string $slot): array
{
    if (!fpIsValidSlot($slot)) {
        return [];
    }
    [$date, $time] = explode(' ', $slot);
    $timeSql = $time === '00:00:00'
        ? "(fs.FSScheduledTime=" . StrSafe_DB($time) . " OR fs.FSScheduledTime IS NULL)"
        : "fs.FSScheduledTime=" . StrSafe_DB($time);

    $sql = "SELECT fs.FSTeamEvent TeamEvent, fs.FSEvent EventCode, fs.FSMatchNo MatchNo,
                fs.FSTarget Target, fs.FSLetter Letter, gr.GrPhase Phase,
                ev.EvEventName EventName, ev.EvFinalTargetType FaceId, ev.EvTargetSize FaceSize,
                ev.EvDistance Distance, tf.TfT1 ArcherFaceId, tf.TfW1 ArcherFaceSize
            FROM FinSchedule fs
            INNER JOIN Grids gr ON gr.GrMatchNo=fs.FSMatchNo
            INNER JOIN Events ev ON ev.EvTournament=fs.FSTournament AND ev.EvCode=fs.FSEvent AND ev.EvTeamEvent=fs.FSTeamEvent
            LEFT JOIN Finals fin ON fs.FSTeamEvent=0 AND fin.FinTournament=fs.FSTournament
                AND fin.FinEvent=fs.FSEvent AND fin.FinMatchNo=fs.FSMatchNo AND fin.FinAthlete>0
            LEFT JOIN Entries en ON en.EnId=fin.FinAthlete AND en.EnTournament=fs.FSTournament
            LEFT JOIN TargetFaces tf ON tf.TfTournament=en.EnTournament AND tf.TfId=en.EnTargetFace
            WHERE fs.FSTournament=" . StrSafe_DB($_SESSION['TourId']) . "
              AND fs.FSScheduledDate=" . StrSafe_DB($date) . "
              AND $timeSql
            ORDER BY fs.FSTeamEvent, fs.FSEvent, fs.FSMatchNo";

    $rows = [];
    $rs = safe_r_sql($sql);
    while ($row = safe_fetch($rs)) {
        // The archer's own face (same TfT1 ManageTargets shows), else the event's finals face
        $face = fpSideFace(
            ['id' => intval($row->FaceId), 'size' => intval($row->FaceSize)],
            $row->ArcherFaceId !== null ? ['id' => intval($row->ArcherFaceId), 'size' => intval($row->ArcherFaceSize)] : null
        );
        $rows[] = [
            'teamEvent' => intval($row->TeamEvent),
            'event' => (string)$row->EventCode,
            'eventName' => (string)$row->EventName,
            'phase' => intval($row->Phase),
            'matchNo' => intval($row->MatchNo),
            'target' => (string)$row->Target,
            'letter' => (string)$row->Letter,
            'faceUrl' => fpFaceImageUrl($face['id']),
            'faceLabel' => laFaceLabel(fpFaceName($face['id']), $face['size']),
            'distance' => (string)$row->Distance,
        ];
    }
    return $rows;
}

/**
 * Every bracket row of every event in the tournament, in two queries:
 * "team|event" => [matchNo => bool placed].
 */
function fpLoadEventSides(): array
{
    $tour = StrSafe_DB($_SESSION['TourId']);
    $result = [];
    $queries = [
        0 => "SELECT FinEvent EventCode, FinMatchNo MatchNo, FinAthlete>0 Placed FROM Finals WHERE FinTournament=$tour",
        1 => "SELECT TfEvent EventCode, TfMatchNo MatchNo, TfTeam>0 Placed FROM TeamFinals WHERE TfTournament=$tour",
    ];
    foreach ($queries as $team => $sql) {
        $rs = safe_r_sql($sql);
        while ($row = safe_fetch($rs)) {
            $result[$team . '|' . $row->EventCode][intval($row->MatchNo)] = (bool)intval($row->Placed);
        }
    }
    return $result;
}

/**
 * Every scheduled match of the tournament, grouped by LiveView's slot string
 * (date + time, NULL time as 00:00:00). One query, no face data.
 *
 * @return array{bySlot: array, numQualified: array}
 */
function fpLoadScheduledMatches(): array
{
    $sql = "SELECT fs.FSTeamEvent TeamEvent, fs.FSEvent EventCode, fs.FSMatchNo MatchNo,
                fs.FSScheduledDate ScheduledDate, fs.FSScheduledTime ScheduledTime, ev.EvNumQualified NumQualified
            FROM FinSchedule fs
            INNER JOIN Events ev ON ev.EvTournament=fs.FSTournament AND ev.EvCode=fs.FSEvent AND ev.EvTeamEvent=fs.FSTeamEvent
            WHERE fs.FSTournament=" . StrSafe_DB($_SESSION['TourId']);
    $bySlot = [];
    $numQualified = [];
    $rs = safe_r_sql($sql);
    while ($row = safe_fetch($rs)) {
        $date = trim((string)$row->ScheduledDate);
        if ($date === '' || strpos($date, '0000') === 0) {
            continue;
        }
        $slot = $date . ' ' . (trim((string)$row->ScheduledTime) ?: '00:00:00');
        $match = ['teamEvent' => intval($row->TeamEvent), 'event' => (string)$row->EventCode, 'matchNo' => intval($row->MatchNo)];
        $base = $match['matchNo'] - ($match['matchNo'] % 2);
        $match['matchNo'] = $base;
        $bySlot[$slot][fpEventKey($match) . '|' . $base] = $match;
        $numQualified[fpEventKey($match)] = intval($row->NumQualified);
    }
    foreach ($bySlot as &$matches) {
        $matches = array_values($matches);
    }
    unset($matches);
    return ['bySlot' => $bySlot, 'numQualified' => $numQualified];
}

/**
 * Projected finalists for events nobody is placed in yet (0 = nobody entered,
 * a phantom bracket). Only those events need it, and core's projection is
 * the same one ManageFinals uses.
 */
function fpProjectedForEmptyEvents(array $numQualifiedByEvent, array $sidesByEvent): array
{
    $projected = [];
    foreach ($numQualifiedByEvent as $key => $numQualified) {
        if (in_array(true, $sidesByEvent[$key] ?? [], true)) {
            continue;
        }
        [$team, $event] = explode('|', $key, 2);
        $projected[$key] = intval(getProjectedFinalistsForEvent($event, intval($team), $numQualified));
    }
    return $projected;
}

/**
 * Readiness of every scheduled round in a handful of queries, for LiveView's
 * 5-second snapshot: slot => ['ready' => bool, 'waiting' => int].
 */
function fpReadinessBySlot(): array
{
    $schedule = fpLoadScheduledMatches();
    $sidesByEvent = fpLoadEventSides();
    $projected = fpProjectedForEmptyEvents($schedule['numQualified'], $sidesByEvent);
    $result = [];
    foreach ($schedule['bySlot'] as $slot => $matches) {
        $result[$slot] = fpRoundReadiness($matches, $sidesByEvent, $projected);
    }
    return $result;
}

/** Everything the print page needs for one slot. */
function fpLoadRound(string $slot): array
{
    $sides = fpLoadRoundSides($slot);
    $sidesByEvent = fpLoadEventSides();
    $schedule = fpLoadScheduledMatches();
    $projected = fpProjectedForEmptyEvents($schedule['numQualified'], $sidesByEvent);
    return [
        'sides' => $sides,
        'sidesByEvent' => $sidesByEvent,
        'readiness' => fpRoundReadiness($schedule['bySlot'][$slot] ?? [], $sidesByEvent, $projected),
    ];
}
