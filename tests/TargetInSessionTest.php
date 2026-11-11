<?php
use PHPUnit\Framework\TestCase;

/**
 * Regression cover for ManageTargets' "Invalid target for session" check.
 *
 * Bug: the check used core createAvailableTargetSQL(), whose MySQL 8 /
 * MariaDB 10.2+ path (IANSEO 2026-10-26) dropped its SesType='Q' filter, so a
 * target belonging only to a finals/elimination session with the same
 * SesOrder was accepted for the qualification session. The check now reads
 * the qualification Session row itself and asks laIsTargetInSession().
 */
final class TargetInSessionTest extends TestCase
{
    /** Session layout: targets 5..14 (10 targets), 4 archers (A-D) each. */
    private function inSession(int $target, string $letter): bool
    {
        return laIsTargetInSession($target, $letter, 5, 10, 4);
    }

    public function testAcceptsEveryTargetAndLetterInsideTheSession(): void
    {
        $this->assertTrue($this->inSession(5, 'A'));
        $this->assertTrue($this->inSession(14, 'D'));
        $this->assertTrue($this->inSession(9, 'b'));
    }

    public function testRejectsTargetsOutsideTheSessionRange(): void
    {
        $this->assertFalse($this->inSession(4, 'A'));
        $this->assertFalse($this->inSession(15, 'A'));
        $this->assertFalse($this->inSession(0, 'A'));
    }

    public function testRejectsLettersBeyondArchersPerTarget(): void
    {
        $this->assertFalse($this->inSession(5, 'E'));
        $this->assertFalse($this->inSession(5, ''));
        $this->assertFalse($this->inSession(5, '1'));
        $this->assertFalse($this->inSession(5, 'AB'));
    }

    public function testEmptySessionAcceptsNothing(): void
    {
        $this->assertFalse(laIsTargetInSession(1, 'A', 1, 0, 4));
        $this->assertFalse(laIsTargetInSession(1, 'A', 1, 10, 0));
    }
}
