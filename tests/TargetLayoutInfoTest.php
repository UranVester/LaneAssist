<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Common/target-layout-info.php';

final class TargetLayoutInfoTest extends TestCase
{
    public function testGetLayoutLanesPerMatKnowsOnlyTheFourCoreLayouts(): void
    {
        $this->assertSame(2, getLayoutLanesPerMat('layout_60cm_3_abc'));
        $this->assertSame(1, getLayoutLanesPerMat('layout_60cm_4_split'));
        $this->assertSame(1, getLayoutLanesPerMat('layout_40cm_4_quad'));
        $this->assertSame(2, getLayoutLanesPerMat('layout_40cm_6_triangle'));
        $this->assertSame(0, getLayoutLanesPerMat('layout_outdoor_mixed_3'));
        $this->assertSame(0, getLayoutLanesPerMat('layout_fallback_stacked'));
        $this->assertSame(0, getLayoutLanesPerMat('not_a_layout'));
    }

    public function testGetLayoutArchersPerTargetCoversEveryNonFallbackLayout(): void
    {
        $this->assertSame(3, getLayoutArchersPerTarget('layout_60cm_3_abc'));
        $this->assertSame(3, getLayoutArchersPerTarget('layout_40cm_6_triangle'));
        $this->assertSame(4, getLayoutArchersPerTarget('layout_60cm_4_split'));
        $this->assertSame(4, getLayoutArchersPerTarget('layout_40cm_4_quad'));
        $this->assertSame(2, getLayoutArchersPerTarget('layout_outdoor_mixed_2'));
        $this->assertSame(3, getLayoutArchersPerTarget('layout_outdoor_mixed_3'));
        $this->assertSame(4, getLayoutArchersPerTarget('layout_outdoor_mixed_4'));
        $this->assertSame(0, getLayoutArchersPerTarget('layout_fallback_stacked'));
        $this->assertSame(0, getLayoutArchersPerTarget('not_a_layout'));
    }

    public function testIsKnownLayoutIdRejectsUnknownAndEmptyValues(): void
    {
        $this->assertTrue(isKnownLayoutId('layout_40cm_4_quad'));
        $this->assertTrue(isKnownLayoutId(' layout_40cm_4_quad '));
        $this->assertFalse(isKnownLayoutId('layout_does_not_exist'));
        $this->assertFalse(isKnownLayoutId(''));
        $this->assertFalse(isKnownLayoutId('   '));
    }

    public function testGetKnownLayoutIdsMatchesWhatIsKnownLayoutIdAccepts(): void
    {
        foreach (getKnownLayoutIds() as $layoutId) {
            $this->assertTrue(isKnownLayoutId($layoutId));
        }
    }

    public function testSavedLayoutPreferenceReadWriteWithoutModulesInfrastructureIsANoop(): void
    {
        // getModuleParameter/setModuleParameter come from core IANSEO and are
        // not loaded in this pure-logic test, so these must degrade safely
        // rather than fatal.
        $this->assertSame('', getSavedTournamentLayoutPreference(1));
        $this->assertSame('', getSavedTournamentLayoutPreference(0));
        saveTournamentLayoutPreference(1, 'layout_40cm_4_quad');
    }
}
