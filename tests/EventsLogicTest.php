<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Common/events-logic.php';

final class EventsLogicTest extends TestCase
{
    // ---------- Bracket sizing ----------

    public function testBracketSizes(): void
    {
        $this->assertSame(16, ceBracketForCount(0, true));
        $this->assertSame(0,  ceBracketForCount(1, false));
        $this->assertSame(2,  ceBracketForCount(2, false));
        $this->assertSame(2,  ceBracketForCount(4, false));
        $this->assertSame(4,  ceBracketForCount(5, false));
        $this->assertSame(8,  ceBracketForCount(16, false));
        $this->assertSame(16, ceBracketForCount(17, false));
        $this->assertSame(32, ceBracketForCount(64, false));
        $this->assertSame(64, ceBracketForCount(65, false));
        $this->assertSame(64, ceBracketForCount(500, false));
    }

    public function testBracketForCountIsZeroWhenCountIsZeroAndTournamentIsNotEmpty(): void
    {
        $this->assertSame(0, ceBracketForCount(0, false));
    }

    // ---------- Age group merging ----------

    public function testDanishGenderPairsMergeByAgeRange(): void
    {
        $g = ceAgeGroups([
            ['id' => 'HJ', 'name' => 'Herre Junior', 'sex' => 0, 'ageFrom' => 18, 'ageTo' => 20, 'order' => 10],
            ['id' => 'DJ', 'name' => 'Dame Junior', 'sex' => 1, 'ageFrom' => 18, 'ageTo' => 20, 'order' => 9],
            ['id' => 'HS', 'name' => 'Herre Senior', 'sex' => 0, 'ageFrom' => 21, 'ageTo' => 49, 'order' => 12],
        ]);
        $this->assertSame($g['HJ']['key'], $g['DJ']['key']);
        $this->assertNotSame($g['HJ']['key'], $g['HS']['key']);
        $this->assertSame('J', $g['HJ']['code']);
        $this->assertSame('Junior', $g['HJ']['name']);
        $this->assertSame('HS', $g['HS']['code']);        // no counterpart: keeps own code/name
        $this->assertSame('Herre Senior', $g['HS']['name']);
    }

    public function testSameSexSameRangeStaySeparate(): void
    {
        $g = ceAgeGroups([
            ['id' => 'OP', 'name' => 'Open', 'sex' => -1, 'ageFrom' => 0, 'ageTo' => 0, 'order' => 1],
            ['id' => 'PA', 'name' => 'Para', 'sex' => -1, 'ageFrom' => 0, 'ageTo' => 0, 'order' => 2],
        ]);
        $this->assertNotSame($g['OP']['key'], $g['PA']['key']);
    }

    public function testWaStyleLabels(): void
    {
        $g = ceAgeGroups([
            ['id' => 'U21M', 'name' => 'Under 21 Men', 'sex' => 0, 'ageFrom' => 18, 'ageTo' => 20, 'order' => 1],
            ['id' => 'U21W', 'name' => 'Under 21 Women', 'sex' => 1, 'ageFrom' => 18, 'ageTo' => 20, 'order' => 2],
        ]);
        $this->assertSame('U21', $g['U21M']['code']);
        $this->assertSame('Under 21', $g['U21M']['name']);
    }

    public function testMergedGroupOrderIsTheMinimumOfItsMembers(): void
    {
        $g = ceAgeGroups([
            ['id' => 'HJ', 'name' => 'Herre Junior', 'sex' => 0, 'ageFrom' => 18, 'ageTo' => 20, 'order' => 10],
            ['id' => 'DJ', 'name' => 'Dame Junior', 'sex' => 1, 'ageFrom' => 18, 'ageTo' => 20, 'order' => 9],
        ]);
        $this->assertSame(9, $g['HJ']['order']);
        $this->assertSame(9, $g['DJ']['order']);
    }

    public function testMergedGroupKeyIsSortedIdsJoinedByPlus(): void
    {
        $g = ceAgeGroups([
            ['id' => 'HJ', 'name' => 'Herre Junior', 'sex' => 0, 'ageFrom' => 18, 'ageTo' => 20, 'order' => 10],
            ['id' => 'DJ', 'name' => 'Dame Junior', 'sex' => 1, 'ageFrom' => 18, 'ageTo' => 20, 'order' => 9],
        ]);
        $this->assertSame('age:DJ+HJ', $g['HJ']['key']);
    }

    public function testThirdClassInSameAgeRangeWithRepeatedSexStartsANewGroup(): void
    {
        // Bucket (18,20) already has one sex=0 class (HJ) with no sex=1
        // counterpart yet; DJ (sex=1) joins it. A second sex=0 class in the
        // same bucket has no open slot in that group (sex=0 already taken),
        // so it must start its own group rather than merging a third member in.
        $g = ceAgeGroups([
            ['id' => 'HJ', 'name' => 'Herre Junior', 'sex' => 0, 'ageFrom' => 18, 'ageTo' => 20, 'order' => 1],
            ['id' => 'DJ', 'name' => 'Dame Junior', 'sex' => 1, 'ageFrom' => 18, 'ageTo' => 20, 'order' => 2],
            ['id' => 'HJ2', 'name' => 'Herre Junior 2', 'sex' => 0, 'ageFrom' => 18, 'ageTo' => 20, 'order' => 3],
        ]);
        $this->assertSame($g['HJ']['key'], $g['DJ']['key']);
        $this->assertNotSame($g['HJ']['key'], $g['HJ2']['key']);
        $this->assertSame('HJ2', $g['HJ2']['code']);
    }

    public function testCommonCodeFallsBackToFirstIdWhenNoCharactersAlign(): void
    {
        // No shared characters at the same position between 'AB' and 'XY';
        // the merged code falls back to the first class's own id.
        $g = ceAgeGroups([
            ['id' => 'AB', 'name' => 'Group One', 'sex' => 0, 'ageFrom' => 0, 'ageTo' => 0, 'order' => 1],
            ['id' => 'XY', 'name' => 'Group Two', 'sex' => 1, 'ageFrom' => 0, 'ageTo' => 0, 'order' => 2],
        ]);
        $this->assertSame('AB', $g['AB']['code']);
    }

    public function testCommonNameFallsBackToFirstDescriptionWhenNoSharedWords(): void
    {
        $g = ceAgeGroups([
            ['id' => 'AB', 'name' => 'Alpha Group', 'sex' => 0, 'ageFrom' => 0, 'ageTo' => 0, 'order' => 1],
            ['id' => 'XY', 'name' => 'Zebra Team', 'sex' => 1, 'ageFrom' => 0, 'ageTo' => 0, 'order' => 2],
        ]);
        $this->assertSame('Alpha Group', $g['AB']['name']);
    }
}
