<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Common/live-view-logic.php';

final class LiveViewLogicTest extends TestCase
{
    public function testLastCompletedEndIgnoresPartialEnd(): void
    {
        $decode = static function(string $arrow): int {
            return $arrow === 'X' ? 10 : intval($arrow);
        };

        $this->assertSame(1, laneAssistCompletedEnds('987X', 3));
        $this->assertSame(24, laneAssistLastEndPoints('987X', 3, $decode));
    }

    public function testAverageArrowIsTotalOverArrowsShotRoundedToTwoDecimals(): void
    {
        $this->assertNull(laneAssistAverageArrow(0, 0));
        $this->assertSame(0.0, laneAssistAverageArrow(0, 6));
        $this->assertSame(8.67, laneAssistAverageArrow(52, 6));
        $this->assertSame(9.0, laneAssistAverageArrow(27, 3));
    }

    public function testPersonalBestMatchesNormalizedNameAndClubAndRequiresAHigherScore(): void
    {
        $archers = laneAssistAttachPersonalBests([
            ['firstName' => 'Ada', 'lastName' => ' Archer', 'club' => 'North  Club', 'division' => 'R', 'totalPoints' => 601],
            ['firstName' => 'Ada', 'lastName' => 'Archer', 'club' => 'South Club', 'division' => 'R', 'totalPoints' => 600],
            ['firstName' => 'No', 'lastName' => 'History', 'club' => 'North Club', 'division' => 'R', 'totalPoints' => 550],
        ], [
            ['firstName' => ' ada ', 'lastName' => 'archer', 'club' => 'north club', 'division' => ' r ', 'score' => 600, 'competitionName' => 'Spring Open'],
            ['firstName' => 'Ada', 'lastName' => 'Archer', 'club' => 'South Club', 'division' => 'R', 'score' => 600, 'competitionName' => 'Autumn Open'],
        ]);

        $this->assertSame(600, $archers[0]['personalBest']);
        $this->assertSame('Spring Open', $archers[0]['personalBestCompetitionName']);
        $this->assertTrue($archers[0]['hasNewPersonalBest']);
        $this->assertSame(600, $archers[1]['personalBest']);
        $this->assertSame('Autumn Open', $archers[1]['personalBestCompetitionName']);
        $this->assertFalse($archers[1]['hasNewPersonalBest']);
        $this->assertNull($archers[2]['personalBest']);
        $this->assertSame('', $archers[2]['personalBestCompetitionName']);
        $this->assertFalse($archers[2]['hasNewPersonalBest']);
    }

    public function testPersonalBestIgnoresHistoryFromADifferentDivision(): void
    {
        $archers = laneAssistAttachPersonalBests([
            ['firstName' => 'Ada', 'lastName' => 'Archer', 'club' => 'North Club', 'division' => 'C', 'totalPoints' => 500],
        ], [
            ['firstName' => 'Ada', 'lastName' => 'Archer', 'club' => 'North Club', 'division' => 'R', 'score' => 650, 'competitionName' => 'Recurve Open'],
        ]);

        $this->assertNull($archers[0]['personalBest']);
        $this->assertSame('', $archers[0]['personalBestCompetitionName']);
        $this->assertFalse($archers[0]['hasNewPersonalBest']);
    }

    public function testFinalSetPointsAreLimitedAndPaddedToFiveSets(): void
    {
        $decode = static function(string $arrow): int {
            return $arrow === 'X' ? 10 : intval($arrow);
        };

        $this->assertSame([24, 21, 30, null, null], laneAssistFinalSetPoints('987876XXX', 3, $decode));
        $this->assertSame([30, 27, 24, 21, 18], laneAssistFinalSetPoints('XXX999888777666555', 3, $decode));
        $this->assertSame([null, null, null, null, null], laneAssistFinalSetPoints('', 3, $decode));
    }

    public function testFinalSetPointsFollowTheEventsEndCount(): void
    {
        $decode = static function(string $arrow): int {
            return $arrow === 'X' ? 10 : intval($arrow);
        };

        // Team matches are 4 ends of 6 arrows; a fixed 5 left a dead fifth column.
        $this->assertSame([60, 54, null, null], laneAssistFinalSetPoints('XXXXXX999999', 6, $decode, 4));
    }

    public function testQualificationLagFlagsAnArcherMoreThanOneEndBehind(): void
    {
        $result = laneAssistMarkQualificationLag([
            ['id' => 1, 'completedEnds' => 4],
            ['id' => 2, 'completedEnds' => 4],
            ['id' => 3, 'completedEnds' => 2],
        ]);

        $this->assertSame(4, $result['expectedEnds']);
        $this->assertFalse($result['archers'][0]['isBehind']);
        $this->assertTrue($result['archers'][2]['isBehind']);
    }

    public function testQualificationLagLeavesAnEndOpenUntilEveryArcherHasEnteredIt(): void
    {
        // The one archer not yet entered for end 4 is not late: end 4 is
        // still being entered, nobody has started end 5.
        $result = laneAssistMarkQualificationLag([
            ['id' => 1, 'completedEnds' => 4],
            ['id' => 2, 'completedEnds' => 4],
            ['id' => 3, 'completedEnds' => 3],
        ]);

        $this->assertSame(4, $result['expectedEnds']);
        $this->assertFalse($result['archers'][2]['isBehind']);
        $this->assertFalse($result['archers'][2]['isAhead']);
    }

    public function testQualificationLagNeverColoursArchersWhileAnEndIsBeingEntered(): void
    {
        // Entering end 5 one archer at a time: neither the first ones in
        // (formerly yellow) nor, after the majority flips, the rest
        // (formerly red) are flagged.
        foreach ([1, 2, 3, 4] as $entered) {
            $archers = [];
            for ($i = 0; $i < 5; $i++) {
                $archers[] = ['id' => $i, 'completedEnds' => $i < $entered ? 5 : 4, 'arrowsShot' => $i < $entered ? 15 : 12];
            }
            $result = laneAssistMarkQualificationLag($archers);

            $this->assertSame(5, $result['expectedEnds'], "$entered entered");
            foreach ($result['archers'] as $archer) {
                $this->assertFalse($archer['isBehind'], "$entered entered, archer {$archer['id']}");
                $this->assertFalse($archer['isAhead'], "$entered entered, archer {$archer['id']}");
            }
        }
    }

    public function testQualificationLagFlagsTheUnenteredArcherOnceTheNextEndStarts(): void
    {
        $result = laneAssistMarkQualificationLag([
            ['id' => 1, 'completedEnds' => 5],
            ['id' => 2, 'completedEnds' => 4],
            ['id' => 3, 'completedEnds' => 3],
        ]);

        $this->assertSame(5, $result['expectedEnds']);
        $this->assertFalse($result['archers'][1]['isBehind']);
        $this->assertTrue($result['archers'][2]['isBehind']);
    }

    public function testQualificationLagKeepsArchersWithoutArrowsRedAndOutOfThePace(): void
    {
        // Two no-shows must not drag the current end back to 0 or keep
        // end 1 open; they stay red throughout.
        $result = laneAssistMarkQualificationLag([
            ['id' => 1, 'completedEnds' => 1, 'arrowsShot' => 3],
            ['id' => 2, 'completedEnds' => 0, 'arrowsShot' => 0],
            ['id' => 3, 'completedEnds' => 0, 'arrowsShot' => 0],
        ]);

        $this->assertSame(1, $result['expectedEnds']);
        $this->assertFalse($result['archers'][0]['isBehind']);
        $this->assertTrue($result['archers'][1]['isBehind']);
        $this->assertTrue($result['archers'][2]['isBehind']);
    }

    public function testQualificationLagFlagsNobodyBeforeTheSessionStarts(): void
    {
        $result = laneAssistMarkQualificationLag([
            ['id' => 1, 'completedEnds' => 0, 'arrowsShot' => 0],
            ['id' => 2, 'completedEnds' => 0, 'arrowsShot' => 0],
        ]);

        $this->assertFalse($result['archers'][0]['isBehind']);
        $this->assertFalse($result['archers'][1]['isBehind']);
    }

    public function testQualificationLagStillFlagsAnArcherWellAheadOfTheField(): void
    {
        $result = laneAssistMarkQualificationLag([
            ['id' => 1, 'completedEnds' => 4],
            ['id' => 2, 'completedEnds' => 4],
            ['id' => 3, 'completedEnds' => 6],
        ]);

        $this->assertSame(4, $result['expectedEnds']);
        $this->assertTrue($result['archers'][2]['isAhead']);
        $this->assertFalse($result['archers'][0]['isBehind']);
    }

    public function testQualificationLagComparesCumulativeEndsAcrossADistanceChange(): void
    {
        // Archer 1 has entered the first end of distance 2 (end 1 there);
        // the others are still entering distance 1's last end. Comparing
        // per-distance counts would call archer 1 nine ends behind.
        $result = laneAssistMarkQualificationLag([
            ['id' => 1, 'distance' => 2, 'completedEnds' => 1, 'completedTotalEnds' => 11],
            ['id' => 2, 'distance' => 1, 'completedEnds' => 10, 'completedTotalEnds' => 10],
            ['id' => 3, 'distance' => 1, 'completedEnds' => 10, 'completedTotalEnds' => 10],
        ]);

        $this->assertSame(2, $result['expectedDistance']);
        $this->assertSame(1, $result['expectedEnds']);
        foreach ($result['archers'] as $archer) {
            $this->assertFalse($archer['isBehind']);
            $this->assertFalse($archer['isAhead']);
        }
    }

    public function testQualificationLagIgnoresRetiredArchersAndNeverFlagsThemAsBehind(): void
    {
        $result = laneAssistMarkQualificationLag([
            ['id' => 1, 'completedEnds' => 4],
            ['id' => 2, 'completedEnds' => 4],
            ['id' => 3, 'completedEnds' => 0, 'retired' => true],
        ]);

        $this->assertSame(4, $result['expectedEnds']);
        $this->assertFalse($result['archers'][2]['isBehind']);
        $this->assertFalse($result['archers'][2]['isAhead']);
    }

    public function testQualificationLagReportsTheMostCommonDistanceSoAnEndNumberIsUnambiguous(): void
    {
        // Everyone has moved on to distance 2, end 10 there — without the
        // distance, "end 10" reads as the overall end count (would be 20).
        $result = laneAssistMarkQualificationLag([
            ['id' => 1, 'completedEnds' => 10, 'distance' => 2],
            ['id' => 2, 'completedEnds' => 10, 'distance' => 2],
            ['id' => 3, 'completedEnds' => 1, 'distance' => 2],
        ]);

        $this->assertSame(2, $result['expectedDistance']);
    }

    public function testQualificationLagDefaultsMissingOrZeroDistanceToOne(): void
    {
        $result = laneAssistMarkQualificationLag([
            ['id' => 1, 'completedEnds' => 4],
            ['id' => 2, 'completedEnds' => 4, 'distance' => 0],
        ]);

        $this->assertSame(1, $result['expectedDistance']);
    }

    public function testQualificationProgressGetsDistanceContextFromTheFirstMat(): void
    {
        // Mirrors the mat cards' "distance N · end M" so the page header and
        // the cards agree on how the cumulative end count breaks down.
        $result = laneAssistAttachDistanceProgress(['end' => 20, 'totalEnds' => 24], [
            ['expectedDistance' => 2, 'expectedEnds' => 10, 'hasMultipleDistances' => true],
        ]);

        $this->assertSame(20, $result['end']);
        $this->assertTrue($result['hasMultipleDistances']);
        $this->assertSame(2, $result['expectedDistance']);
        $this->assertSame(10, $result['currentDistanceEnd']);
    }

    public function testQualificationProgressDistanceContextIsUntouchedWithoutMats(): void
    {
        $result = laneAssistAttachDistanceProgress(['end' => 0], []);

        $this->assertArrayNotHasKey('hasMultipleDistances', $result);
    }

    public function testQualificationProgressReportsCurrentEndAndArrowTotal(): void
    {
        $result = laneAssistQualificationProgress([['archers' => [
            ['arrowsShot' => 18, 'totalArrows' => 72, 'completedTotalEnds' => 6, 'totalEnds' => 24],
            ['arrowsShot' => 18, 'totalArrows' => 72, 'completedTotalEnds' => 6, 'totalEnds' => 24],
            ['arrowsShot' => 15, 'totalArrows' => 72, 'completedTotalEnds' => 5, 'totalEnds' => 24],
        ]] ]);

        // End 6 is still open (one archer not entered yet), so it is the current end.
        $this->assertSame(['end' => 6, 'arrowsShot' => 18, 'totalArrows' => 72, 'complete' => false], $result);
    }

    public function testQualificationProgressMovesToTheNextEndOnceEveryoneHasEnteredTheCurrentOne(): void
    {
        $result = laneAssistQualificationProgress([['archers' => [
            ['arrowsShot' => 18, 'totalArrows' => 72, 'completedTotalEnds' => 6, 'totalEnds' => 24],
            ['arrowsShot' => 18, 'totalArrows' => 72, 'completedTotalEnds' => 6, 'totalEnds' => 24],
            ['arrowsShot' => 12, 'totalArrows' => 72, 'completedTotalEnds' => 4, 'totalEnds' => 24],
            ['arrowsShot' => 0, 'totalArrows' => 72, 'completedTotalEnds' => 0, 'totalEnds' => 24],
        ]] ]);

        // The archer two ends behind and the no-show don't hold end 6 open.
        $this->assertSame(7, $result['end']);
    }

    public function testQualificationProgressIsCompleteOnlyWhenEveryArcherFinishes(): void
    {
        $complete = laneAssistQualificationProgress([['archers' => [
            ['arrowsShot' => 72, 'totalArrows' => 72, 'completedTotalEnds' => 24, 'totalEnds' => 24],
            ['arrowsShot' => 72, 'totalArrows' => 72, 'completedTotalEnds' => 24, 'totalEnds' => 24],
        ]] ]);
        $empty = laneAssistQualificationProgress([]);

        $this->assertTrue($complete['complete']);
        $this->assertFalse($empty['complete']);
    }

    public function testQualificationProgressIgnoresRetiredArchersSoASingleNoShowCannotBlockCompletion(): void
    {
        $result = laneAssistQualificationProgress([['archers' => [
            ['arrowsShot' => 72, 'totalArrows' => 72, 'completedTotalEnds' => 24, 'totalEnds' => 24],
            ['arrowsShot' => 72, 'totalArrows' => 72, 'completedTotalEnds' => 24, 'totalEnds' => 24],
            ['arrowsShot' => 0, 'totalArrows' => 72, 'completedTotalEnds' => 0, 'totalEnds' => 24, 'retired' => true],
        ]] ]);

        $this->assertTrue($result['complete']);
    }

    public function testFinalsProgressReportsCurrentEnd(): void
    {
        $result = laneAssistFinalsProgress([
            ['status' => 'live', 'totalEnds' => 5, 'sides' => [['participantId' => 1, 'completedEnds' => 2], ['participantId' => 2, 'completedEnds' => 2]]],
            ['status' => 'live', 'totalEnds' => 5, 'sides' => [['participantId' => 3, 'completedEnds' => 2], ['participantId' => 4, 'completedEnds' => 1]]],
        ]);

        $this->assertSame(['end' => 3, 'totalEnds' => 5, 'complete' => false], $result);
    }

    public function testFinalsProgressReportsCompletedBlock(): void
    {
        $result = laneAssistFinalsProgress([
            ['status' => 'complete', 'totalEnds' => 5, 'sides' => []],
            ['status' => 'complete', 'totalEnds' => 5, 'sides' => []],
        ]);

        $this->assertSame(['end' => 5, 'totalEnds' => 5, 'complete' => true], $result);
    }

    /** @dataProvider finalStatusProvider */
    public function testFinalStatus(string $expected, array $sides): void
    {
        $this->assertSame($expected, laneAssistFinalMatchStatus($sides, 3));
    }

    public static function finalStatusProvider(): array
    {
        return [
            'bye' => ['bye', [['participantId' => 1], ['participantId' => 0]]],
            'marked bye' => ['complete', [['participantId' => 1, 'tie' => 2, 'winLose' => 1], ['participantId' => 0]]],
            'empty pair' => ['complete', [['participantId' => 0], ['participantId' => 0]]],
            'no arrows' => ['unreported', [['participantId' => 1], ['participantId' => 2]]],
            'partial end' => ['partial', [['participantId' => 1, 'arrowString' => '98'], ['participantId' => 2, 'arrowString' => '987']]],
            'uneven ends' => ['uneven', [['participantId' => 1, 'arrowString' => '987'], ['participantId' => 2, 'arrowString' => '987654']]],
            'live' => ['live', [['participantId' => 1, 'arrowString' => '987'], ['participantId' => 2, 'arrowString' => '876']]],
            'complete' => ['complete', [['participantId' => 1, 'winLose' => 1], ['participantId' => 2]]],
        ];
    }

    public function testDetectsWinnerInNextBracketSlot(): void
    {
        $match = [
            'phase' => 8,
            'matchNo' => 20,
            'sides' => [
                ['participantId' => 44, 'winLose' => 1],
                ['participantId' => 45, 'winLose' => 0],
            ],
        ];

        $this->assertSame(10, laneAssistFinalWinnerDestination(8, 20));
        $this->assertTrue(laneAssistFinalMatchAdvanced($match, [10 => 44]));
        $this->assertFalse(laneAssistFinalMatchAdvanced($match, [10 => 45]));
    }

    public function testFinalHasNoWinnerDestination(): void
    {
        $this->assertNull(laneAssistFinalWinnerDestination(1, 2));
    }

    public function testMedalByeCanBeMarkedWithoutWinnerDestination(): void
    {
        $match = ['phase' => 1, 'matchNo' => 2, 'status' => 'bye', 'advanced' => false];

        $this->assertNull(laneAssistFinalWinnerDestination($match['phase'], $match['matchNo']));
        $this->assertTrue(laneAssistFinalMatchCanMarkBye($match));
        $this->assertFalse(laneAssistFinalMatchCanMarkBye(['status' => 'complete', 'advanced' => false]));
        $this->assertFalse(laneAssistFinalMatchCanMarkBye(['status' => 'bye', 'advanced' => true]));
    }

    public function testMedalByeRunsCorePhaseUpdateSoFinalRanksAreCalculated(): void
    {
        // Bug: a 3-archer bracket's lone bronze archer was marked bye but never
        // reached the medal list, because core's move2NextPhase() (which runs
        // the FinalInd rank calculation) was skipped when there is no next match.
        $plan = laneAssistLiveMatchActionPlan(['phase' => 1, 'matchNo' => 2, 'status' => 'bye', 'advanced' => false, 'canMarkBye' => true, 'canAdvance' => false]);
        $this->assertSame(['markBye' => true, 'runPhaseUpdate' => true, 'expectAdvance' => false], $plan);

        $gold = laneAssistLiveMatchActionPlan(['phase' => 0, 'matchNo' => 0, 'status' => 'bye', 'advanced' => false, 'canMarkBye' => true, 'canAdvance' => false]);
        $this->assertSame(['markBye' => true, 'runPhaseUpdate' => true, 'expectAdvance' => false], $gold);
    }

    public function testLiveMatchActionPlanForAdvancingMatches(): void
    {
        $this->assertSame(
            ['markBye' => true, 'runPhaseUpdate' => true, 'expectAdvance' => true],
            laneAssistLiveMatchActionPlan(['phase' => 2, 'matchNo' => 4, 'status' => 'bye', 'canMarkBye' => true, 'canAdvance' => true])
        );
        $this->assertSame(
            ['markBye' => false, 'runPhaseUpdate' => true, 'expectAdvance' => true],
            laneAssistLiveMatchActionPlan(['phase' => 2, 'matchNo' => 4, 'status' => 'complete', 'canMarkBye' => false, 'canAdvance' => true])
        );
        $this->assertNull(laneAssistLiveMatchActionPlan(['phase' => 1, 'matchNo' => 2, 'status' => 'complete', 'canMarkBye' => false, 'canAdvance' => false]));
        $this->assertNull(laneAssistLiveMatchActionPlan(['phase' => 2, 'matchNo' => 4, 'status' => 'live', 'canMarkBye' => false, 'canAdvance' => false]));
    }

    public function testSemifinalWinnersUseIanseoFinalDestinations(): void
    {
        $this->assertSame(0, laneAssistFinalWinnerDestination(2, 4));
        $this->assertSame(1, laneAssistFinalWinnerDestination(2, 6));
        $match = [
            'phase' => 2,
            'matchNo' => 4,
            'sides' => [
                ['participantId' => 44, 'winLose' => 0],
                ['participantId' => 45, 'winLose' => 1],
            ],
        ];
        $this->assertTrue(laneAssistFinalMatchAdvanced($match, [0 => 45, 2 => 44]));
    }

    public function testByeWinnerUsesThePresentArchersExactRow(): void
    {
        $match = [
            'status' => 'bye',
            'sides' => [
                ['matchNo' => 16, 'participantId' => 97],
                ['matchNo' => 17, 'participantId' => 0],
            ],
        ];

        $this->assertSame(16, laneAssistByeWinnerMatchNo($match));
        $this->assertNull(laneAssistByeWinnerMatchNo(['status' => 'complete', 'sides' => $match['sides']]));
    }

    public function testCurrentBlockIncludesAllPendingUnscheduledByesAndExcludesLaterMatches(): void
    {
        $matches = [
            ['event' => '60R', 'teamEvent' => 0, 'phase' => 8, 'matchNo' => 16, 'scheduledSlot' => '', 'status' => 'bye', 'advanced' => false],
            ['event' => '60R', 'teamEvent' => 0, 'phase' => 8, 'matchNo' => 18, 'scheduledSlot' => '2026-08-30 16:00:00', 'status' => 'unreported', 'advanced' => false],
            ['event' => '60R', 'teamEvent' => 0, 'phase' => 4, 'matchNo' => 8, 'scheduledSlot' => '2026-08-30 16:30:00', 'status' => 'bye', 'advanced' => false],
            ['event' => '40B', 'teamEvent' => 0, 'phase' => 8, 'matchNo' => 16, 'scheduledSlot' => '', 'status' => 'bye', 'advanced' => false],
            ['event' => '50C', 'teamEvent' => 0, 'phase' => 8, 'matchNo' => 24, 'scheduledSlot' => '', 'status' => 'bye', 'advanced' => false],
        ];

        $result = laneAssistSelectCurrentFinalMatches($matches);

        $this->assertSame('2026-08-30 16:00:00', $result['slot']);
        $this->assertSame([16, 18, 16, 24], array_column($result['matches'], 'matchNo'));
        $this->assertSame(['60R', '60R', '40B', '50C'], array_column($result['matches'], 'event'));
    }

    public function testSelectionMovesToNextBlockAfterCurrentBlockIsAdvanced(): void
    {
        $matches = [
            ['event' => '60R', 'teamEvent' => 0, 'phase' => 8, 'scheduledSlot' => '', 'status' => 'advanced', 'advanced' => true],
            ['event' => '60R', 'teamEvent' => 0, 'phase' => 8, 'scheduledSlot' => '2026-08-30 16:00:00', 'status' => 'advanced', 'advanced' => true],
            ['event' => '40B', 'teamEvent' => 0, 'phase' => 8, 'scheduledSlot' => '', 'status' => 'bye', 'advanced' => false],
            ['event' => '60R', 'teamEvent' => 0, 'phase' => 4, 'scheduledSlot' => '2026-08-30 16:30:00', 'status' => 'unreported', 'advanced' => false],
        ];

        $result = laneAssistSelectCurrentFinalMatches($matches);

        $this->assertSame('2026-08-30 16:30:00', $result['slot']);
        $this->assertSame(['40B', '60R'], array_column($result['matches'], 'event'));
    }

    // arhE26V: a small event's gold final (phase 0) shared the first slot with
    // other events' 1/8 finals. A medal match has no next match, so it never
    // becomes "advanced"; once decided it must still count as done, or it pins
    // the current round to that first slot forever.
    public function testFinishedMedalMatchDoesNotPinTheCurrentRound(): void
    {
        $matches = [
            ['event' => 'RJ', 'teamEvent' => 0, 'phase' => 0, 'matchNo' => 0, 'scheduledSlot' => '2026-11-22 14:45:00', 'status' => 'complete', 'advanced' => false],
            ['event' => 'BS', 'teamEvent' => 0, 'phase' => 8, 'matchNo' => 18, 'scheduledSlot' => '2026-11-22 14:45:00', 'status' => 'advanced', 'advanced' => true],
            ['event' => 'BS', 'teamEvent' => 0, 'phase' => 4, 'matchNo' => 8, 'scheduledSlot' => '2026-11-22 15:15:00', 'status' => 'live', 'advanced' => false],
        ];

        $result = laneAssistSelectCurrentFinalMatches($matches);

        $this->assertSame('2026-11-22 15:15:00', $result['slot']);
    }

    public function testUndecidedMedalMatchStillHoldsTheCurrentRound(): void
    {
        $matches = [
            ['event' => 'RJ', 'teamEvent' => 0, 'phase' => 0, 'matchNo' => 0, 'scheduledSlot' => '2026-11-22 14:45:00', 'status' => 'live', 'advanced' => false],
            ['event' => 'BS', 'teamEvent' => 0, 'phase' => 4, 'matchNo' => 8, 'scheduledSlot' => '2026-11-22 15:15:00', 'status' => 'unreported', 'advanced' => false],
        ];

        $this->assertSame('2026-11-22 14:45:00', laneAssistSelectCurrentFinalMatches($matches)['slot']);
    }

    public function testAdvancedByeIsHiddenWhilePendingByeRemains(): void
    {
        $matches = [
            ['event' => '60R', 'teamEvent' => 0, 'phase' => 8, 'matchNo' => 16, 'scheduledSlot' => '', 'status' => 'advanced', 'advanced' => true],
            ['event' => '60R', 'teamEvent' => 0, 'phase' => 8, 'matchNo' => 20, 'scheduledSlot' => '', 'status' => 'bye', 'advanced' => false],
            ['event' => '60R', 'teamEvent' => 0, 'phase' => 8, 'matchNo' => 18, 'scheduledSlot' => '2026-08-30 16:00:00', 'status' => 'advanced', 'advanced' => true],
            ['event' => '60R', 'teamEvent' => 0, 'phase' => 4, 'matchNo' => 8, 'scheduledSlot' => '2026-08-30 16:30:00', 'status' => 'unreported', 'advanced' => false],
        ];

        $result = laneAssistSelectCurrentFinalMatches($matches);

        $this->assertSame('2026-08-30 16:00:00', $result['slot']);
        $this->assertSame([20, 18], array_column($result['matches'], 'matchNo'));
    }

    public function testGroupFinalRoundsOrdersChronologicallyAndFoldsUnscheduledByesIntoCurrentOnly(): void
    {
        $matches = [
            ['event' => '60R', 'teamEvent' => 0, 'phase' => 8, 'matchNo' => 16, 'scheduledSlot' => '', 'status' => 'bye', 'advanced' => false],
            ['event' => '60R', 'teamEvent' => 0, 'phase' => 8, 'matchNo' => 18, 'scheduledSlot' => '2026-08-30 16:00:00', 'status' => 'unreported', 'advanced' => false],
            ['event' => '60R', 'teamEvent' => 0, 'phase' => 4, 'matchNo' => 8, 'scheduledSlot' => '2026-08-30 16:30:00', 'status' => 'bye', 'advanced' => false],
            ['event' => '40B', 'teamEvent' => 0, 'phase' => 8, 'matchNo' => 16, 'scheduledSlot' => '', 'status' => 'bye', 'advanced' => false],
            ['event' => '50C', 'teamEvent' => 0, 'phase' => 8, 'matchNo' => 24, 'scheduledSlot' => '', 'status' => 'bye', 'advanced' => false],
        ];

        $result = laneAssistGroupFinalRounds($matches);

        $this->assertCount(2, $result);
        $this->assertSame('2026-08-30 16:00:00', $result[0]['slot']);
        $this->assertTrue($result[0]['isCurrent']);
        $this->assertSame([16, 18, 16, 24], array_column($result[0]['matches'], 'matchNo'));
        $this->assertSame('2026-08-30 16:30:00', $result[1]['slot']);
        $this->assertFalse($result[1]['isCurrent']);
        $this->assertSame([8], array_column($result[1]['matches'], 'matchNo'));
    }

    public function testGroupFinalRoundsKeepsGenuinelyUnscheduledMatchesInTheirOwnRoundWhenNotPartOfCurrent(): void
    {
        // The 40B match is live (not a bye), so it's never folded into the
        // current block - it must still surface somewhere, as its own
        // "Unscheduled" round, rather than vanishing or duplicating.
        $matches = [
            ['event' => '60R', 'teamEvent' => 0, 'phase' => 8, 'matchNo' => 8, 'scheduledSlot' => '2026-08-30 16:00:00', 'status' => 'bye', 'advanced' => false],
            ['event' => '40B', 'teamEvent' => 0, 'phase' => 4, 'matchNo' => 4, 'scheduledSlot' => '', 'status' => 'live', 'advanced' => false],
        ];

        $result = laneAssistGroupFinalRounds($matches);

        $this->assertCount(2, $result);
        $this->assertSame('2026-08-30 16:00:00', $result[0]['slot']);
        $this->assertTrue($result[0]['isCurrent']);
        $this->assertSame([8], array_column($result[0]['matches'], 'matchNo'));
        $this->assertSame('', $result[1]['slot']);
        $this->assertFalse($result[1]['isCurrent']);
        $this->assertSame([4], array_column($result[1]['matches'], 'matchNo'));
    }

    public function testGroupFinalRoundsFlagsTheOnlyUnscheduledRoundCurrentWhenNoSlotHasEverBeenScheduled(): void
    {
        $matches = [
            ['event' => '60R', 'teamEvent' => 0, 'phase' => 8, 'matchNo' => 16, 'scheduledSlot' => '', 'status' => 'bye', 'advanced' => false],
        ];

        $result = laneAssistGroupFinalRounds($matches);

        $this->assertCount(1, $result);
        $this->assertSame('', $result[0]['slot']);
        $this->assertTrue($result[0]['isCurrent']);
        $this->assertSame([16], array_column($result[0]['matches'], 'matchNo'));
    }
}