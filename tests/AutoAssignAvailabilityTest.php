<?php
use PHPUnit\Framework\TestCase;

/**
 * Regression cover for the ManageTargets auto-assign availability map.
 *
 * Bug: after "Unassign all" the browser posts a snapshot in which every
 * participant has an empty target. The server treated that as "no snapshot
 * supplied" and read occupancy from the database instead, so auto-assign
 * skipped every still-saved target (observed live: it started at target 9
 * because targets 1-8 were saved).
 */
final class AutoAssignAvailabilityTest extends TestCase
{
    /** Letter order used for 4 archers per target on the default draw type. */
    private const LETTERS = array('A', 'C', 'B', 'D');

    public function testSnapshotParsingFlagsAProvidedButFullyClearedSnapshot(): void
    {
        $none = laParseAssignmentSnapshot(array());
        $this->assertFalse($none['provided']);

        $cleared = laParseAssignmentSnapshot(array(
            array('participantId' => 7, 'targetFull' => '   '),
        ));
        $this->assertTrue($cleared['provided'], 'a snapshot of cleared assignments is still a snapshot');
        $this->assertSame(array(7 => ''), $cleared['assignedByParticipant']);
        $this->assertSame(array(), $cleared['occupiedTargets']);
    }

    public function testClearedSnapshotNeverConsultsSavedOccupancy(): void
    {
        $snapshot = laParseAssignmentSnapshot(array(
            array('participantId' => 101, 'targetFull' => ''),
            array('participantId' => 102, 'targetFull' => ''),
        ));

        $probeCalls = 0;
        $availability = laBuildTargetAvailability(1, 3, self::LETTERS, $snapshot,
            function ($target, $letter) use (&$probeCalls) {
                $probeCalls++;
                return true;
            });

        $this->assertSame(0, $probeCalls, 'saved DB occupancy must not be read when the UI supplied a snapshot');
        $this->assertSame(array_fill_keys(array_keys($availability), 1), $availability);
    }

    public function testAutoAssignStartsAtTheFirstTargetAfterUnassignAll(): void
    {
        // Reproduces tournament arhS26: targets 1-8 are still saved in the DB,
        // the operator hit "Unassign all", so every target must be free again.
        $snapshot = laParseAssignmentSnapshot(array(
            array('participantId' => 101, 'targetFull' => ''),
        ));

        $availability = laBuildTargetAvailability(1, 20, self::LETTERS, $snapshot,
            function ($target, $letter) {
                return $target >= 1 && $target <= 8;
            });

        $this->assertSame('0001A', array_search(1, $availability, true));
    }

    public function testMissingSnapshotFallsBackToSavedOccupancy(): void
    {
        $snapshot = laParseAssignmentSnapshot(array());

        $availability = laBuildTargetAvailability(1, 3, self::LETTERS, $snapshot,
            function ($target, $letter) {
                return $target === 1;
            });

        $this->assertSame(0, $availability['0001A']);
        $this->assertSame(0, $availability['0001D']);
        $this->assertSame(1, $availability['0002A']);
    }

    public function testSnapshotOccupiedTargetsAreUnavailable(): void
    {
        $snapshot = laParseAssignmentSnapshot(array(
            array('participantId' => 101, 'targetFull' => '0001A'),
            array('participantId' => 102, 'targetFull' => ' 0002c '),
        ));

        $availability = laBuildTargetAvailability(1, 2, self::LETTERS, $snapshot,
            function ($target, $letter) {
                return false;
            });

        $this->assertSame(0, $availability['0001A']);
        $this->assertSame(0, $availability['0002C'], 'targetFull is normalised to upper case and trimmed');
        $this->assertSame(1, $availability['0001C']);
        $this->assertSame(1, $availability['0002A']);
    }

    public function testKeysArePaddedAndOrderedByTargetThenLetterOrder(): void
    {
        $snapshot = laParseAssignmentSnapshot(array(
            array('participantId' => 1, 'targetFull' => ''),
        ));

        $availability = laBuildTargetAvailability(9, 10, self::LETTERS, $snapshot,
            function ($target, $letter) {
                return false;
            });

        $this->assertSame(
            array('0009A', '0009C', '0009B', '0009D', '0010A', '0010C', '0010B', '0010D'),
            array_keys($availability)
        );
    }
}
