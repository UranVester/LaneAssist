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

    // ---------- Plan building ----------

    /**
     * Builds Danish indoor pairs: divisions R/C, classes DJ(order 9)/HJ(10)/
     * DS(11)/HS(12)/DM(13)/HM(14), plus HA(order 6, 12m) when $withAspirant.
     * Old events: 18R/18C (distance '18') cover the six "normal" classes for
     * R/C respectively; 12R (distance '12') covers R's HA only. Each old
     * event's count is the sum of the pairs mapped to it. Returns
     * ceBuildPlan(...) with DK labels.
     */
    private function dkPlan(array $counts, array $criteria, bool $withAspirant = false): array
    {
        $classDefs = [
            'DJ' => ['sex' => 1, 'ageFrom' => 18, 'ageTo' => 20, 'order' => 9,  'name' => 'Dame Junior'],
            'HJ' => ['sex' => 0, 'ageFrom' => 18, 'ageTo' => 20, 'order' => 10, 'name' => 'Herre Junior'],
            'DS' => ['sex' => 1, 'ageFrom' => 21, 'ageTo' => 49, 'order' => 11, 'name' => 'Dame Senior'],
            'HS' => ['sex' => 0, 'ageFrom' => 21, 'ageTo' => 49, 'order' => 12, 'name' => 'Herre Senior'],
            'DM' => ['sex' => 1, 'ageFrom' => 50, 'ageTo' => 99, 'order' => 13, 'name' => 'Dame Master'],
            'HM' => ['sex' => 0, 'ageFrom' => 50, 'ageTo' => 99, 'order' => 14, 'name' => 'Herre Master'],
        ];
        if ($withAspirant) {
            $classDefs['HA'] = ['sex' => 0, 'ageFrom' => 14, 'ageTo' => 15, 'order' => 6, 'name' => 'Herre Aspirant'];
        }

        $classes = [];
        foreach ($classDefs as $id => $def) {
            $classes[] = [
                'id' => $id,
                'name' => $def['name'],
                'sex' => $def['sex'],
                'ageFrom' => $def['ageFrom'],
                'ageTo' => $def['ageTo'],
                'order' => $def['order'],
            ];
        }

        $divisions = [
            'R' => ['name' => 'Recurve', 'order' => 1],
            'C' => ['name' => 'Compound', 'order' => 2],
        ];

        $pairs = [];
        $oldEventCounts = ['18R' => 0, '18C' => 0];
        if ($withAspirant) {
            $oldEventCounts['12R'] = 0;
        }

        foreach ($divisions as $divId => $divDef) {
            foreach ($classDefs as $classId => $classDef) {
                $count = $counts[$divId . $classId] ?? 0;

                if ($classId === 'HA') {
                    $oldEventCodes = $divId === 'R' ? ['12R'] : [];
                } else {
                    $oldEventCodes = [$divId === 'R' ? '18R' : '18C'];
                }
                foreach ($oldEventCodes as $code) {
                    $oldEventCounts[$code] += $count;
                }

                $pairs[] = [
                    'div' => $divId,
                    'divName' => $divDef['name'],
                    'divOrder' => $divDef['order'],
                    'class' => $classId,
                    'count' => $count,
                    'oldEvents' => $oldEventCodes,
                    'qualShortest' => '',
                ];
            }
        }

        $oldEvents = [
            '18R' => ['code' => '18R', 'name' => '18m Recurve', 'progr' => 1, 'distance' => '18', 'count' => $oldEventCounts['18R'], 'settings' => []],
            '18C' => ['code' => '18C', 'name' => '18m Compound', 'progr' => 2, 'distance' => '18', 'count' => $oldEventCounts['18C'], 'settings' => []],
        ];
        if ($withAspirant) {
            $oldEvents['12R'] = ['code' => '12R', 'name' => '12m Recurve', 'progr' => 3, 'distance' => '12', 'count' => $oldEventCounts['12R'], 'settings' => []];
        }

        $labels = $this->dkLabels();

        return ceBuildPlan($pairs, $oldEvents, $classes, $criteria, $labels);
    }

    private function dkLabels(): array
    {
        return [
            'genderCode' => [0 => 'H', 1 => 'D', -1 => 'U'],
            'genderName' => [0 => 'Herre', 1 => 'Dame', -1 => 'Åben'],
            'allCode' => 'ALL',
            'allName' => 'Alle',
        ];
    }

    public function testDivisionDisablesDistance(): void
    {
        $this->assertFalse(ceNormalizeCriteria(['division' => 1, 'distance' => 1])['distance']);
    }

    public function testClassOrderUsesOwnOrderNotMergedAgeGroupWhenGenderAndClassBothTicked(): void
    {
        // HJ (order 9) and DJ (order 10) share an age range, so ceAgeGroups()
        // merges them into one age group with a shared order of min(9,10)=9.
        // With Gender+Class both ticked, the grouping *unit* is the class
        // itself (one event per class), so sorting must use each class's own
        // order (9 vs 10, no tie) -- not the merged age-group order, which
        // would tie them at 9 and fall through to the sex tiebreak (Dame
        // before Herre), reversing the expected HJ-before-DJ result.
        $classes = [
            ['id' => 'HJ', 'name' => 'Herre Junior', 'sex' => 0, 'ageFrom' => 18, 'ageTo' => 20, 'order' => 9],
            ['id' => 'DJ', 'name' => 'Dame Junior', 'sex' => 1, 'ageFrom' => 18, 'ageTo' => 20, 'order' => 10],
        ];
        $pairs = [
            ['div' => 'R', 'divName' => 'Recurve', 'divOrder' => 1, 'class' => 'HJ', 'count' => 5, 'oldEvents' => [], 'qualShortest' => ''],
            ['div' => 'R', 'divName' => 'Recurve', 'divOrder' => 1, 'class' => 'DJ', 'count' => 5, 'oldEvents' => [], 'qualShortest' => ''],
        ];

        $plan = ceBuildPlan($pairs, [], $classes, ['gender' => 1, 'class' => 1], $this->dkLabels());

        $this->assertSame(['HJ', 'DJ'], array_column($plan['events'], 'code'));
    }

    public function testGenderClassDivisionSplitsDanish(): void
    {
        $plan = $this->dkPlan(['RHJ' => 5, 'RDJ' => 2, 'RHS' => 12, 'RDM' => 1], ['gender' => 1, 'class' => 1, 'division' => 1, 'distance' => 0]);
        $codes = array_column($plan['events'], 'code');
        $this->assertSame(['RDJ', 'RHJ', 'RHS', 'RDM'], $codes);       // only groups with archers, in order
        $byCode = array_column($plan['events'], null, 'code');
        $this->assertSame('Recurve Herre Junior', $byCode['RHJ']['name']);
        $this->assertSame(4, $byCode['RHJ']['firstPhase']);
        $this->assertSame(2, $byCode['RDJ']['firstPhase']);
        $this->assertSame(0, $byCode['RDM']['firstPhase']);
        $this->assertSame('18R', $byCode['RHS']['settingsFrom']);
    }

    public function testEmptyTournamentCreatesAllGroupsAt16(): void
    {
        $plan = $this->dkPlan([], ['gender' => 1, 'class' => 1, 'division' => 1]);
        $this->assertTrue($plan['tournamentEmpty']);
        $this->assertCount(12, $plan['events']);                      // 2 divs × 6 classes
        $this->assertSame([16], array_values(array_unique(array_column($plan['events'], 'firstPhase'))));
    }

    public function testDivisionOnlyMixesDistancesAndTakesShortest(): void
    {
        // RHS in 18R (18m), aspirant-like class in 12R (12m)
        $plan = $this->dkPlan(['RHS' => 3, 'RHA' => 2], ['division' => 1], true);
        $r = array_column($plan['events'], null, 'code')['R'];
        $this->assertSame('Recurve', $r['name']);
        $this->assertSame('12', $r['distance']);
        $this->assertSame('12R', $r['settingsFrom']);
    }

    public function testClassWithoutGenderMergesHerreDame(): void
    {
        $plan = $this->dkPlan(['RHJ' => 3, 'RDJ' => 3], ['class' => 1, 'division' => 1]);
        $this->assertSame(['RJ'], array_column($plan['events'], 'code'));
        $this->assertSame('Recurve Junior', $plan['events'][0]['name']);
        $this->assertSame(4, $plan['events'][0]['firstPhase']);       // 6 archers
    }

    public function testNothingTickedIsOneEvent(): void
    {
        $plan = $this->dkPlan(['RHJ' => 3, 'RDJ' => 2, 'CHS' => 4], []);
        $this->assertSame(['ALL'], array_column($plan['events'], 'code'));
        $this->assertSame('Alle', $plan['events'][0]['name']);
        $this->assertSame(9, $plan['events'][0]['count']);
    }

    public function testDistanceAndGender(): void
    {
        $classes = [
            ['id' => 'H', 'name' => 'Herre', 'sex' => 0, 'ageFrom' => 0, 'ageTo' => 0, 'order' => 1],
            ['id' => 'D', 'name' => 'Dame', 'sex' => 1, 'ageFrom' => 0, 'ageTo' => 0, 'order' => 2],
        ];
        $pairs = [
            ['div' => 'R', 'divName' => 'Recurve', 'divOrder' => 1, 'class' => 'H', 'count' => 5, 'oldEvents' => ['18R'], 'qualShortest' => ''],
            ['div' => 'R', 'divName' => 'Recurve', 'divOrder' => 1, 'class' => 'D', 'count' => 3, 'oldEvents' => ['18R'], 'qualShortest' => ''],
        ];
        $oldEvents = [
            '18R' => ['code' => '18R', 'name' => '18m Recurve', 'progr' => 1, 'distance' => '18', 'count' => 8, 'settings' => []],
        ];

        $plan = ceBuildPlan($pairs, $oldEvents, $classes, ['gender' => 1, 'distance' => 1], $this->dkLabels());
        $byCode = array_column($plan['events'], null, 'code');
        $this->assertSame('18m Herre', $byCode['18H']['name']);
        $this->assertSame(5, $byCode['18H']['count']);
    }

    public function testNoOldEventsUsesFallbacks(): void
    {
        $classes = [
            ['id' => 'H', 'name' => 'Herre', 'sex' => 0, 'ageFrom' => 0, 'ageTo' => 0, 'order' => 1],
        ];
        $pairs = [
            ['div' => 'R', 'divName' => 'Recurve', 'divOrder' => 1, 'class' => 'H', 'count' => 4, 'oldEvents' => [], 'qualShortest' => '18'],
        ];

        $plan = ceBuildPlan($pairs, [], $classes, ['gender' => 1], $this->dkLabels());
        $this->assertSame('18', $plan['events'][0]['distance']);
        $this->assertNull($plan['events'][0]['settingsFrom']);
    }

    public function testUncoveredPairIsIncluded(): void
    {
        $classes = [
            ['id' => 'H', 'name' => 'Herre', 'sex' => 0, 'ageFrom' => 0, 'ageTo' => 0, 'order' => 1],
        ];
        $pairs = [
            ['div' => 'R', 'divName' => 'Recurve', 'divOrder' => 1, 'class' => 'H', 'count' => 3, 'oldEvents' => [], 'qualShortest' => ''],
        ];

        $plan = ceBuildPlan($pairs, [], $classes, ['division' => 1], $this->dkLabels());
        $this->assertCount(1, $plan['events']);
        $this->assertSame(3, $plan['events'][0]['count']);
    }

    public function testCodesAreUniqueAndFitColumn(): void
    {
        $classes = [
            ['id' => 'U21M', 'name' => 'Under 21 Men', 'sex' => 0, 'ageFrom' => 18, 'ageTo' => 20, 'order' => 1],
            ['id' => 'U21W', 'name' => 'Under 21 Women', 'sex' => 1, 'ageFrom' => 18, 'ageTo' => 20, 'order' => 2],
            ['id' => 'VETERANONE', 'name' => 'Veteran Men', 'sex' => 0, 'ageFrom' => 50, 'ageTo' => 99, 'order' => 3],
            ['id' => 'VETERANTWO', 'name' => 'Veteran Women', 'sex' => 1, 'ageFrom' => 50, 'ageTo' => 99, 'order' => 4],
        ];
        $pairs = [];
        foreach ($classes as $class) {
            $pairs[] = [
                'div' => 'R', 'divName' => 'Recurve', 'divOrder' => 1,
                'class' => $class['id'], 'count' => 1,
                'oldEvents' => [], 'qualShortest' => '700',
            ];
        }

        $plan = ceBuildPlan($pairs, [], $classes, ['gender' => 1, 'class' => 1, 'distance' => 1], $this->dkLabels());

        $codes = array_column($plan['events'], 'code');
        $this->assertCount(4, $codes);
        foreach ($codes as $code) {
            $this->assertLessThanOrEqual(10, strlen($code));
        }
        $this->assertCount(4, array_unique($codes));                 // all unique
        $this->assertContains('2', array_map(fn($c) => substr($c, -1), $codes)); // forced collision resolved with a tail digit
    }

    public function testUnknownDistanceIsEmptyString(): void
    {
        $classes = [
            ['id' => 'H', 'name' => 'Herre', 'sex' => 0, 'ageFrom' => 0, 'ageTo' => 0, 'order' => 1],
        ];
        $pairs = [
            ['div' => 'R', 'divName' => 'Recurve', 'divOrder' => 1, 'class' => 'H', 'count' => 2, 'oldEvents' => [], 'qualShortest' => ''],
        ];

        $plan = ceBuildPlan($pairs, [], $classes, ['gender' => 1], $this->dkLabels());
        $this->assertSame('', $plan['events'][0]['distance']);
    }

    public function testNormalizeDistanceKeepsDigitsOnly(): void
    {
        // Live EvDistance values include '18 m', '12m', '08' and '0'/'' (the
        // CreateEventNew default, i.e. "not set"). Plan building appends 'm'
        // to the distance for the name and uses its digits for the code, so
        // it must arrive as bare digits or '' for unknown.
        $this->assertSame('18', ceNormalizeDistance('18'));
        $this->assertSame('18', ceNormalizeDistance('18 m'));
        $this->assertSame('12', ceNormalizeDistance('12m'));
        $this->assertSame('8', ceNormalizeDistance('08'));
        $this->assertSame('', ceNormalizeDistance('0'));
        $this->assertSame('', ceNormalizeDistance(''));
        $this->assertSame('', ceNormalizeDistance('-'));
    }

    public function testFingerprintChangesWithDeletionCounts(): void
    {
        $plan = $this->dkPlan(['RHS' => 2], ['division' => 1]);
        $this->assertNotSame(
            cePlanFingerprint($plan, ['scoredMatches' => 0]),
            cePlanFingerprint($plan, ['scoredMatches' => 1])
        );
    }

    public function testDefaultEventSettingsDifferForCompound(): void
    {
        $recurve = ceDefaultEventSettings('R');
        $compound = ceDefaultEventSettings('C');
        $this->assertSame(1, $recurve['EvMatchMode']);
        $this->assertSame(0, $compound['EvMatchMode']);
        $this->assertSame(1, $recurve['EvFinalTargetType']);
        $this->assertSame(3, $compound['EvFinalTargetType']);
        $this->assertSame(5, $recurve['EvElimEnds']);
        $this->assertSame(3, $recurve['EvElimArrows']);
        $this->assertSame(1, $recurve['EvElimSO']);
        $this->assertSame(5, $recurve['EvFinEnds']);
        $this->assertSame(3, $recurve['EvFinArrows']);
        $this->assertSame(1, $recurve['EvFinSO']);
        $this->assertSame(0, $recurve['EvMatchArrowsNo']);
        $this->assertSame(248, $recurve['EvFinalAthTarget']);
        $this->assertSame(40, $recurve['EvTargetSize']);
    }
}
