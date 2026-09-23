<?php
use PHPUnit\Framework\TestCase;

final class OwnBadgesTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['LANEASSIST_BADGE_PROVIDERS'] = [];
        require dirname(__DIR__) . '/laneassist-badges.php';
    }

    private function archer(array $over = []): array
    {
        return $over + [
            'participantId' => 5,
            'personalBest' => null,
            'personalBestCompetitionName' => '',
            'hasNewPersonalBest' => false,
        ];
    }

    public function testItRegistersItselfUnderLaneAssist(): void
    {
        $this->assertArrayHasKey('LaneAssist', $GLOBALS['LANEASSIST_BADGE_PROVIDERS']);
        $this->assertTrue(is_callable($GLOBALS['LANEASSIST_BADGE_PROVIDERS']['LaneAssist']));
    }

    public function testAnArcherWithNoHistoryGetsNothing(): void
    {
        $this->assertSame([], laneAssistOwnBadges([$this->archer()], []));
    }

    public function testAPersonalBestBecomesAPillNamingTheCompetition(): void
    {
        $badges = laneAssistOwnBadges([$this->archer([
            'personalBest' => 570,
            'personalBestCompetitionName' => 'Vordingborg Indoor',
        ])], []);

        $this->assertCount(1, $badges[5]);
        $this->assertSame('pill', $badges[5][0]['kind']);
        $this->assertSame('PB 570', $badges[5][0]['label']);
        $this->assertStringContainsString('Vordingborg Indoor', $badges[5][0]['title']);
    }

    public function testAPersonalBestWithNoKnownCompetitionStillRenders(): void
    {
        $badges = laneAssistOwnBadges([$this->archer(['personalBest' => 570])], []);
        $this->assertSame('PB 570', $badges[5][0]['label']);
        $this->assertNotSame('', $badges[5][0]['title']);
    }

    public function testANewPersonalBestAddsAnIcon(): void
    {
        $badges = laneAssistOwnBadges([$this->archer([
            'personalBest' => 570,
            'personalBestCompetitionName' => 'Vordingborg Indoor',
            'hasNewPersonalBest' => true,
        ])], []);

        $this->assertSame(['pill', 'icon'], array_column($badges[5], 'kind'));
        $this->assertSame('fa-trophy', $badges[5][1]['icon']);
    }

    public function testANewPersonalBestWithNoPriorScoreStillGetsTheIcon(): void
    {
        // A first-ever recorded score has no previous best to show as a pill.
        $badges = laneAssistOwnBadges([$this->archer(['hasNewPersonalBest' => true])], []);
        $this->assertSame(['icon'], array_column($badges[5], 'kind'));
    }

    public function testZeroIsNotAPersonalBest(): void
    {
        $this->assertSame([], laneAssistOwnBadges([$this->archer(['personalBest' => 0])], []));
    }
}
