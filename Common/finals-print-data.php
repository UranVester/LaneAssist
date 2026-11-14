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
                ev.EvDistance Distance, tar.TarDescr FaceName
            FROM FinSchedule fs
            INNER JOIN Grids gr ON gr.GrMatchNo=fs.FSMatchNo
            INNER JOIN Events ev ON ev.EvTournament=fs.FSTournament AND ev.EvCode=fs.FSEvent AND ev.EvTeamEvent=fs.FSTeamEvent
            LEFT JOIN Targets tar ON tar.TarId=ev.EvFinalTargetType
            WHERE fs.FSTournament=" . StrSafe_DB($_SESSION['TourId']) . "
              AND fs.FSScheduledDate=" . StrSafe_DB($date) . "
              AND $timeSql
            ORDER BY fs.FSTeamEvent, fs.FSEvent, fs.FSMatchNo";

    $rows = [];
    $rs = safe_r_sql($sql);
    while ($row = safe_fetch($rs)) {
        $rows[] = [
            'teamEvent' => intval($row->TeamEvent),
            'event' => (string)$row->EventCode,
            'eventName' => (string)$row->EventName,
            'phase' => intval($row->Phase),
            'matchNo' => intval($row->MatchNo),
            'target' => (string)$row->Target,
            'letter' => (string)$row->Letter,
            'faceUrl' => fpFaceImageUrl(intval($row->FaceId)),
            'faceLabel' => laFaceLabel($row->FaceName ? get_text($row->FaceName) : '', $row->FaceSize),
            'distance' => (string)$row->Distance,
        ];
    }
    return $rows;
}

/**
 * Every bracket row of the given events: "team|event" => [matchNo => bool placed].
 *
 * @param string[] $eventKeys "team|event"
 */
function fpLoadEventSides(array $eventKeys): array
{
    $tour = StrSafe_DB($_SESSION['TourId']);
    $result = [];
    foreach (array_unique($eventKeys) as $key) {
        [$team, $event] = explode('|', $key, 2);
        $result[$key] = [];
        $sql = intval($team)
            ? "SELECT TfMatchNo MatchNo, TfTeam>0 Placed FROM TeamFinals WHERE TfTournament=$tour AND TfEvent=" . StrSafe_DB($event)
            : "SELECT FinMatchNo MatchNo, FinAthlete>0 Placed FROM Finals WHERE FinTournament=$tour AND FinEvent=" . StrSafe_DB($event);
        $rs = safe_r_sql($sql);
        while ($row = safe_fetch($rs)) {
            $result[$key][intval($row->MatchNo)] = (bool)intval($row->Placed);
        }
    }
    return $result;
}

/** Distinct matches (even base matchNo) behind a list of side rows. */
function fpRoundMatchesFromSides(array $sides): array
{
    $matches = [];
    foreach ($sides as $side) {
        $base = intval($side['matchNo']) - (intval($side['matchNo']) % 2);
        $key = fpEventKey($side) . '|' . $base;
        $matches[$key] = ['teamEvent' => intval($side['teamEvent']), 'event' => (string)$side['event'], 'matchNo' => $base];
    }
    return array_values($matches);
}

/** Everything the print page needs for one slot. */
function fpLoadRound(string $slot): array
{
    $sides = fpLoadRoundSides($slot);
    $sidesByEvent = fpLoadEventSides(array_map('fpEventKey', $sides));
    return [
        'sides' => $sides,
        'sidesByEvent' => $sidesByEvent,
        'readiness' => fpRoundReadiness(fpRoundMatchesFromSides($sides), $sidesByEvent),
    ];
}

/** @return array{ready: bool, waiting: int} */
function fpSlotReadiness(string $slot): array
{
    if (!fpIsValidSlot($slot)) {
        return ['ready' => false, 'waiting' => 0];
    }
    return fpLoadRound($slot)['readiness'];
}
