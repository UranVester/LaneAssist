<?php
use PHPUnit\Framework\TestCase;

final class ProjectFinalistsTest extends TestCase
{
    // Planning mode: caller passes count of flag-carrying entrants (assumed finalists).
    public function testPlanningFlaggedEntrantsProjectedWhenNoCap(): void
    {
        $this->assertSame(16, projectFinalists(16, 0)); // 16 flagged, no numQualified cap
    }

    public function testPlanningCappedByNumQualified(): void
    {
        $this->assertSame(8, projectFinalists(30, 8)); // more flagged than qualify -> capped
    }

    public function testPlanningFewerEntrantsThanCap(): void
    {
        $this->assertSame(5, projectFinalists(5, 8)); // fewer flagged than cap -> entrants
    }

    public function testUnflaggedFieldProjectsZero(): void
    {
        // Caller found no flag-carrying entrants in planning mode.
        $this->assertSame(0, projectFinalists(0, 8));
    }

    // Post-qualification: caller passes seated participant count.
    public function testPostQualSeatedPassThrough(): void
    {
        $this->assertSame(11, projectFinalists(11, 0));
        $this->assertSame(8, projectFinalists(11, 8)); // still capped by numQualified
    }

    public function testNegativeClampedToZero(): void
    {
        $this->assertSame(0, projectFinalists(-3, 0));
    }
}
