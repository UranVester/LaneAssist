<?php
/**
 * LaneAssist ManageTargets — pure target-assignment logic.
 *
 * Everything here is DB- and session-free so it can be unit tested without an
 * IANSEO request context. Where saved (database) state is needed, the caller
 * injects it as a callable probe.
 */

if (!defined('TargetNoPadding')) {
    define('TargetNoPadding', 4);
}

/**
 * Normalise the "current assignments" snapshot posted by the ManageTargets UI.
 *
 * `provided` says whether the browser sent a snapshot at all — NOT whether the
 * snapshot contains any occupied target. Those are different questions: after
 * "Unassign all" the UI sends one entry per participant with an empty target,
 * which is a snapshot asserting that every target is free.
 *
 * @param mixed $currentAssignments Decoded `currentAssignments` request payload.
 * @return array{provided:bool, assignedByParticipant:array<int,string>, occupiedTargets:array<string,bool>}
 */
function laParseAssignmentSnapshot($currentAssignments) {
    $assignedByParticipant = array();
    $occupiedTargets = array();
    $provided = false;

    if (is_array($currentAssignments)) {
        foreach ($currentAssignments as $assignment) {
            if (!is_array($assignment)) {
                continue;
            }
            $provided = true;
            $participantId = intval($assignment['participantId'] ?? 0);
            $targetFull = strtoupper(trim((string)($assignment['targetFull'] ?? '')));
            if ($participantId > 0) {
                $assignedByParticipant[$participantId] = $targetFull;
            }
            if ($targetFull !== '') {
                $occupiedTargets[$targetFull] = true;
            }
        }
    }

    return array(
        'provided' => $provided,
        'assignedByParticipant' => $assignedByParticipant,
        'occupiedTargets' => $occupiedTargets,
    );
}

/**
 * Build the target/letter availability map used by auto-assign.
 *
 * 1 = free, 0 = taken. Keys are zero-padded target numbers plus the letter
 * (e.g. `0001A`), in target order and then in $letters order — auto-assign
 * relies on that insertion order when it walks for the next free slot.
 *
 * When the UI supplied a snapshot it is the only source of truth; saved
 * database state is deliberately ignored, because unsaved unassignments must
 * free their targets. $savedOccupancyProbe is only consulted otherwise.
 *
 * @param int      $firstTarget         First target number in range.
 * @param int      $lastTarget          Last target number in range.
 * @param string[] $letters             Letters in draw order.
 * @param array    $snapshot            Result of laParseAssignmentSnapshot().
 * @param callable $savedOccupancyProbe fn(int $target, string $letter): bool
 * @return array<string,int>
 */
function laBuildTargetAvailability($firstTarget, $lastTarget, array $letters, array $snapshot, callable $savedOccupancyProbe) {
    $useSnapshot = !empty($snapshot['provided']);
    $occupiedTargets = isset($snapshot['occupiedTargets']) ? $snapshot['occupiedTargets'] : array();

    $availability = array();
    for ($target = intval($firstTarget); $target <= intval($lastTarget); $target++) {
        foreach ($letters as $letter) {
            $targetFull = str_pad($target . $letter, (TargetNoPadding + 1), '0', STR_PAD_LEFT);
            if ($useSnapshot) {
                $availability[$targetFull] = (empty($occupiedTargets[$targetFull]) ? 1 : 0);
            } else {
                $availability[$targetFull] = ($savedOccupancyProbe($target, $letter) ? 0 : 1);
            }
        }
    }

    return $availability;
}

/**
 * Whether target/letter exists in a qualification session laid out as
 * $targetsInSession targets from $firstTarget, $archersPerTarget letters
 * (A, B, ...) each. Mirrors what core createAvailableTargetSQL() generates,
 * which is not used here: its MySQL 8 / MariaDB path (IANSEO 2026-10-26)
 * stopped filtering on SesType='Q'.
 */
function laIsTargetInSession($target, $letter, $firstTarget, $targetsInSession, $archersPerTarget) {
    $target = intval($target);
    $firstTarget = intval($firstTarget);
    $letter = strtoupper((string)$letter);
    if (!preg_match('/^[A-Z]$/', $letter)) {
        return false;
    }
    return $target >= $firstTarget
        && $target < $firstTarget + intval($targetsInSession)
        && ord($letter) - 64 <= intval($archersPerTarget);
}

/**
 * Auto-assign grouping key: archers with the same key are drawn onto the same
 * block of mats. Matches the original SetTarget_auto.php index
 * (distance|div class|widths|faces). With $mixSameSize the face ids are
 * dropped, so faces of the same size (e.g. 40cm full, triple, CO triple)
 * share mats; distance and size still split.
 *
 * @param string $widths TfW1..TfW8 joined with '-'
 * @param string $faces  TfT1..TfT8 joined with '-'
 */
function laAutoAssignGroupKey($distanceKey, $division, $class, $groupByDiv, $groupByClass, $widths, $faces, $mixSameSize) {
    $key = $distanceKey . '|';
    if ($groupByDiv) {
        $key .= $division;
    }
    if ($groupByClass) {
        $key .= $class;
    }
    $key .= '|' . $widths;
    if (!$mixSameSize) {
        $key .= '|' . $faces;
    }
    return $key;
}
