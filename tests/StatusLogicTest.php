<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Common/status-logic.php';

final class StatusLogicTest extends TestCase
{
    // ---------- Stage derivation ----------

    public function testStageIsPlanningWhenNoQualificationSessionIsScheduledYet(): void
    {
        $stage = laneAssistTournamentStage('2026-08-01 10:00:00', null, false, false, true, false);
        $this->assertSame('planning', $stage);
    }

    public function testStageIsPlanningMoreThanOneHourBeforeFirstQualSession(): void
    {
        $stage = laneAssistTournamentStage('2026-08-30 08:59:00', '2026-08-30 10:00:00', false, false, true, false);
        $this->assertSame('planning', $stage);
    }

    public function testStageIsQualificationWithinOneHourOfFirstQualSession(): void
    {
        $stage = laneAssistTournamentStage('2026-08-30 09:30:00', '2026-08-30 10:00:00', false, false, true, false);
        $this->assertSame('qualification', $stage);
    }

    public function testStageIsFinalsOnceBracketsAreInitializedButNotComplete(): void
    {
        $stage = laneAssistTournamentStage('2026-08-30 16:00:00', '2026-08-30 10:00:00', true, false, true, true);
        $this->assertSame('finals', $stage);
    }

    public function testStageIsOverOnceFinalsAreComplete(): void
    {
        $stage = laneAssistTournamentStage('2026-08-30 18:00:00', '2026-08-30 10:00:00', true, true, true, true);
        $this->assertSame('over', $stage);
    }

    public function testStageIsOverWhenNoFinalsAreConfiguredAndQualificationIsComplete(): void
    {
        $stage = laneAssistTournamentStage('2026-08-30 16:00:00', '2026-08-30 10:00:00', false, false, false, true);
        $this->assertSame('over', $stage);
    }

    public function testStageStaysQualificationWhenNoFinalsConfiguredButQualificationStillRunning(): void
    {
        $stage = laneAssistTournamentStage('2026-08-30 12:00:00', '2026-08-30 10:00:00', false, false, false, false);
        $this->assertSame('qualification', $stage);
    }

    // ---------- Finals completion ----------

    public function testFinalsAreNotCompleteWithNoMatches(): void
    {
        $this->assertFalse(laneAssistFinalsAreComplete([]));
    }

    public function testFinalsAreCompleteWhenEveryMatchIsCompleteOrAdvanced(): void
    {
        $matches = [
            ['status' => 'complete', 'canAdvance' => false, 'canMarkBye' => false],
            ['status' => 'advanced', 'canAdvance' => false, 'canMarkBye' => false],
        ];
        $this->assertTrue(laneAssistFinalsAreComplete($matches));
    }

    public function testFinalsAreNotCompleteWhenAMatchCanStillAdvance(): void
    {
        $matches = [
            ['status' => 'bye', 'canAdvance' => true, 'canMarkBye' => true],
        ];
        $this->assertFalse(laneAssistFinalsAreComplete($matches));
    }

    public function testFinalsAreNotCompleteWhileAMatchIsStillInProgress(): void
    {
        // Regression: 'unreported'/'partial'/'uneven'/'live' matches have
        // canAdvance=false and canMarkBye=false, same as a genuinely finished
        // match -- checking only those flags misreports the tournament as
        // "over" while a round is still being shot.
        foreach (['unreported', 'partial', 'uneven', 'live'] as $inProgressStatus) {
            $matches = [
                ['status' => $inProgressStatus, 'canAdvance' => false, 'canMarkBye' => false],
            ];
            $this->assertFalse(
                laneAssistFinalsAreComplete($matches),
                "status '$inProgressStatus' with no pending action must still block completion"
            );
        }
    }

    // ---------- Per-event finals planning (demand-gating + size mismatch) ----------

    public function testFinalsPlanningIsSilentWhenNoEntrantsWantThisFinalsType(): void
    {
        $issues = laneAssistFinalsPlanningIssues([
            'code' => 'TIC', 'label' => 'team', 'finalFirstPhase' => 0,
            'rawEntrantCount' => 0, 'hasAnyScheduled' => false, 'expectedSize' => 0,
        ]);
        $this->assertSame([], $issues);
    }

    public function testFinalsPlanningFlagsMissingBracketWhenEntrantsExist(): void
    {
        $issues = laneAssistFinalsPlanningIssues([
            'code' => 'TIC', 'label' => 'individual', 'finalFirstPhase' => 0,
            'rawEntrantCount' => 10, 'hasAnyScheduled' => false, 'expectedSize' => 0,
        ]);
        $this->assertCount(1, $issues);
        $this->assertSame('no_bracket', $issues[0]['type']);
        $this->assertSame('warning', $issues[0]['severity']);
        $this->assertStringContainsString('no finals bracket is configured', $issues[0]['message']);
    }

    public function testFinalsPlanningMissingBracketSuggestsStandardSize(): void
    {
        $issues = laneAssistFinalsPlanningIssues([
            'code' => 'TIC', 'label' => 'individual', 'finalFirstPhase' => 0,
            'rawEntrantCount' => 10, 'hasAnyScheduled' => false, 'expectedSize' => 0,
            'standardCapacities' => [8, 16, 32],
        ]);
        $this->assertCount(1, $issues);
        $this->assertStringContainsString('10 entrants', $issues[0]['message']);
        $this->assertStringContainsString('suggest sizing for 16', $issues[0]['message']);
    }

    public function testFinalsPlanningMissingBracketOmitsSuggestionWhenNoStandardCapacitiesGiven(): void
    {
        $issues = laneAssistFinalsPlanningIssues([
            'code' => 'TIC', 'label' => 'individual', 'finalFirstPhase' => 0,
            'rawEntrantCount' => 10, 'hasAnyScheduled' => false, 'expectedSize' => 0,
        ]);
        $this->assertCount(1, $issues);
        $this->assertStringNotContainsString('suggest sizing for', $issues[0]['message']);
    }

    public function testFinalsPlanningFlagsUnscheduledBracket(): void
    {
        $issues = laneAssistFinalsPlanningIssues([
            'code' => 'TIC', 'label' => 'individual', 'finalFirstPhase' => 8,
            'rawEntrantCount' => 16, 'hasAnyScheduled' => false, 'expectedSize' => 16,
        ]);
        $this->assertCount(1, $issues);
        $this->assertSame('not_scheduled', $issues[0]['type']);
        $this->assertStringContainsString('not scheduled yet', $issues[0]['message']);
        $this->assertStringContainsString('sized for 16', $issues[0]['message']);
    }

    public function testFinalsPlanningFlagsSizeMismatchIncludingIrregularPhases(): void
    {
        // Phase 12 (irregular) expects 24 entrants (numQualifiedByPhase(12) == 24).
        $issues = laneAssistFinalsPlanningIssues([
            'code' => 'TIC', 'label' => 'individual', 'finalFirstPhase' => 12,
            'rawEntrantCount' => 30, 'hasAnyScheduled' => true, 'expectedSize' => 24,
        ]);
        $this->assertCount(1, $issues);
        $this->assertSame('size_mismatch', $issues[0]['type']);
        $this->assertStringContainsString('30 entrants but bracket sized for 24', $issues[0]['message']);
    }

    public function testFinalsPlanningIsQuietWhenScheduledAndCorrectlySized(): void
    {
        $issues = laneAssistFinalsPlanningIssues([
            'code' => 'TIC', 'label' => 'individual', 'finalFirstPhase' => 8,
            'rawEntrantCount' => 16, 'hasAnyScheduled' => true, 'expectedSize' => 16,
        ]);
        $this->assertSame([], $issues);
    }

    public function testFinalsPlanningNeverComputesSizeMismatchWhenNoBracketIsConfigured(): void
    {
        // finalFirstPhase=0 (no bracket chosen yet) must short-circuit before any
        // size math runs, even though rawEntrantCount is > 0.
        $issues = laneAssistFinalsPlanningIssues([
            'code' => 'TIC', 'label' => 'team', 'finalFirstPhase' => 0,
            'rawEntrantCount' => 12, 'hasAnyScheduled' => false, 'expectedSize' => 0,
        ]);
        $this->assertCount(1, $issues);
        $this->assertSame('no_bracket', $issues[0]['type']);
    }

    // ---------- Phase labels ----------

    public function testPhaseLabelNamesTheMedalPhases(): void
    {
        $this->assertSame('Gold', laneAssistPhaseLabel(0));
        $this->assertSame('Bronze', laneAssistPhaseLabel(1));
    }

    public function testPhaseLabelFormatsStandardPhasesAsFractions(): void
    {
        $this->assertSame('1/2', laneAssistPhaseLabel(2));
        $this->assertSame('1/8', laneAssistPhaseLabel(8));
        $this->assertSame('1/16', laneAssistPhaseLabel(16));
    }

    public function testPhaseLabelFormatsIrregularPhasesAsFractionsToo(): void
    {
        // numQualifiedByPhase() has hardcoded irregular overrides for these
        // phase ids (12 => 24 entrants, not 12*2), but the *label* is still
        // just "1/<phase>" -- no lookup table needed.
        $this->assertSame('1/12', laneAssistPhaseLabel(12));
    }

    // ---------- Finals planning: bucketing into Status-tab cards ----------

    public function testGroupFinalsPlanningIssuesFormatsBracketSizeWrongRowWithSuggestion(): void
    {
        // Mirrors the user's own example: an individual event sized for 1/8
        // (8 qualified) but with 30 raw entrants should suggest 1/16 (32
        // qualified), the smallest standard capacity that covers them.
        $grouped = laneAssistGroupFinalsPlanningIssues([[
            'code' => 'BD', 'label' => 'individual', 'teamEvent' => 0,
            'finalFirstPhase' => 8, 'rawEntrantCount' => 30, 'hasAnyScheduled' => true,
            'expectedSize' => 16, 'standardCapacities' => [8, 16, 32],
            'phaseByCapacity' => [8 => 8, 16 => 16, 32 => 16],
            'listLink' => '/list', 'scheduleLink' => '/schedule',
        ]]);

        $this->assertSame([['text' => 'BD - 1/8 -> 1/16', 'link' => '/list']], $grouped['bracketSizeWrong']);
        $this->assertSame([], $grouped['unscheduledFinals']);
        $this->assertSame([], $grouped['noBracketConfigured']);
    }

    public function testGroupFinalsPlanningIssuesOmitsArrowWhenNoSuggestedPhaseIsKnown(): void
    {
        $grouped = laneAssistGroupFinalsPlanningIssues([[
            'code' => 'BD', 'label' => 'individual', 'teamEvent' => 0,
            'finalFirstPhase' => 8, 'rawEntrantCount' => 30, 'hasAnyScheduled' => true,
            'expectedSize' => 16, 'standardCapacities' => [],
            'phaseByCapacity' => [], 'listLink' => '/list', 'scheduleLink' => '/schedule',
        ]]);

        $this->assertSame([['text' => 'BD - 1/8', 'link' => '/list']], $grouped['bracketSizeWrong']);
    }

    public function testGroupFinalsPlanningIssuesDropsSizeMismatchForTeamAndMixedEvents(): void
    {
        foreach ([1, 2] as $teamEvent) {
            $grouped = laneAssistGroupFinalsPlanningIssues([[
                'code' => 'TIC', 'label' => 'team', 'teamEvent' => $teamEvent,
                'finalFirstPhase' => 8, 'rawEntrantCount' => 30, 'hasAnyScheduled' => true,
                'expectedSize' => 16, 'standardCapacities' => [8, 16, 32],
                'phaseByCapacity' => [8 => 8, 16 => 16, 32 => 16],
                'listLink' => '/list', 'scheduleLink' => '/schedule',
            ]]);
            $this->assertSame([], $grouped['bracketSizeWrong'], "teamEvent={$teamEvent} must not surface a size-wrong row");
        }
    }

    public function testGroupFinalsPlanningIssuesListsUnscheduledEventsOfAnyType(): void
    {
        $grouped = laneAssistGroupFinalsPlanningIssues([[
            'code' => 'TIC', 'label' => 'team', 'teamEvent' => 1,
            'finalFirstPhase' => 8, 'rawEntrantCount' => 16, 'hasAnyScheduled' => false,
            'expectedSize' => 16, 'standardCapacities' => [8, 16, 32],
            'phaseByCapacity' => [], 'listLink' => '/list', 'scheduleLink' => '/schedule',
        ]]);

        $this->assertSame([['text' => 'TIC (team)', 'link' => '/schedule']], $grouped['unscheduledFinals']);
    }

    public function testGroupFinalsPlanningIssuesListsNoBracketConfiguredEventsOfAnyType(): void
    {
        $grouped = laneAssistGroupFinalsPlanningIssues([[
            'code' => 'TIC', 'label' => 'team', 'teamEvent' => 1,
            'finalFirstPhase' => 0, 'rawEntrantCount' => 16, 'hasAnyScheduled' => false,
            'expectedSize' => 0, 'standardCapacities' => [],
            'phaseByCapacity' => [], 'listLink' => '/list', 'scheduleLink' => '/schedule',
        ]]);

        $this->assertSame([['text' => 'TIC (team)', 'link' => '/list']], $grouped['noBracketConfigured']);
    }

    public function testGroupFinalsPlanningIssuesBucketsAcrossMultipleEvents(): void
    {
        $grouped = laneAssistGroupFinalsPlanningIssues([
            [
                'code' => 'BD', 'label' => 'individual', 'teamEvent' => 0,
                'finalFirstPhase' => 8, 'rawEntrantCount' => 30, 'hasAnyScheduled' => true,
                'expectedSize' => 16, 'standardCapacities' => [8, 16, 32],
                'phaseByCapacity' => [8 => 8, 16 => 16, 32 => 16],
                'listLink' => '/list/ind', 'scheduleLink' => '/schedule',
            ],
            [
                'code' => 'TIC', 'label' => 'team', 'teamEvent' => 1,
                'finalFirstPhase' => 8, 'rawEntrantCount' => 16, 'hasAnyScheduled' => false,
                'expectedSize' => 16, 'standardCapacities' => [],
                'phaseByCapacity' => [], 'listLink' => '/list/team', 'scheduleLink' => '/schedule',
            ],
            [
                'code' => 'RC', 'label' => 'individual', 'teamEvent' => 0,
                'finalFirstPhase' => 0, 'rawEntrantCount' => 12, 'hasAnyScheduled' => false,
                'expectedSize' => 0, 'standardCapacities' => [],
                'phaseByCapacity' => [], 'listLink' => '/list/ind', 'scheduleLink' => '/schedule',
            ],
        ]);

        $this->assertCount(1, $grouped['bracketSizeWrong']);
        $this->assertCount(1, $grouped['unscheduledFinals']);
        $this->assertCount(1, $grouped['noBracketConfigured']);
    }

    // ---------- Unassigned archers ----------

    public function testDetectUnassignedArchersGroupsCountBySession(): void
    {
        $issues = laneAssistDetectUnassignedArchers([
            ['sessionOrder' => 1, 'target' => '01', 'letter' => 'A'],
            ['sessionOrder' => 1, 'target' => '', 'letter' => ''],
            ['sessionOrder' => 2, 'target' => '', 'letter' => ''],
            ['sessionOrder' => 2, 'target' => '', 'letter' => ''],
        ]);

        $this->assertSame([
            ['sessionOrder' => 1, 'count' => 1],
            ['sessionOrder' => 2, 'count' => 2],
        ], $issues);
    }

    public function testDetectUnassignedArchersIsEmptyWhenEveryoneHasATarget(): void
    {
        $issues = laneAssistDetectUnassignedArchers([
            ['sessionOrder' => 1, 'target' => '01', 'letter' => 'A'],
        ]);
        $this->assertSame([], $issues);
    }

    // ---------- Sessions without times ----------

    public function testDetectSessionsWithoutTimesFlagsTheZeroDateSentinel(): void
    {
        $issues = laneAssistDetectSessionsWithoutTimes([
            ['sessionOrder' => 1, 'sessionId' => '1_Q', 'dtStart' => '0000-00-00 00:00:00'],
            ['sessionOrder' => 2, 'sessionId' => '2_Q', 'dtStart' => '2026-08-30 09:00:00'],
        ]);

        $this->assertSame([['sessionOrder' => 1, 'sessionId' => '1_Q']], $issues);
    }

    public function testDetectSessionsWithoutTimesLeavesAnyNonSentinelDateAlone(): void
    {
        // A real-looking-but-wrong date is out of scope: only the exact zero-date
        // sentinel is flagged, per spec. This pins that boundary.
        $issues = laneAssistDetectSessionsWithoutTimes([
            ['sessionOrder' => 1, 'sessionId' => '1_Q', 'dtStart' => '1999-01-01 00:00:00'],
        ]);
        $this->assertSame([], $issues);
    }

    public function testDetectSessionsWithoutTimesSkipsSessionsWithARealDistanceTime(): void
    {
        // Regression: Tournament/ManSessions_kiss.php (the simplified session UI
        // for multi-distance Qualification rounds) saves per-distance start
        // times into DistanceInformation and never backfills Session's own
        // SesDtStart, which stays at the zero-date sentinel forever. A session
        // with a real distance time must not be flagged just because SesDtStart
        // itself is unset.
        $issues = laneAssistDetectSessionsWithoutTimes([
            ['sessionOrder' => 1, 'sessionId' => '1_Q', 'dtStart' => '0000-00-00 00:00:00', 'hasDistanceTime' => true],
        ]);
        $this->assertSame([], $issues);
    }

    // ---------- Club logos ----------

    public function testClassifyClubLogoIsNullWhenAlreadyLinkedToTournament(): void
    {
        $this->assertNull(laneAssistClassifyClubLogo('USA', true, true));
        $this->assertNull(laneAssistClassifyClubLogo('USA', true, false));
    }

    public function testClassifyClubLogoIsLinkableWhenOnlyInGlobalPool(): void
    {
        $result = laneAssistClassifyClubLogo('USA', false, true);
        $this->assertSame(['type' => 'linkable', 'club' => 'USA'], $result);
    }

    public function testClassifyClubLogoIsMissingWhenNowhereAtAll(): void
    {
        $result = laneAssistClassifyClubLogo('USA', false, false);
        $this->assertSame(['type' => 'missing', 'club' => 'USA'], $result);
    }

    public function testGroupClubLogoIssuesSeparatesFixableFromMissing(): void
    {
        $groups = laneAssistGroupClubLogoIssues([
            ['type' => 'linkable', 'club' => 'USA'],
            ['type' => 'missing', 'club' => 'GER'],
            ['type' => 'linkable', 'club' => 'FRA'],
        ]);
        $this->assertSame(['USA', 'FRA'], $groups['fixable']);
        $this->assertSame(['GER'], $groups['missing']);
    }

    public function testGroupClubLogoIssuesIsEmptyArraysWhenNoIssues(): void
    {
        $groups = laneAssistGroupClubLogoIssues([]);
        $this->assertSame(['fixable' => [], 'missing' => []], $groups);
    }

    // ---------- Bracket size recommendation ----------

    public function testRecommendBracketSizePicksSmallestCapacityThatCoversEntrants(): void
    {
        $this->assertSame(16, laneAssistRecommendBracketSize(10, [8, 16, 32]));
        $this->assertSame(8, laneAssistRecommendBracketSize(8, [8, 16, 32]));
    }

    public function testRecommendBracketSizeFallsBackToLargestWhenEntrantsExceedAllCapacities(): void
    {
        $this->assertSame(32, laneAssistRecommendBracketSize(50, [8, 16, 32]));
    }

    public function testRecommendBracketSizeIsZeroWhenNothingToRecommend(): void
    {
        $this->assertSame(0, laneAssistRecommendBracketSize(0, [8, 16, 32]));
        $this->assertSame(0, laneAssistRecommendBracketSize(10, []));
    }
}
