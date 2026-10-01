<?php
/**
 * LaneAssist Status tab — pure decision logic (no DB, no session).
 *
 * Mirrors the live-view-logic.php / finals-logic.php split: this file has no
 * DB access at all, so every function here is safe to unit test directly.
 * LiveView/api.php's statusSnapshot() gathers the DB facts these functions
 * need and calls into them.
 */

function laneAssistTournamentStage(
    $now,
    $firstQualSessionStart,
    $finalsInitialized,
    $finalsComplete,
    $anyFinalsConfigured,
    $qualificationComplete
) {
    if ($finalsComplete) {
        return 'over';
    }

    if (!$anyFinalsConfigured && $qualificationComplete) {
        return 'over';
    }

    if ($finalsInitialized) {
        return 'finals';
    }

    if ($firstQualSessionStart === null || $firstQualSessionStart === '') {
        return 'planning';
    }

    $thresholdTimestamp = strtotime($firstQualSessionStart) - 3600;
    if (strtotime($now) < $thresholdTimestamp) {
        return 'planning';
    }

    return 'qualification';
}

function laneAssistFinalsPlanningIssues(array $event) {
    $rawEntrantCount = intval($event['rawEntrantCount'] ?? 0);
    if ($rawEntrantCount <= 0) {
        return [];
    }

    $code = (string)($event['code'] ?? '');
    $label = (string)($event['label'] ?? '');
    $finalFirstPhase = intval($event['finalFirstPhase'] ?? 0);

    if ($finalFirstPhase <= 0) {
        return [[
            'type' => 'no_bracket',
            'severity' => 'warning',
            'message' => "Event {$code} ({$label}): entrants exist but no finals bracket is configured",
        ]];
    }

    $issues = [];

    if (empty($event['hasAnyScheduled'])) {
        $issues[] = [
            'type' => 'not_scheduled',
            'severity' => 'warning',
            'message' => "Event {$code} ({$label}): bracket configured but not scheduled yet",
        ];
    }

    $expectedSize = intval($event['expectedSize'] ?? 0);
    if ($expectedSize > 0 && $rawEntrantCount !== $expectedSize) {
        $issues[] = [
            'type' => 'size_mismatch',
            'severity' => 'warning',
            'message' => "Event {$code} ({$label}): {$rawEntrantCount} entrants but bracket sized for {$expectedSize}",
        ];
    }

    return $issues;
}

function laneAssistDetectUnassignedArchers(array $assignments) {
    $countsBySession = [];

    foreach ($assignments as $assignment) {
        $target = trim((string)($assignment['target'] ?? ''));
        $letter = trim((string)($assignment['letter'] ?? ''));
        if ($target !== '' && $letter !== '') {
            continue;
        }

        $sessionOrder = intval($assignment['sessionOrder'] ?? 0);
        $countsBySession[$sessionOrder] = ($countsBySession[$sessionOrder] ?? 0) + 1;
    }

    $issues = [];
    foreach ($countsBySession as $sessionOrder => $count) {
        $issues[] = ['sessionOrder' => $sessionOrder, 'count' => $count];
    }

    return $issues;
}

function laneAssistDetectSessionsWithoutTimes(array $sessions) {
    $issues = [];

    foreach ($sessions as $session) {
        if (trim((string)($session['dtStart'] ?? '')) === '0000-00-00 00:00:00') {
            $issues[] = [
                'sessionOrder' => intval($session['sessionOrder'] ?? 0),
                'sessionId' => (string)($session['sessionId'] ?? ''),
            ];
        }
    }

    return $issues;
}

function laneAssistClassifyClubLogo($clubCode, $hasTournamentFlag, $hasGlobalFlag) {
    if ($hasTournamentFlag) {
        return null;
    }

    if ($hasGlobalFlag) {
        return ['type' => 'linkable', 'club' => (string)$clubCode];
    }

    return ['type' => 'missing', 'club' => (string)$clubCode];
}
