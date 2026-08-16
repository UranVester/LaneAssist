<?php
use PHPUnit\Framework\TestCase;

final class ValidateFinalRowsTest extends TestCase
{
    /** Build one finals row. */
    private function row(array $over = []): array
    {
        return array_merge([
            'teamEvent' => 0, 'event' => 'RC-M', 'matchNo' => 0,
            'scheduledDate' => '', 'scheduledTime' => '',
            'scheduledLen' => 0, 'group' => 0, 'target' => '',
            'phase' => 8, 'hasParticipant' => 1,
        ], $over);
    }

    /**
     * Build a full phase of playable matches: `pairs` matches, each with 2 seated archers.
     * matchNo runs 0,1 (pair 0), 2,3 (pair 1)... Optionally schedule all rows at $dt.
     */
    private function phase(int $phase, int $pairs, string $date = '', string $time = '', string $event = 'RC-M'): array
    {
        $rows = [];
        for ($p = 0; $p < $pairs; $p++) {
            foreach ([0, 1] as $slot) {
                $rows[] = $this->row([
                    'event' => $event, 'phase' => $phase,
                    'matchNo' => $p * 2 + $slot,
                    'scheduledDate' => $date, 'scheduledTime' => $time,
                    'hasParticipant' => 1,
                ]);
            }
        }
        return $rows;
    }

    private function errorTypes(array $errors): array
    {
        return array_map(fn($e) => $e['type'], $errors);
    }

    // ---- Playability / count ----

    public function testLoneArcherPairIsNotPlayable_noPhaseOrderError(): void
    {
        // 1/8 fully scheduled AFTER 1/4, but 1/4 has only a lone archer -> not playable -> no error.
        $rows = array_merge(
            $this->phase(8, 1, '2026-08-16', '12:00'),          // 1/8 at 12:00 (playable)
            [$this->row(['phase' => 4, 'matchNo' => 0, 'scheduledDate' => '2026-08-16', 'scheduledTime' => '09:00'])], // lone 1/4 at 09:00
        );
        $errors = validateFinalRows($rows);
        $this->assertNotContains('phase_order', $this->errorTypes($errors));
    }

    public function testCorrectlyOrderedFullBracketHasNoErrors(): void
    {
        $rows = array_merge(
            $this->phase(8, 2, '2026-08-16', '09:00'),  // 1/8 first
            $this->phase(4, 1, '2026-08-16', '11:00'),  // 1/4 later
            $this->phase(2, 1, '2026-08-16', '13:00'),  // 1/2 later still
        );
        $errors = validateFinalRows($rows);
        $this->assertSame([], $errors);
    }

    // ---- Phase ordering ----

    public function testMisorderedPhasesRaisePhaseOrder(): void
    {
        // 1/8 scheduled AFTER 1/4 -> violation
        $rows = array_merge(
            $this->phase(8, 1, '2026-08-16', '14:00'),
            $this->phase(4, 1, '2026-08-16', '10:00'),
        );
        $errors = validateFinalRows($rows);
        $this->assertContains('phase_order', $this->errorTypes($errors));
    }

    public function testSemisMustPrecedeGoldAndBronze(): void
    {
        // 1/2 at 15:00, Gold (0) and Bronze (1) at 10:00 -> two violations (2->0 and 2->1)
        $rows = array_merge(
            $this->phase(2, 1, '2026-08-16', '15:00'),
            $this->phase(0, 1, '2026-08-16', '10:00'),
            $this->phase(1, 1, '2026-08-16', '10:00'),
        );
        $errors = validateFinalRows($rows);
        $this->assertSame(2, count(array_filter($this->errorTypes($errors), fn($t) => $t === 'phase_order')));
    }

    public function testUnscheduledPhasesDoNotRaiseOrderError(): void
    {
        // Playable but no date/time -> phase-order rule skips them.
        $rows = array_merge($this->phase(8, 1), $this->phase(4, 1));
        $errors = validateFinalRows($rows);
        $this->assertNotContains('phase_order', $this->errorTypes($errors));
    }

    // ---- Target conflicts ----

    public function testSameTargetDifferentBundlesSameSlotConflicts(): void
    {
        $a = $this->phase(8, 1, '2026-08-16', '10:00', 'RC-M');
        $b = $this->phase(8, 1, '2026-08-16', '10:00', 'RC-W');
        foreach ($a as &$r) { $r['target'] = '0005'; } unset($r);
        foreach ($b as &$r) { $r['target'] = '0005'; } unset($r);
        $errors = validateFinalRows(array_merge($a, $b));
        $this->assertContains('target_conflict', $this->errorTypes($errors));
    }

    public function testSameBundleSameTargetNoConflict(): void
    {
        $rows = $this->phase(8, 1, '2026-08-16', '10:00', 'RC-M');
        foreach ($rows as &$r) { $r['target'] = '0005'; } unset($r);
        $errors = validateFinalRows($rows);
        $this->assertNotContains('target_conflict', $this->errorTypes($errors));
    }

    public function testHasParticipantZeroIsNotPlayable(): void
    {
        // phase(8) is playable; phase(4) pair has hasParticipant=0 on both rows (scheduled) -> not playable -> no phase_order error
        $rows = array_merge(
            $this->phase(8, 1, '2026-08-16', '09:00'),
            [
                $this->row(['phase' => 4, 'matchNo' => 0, 'hasParticipant' => 0, 'scheduledDate' => '2026-08-16', 'scheduledTime' => '11:00']),
                $this->row(['phase' => 4, 'matchNo' => 1, 'hasParticipant' => 0, 'scheduledDate' => '2026-08-16', 'scheduledTime' => '11:00']),
            ]
        );
        $errors = validateFinalRows($rows);
        $this->assertNotContains('phase_order', $this->errorTypes($errors));
    }

    public function testFullFivePhaseBracketCorrectlyOrderedHasNoErrors(): void
    {
        // All five phases in correct chronological order: phase(8) -> phase(4) -> phase(2) -> phase(1) Bronze -> phase(0) Gold
        $rows = array_merge(
            $this->phase(8, 2, '2026-08-16', '08:00'),
            $this->phase(4, 1, '2026-08-16', '10:00'),
            $this->phase(2, 1, '2026-08-16', '12:00'),
            $this->phase(1, 1, '2026-08-16', '14:00'),
            $this->phase(0, 1, '2026-08-16', '14:00'),
        );
        $errors = validateFinalRows($rows);
        $this->assertSame([], $errors);
    }
}
