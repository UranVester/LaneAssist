<?php
require_once __DIR__ . '/LaneAssistDbTestCase.php';

/**
 * DB integration test for LiveView/api.php's applySessionDefaults() and
 * pullClubLogo() write actions.
 *
 * Writes real rows to the local ianseo DB under sentinel TourId 999999 and
 * deletes them in teardown. Skips entirely if the IANSEO DB layer cannot be
 * bootstrapped. DO NOT run against a production database.
 *
 * Session has no SesId column -- its real primary key is the composite
 * (SesTournament, SesOrder, SesType), confirmed against Install/install.sql
 * and against Common/Fun_Sessions.inc.php's GetSessions(), which synthesizes
 * `$tmp->Id = $row->SesOrder.'_'.$row->SesType` (e.g. "1_Q"). That exact
 * string is what statusChecklistItems() puts into the "sessions without
 * times" checklist item's fix.params.sessionId, so applySessionDefaults()
 * takes that composite string, not a bare int.
 */
final class StatusWriteActionsIntegrationTest extends LaneAssistDbTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (function_exists('applySessionDefaultsForTest')) {
            return;
        }

        $root = dirname(__DIR__, 4);
        $previousCwd = getcwd();
        // Common/Fun_Sessions.inc.php require_once()s Common/Lib/Fun_Phases.inc.php
        // using a path relative to the IANSEO root (not __DIR__), so it only
        // resolves when the working directory actually is that root.
        chdir($root);
        try {
            require_once $root . '/Common/Lib/ArrTargets.inc.php';
            require_once $root . '/Common/Lib/Fun_Phases.inc.php';
            require_once $root . '/Common/Fun_Sessions.inc.php';
        } finally {
            chdir($previousCwd);
        }

        require_once dirname(__DIR__) . '/Common/live-view-logic.php';
        require_once dirname(__DIR__) . '/Common/status-logic.php';
        if (!defined('LANEASSIST_LIVEVIEW_API_TEST_MODE')) {
            define('LANEASSIST_LIVEVIEW_API_TEST_MODE', true);
        }
        require_once dirname(__DIR__) . '/LiveView/api.php';
    }

    protected static function cleanupSentinel(): void
    {
        parent::cleanupSentinel();
        // Session has no SesId column, so it isn't covered by the generic
        // $tables-driven cleanup above. Remove this suite's own rows by
        // their known composite key instead (both here, in case a prior run
        // crashed mid-test, and again in tearDownAfterClass).
        safe_w_sql('DELETE FROM Session WHERE SesTournament=' . StrSafe_DB(self::SENTINEL)
            . " AND SesType='Q' AND SesOrder IN (1, 2)");
    }

    public function testApplySessionDefaultsSetsSessionTimeFromTournamentStart(): void
    {
        self::seedRow('Tournament', ['ToId' => self::SENTINEL, 'ToWhenFrom' => '2026-08-30']);
        self::seedRow('Session', [
            'SesTournament' => self::SENTINEL, 'SesType' => 'Q',
            'SesOrder' => 1, 'SesDtStart' => '0000-00-00 00:00:00', 'SesDtEnd' => '0000-00-00 00:00:00',
        ]);

        applySessionDefaultsForTest('1_Q');

        $row = safe_fetch(safe_r_sql("SELECT SesDtStart FROM Session WHERE SesTournament="
            . self::SENTINEL . " AND SesType='Q' AND SesOrder=1"));
        $this->assertStringStartsWith('2026-08-30', (string)$row->SesDtStart);
    }

    public function testApplySessionDefaultsIsIdempotent(): void
    {
        self::seedRow('Tournament', ['ToId' => self::SENTINEL, 'ToWhenFrom' => '2026-08-30']);
        self::seedRow('Session', [
            'SesTournament' => self::SENTINEL, 'SesType' => 'Q',
            'SesOrder' => 2, 'SesDtStart' => '0000-00-00 00:00:00', 'SesDtEnd' => '0000-00-00 00:00:00',
        ]);

        applySessionDefaultsForTest('2_Q');
        applySessionDefaultsForTest('2_Q');

        $row = safe_fetch(safe_r_sql("SELECT SesDtStart FROM Session WHERE SesTournament="
            . self::SENTINEL . " AND SesType='Q' AND SesOrder=2"));
        $this->assertStringStartsWith('2026-08-30', (string)$row->SesDtStart);
    }

    public function testPullClubLogoInsertsIgnoreScopedToOneClub(): void
    {
        self::seedRow('Flags', ['FlCode' => 'ZZZ', 'FlTournament' => -1, 'FlJPG' => 'x.jpg', 'FlSVG' => '']);

        pullClubLogoForTest('ZZZ');

        $row = safe_fetch(safe_r_sql("SELECT FlJPG FROM Flags WHERE FlCode='ZZZ' AND FlTournament=" . self::SENTINEL));
        $this->assertSame('x.jpg', (string)$row->FlJPG);
    }

    public function testPullClubLogoIsIdempotentOnSecondClick(): void
    {
        self::seedRow('Flags', ['FlCode' => 'YYY', 'FlTournament' => -1, 'FlJPG' => 'y.jpg', 'FlSVG' => '']);

        pullClubLogoForTest('YYY');
        pullClubLogoForTest('YYY'); // must not error or duplicate

        $count = 0;
        $rs = safe_r_sql("SELECT FlCode FROM Flags WHERE FlCode='YYY' AND FlTournament=" . self::SENTINEL);
        while (safe_fetch($rs)) { $count++; }
        $this->assertSame(1, $count);
    }
}
