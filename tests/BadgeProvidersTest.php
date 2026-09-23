<?php
use PHPUnit\Framework\TestCase;

final class BadgeProvidersTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/la-badge-providers-' . getmypid() . '-' . mt_rand();
        mkdir($this->root, 0777, true);
        $GLOBALS['LANEASSIST_BADGE_PROVIDERS'] = [];
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*/laneassist-badges.php') ?: [] as $file) {
            unlink($file);
        }
        foreach (glob($this->root . '/*') ?: [] as $dir) {
            @rmdir($dir);
        }
        @rmdir($this->root);
        $GLOBALS['LANEASSIST_BADGE_PROVIDERS'] = [];
    }

    private function provider(string $module, string $body): void
    {
        mkdir($this->root . '/' . $module, 0777, true);
        file_put_contents($this->root . '/' . $module . '/laneassist-badges.php',
            "<?php\n" . $body);
    }

    private array $archers = [
        ['participantId' => 1, 'totalPoints' => 570],
        ['participantId' => 2, 'totalPoints' => 100],
    ];

    public function testNoProvidersMeansNoBadges(): void
    {
        $this->assertSame([], laneAssistBadgeProviderFiles($this->root));
        $this->assertSame([], laneAssistCollectBadges($this->archers, [], $this->root));
    }

    public function testDiscoveryFindsProviderFilesInSortedOrder(): void
    {
        $this->provider('Zeta', 'return;');
        $this->provider('Alpha', 'return;');
        $files = laneAssistBadgeProviderFiles($this->root);
        $this->assertCount(2, $files);
        $this->assertStringContainsString('/Alpha/', $files[0]);
        $this->assertStringContainsString('/Zeta/', $files[1]);
    }

    public function testAProviderSBadgesAreKeyedByParticipant(): void
    {
        $this->provider('Good', <<<'PHP'
$GLOBALS['LANEASSIST_BADGE_PROVIDERS']['Good'] = function ($archers, $context) {
    $out = [];
    foreach ($archers as $archer) {
        if ($archer['totalPoints'] >= 500) {
            $out[$archer['participantId']] = [['label' => 'Guld', 'color' => '#d4af37']];
        }
    }
    return $out;
};
PHP);
        $badges = laneAssistCollectBadges($this->archers, [], $this->root);
        $this->assertSame([1], array_keys($badges));
        $this->assertSame('Guld', $badges[1][0]['label']);
    }

    public function testContextIsPassedThrough(): void
    {
        $this->provider('Ctx', <<<'PHP'
$GLOBALS['LANEASSIST_BADGE_PROVIDERS']['Ctx'] = function ($archers, $context) {
    return [1 => [['label' => $context['toTypeName'], 'color' => '#000000']]];
};
PHP);
        $badges = laneAssistCollectBadges(
            $this->archers, ['toTypeName' => 'Type_Indoor 18'], $this->root);
        $this->assertSame('Type_Indoor 18', $badges[1][0]['label']);
    }

    public function testAThrowingProviderIsIsolatedAndOthersStillRun(): void
    {
        $this->provider('Bad', <<<'PHP'
$GLOBALS['LANEASSIST_BADGE_PROVIDERS']['Bad'] = function ($archers, $context) {
    throw new RuntimeException('provider exploded');
};
PHP);
        $this->provider('Good', <<<'PHP'
$GLOBALS['LANEASSIST_BADGE_PROVIDERS']['Good'] = function ($archers, $context) {
    return [2 => [['label' => 'Hvid', 'color' => '#f2f2f2']]];
};
PHP);
        $badges = laneAssistCollectBadges($this->archers, [], $this->root);
        $this->assertSame([2], array_keys($badges));
    }

    public function testProviderOutputCannotLeakIntoTheJsonResponse(): void
    {
        $this->provider('Noisy', <<<'PHP'
$GLOBALS['LANEASSIST_BADGE_PROVIDERS']['Noisy'] = function ($archers, $context) {
    echo "this must never reach the client";
    return [1 => [['label' => 'Sølv', 'color' => '#9aa0a6']]];
};
PHP);
        ob_start();
        $badges = laneAssistCollectBadges($this->archers, [], $this->root);
        $leaked = ob_get_clean();
        $this->assertSame('', $leaked);
        $this->assertSame('Sølv', $badges[1][0]['label']);
    }

    public function testOutputAtIncludeTimeIsAlsoSwallowed(): void
    {
        $this->provider('NoisyInclude', "echo 'noise at include time';\nreturn;");
        ob_start();
        laneAssistCollectBadges($this->archers, [], $this->root);
        $this->assertSame('', ob_get_clean());
    }

    public function testAProviderLeavingAnOutputBufferOpenCannotSwallowTheResponse(): void
    {
        $this->provider('Leaky', <<<'PHP'
$GLOBALS['LANEASSIST_BADGE_PROVIDERS']['Leaky'] = function ($archers, $context) {
    ob_start();
    echo 'this must not swallow the response';
    // Deliberately never closed -- a real third-party provider could do this.
    return [1 => [['label' => 'Guld', 'color' => '#d4af37']]];
};
PHP);
        $level = ob_get_level();
        $badges = laneAssistCollectBadges($this->archers, [], $this->root);

        $this->assertSame($level, ob_get_level(),
            'the buffer stack must be back where it started; a buffer left open here '
            . "would swallow LiveView/api.php's real JSON response");
        $this->assertSame('Guld', $badges[1][0]['label'],
            'the leaky provider still contributed its badge');
    }

    public function testABufferLeftOpenAtIncludeTimeIsAlsoUnwound(): void
    {
        $this->provider('LeakyInclude', "ob_start();\necho 'noise';\nreturn;");
        $level = ob_get_level();
        laneAssistCollectBadges($this->archers, [], $this->root);
        $this->assertSame($level, ob_get_level());
    }

    public function testANonCallableRegistrationIsSkipped(): void
    {
        $this->provider('Broken', <<<'PHP'
$GLOBALS['LANEASSIST_BADGE_PROVIDERS']['Broken'] = 'dkNoSuchFunctionAnywhere';
PHP);
        $this->assertSame([], laneAssistCollectBadges($this->archers, [], $this->root));
    }

    public function testANonArrayReturnIsIgnored(): void
    {
        $this->provider('Weird', <<<'PHP'
$GLOBALS['LANEASSIST_BADGE_PROVIDERS']['Weird'] = function ($archers, $context) {
    return 'not an array';
};
PHP);
        $this->assertSame([], laneAssistCollectBadges($this->archers, [], $this->root));
    }

    public function testBadgesFromSeveralProvidersAreConcatenatedPerArcher(): void
    {
        $this->provider('First', <<<'PHP'
$GLOBALS['LANEASSIST_BADGE_PROVIDERS']['First'] = function ($archers, $context) {
    return [1 => [['label' => 'DK Guld', 'color' => '#d4af37']]];
};
PHP);
        $this->provider('Second', <<<'PHP'
$GLOBALS['LANEASSIST_BADGE_PROVIDERS']['Second'] = function ($archers, $context) {
    return [1 => [['label' => 'Club record', 'color' => '#1f5fbf']]];
};
PHP);
        $badges = laneAssistCollectBadges($this->archers, [], $this->root);
        $this->assertCount(2, $badges[1]);
        $this->assertSame(['DK Guld', 'Club record'], array_column($badges[1], 'label'));
    }

    public function testBadgesWithoutALabelAreDropped(): void
    {
        $this->provider('Sloppy', <<<'PHP'
$GLOBALS['LANEASSIST_BADGE_PROVIDERS']['Sloppy'] = function ($archers, $context) {
    return [1 => [['color' => '#000000'], ['label' => 'Fine', 'color' => '#000000'], 'nope']];
};
PHP);
        $badges = laneAssistCollectBadges($this->archers, [], $this->root);
        $this->assertCount(1, $badges[1]);
        $this->assertSame('Fine', $badges[1][0]['label']);
    }

    public function testAMissingKindDefaultsToPill(): void
    {
        $this->provider('Minimal', <<<'PHP'
$GLOBALS['LANEASSIST_BADGE_PROVIDERS']['Minimal'] = function ($archers, $context) {
    return [1 => [['label' => 'PB 570', 'color' => '#e8edf0']]];
};
PHP);
        $badges = laneAssistCollectBadges($this->archers, [], $this->root);
        $this->assertSame('pill', $badges[1][0]['kind'],
            'the simplest possible provider must work');
    }

    public function testTheThreeKindsArePreserved(): void
    {
        $this->provider('Kinds', <<<'PHP'
$GLOBALS['LANEASSIST_BADGE_PROVIDERS']['Kinds'] = function ($archers, $context) {
    return [1 => [
        ['kind' => 'edge', 'label' => 'Guld', 'color' => '#d4af37'],
        ['kind' => 'pill', 'label' => 'PB 570', 'color' => '#e8edf0'],
        ['kind' => 'icon', 'label' => 'Record', 'icon' => 'fa-flag'],
    ]];
};
PHP);
        $badges = laneAssistCollectBadges($this->archers, [], $this->root);
        $this->assertSame(['edge', 'pill', 'icon'], array_column($badges[1], 'kind'));
    }

    public function testAnUnknownKindIsDropped(): void
    {
        $this->provider('Future', <<<'PHP'
$GLOBALS['LANEASSIST_BADGE_PROVIDERS']['Future'] = function ($archers, $context) {
    return [1 => [
        ['kind' => 'hologram', 'label' => 'Shiny', 'color' => '#000000'],
        ['kind' => 'pill', 'label' => 'Fine', 'color' => '#000000'],
    ]];
};
PHP);
        $badges = laneAssistCollectBadges($this->archers, [], $this->root);
        $this->assertCount(1, $badges[1], 'an unknown kind must not be guessed at');
        $this->assertSame('Fine', $badges[1][0]['label']);
    }

    public function testAnIconBadgeNeedsASafeFontAwesomeName(): void
    {
        $this->provider('Icons', <<<'PHP'
$GLOBALS['LANEASSIST_BADGE_PROVIDERS']['Icons'] = function ($archers, $context) {
    return [1 => [
        ['kind' => 'icon', 'label' => 'ok', 'icon' => 'fa-trophy'],
        ['kind' => 'icon', 'label' => 'no name', 'icon' => ''],
        ['kind' => 'icon', 'label' => 'wrong prefix', 'icon' => 'trophy'],
        ['kind' => 'icon', 'label' => 'injection', 'icon' => 'fa-x" onload="alert(1)'],
    ]];
};
PHP);
        $badges = laneAssistCollectBadges($this->archers, [], $this->root);
        $this->assertCount(1, $badges[1]);
        $this->assertSame('fa-trophy', $badges[1][0]['icon']);
    }
}
