<?php
/**
 * Manage Finals API
 */

require_once(dirname(__FILE__, 3) . '/config.php');
header('Content-Type: application/json');

if (!CheckTourSession()) {
    echo json_encode(['error' => 1, 'message' => get_text('CrackError')]);
    exit;
}

checkFullACL(AclCompetition, 'cSchedule', AclReadWrite, false);

require_once(dirname(__FILE__, 2) . '/Common/csrf.php');
require_once(dirname(__FILE__, 2) . '/Common/finals-logic.php');

$action = $_REQUEST['action'] ?? '';

if (in_array($action, ['apply', 'validateChanges'], true)) {
    laneAssistRequirePost();
}

switch ($action) {
    case 'getCurrent':
        getCurrent();
        break;
    case 'validateChanges':
        validateChanges();
        break;
    case 'apply':
        applyChanges();
        break;
    default:
        echo json_encode(['error' => 1, 'message' => 'Invalid action']);
}

function loadFinalRowsForValidation() {
    $sql = "SELECT
                fs.FSTeamEvent,
                fs.FSEvent,
                fs.FSMatchNo,
                fs.FSScheduledDate,
                fs.FSScheduledTime,
                fs.FSScheduledLen,
                fs.FSGroup,
                fs.FSTarget,
                gr.GrPhase,
                ev.EvFinalAthTarget,
                fi.FinAthlete,
                tf.TfTeam
            FROM FinSchedule fs
            LEFT JOIN Events ev
                ON ev.EvTournament=fs.FSTournament
                AND ev.EvCode=fs.FSEvent
                AND ev.EvTeamEvent=fs.FSTeamEvent
            LEFT JOIN Grids gr
                ON gr.GrMatchNo=fs.FSMatchNo
            LEFT JOIN Finals fi
                ON fi.FinTournament=fs.FSTournament
                AND fi.FinEvent=fs.FSEvent
                AND fi.FinMatchNo=fs.FSMatchNo
                AND fs.FSTeamEvent=0
            LEFT JOIN TeamFinals tf
                ON tf.TfTournament=fs.FSTournament
                AND tf.TfEvent=fs.FSEvent
                AND tf.TfMatchNo=fs.FSMatchNo
                AND fs.FSTeamEvent=1
            WHERE fs.FSTournament=" . StrSafe_DB($_SESSION['TourId']);

    $rows = [];
    $rs = safe_r_sql($sql);
    while ($row = safe_fetch($rs)) {
        $scheduledDate = normalizeScheduledDateForUi($row->FSScheduledDate);
        $scheduledTime = normalizeScheduledTimeForUi($row->FSScheduledTime, $scheduledDate);
        $phase = intval($row->GrPhase);
        $phaseBit = max(1, $phase * 2);
        $finalAthTargetMask = intval($row->EvFinalAthTarget);
        $archersPerTarget = (($finalAthTargetMask & $phaseBit) ? 2 : 1);

        $rows[] = [
            'teamEvent' => intval($row->FSTeamEvent),
            'event' => trim((string)$row->FSEvent),
            'matchNo' => intval($row->FSMatchNo),
            'scheduledDate' => $scheduledDate,
            'scheduledTime' => $scheduledTime,
            'scheduledLen' => intval($row->FSScheduledLen),
            'group' => intval($row->FSGroup),
            'target' => strtoupper(trim((string)$row->FSTarget)),
            'phase' => $phase,
            'archersPerTarget' => $archersPerTarget,
            'hasParticipant' => (intval($row->FSTeamEvent) === 1)
                ? (intval($row->TfTeam) > 0 ? 1 : 0)
                : (intval($row->FinAthlete) > 0 ? 1 : 0),
        ];
    }

    return $rows;
}

function validateChanges() {
    $changesRaw = $_REQUEST['changes'] ?? '[]';
    $changes = json_decode($changesRaw, true);

    if (!is_array($changes)) {
        echo json_encode(['error' => 1, 'message' => 'Invalid changes payload']);
        return;
    }

    $rows = loadFinalRowsForValidation();
    $rowsAfter = applyChangesToRows($rows, $changes);
    $focus = buildValidationFocusFromChanges($rowsAfter, $changes);
    $errors = validateFinalRows($rowsAfter, $focus);

    echo json_encode([
        'error' => 0,
        'valid' => count($errors) === 0,
        'errors' => $errors,
    ]);
}

function applyChanges() {
    $changesRaw = $_REQUEST['changes'] ?? '[]';
    $changes = json_decode($changesRaw, true);

    if (!is_array($changes)) {
        echo json_encode(['error' => 1, 'message' => 'Invalid changes payload']);
        return;
    }

    if (count($changes) === 0) {
        echo json_encode(['error' => 0, 'message' => 'No changes to apply', 'updated' => 0]);
        return;
    }

    // Server-side validation before applying
    $rows = loadFinalRowsForValidation();
    $rowsAfter = applyChangesToRows($rows, $changes);
    $focus = buildValidationFocusFromChanges($rowsAfter, $changes);
    $validationErrors = validateFinalRows($rowsAfter, $focus);
    if (!empty($validationErrors)) {
        echo json_encode([
            'error' => 1,
            'message' => $validationErrors[0]['message'],
            'errors' => $validationErrors,
        ]);
        return;
    }

    $updated = 0;

    foreach ($changes as $change) {
        $teamEvent = intval($change['teamEvent'] ?? 0);
        $event = trim($change['event'] ?? '');
        $matchNo = intval($change['matchNo'] ?? 0);
        $target = strtoupper(trim($change['target'] ?? ''));
        $scheduledDate = trim($change['scheduledDate'] ?? '');
        $scheduledTime = trim($change['scheduledTime'] ?? '');
        $scheduledLen = max(0, intval($change['scheduledLen'] ?? 0));

        if ($scheduledDate === '') {
            $scheduledLen = 0;
        }

        if ($event === '' || $matchNo < 0) {
            continue;
        }

        // Keep formatting consistent with existing data
        if ($target !== '' && ctype_digit($target)) {
            $target = str_pad($target, TargetNoPadding, '0', STR_PAD_LEFT);
        }

        $letter = $target;

        // Unassign = remove the row, don't leave a blank shell behind.
        // An empty FinSchedule is what lets the bracket-synthesis fallback repopulate the pool.
        if ($target === '') {
            safe_w_sql("DELETE FROM FinSchedule
                        WHERE FSTournament=" . StrSafe_DB($_SESSION['TourId']) . "
                          AND FSTeamEvent="  . StrSafe_DB($teamEvent) . "
                          AND FSEvent="      . StrSafe_DB($event) . "
                          AND FSMatchNo="    . StrSafe_DB($matchNo));
            $updated++;
            continue;
        }
        
        $sql = "INSERT INTO FinSchedule
                    (FSTournament, FSTeamEvent, FSEvent, FSMatchNo, FSGroup, FSTarget, FSLetter, FSScheduledDate, FSScheduledTime, FSScheduledLen)
                VALUES
                    (" . StrSafe_DB($_SESSION['TourId']) . ", " . StrSafe_DB($teamEvent) . ", " . StrSafe_DB($event) . ", " . StrSafe_DB($matchNo) . ", 0, " . StrSafe_DB($target) . ", " . StrSafe_DB($letter) . ", " . StrSafe_DB($scheduledDate) . ", " . StrSafe_DB($scheduledTime) . ", " . StrSafe_DB($scheduledLen) . ")
                ON DUPLICATE KEY UPDATE
                    FSTarget=VALUES(FSTarget),
                    FSLetter=VALUES(FSLetter),
                    FSScheduledDate=VALUES(FSScheduledDate),
                    FSScheduledTime=VALUES(FSScheduledTime),
                    FSScheduledLen=VALUES(FSScheduledLen)";

        safe_w_sql($sql);
        if (safe_w_affected_rows() > 0) {
            $updated++;
        }
    }

    echo json_encode([
        'error' => 0,
        'message' => 'Applied finals setup changes',
        'updated' => $updated
    ]);
}
