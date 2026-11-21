<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Common/finals-print-logic.php';

/**
 * Finals lane print: which sides are byes, when a round is ready to print, and
 * how placed sides group into lanes. Grid numbering: gold 0/1, bronze 2/3,
 * semis 4-7, quarters 8-15, ... (see laneAssistFinalWinnerDestination()).
 */
final class FinalsPrintLogicTest extends TestCase
{
    private static function side(int $matchNo, string $target, string $letter = '', string $event = 'RM', int $team = 0): array
    {
        return [
            'teamEvent' => $team, 'event' => $event, 'eventName' => 'Recurve Men', 'phase' => 8,
            'matchNo' => $matchNo, 'target' => $target, 'letter' => $letter !== '' ? $letter : $target,
            'faceUrl' => '/ianseo/Common/Images/Targets/5.svg', 'faceLabel' => 'Outdoor 122 cm', 'distance' => '70',
        ];
    }

    private static function lanesOf(array $result): array
    {
        return array_map(function ($lane) { return $lane['lane']; }, $result['lanes']);
    }

    public function testFeedingPairs(): void
    {
        $this->assertSame(['pair' => [4, 5], 'loser' => false], fpFeedingPair(0));
        $this->assertSame(['pair' => [6, 7], 'loser' => false], fpFeedingPair(1));
        $this->assertSame(['pair' => [4, 5], 'loser' => true], fpFeedingPair(2));
        $this->assertSame(['pair' => [6, 7], 'loser' => true], fpFeedingPair(3));
        $this->assertSame(['pair' => [18, 19], 'loser' => false], fpFeedingPair(9));
    }

    public function testEmptySubtreeIsBye(): void
    {
        $sides = [16 => true, 17 => false, 34 => false, 35 => false];
        $this->assertTrue(fpSideIsBye(17, $sides));

        $sides[34] = true;
        $this->assertFalse(fpSideIsBye(17, $sides));
    }

    public function testPlacedSideIsNeverABye(): void
    {
        $this->assertFalse(fpSideIsBye(16, [16 => true, 17 => false]));
    }

    public function testNoFeedingRowsIsBye(): void
    {
        $this->assertTrue(fpSideIsBye(9, [8 => true, 9 => false]));
    }

    public function testUndecidedFeederSubtreeBlocksReadiness(): void
    {
        $sides = [4 => false, 5 => true, 8 => false, 9 => false, 16 => true, 17 => true];
        $this->assertSame(
            ['ready' => false, 'waiting' => 1],
            fpRoundReadiness([['teamEvent' => 0, 'event' => 'CM', 'matchNo' => 4]], ['0|CM' => $sides])
        );
    }

    public function testFullyPlacedRoundIsReady(): void
    {
        $sides = [8 => true, 9 => true, 10 => true, 11 => true];
        $this->assertSame(
            ['ready' => true, 'waiting' => 0],
            fpRoundReadiness([
                ['teamEvent' => 0, 'event' => 'RM', 'matchNo' => 8],
                ['teamEvent' => 0, 'event' => 'RM', 'matchNo' => 10],
            ], ['0|RM' => $sides])
        );
    }

    public function testBronzeIsByeWhenSemiHadABye(): void
    {
        $sides = [0 => true, 1 => true, 2 => false, 3 => true, 4 => true, 5 => false, 6 => true, 7 => true];
        $this->assertTrue(fpSideIsBye(2, $sides));
        $this->assertFalse(fpSideIsBye(3, $sides));
        $this->assertTrue(fpRoundReadiness([
            ['teamEvent' => 0, 'event' => 'RM', 'matchNo' => 0],
            ['teamEvent' => 0, 'event' => 'RM', 'matchNo' => 2],
        ], ['0|RM' => $sides])['ready']);
    }

    public function testByeMatchContributesNoTargets(): void
    {
        $result = fpBuildLanes(
            [self::side(16, '003'), self::side(17, '004')],
            ['0|RM' => [16 => true, 17 => false]]
        );
        $this->assertSame([], $result['lanes']);
        $this->assertSame([], $result['unassigned']);
    }

    public function testLanesSortedAndPerSideLanes(): void
    {
        $result = fpBuildLanes(
            [self::side(8, '012'), self::side(9, '011'), self::side(10, '002'), self::side(11, '002')],
            ['0|RM' => [8 => true, 9 => true, 10 => true, 11 => true]]
        );
        $this->assertSame([2, 11, 12], self::lanesOf($result));
        $this->assertSame([2, 1, 1], array_map(function ($lane) { return count($lane['targets']); }, $result['lanes']));
        $this->assertSame(12, $result['lanes'][2]['lane']);
        $this->assertSame(8, $result['lanes'][2]['targets'][0]['matchNo']);
    }

    public function testLettersAssignedWhenScheduleHasNone(): void
    {
        $result = fpBuildLanes(
            [self::side(10, '005'), self::side(8, '005'), self::side(9, '005'), self::side(11, '005')],
            ['0|RM' => [8 => true, 9 => true, 10 => true, 11 => true]]
        );
        $targets = $result['lanes'][0]['targets'];
        $this->assertSame([8, 9, 10, 11], array_column($targets, 'matchNo'));
        $this->assertSame(['A', 'B', 'C', 'D'], array_column($targets, 'letterLabel'));
    }

    public function testStoredLettersAreKept(): void
    {
        $result = fpBuildLanes(
            [self::side(8, '005', '005B'), self::side(9, '005', '005A')],
            ['0|RM' => [8 => true, 9 => true]]
        );
        $targets = $result['lanes'][0]['targets'];
        $this->assertSame([8, 9], array_column($targets, 'matchNo'));
        $this->assertSame(['B', 'A'], array_column($targets, 'letterLabel'));
    }

    public function testUnassignedSideListed(): void
    {
        $result = fpBuildLanes(
            [self::side(8, ''), self::side(9, '007')],
            ['0|RM' => [8 => true, 9 => true]]
        );
        $this->assertSame([7], self::lanesOf($result));
        $this->assertCount(1, $result['unassigned']);
        $this->assertSame(8, $result['unassigned'][0]['matchNo']);
    }

    public function testEventsAreKeptApartOnTheSameLane(): void
    {
        $result = fpBuildLanes(
            [self::side(9, '004', '', 'RW'), self::side(8, '004', '', 'CM')],
            ['0|RW' => [8 => true, 9 => true], '0|CM' => [8 => true, 9 => true]]
        );
        $targets = $result['lanes'][0]['targets'];
        $this->assertSame(['CM', 'RW'], array_column($targets, 'event'));
    }

    public function testEmptyRoundIsNotReady(): void
    {
        $this->assertFalse(fpRoundReadiness([], [])['ready']);
    }

    public function testEventNotYetMovedToFinalsBlocksReadiness(): void
    {
        // Core creates an event's whole empty bracket as soon as it has a first
        // phase; until it is moved to finals every first-phase side looks like a bye.
        $rm = [8 => true, 9 => true];
        $cm = [];
        for ($m = 16; $m <= 31; $m++) {
            $cm[$m] = false;
        }
        $this->assertSame(['ready' => false, 'waiting' => 2], fpRoundReadiness([
            ['teamEvent' => 0, 'event' => 'RM', 'matchNo' => 8],
            ['teamEvent' => 0, 'event' => 'CM', 'matchNo' => 16],
            ['teamEvent' => 0, 'event' => 'CM', 'matchNo' => 18],
        ], ['0|RM' => $rm, '0|CM' => $cm]));
    }

    public function testRealBothEmptyMatchStillReadyOncePopulated(): void
    {
        // A 1/8 with 5 entrants: 6 v 11 has nobody, which is a real bye pair.
        $sides = [16 => true, 17 => false, 18 => false, 19 => false, 20 => true, 21 => true];
        $this->assertTrue(fpRoundReadiness([
            ['teamEvent' => 0, 'event' => 'RM', 'matchNo' => 18],
            ['teamEvent' => 0, 'event' => 'RM', 'matchNo' => 20],
        ], ['0|RM' => $sides])['ready']);
    }

    public function testPhantomEventWithNobodyEnteredDoesNotBlock(): void
    {
        // Projected finalists 0 = nobody is entered: the bracket is phantom and
        // never fills, so it must not hold the round (or any other) hostage.
        $matches = [
            ['teamEvent' => 0, 'event' => 'RM', 'matchNo' => 8],
            ['teamEvent' => 0, 'event' => '12R', 'matchNo' => 16],
        ];
        $sides = ['0|RM' => [8 => true, 9 => true], '0|12R' => [16 => false, 17 => false]];
        $this->assertSame(['ready' => true, 'waiting' => 0], fpRoundReadiness($matches, $sides, ['0|12R' => 0]));
        $this->assertSame(['ready' => false, 'waiting' => 1], fpRoundReadiness($matches, $sides, ['0|12R' => 6]));
        $this->assertSame(['ready' => false, 'waiting' => 1], fpRoundReadiness($matches, $sides));
    }

    public function testRoundOfOnlyPhantomEventsIsNotReady(): void
    {
        $this->assertFalse(fpRoundReadiness(
            [['teamEvent' => 0, 'event' => '12R', 'matchNo' => 16]],
            ['0|12R' => [16 => false, 17 => false]],
            ['0|12R' => 0]
        )['ready']);
    }

    // Bug: finals print showed the event's finals face, not the face each archer chose
    // (e.g. a DK archer on Trippel got the event's Full Face).
    public function testSideFaceUsesTheArchersChosenFace(): void
    {
        $this->assertSame(['id' => 98, 'size' => 40],
            fpSideFace(['id' => 2, 'size' => 40], ['id' => 98, 'size' => 40]));
        $this->assertSame(['id' => 4, 'size' => 60],
            fpSideFace(['id' => 2, 'size' => 40], ['id' => 4, 'size' => 60]));
    }

    public function testSideFaceFallsBackToTheEventFace(): void
    {
        $event = ['id' => 2, 'size' => 40];
        // team side, unplaced side, or an archer whose face record has no face for distance 1
        $this->assertSame($event, fpSideFace($event, null));
        $this->assertSame($event, fpSideFace($event, ['id' => 0, 'size' => 0]));
    }

    public function testSideFaceKeepsTheEventSizeWhenTheArchersSizeIsMissing(): void
    {
        $this->assertSame(['id' => 98, 'size' => 40],
            fpSideFace(['id' => 2, 'size' => 40], ['id' => 98, 'size' => 0]));
    }
}
