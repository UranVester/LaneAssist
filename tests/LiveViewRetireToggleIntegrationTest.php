<?php
require_once __DIR__ . '/LaneAssistDbTestCase.php';
require_once dirname(__DIR__) . '/Common/live-view-logic.php';

/**
 * DB integration test for performArcherRetireToggle — the per-archer
 * validation+update step shared by the single-archer and bulk forfeit
 * endpoints in LiveView/api.php. Writes real rows to the local ianseo DB
 * under sentinel TourId 999999 and deletes them in teardown. Skips entirely
 * if the IANSEO DB layer cannot be bootstrapped. DO NOT run against a
 * production database.
 *
 * Deliberately does not exercise toggleArcherRetiredBulk() or
 * recalcRanksAndTeamsAfterRetireToggle(): CalcRank/MakeTeams/MakeTeamsAbs are
 * tournament-wide functions that expect a fully-built tournament (categories,
 * disciplines, distances, ...) with no equivalent fixture in this suite yet.
 * The batching behaviour they're needed for (recalc runs once per bulk
 * request, not once per archer) is a one-line code fact in
 * toggleArcherRetiredBulk() — the loop calls performArcherRetireToggle() for
 * every id first, and recalcRanksAndTeamsAfterRetireToggle() only after —
 * and was confirmed end-to-end against a real tournament in the UI.
 */
final class LiveViewRetireToggleIntegrationTest extends LaneAssistDbTestCase
{
    private const QUAL_ID_BASE = 995000;

    protected static function cleanupSentinel(): void
    {
        parent::cleanupSentinel();
        safe_w_sql('DELETE FROM Qualifications WHERE QuId BETWEEN '
            . self::QUAL_ID_BASE . ' AND ' . (self::QUAL_ID_BASE + 999));
    }

    private static function seedArcher(int $enId, int $status, string $arrowString = ''): void
    {
        self::seedRow('Entries', [
            'EnId' => $enId, 'EnTournament' => self::SENTINEL,
            'EnCode' => 'RT' . $enId, 'EnName' => 'Archer', 'EnFirstName' => 'Test',
            'EnAthlete' => 1, 'EnStatus' => $status,
        ]);
        self::seedRow('Qualifications', [
            'QuId' => $enId, 'QuSession' => 1, 'QuTarget' => 1, 'QuLetter' => 'A',
            'QuD1Arrowstring' => $arrowString,
        ]);
    }

    private static function currentStatus(int $enId): int
    {
        $row = safe_fetch(safe_r_sql('SELECT EnStatus FROM Entries WHERE EnId=' . $enId));
        return intval($row->EnStatus);
    }

    public function testForfeitsAnArcherWithNoRecordedArrows(): void
    {
        $enId = self::QUAL_ID_BASE + 1;
        self::seedArcher($enId, 1);

        $result = performArcherRetireToggle($enId, 1, self::SENTINEL);

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['retired']);
        $this->assertSame(6, self::currentStatus($enId));
    }

    public function testRefusesAnArcherWithRecordedArrowsAndLeavesStatusUnchanged(): void
    {
        $enId = self::QUAL_ID_BASE + 2;
        self::seedArcher($enId, 1, '987X');

        $result = performArcherRetireToggle($enId, 1, self::SENTINEL);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('recorded arrows', $result['message']);
        $this->assertSame(1, self::currentStatus($enId));
    }

    public function testRefusesAnUnknownParticipant(): void
    {
        $result = performArcherRetireToggle(self::QUAL_ID_BASE + 999, 1, self::SENTINEL);

        $this->assertFalse($result['ok']);
        $this->assertSame('Participant not found', $result['message']);
    }

    public function testBatchOfMixedArchersForfeitsEligibleOnesAndReportsTheRest(): void
    {
        // Mirrors what toggleArcherRetiredBulk()'s loop does: call
        // performArcherRetireToggle() once per id and collect results, without
        // recalculating ranks in between.
        $eligible1 = self::QUAL_ID_BASE + 3;
        $eligible2 = self::QUAL_ID_BASE + 4;
        $ineligible = self::QUAL_ID_BASE + 5;
        self::seedArcher($eligible1, 1);
        self::seedArcher($eligible2, 1);
        self::seedArcher($ineligible, 1, '987X');

        $results = [];
        $succeeded = 0;
        foreach ([$eligible1, $eligible2, $ineligible] as $enId) {
            $result = performArcherRetireToggle($enId, 1, self::SENTINEL);
            $results[] = $result;
            if ($result['ok']) {
                $succeeded++;
            }
        }

        $this->assertSame(2, $succeeded);
        $this->assertSame(6, self::currentStatus($eligible1));
        $this->assertSame(6, self::currentStatus($eligible2));
        $this->assertSame(1, self::currentStatus($ineligible));
        $this->assertFalse($results[2]['ok']);
    }
}
