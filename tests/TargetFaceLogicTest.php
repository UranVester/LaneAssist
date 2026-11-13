<?php
use PHPUnit\Framework\TestCase;

/**
 * Fixture shapes come from local tournament VJL24: 7 face records, 2 real
 * choices (Full Face, Trippel), each record covering different divisions.
 */
final class TargetFaceLogicTest extends TestCase
{
    private const FF = [1, 2, 5, 6];

    /** Rows in core getTargets() ORDER BY: per div/class, defaults first. */
    private static function vjl24Rows(): array
    {
        $rows = [];
        $perDivision = [
            'R' => [[7, 1], [1, 0], [3, 0]],
            'C' => [[4, 1], [2, 0]],
            'L' => [[5, 1], [1, 0], [3, 0]],
            'B' => [[6, 1], [1, 0], [3, 0]],
        ];
        foreach ($perDivision as $div => $faces) {
            foreach ($faces as [$tfId, $default]) {
                $rows[] = ['DivId' => $div, 'ClId' => 'S', 'TfId' => $tfId, 'TfDefault' => $default];
            }
        }
        return $rows;
    }

    private static function vjl24Names(): array
    {
        return [1 => 'Full Face', 2 => 'Full Face', 3 => 'Trippel', 4 => 'Trippel', 5 => 'Full Face', 6 => 'Full Face', 7 => 'Trippel'];
    }

    public function testLegalSetsVjl24(): void
    {
        $legal = tfBuildLegalSets(self::vjl24Rows());
        $this->assertSame([5, 1, 3], $legal['L']['S']);
        $this->assertSame([4, 2], $legal['C']['S']);
        $this->assertSame([7, 1, 3], $legal['R']['S']);
        $this->assertSame([6, 1, 3], $legal['B']['S']);
    }

    public function testLaterDefaultIsDropped(): void
    {
        $legal = tfBuildLegalSets([
            ['DivId' => 'R', 'ClId' => 'S', 'TfId' => 10, 'TfDefault' => 1],
            ['DivId' => 'R', 'ClId' => 'S', 'TfId' => 11, 'TfDefault' => 1],
            ['DivId' => 'R', 'ClId' => 'S', 'TfId' => 12, 'TfDefault' => 0],
        ]);
        $this->assertSame([10, 12], $legal['R']['S']);
    }

    public function testMergeKeyNormalises(): void
    {
        $this->assertSame(tfMergeKey('fullface'), tfMergeKey('Fullface  '));
        $this->assertSame('tripel', tfMergeKey(' Tripel '));
        $this->assertSame('full face', tfMergeKey('Full  Face'));
    }

    public function testGroupsVjl24(): void
    {
        $groups = tfBuildGroups(self::vjl24Names());
        $this->assertSame([
            ['key' => 'full face', 'label' => 'Full Face', 'tfIds' => [1, 2, 5, 6]],
            ['key' => 'trippel', 'label' => 'Trippel', 'tfIds' => [3, 4, 7]],
        ], $groups);
    }

    public function testGroupsTrailingSpaces(): void
    {
        $groups = tfBuildGroups([9 => 'Tripel ', 8 => 'Fullface  ', 6 => 'Fullface']);
        $this->assertSame(['Fullface', 'Tripel'], array_column($groups, 'label'));
        $this->assertSame([6, 8], $groups[0]['tfIds']);
    }

    public function testGroupKeyForFace(): void
    {
        $groups = tfBuildGroups(self::vjl24Names());
        $this->assertSame('trippel', tfGroupKeyForFace($groups, 7));
        $this->assertSame('full face', tfGroupKeyForFace($groups, 5));
        $this->assertNull(tfGroupKeyForFace($groups, 0));
    }

    public function testPickPrefersCurrent(): void
    {
        $this->assertSame(1, tfPickFace([5, 1, 3], self::FF, 1));
    }

    public function testPickFallsBackToLegalOrder(): void
    {
        $this->assertSame(5, tfPickFace([5, 1, 3], self::FF, 3));
    }

    public function testPickUsesArchersOwnLegalMember(): void
    {
        $this->assertSame(2, tfPickFace([4, 2], self::FF, 4));
    }

    public function testPickIgnoresIllegalCurrent(): void
    {
        // C archer illegally on TfId 1 (a Full Face record for R/B/L): pick their own legal one.
        $this->assertSame(2, tfPickFace([4, 2], self::FF, 1));
    }

    public function testPickNullWhenNoneLegal(): void
    {
        $this->assertNull(tfPickFace([], self::FF, 0));
        $this->assertNull(tfPickFace([4], self::FF, 4));
    }

    public function testDescribeSelectedLegal(): void
    {
        $groups = tfBuildGroups(self::vjl24Names());
        $d = tfDescribeArcher([5, 1, 3], $groups, 5);
        $this->assertSame(['full face' => true, 'trippel' => true], $d['cells']);
        $this->assertSame('full face', $d['selected']);
        $this->assertFalse($d['currentIllegal']);
        $this->assertFalse($d['noFace']);
    }

    public function testDescribeIllegalCurrent(): void
    {
        $groups = tfBuildGroups(self::vjl24Names());
        $d = tfDescribeArcher([4, 2], $groups, 1);
        $this->assertNull($d['selected']);
        $this->assertTrue($d['currentIllegal']);
        $this->assertSame(['full face' => true, 'trippel' => true], $d['cells']);
    }

    public function testDescribeNoFace(): void
    {
        $groups = tfBuildGroups(self::vjl24Names());
        $d = tfDescribeArcher([], $groups, 0);
        $this->assertTrue($d['noFace']);
        $this->assertFalse($d['currentIllegal']);
        $this->assertNull($d['selected']);
        $this->assertSame(['full face' => false, 'trippel' => false], $d['cells']);
    }

    public function testPlanBulkPartitions(): void
    {
        $groups = tfBuildGroups(self::vjl24Names());
        $legal = tfBuildLegalSets(self::vjl24Rows());
        $plan = tfPlanBulk([
            ['id' => 11, 'div' => 'L', 'cls' => 'S', 'face' => 3],
            ['id' => 12, 'div' => 'C', 'cls' => 'S', 'face' => 2],
            ['id' => 13, 'div' => 'X', 'cls' => 'S', 'face' => 0],
            ['id' => 14, 'div' => 'L', 'cls' => 'S', 'face' => 5],
        ], $groups, 'full face', $legal);
        $this->assertSame([11 => 5], $plan['changes']);
        $this->assertSame(2, $plan['unchanged']);
        $this->assertSame(['XS' => 1], $plan['skipped']);
    }

    public function testNoFaceArcherIsSkipped(): void
    {
        $groups = tfBuildGroups(self::vjl24Names());
        $legal = tfBuildLegalSets(self::vjl24Rows());
        $plan = tfPlanBulk([['id' => 20, 'div' => 'T', 'cls' => 'S', 'face' => 0]], $groups, 'trippel', $legal);
        $this->assertSame([], $plan['changes']);
        $this->assertSame(0, $plan['unchanged']);
        $this->assertSame(['TS' => 1], $plan['skipped']);
    }

    public function testPlanBulkUnknownGroupThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        tfPlanBulk([], tfBuildGroups(self::vjl24Names()), 'nope', []);
    }
}
