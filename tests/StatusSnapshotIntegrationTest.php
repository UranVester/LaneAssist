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
        safe_w_sql('DELETE FROM Session WHERE SesTournament=' . StrSafe_DB(self::SENTINEL) . " AND SesType='Q' AND SesOrder=1");
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
        self::seedRow('Session', [
            'SesTournament' => self::SENTINEL, 'SesType' => 'Q',
            'SesOrder' => 1, 'SesFirstTarget' => 1, 'SesTar4Session' => 10,
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
}
