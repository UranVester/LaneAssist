<?php
require_once __DIR__ . '/LaneAssistDbTestCase.php';

/**
 * DB integration test for LiveView/api.php's statusSnapshot() assembly.
 *
 * Writes real rows to the local ianseo DB under sentinel TourId 999999 and
 * deletes them in teardown. Skips entirely if the IANSEO DB layer cannot be
 * bootstrapped. DO NOT run against a production database.
 *
 * statusChecklistItems() (and statusSnapshot()/statusTournamentStage()) are
 * defined in LiveView/api.php, which is normally a web-request entry point:
 * requiring it runs config.php (starts a real HTTP session) and then
 * CheckTourSession()/checkFullACL(), either of which exit()/die() outside a
 * real IANSEO request. To make the functions it defines callable from here,
 * this test defines LANEASSIST_LIVEVIEW_API_TEST_MODE before requiring the
 * file; api.php's own request-handling top section checks that constant and
 * skips itself when it's set, while the function definitions below it are
 * unaffected and still get declared. This test pre-loads, by hand, exactly
 * the dependencies that top section would otherwise have required.
 */
final class StatusSnapshotIntegrationTest extends LaneAssistDbTestCase
{
    /** EnId used by the withdrawn-entry scenario; also Qualifications.QuId. */
    private const WITHDRAWN_ENTRY_ID = 992002;
    /** EnId used by the non-athlete scenario; also Qualifications.QuId. */
    private const NON_ATHLETE_ENTRY_ID = 992003;
    /** EnId used by the session-0 (not yet assigned) scenario; also Qualifications.QuId. */
    private const SESSION_ZERO_ENTRY_ID = 992004;

    protected static function cleanupSentinel(): void
    {
        parent::cleanupSentinel();
        // Session has no SesId column and Qualifications has no QuTournament
        // column (QuId IS Entries.EnId; tournament scoping is implicit
        // through that join), so neither is covered by the generic
        // $tables-driven cleanup above. Remove this suite's own rows by
        // their known keys instead -- both here (in case a prior run
        // crashed mid-test) and this same method runs again in
        // tearDownAfterClass.
        safe_w_sql('DELETE FROM Qualifications WHERE QuId IN ('
            . StrSafe_DB(self::WITHDRAWN_ENTRY_ID) . ',' . StrSafe_DB(self::NON_ATHLETE_ENTRY_ID)
            . ',' . StrSafe_DB(self::SESSION_ZERO_ENTRY_ID) . ')');
        safe_w_sql('DELETE FROM Session WHERE SesTournament=' . StrSafe_DB(self::SENTINEL)
            . " AND SesType IN ('Q','F') AND SesOrder IN (1, 2, 3, 4, 5, 6)");
        // DistanceInformation has no DiTournament-only cleanup in the generic
        // $tables map (it isn't listed there), and its primary key is
        // composite (DiTournament, DiSession, DiDistance, DiType).
        safe_w_sql('DELETE FROM DistanceInformation WHERE DiTournament=' . StrSafe_DB(self::SENTINEL));
        // Countries is per-tournament reference data (has its own CoTournament
        // column), unlike Grids -- safe to seed/delete scoped to the sentinel.
        safe_w_sql('DELETE FROM Countries WHERE CoTournament=' . StrSafe_DB(self::SENTINEL));
        // The club-logo tests below seed one FITA-style global (FlTournament=-1)
        // row under a code only this suite uses, so it isn't covered by the
        // generic $tables cleanup (which never touches global Flags rows).
        safe_w_sql("DELETE FROM Flags WHERE FlTournament=" . StrSafe_DB(self::SENTINEL)
            . " OR (FlTournament=-1 AND FlCode='FIX')");
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (function_exists('statusChecklistItems')) {
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
        define('LANEASSIST_LIVEVIEW_API_TEST_MODE', true);
        require_once dirname(__DIR__) . '/LiveView/api.php';
    }

    public function testParticipantsItemReportsZeroWhenNoneEntered(): void
    {
        $items = statusChecklistItems();
        $participants = null;
        foreach ($items as $item) {
            if ($item['key'] === 'participants') {
                $participants = $item;
                break;
            }
        }

        $this->assertNotNull($participants);
        $this->assertSame('danger', $participants['severity']);
        $this->assertStringContainsString('No participants entered yet', $participants['detail']);
    }

    public function testParticipantsItemCountsEnteredAthletes(): void
    {
        self::seedRow('Entries', [
            'EnId' => 992001, 'EnTournament' => self::SENTINEL,
            'EnDivision' => 'R', 'EnClass' => 'XZ', 'EnCode' => 'S1',
            'EnName' => 'T', 'EnFirstName' => 'T', 'EnAthlete' => 1,
        ]);

        $items = statusChecklistItems();
        $participants = null;
        foreach ($items as $item) {
            if ($item['key'] === 'participants') {
                $participants = $item;
                break;
            }
        }

        $this->assertSame('info', $participants['severity']);
        $this->assertStringContainsString('1 participant', $participants['detail']);
    }

    public function testUnassignedArchersExcludesWithdrawnEntries(): void
    {
        // Session has no SesId column; (SesTournament, SesOrder, SesType) is
        // its primary key.
        self::seedRow('Session', [
            'SesTournament' => self::SENTINEL, 'SesType' => 'Q',
            'SesOrder' => 1, 'SesFirstTarget' => 1, 'SesTar4Session' => 10,
            'SesDtStart' => '2026-08-30 09:00:00', 'SesDtEnd' => '2026-08-30 12:00:00',
        ]);
        // EnStatus=6 ("Pull Out") is the real withdrawn status; this must be
        // excluded from the unassigned-targets check even though it has no
        // target/letter assigned. EnStatus=1 ("Can Participate") is still an
        // active, countable entrant and must NOT be excluded -- that's the
        // same convention qualificationSnapshot() already uses one function
        // away (`EnAthlete=1 AND EnStatus<=1`) and every core
        // Common/Rank/Obj_Rank_*.php ranking file uses too.
        self::seedRow('Entries', [
            'EnId' => self::WITHDRAWN_ENTRY_ID, 'EnTournament' => self::SENTINEL,
            'EnDivision' => 'R', 'EnClass' => 'XZ', 'EnCode' => 'S2',
            'EnName' => 'T', 'EnFirstName' => 'T', 'EnAthlete' => 1, 'EnStatus' => 6,
        ]);
        // Qualifications has no QuTournament column: QuId is Entries.EnId, so
        // tournament scoping is implicit through that join (see api.php).
        // QuTarget is an int column; 0 is the "no target" sentinel used
        // elsewhere (qualificationSnapshot()'s `QuTarget<>'0'` check).
        self::seedRow('Qualifications', [
            'QuId' => self::WITHDRAWN_ENTRY_ID, 'QuSession' => 1,
            'QuTarget' => 0, 'QuLetter' => '',
        ]);

        $items = statusChecklistItems();
        $unassigned = null;
        foreach ($items as $item) {
            if ($item['key'] === 'unassignedTargets_1') {
                $unassigned = $item;
                break;
            }
        }

        $this->assertNull($unassigned, 'A withdrawn entry with no target must not be counted as unassigned');
    }

    public function testUnassignedArchersExcludesNonAthletesAndSessionZeroEntries(): void
    {
        // A distinct SesOrder (2, not 1) from testUnassignedArchersExcludesWithdrawnEntries's
        // seed above: Session's primary key is (SesTournament, SesOrder, SesType), and both
        // tests run in the same class/process, so reusing SesOrder=1 here would hit a
        // duplicate-key INSERT the second time either test runs.
        self::seedRow('Session', [
            'SesTournament' => self::SENTINEL, 'SesType' => 'Q',
            'SesOrder' => 2, 'SesFirstTarget' => 1, 'SesTar4Session' => 10,
            'SesDtStart' => '2026-08-30 09:00:00', 'SesDtEnd' => '2026-08-30 12:00:00',
        ]);

        // A non-athlete (e.g. a captain-only registration) with no target or
        // letter must not be counted: the target-assignment check only
        // concerns athletes who actually shoot, same as
        // qualificationSnapshot()'s own `EnAthlete=1` filter.
        self::seedRow('Entries', [
            'EnId' => self::NON_ATHLETE_ENTRY_ID, 'EnTournament' => self::SENTINEL,
            'EnDivision' => 'R', 'EnClass' => 'XZ', 'EnCode' => 'S3',
            'EnName' => 'T', 'EnFirstName' => 'T', 'EnAthlete' => 0,
        ]);
        self::seedRow('Qualifications', [
            'QuId' => self::NON_ATHLETE_ENTRY_ID, 'QuSession' => 1,
            'QuTarget' => 0, 'QuLetter' => '',
        ]);

        // QuSession=0 means "not yet assigned to any session at all", which is
        // distinct from "assigned to session 1 but missing target/letter",
        // and must not be folded into session 1's unassigned count.
        self::seedRow('Entries', [
            'EnId' => self::SESSION_ZERO_ENTRY_ID, 'EnTournament' => self::SENTINEL,
            'EnDivision' => 'R', 'EnClass' => 'XZ', 'EnCode' => 'S4',
            'EnName' => 'T', 'EnFirstName' => 'T', 'EnAthlete' => 1,
        ]);
        self::seedRow('Qualifications', [
            'QuId' => self::SESSION_ZERO_ENTRY_ID, 'QuSession' => 0,
            'QuTarget' => 0, 'QuLetter' => '',
        ]);

        $items = statusChecklistItems();
        foreach (['unassignedTargets_0', 'unassignedTargets_1'] as $key) {
            $match = null;
            foreach ($items as $item) {
                if ($item['key'] === $key) {
                    $match = $item;
                    break;
                }
            }
            $this->assertNull($match, "$key must not appear: only a non-athlete and a session-0 entry are present");
        }
    }

    public function testSessionsWithoutTimesIgnoresNonQualificationSessionAtSameOrder(): void
    {
        // Regression: GetSessions() with no type filter returned this
        // Final-type row and a correctly-timed Qualification row at the
        // same SesOrder together, so the pair got flagged as "no time set"
        // even though the Qualification session the user actually sees has
        // a real start time. statusChecklistItems() must scope to 'Q' only.
        self::seedRow('Session', [
            'SesTournament' => self::SENTINEL, 'SesType' => 'Q',
            'SesOrder' => 3, 'SesDtStart' => '2026-08-30 09:00:00', 'SesDtEnd' => '2026-08-30 12:00:00',
        ]);
        self::seedRow('Session', [
            'SesTournament' => self::SENTINEL, 'SesType' => 'F',
            'SesOrder' => 3, 'SesDtStart' => '0000-00-00 00:00:00', 'SesDtEnd' => '0000-00-00 00:00:00',
        ]);

        $items = statusChecklistItems();
        $match = null;
        foreach ($items as $item) {
            if ($item['key'] === 'sessionsWithoutTimes') {
                $match = $item;
                break;
            }
        }

        $this->assertNull($match, 'A same-order Final session must not make a correctly-timed Qualification session get flagged');
    }

    public function testSessionsWithoutTimesCollapsesAllFlaggedSessionsIntoOneBulkFix(): void
    {
        self::seedRow('Session', [
            'SesTournament' => self::SENTINEL, 'SesType' => 'Q',
            'SesOrder' => 4, 'SesDtStart' => '0000-00-00 00:00:00', 'SesDtEnd' => '0000-00-00 00:00:00',
        ]);
        self::seedRow('Session', [
            'SesTournament' => self::SENTINEL, 'SesType' => 'Q',
            'SesOrder' => 5, 'SesDtStart' => '0000-00-00 00:00:00', 'SesDtEnd' => '0000-00-00 00:00:00',
        ]);

        $items = statusChecklistItems();
        $match = null;
        foreach ($items as $item) {
            if ($item['key'] === 'sessionsWithoutTimes') {
                $match = $item;
                break;
            }
        }

        $this->assertNotNull($match, 'One collapsed card must cover every flagged session');
        $this->assertStringContainsString('4', $match['detail']);
        $this->assertStringContainsString('5', $match['detail']);
        $this->assertSame('applySessionDefaults', $match['fix']['action']);
        $this->assertSame(['4_Q', '5_Q'], $match['fix']['params']['sessionId']);
    }

    public function testSessionsWithoutTimesIgnoresASessionWithARealDistanceTime(): void
    {
        // Regression: Tournament/ManSessions_kiss.php (the simplified session
        // UI for multi-distance Qualification rounds) saves per-distance start
        // times into DistanceInformation and never backfills Session's own
        // SesDtStart, which stays at the zero-date sentinel forever even
        // though the tournament has real, admin-set times.
        self::seedRow('Session', [
            'SesTournament' => self::SENTINEL, 'SesType' => 'Q',
            'SesOrder' => 6, 'SesDtStart' => '0000-00-00 00:00:00', 'SesDtEnd' => '0000-00-00 00:00:00',
        ]);
        self::seedRow('DistanceInformation', [
            'DiTournament' => self::SENTINEL, 'DiSession' => 6, 'DiDistance' => 1, 'DiType' => 'Q',
            'DiDay' => '2026-08-30', 'DiStart' => '09:00:00',
        ]);

        $items = statusChecklistItems();
        $match = null;
        foreach ($items as $item) {
            if ($item['key'] === 'sessionsWithoutTimes') {
                $match = $item;
                break;
            }
        }

        // Not asserting $match===null: an earlier test in this class (orders
        // 4 and 5) leaves its own flagged sessions in place until
        // tearDownAfterClass, so a card may legitimately still exist here.
        // Only session 6, this test's own session, must be excluded from it.
        $flaggedIds = $match['fix']['params']['sessionId'] ?? [];
        $this->assertNotContains('6_Q', $flaggedIds, 'A session with a real distance time must not be flagged');
    }

    public function testClubLogosSplitIntoFixableAndMissingCards(): void
    {
        self::seedRow('Countries', [
            'CoId' => 999901, 'CoTournament' => self::SENTINEL,
            'CoIocCode' => 'FIX', 'CoCode' => 'FIX', 'CoName' => 'Fixable',
        ]);
        self::seedRow('Countries', [
            'CoId' => 999902, 'CoTournament' => self::SENTINEL,
            'CoIocCode' => 'MIS', 'CoCode' => 'MIS', 'CoName' => 'Missing',
        ]);
        self::seedRow('Entries', [
            'EnId' => 999901, 'EnTournament' => self::SENTINEL, 'EnCountry' => 999901,
            'EnDivision' => 'R', 'EnClass' => 'XZ', 'EnCode' => 'F1',
            'EnName' => 'T', 'EnFirstName' => 'T', 'EnAthlete' => 1,
        ]);
        self::seedRow('Entries', [
            'EnId' => 999902, 'EnTournament' => self::SENTINEL, 'EnCountry' => 999902,
            'EnDivision' => 'R', 'EnClass' => 'XZ', 'EnCode' => 'F2',
            'EnName' => 'T', 'EnFirstName' => 'T', 'EnAthlete' => 1,
        ]);
        // FIX has a global (FlTournament=-1) logo available to pull in; MIS has none anywhere.
        self::seedRow('Flags', ['FlCode' => 'FIX', 'FlTournament' => -1, 'FlJPG' => 'f.jpg', 'FlSVG' => '']);

        $items = statusChecklistItems();
        $fixable = null;
        $missing = null;
        foreach ($items as $item) {
            if ($item['key'] === 'clubLogosFixable') {
                $fixable = $item;
            }
            if ($item['key'] === 'clubLogosMissing') {
                $missing = $item;
            }
        }

        $this->assertNotNull($fixable, 'A single bulk-fixable card must list FIX');
        $this->assertStringContainsString('FIX', $fixable['detail']);
        $this->assertSame('pullClubLogo', $fixable['fix']['action']);
        $this->assertSame(['FIX'], $fixable['fix']['params']['clubCode']);

        $this->assertNotNull($missing, 'A single missing-logos card must list MIS and link to Countries.php');
        $this->assertStringContainsString('MIS', $missing['detail']);
        $this->assertStringEndsWith('Tournament/Countries.php', $missing['link']);
        $this->assertNull($missing['fix']);
    }
}
