<?php
use PHPUnit\Framework\TestCase;

final class ApplyChangesTest extends TestCase
{
    private function baseRow(array $over = []): array
    {
        return array_merge([
            'teamEvent' => 0, 'event' => 'RC-M', 'matchNo' => 2,
            'target' => '', 'scheduledDate' => '', 'scheduledTime' => '',
            'scheduledLen' => 0, 'phase' => 8, 'group' => 0, 'hasParticipant' => 1,
        ], $over);
    }

    public function testApplyUpdatesMatchingRowOnly(): void
    {
        $rows = [$this->baseRow(['matchNo' => 2]), $this->baseRow(['matchNo' => 3])];
        $changes = [[
            'teamEvent' => 0, 'event' => 'RC-M', 'matchNo' => 2,
            'target' => '5', 'scheduledDate' => '2026-08-16', 'scheduledTime' => '10:00', 'scheduledLen' => 20,
        ]];
        $out = applyChangesToRows($rows, $changes);
        $byMatch = [];
        foreach ($out as $r) { $byMatch[$r['matchNo']] = $r; }
        $this->assertSame('0005', $byMatch[2]['target']); // zero-padded to TargetNoPadding=4
        $this->assertSame('2026-08-16', $byMatch[2]['scheduledDate']);
        $this->assertSame(20, $byMatch[2]['scheduledLen']);
        $this->assertSame('', $byMatch[3]['target']); // untouched
    }

    public function testNonNumericTargetNotPadded(): void
    {
        $rows = [$this->baseRow(['matchNo' => 2])];
        $changes = [[
            'teamEvent' => 0, 'event' => 'RC-M', 'matchNo' => 2, 'target' => '5A',
            'scheduledDate' => '', 'scheduledTime' => '', 'scheduledLen' => 0,
        ]];
        $out = applyChangesToRows($rows, $changes);
        $this->assertSame('5A', $out[0]['target']);
    }

    public function testUnmatchedChangeIgnored(): void
    {
        $rows = [$this->baseRow(['matchNo' => 2])];
        $changes = [[
            'teamEvent' => 0, 'event' => 'RC-M', 'matchNo' => 99, 'target' => '5',
            'scheduledDate' => '', 'scheduledTime' => '', 'scheduledLen' => 0,
        ]];
        $out = applyChangesToRows($rows, $changes);
        $this->assertCount(1, $out);
        $this->assertSame('', $out[0]['target']);
    }

    public function testFocusRecordsChangedPhases(): void
    {
        $rowsAfter = [$this->baseRow(['matchNo' => 2, 'phase' => 8])];
        $changes = [[
            'teamEvent' => 0, 'event' => 'RC-M', 'matchNo' => 2,
            'target' => '', 'scheduledDate' => '', 'scheduledTime' => '', 'scheduledLen' => 0,
        ]];
        $focus = buildValidationFocusFromChanges($rowsAfter, $changes);
        $this->assertArrayHasKey('0|RC-M', $focus['phasesByEvent']);
        $this->assertArrayHasKey(8, $focus['phasesByEvent']['0|RC-M']);
    }
}
