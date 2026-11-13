<?php
use PHPUnit\Framework\TestCase;

final class ColorLogicTest extends TestCase
{
    public function testCountriesSortedAndDeduped(): void
    {
        $groups = laBuildColorGroups(['countries' => ['ZZ', 'AA', ' AA', '']]);
        $this->assertSame(['AA', 'ZZ'], $groups['country']);
    }

    public function testCategoryOrderFollowsDivisionThenClassViewOrder(): void
    {
        $groups = laBuildColorGroups([
            'divisions' => [['id' => 'R', 'description' => 'Recurve'], ['id' => 'C', 'description' => 'Compound']],
            'classes' => ['W', 'M'],
            'categories' => [['division' => 'C', 'class' => 'M'], ['division' => 'R', 'class' => 'W'], ['division' => 'R', 'class' => 'M']],
        ]);
        $this->assertSame(['RW', 'RM', 'CM'], $groups['category']);
    }

    public function testCategoryWithUnknownDivisionOrClassSortsLast(): void
    {
        $groups = laBuildColorGroups([
            'divisions' => [['id' => 'R', 'description' => 'Recurve']],
            'classes' => ['M'],
            'categories' => [['division' => 'X', 'class' => 'M'], ['division' => 'R', 'class' => 'M']],
        ]);
        $this->assertSame(['RM', 'XM'], $groups['category']);
    }

    public function testIndividualAndTeamEventWithSameCodeAreDistinct(): void
    {
        $groups = laBuildColorGroups(['events' => [
            ['teamEvent' => 1, 'code' => 'RM'],
            ['teamEvent' => 0, 'code' => 'RW'],
            ['teamEvent' => 0, 'code' => 'RM'],
        ]]);
        $this->assertSame(['0|RW', '0|RM', '1|RM'], $groups['event']);
    }

    public function testDistanceProfilesSortNumerically(): void
    {
        $groups = laBuildColorGroups(['distanceProfiles' => ['70m', '18m-25m', '30m-50m', '18m-25m', '']]);
        $this->assertSame(['18m-25m', '30m-50m', '70m'], $groups['distance']);
    }

    public function testTargetFacesAreNumericStrings(): void
    {
        $groups = laBuildColorGroups(['targetFaces' => [5, '10', 1, 0]]);
        $this->assertSame(['1', '5', '10'], $groups['target-face']);
    }

    public function testDivisionsPassThroughInOrder(): void
    {
        $groups = laBuildColorGroups(['divisions' => [
            ['id' => 'R', 'description' => 'Recurve'],
            ['id' => 'C', 'description' => 'Compound'],
            ['id' => 'R', 'description' => 'Recurve again'],
        ]]);
        $this->assertSame([
            ['id' => 'R', 'description' => 'Recurve'],
            ['id' => 'C', 'description' => 'Compound'],
        ], $groups['division']);
    }

    public function testClassesKeepViewOrderAsStrings(): void
    {
        $groups = laBuildColorGroups(['classes' => ['10', '2', 1, '001']]);
        $this->assertSame(['10', '2', '1', '001'], $groups['class']);
    }

    public function testEmptyInputGivesEveryGroupAsEmptyList(): void
    {
        $this->assertSame([
            'country' => [], 'division' => [], 'class' => [], 'category' => [],
            'event' => [], 'distance' => [], 'target-face' => [],
        ], laBuildColorGroups([]));
    }
}
