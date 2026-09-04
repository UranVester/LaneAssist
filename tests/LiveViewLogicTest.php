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

    public function testFinalSetPointsAreLimitedAndPaddedToFiveSets(): void
    {
        $decode = static function(string $arrow): int {
            return $arrow === 'X' ? 10 : intval($arrow);
        };

        $this->assertSame([24, 21, 30, null, null], laneAssistFinalSetPoints('987876XXX', 3, $decode));
        $this->assertSame([30, 27, 24, 21, 18], laneAssistFinalSetPoints('XXX999888777666555', 3, $decode));
        $this->assertSame([null, null, null, null, null], laneAssistFinalSetPoints('', 3, $decode));
    }

    public function testQualificationLagUsesMostCommonEndCount(): void
    {
        $result = laneAssistMarkQualificationLag([
            ['id' => 1, 'completedEnds' => 4],
            ['id' => 2, 'completedEnds' => 4],
            ['id' => 3, 'completedEnds' => 3],
        ]);

        $this->assertSame(4, $result['expectedEnds']);
        $this->assertFalse($result['archers'][0]['isBehind']);
        $this->assertTrue($result['archers'][2]['isBehind']);
    }

    public function testQualificationProgressReportsCurrentEndAndArrowTotal(): void
    {
        $result = laneAssistQualificationProgress([['archers' => [
            ['arrowsShot' => 18, 'totalArrows' => 72, 'completedTotalEnds' => 6, 'totalEnds' => 24],
            ['arrowsShot' => 18, 'totalArrows' => 72, 'completedTotalEnds' => 6, 'totalEnds' => 24],
            ['arrowsShot' => 15, 'totalArrows' => 72, 'completedTotalEnds' => 5, 'totalEnds' => 24],
        ]] ]);

        $this->assertSame(['end' => 7, 'arrowsShot' => 18, 'totalArrows' => 72, 'complete' => false], $result);
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
}