<?php
use PHPUnit\Framework\TestCase;

/**
 * Auto-assign grouping key. Archers sharing a key are drawn onto the same
 * block of mats.
 *
 * Feature: "Mix faces of the same size" drops the face ids from the key, so
 * e.g. arhE26V's 40cm full face, 40cm triple and 40cm CO triple can share mats
 * instead of being split into three blocks.
 */
final class AutoAssignGroupKeyTest extends TestCase
{
    private const W40 = '40-40';

    private function key(array $o): string
    {
        return laAutoAssignGroupKey(
            $o['dist'] ?? '18-18',
            $o['div'] ?? 'R',
            $o['cls'] ?? 'HS',
            $o['byDiv'] ?? false,
            $o['byClass'] ?? false,
            $o['widths'] ?? self::W40,
            $o['faces'] ?? '1-1',
            $o['mix'] ?? false
        );
    }

    public function testLegacyKeyMatchesOriginalFormat(): void
    {
        $this->assertSame('18-18||40-40|1-1', $this->key(array()));
        $this->assertSame('18-18|RHS|40-40|1-1', $this->key(array('byDiv' => true, 'byClass' => true)));
        $this->assertSame('18-18|R|40-40|1-1', $this->key(array('byDiv' => true)));
        $this->assertSame('18-18|HS|40-40|1-1', $this->key(array('byClass' => true)));
    }

    public function testSameSizeFacesSplitWithoutMixing(): void
    {
        $keys = array(
            $this->key(array('div' => 'R', 'faces' => '1-1')),
            $this->key(array('div' => 'B', 'faces' => '2-2')),
            $this->key(array('div' => 'C', 'faces' => '4-4')),
        );
        $this->assertCount(3, array_unique($keys));
    }

    public function testSameSizeFacesShareAGroupWhenMixing(): void
    {
        $keys = array(
            $this->key(array('div' => 'R', 'faces' => '1-1', 'mix' => true)),
            $this->key(array('div' => 'B', 'faces' => '2-2', 'mix' => true)),
            $this->key(array('div' => 'C', 'faces' => '4-4', 'mix' => true)),
        );
        $this->assertCount(1, array_unique($keys));
    }

    public function testMixingStillSplitsDifferentSizesAndDistances(): void
    {
        $base = $this->key(array('mix' => true));
        $this->assertNotSame($base, $this->key(array('mix' => true, 'widths' => '60-60')));
        $this->assertNotSame($base, $this->key(array('mix' => true, 'dist' => '25-25')));
    }

    public function testMixingStillHonoursDivisionAndClassSplits(): void
    {
        $r = $this->key(array('mix' => true, 'byDiv' => true, 'div' => 'R', 'faces' => '1-1'));
        $c = $this->key(array('mix' => true, 'byDiv' => true, 'div' => 'C', 'faces' => '4-4'));
        $this->assertNotSame($r, $c);
    }
}
