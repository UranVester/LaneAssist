<?php
require_once __DIR__ . '/LaneAssistDbTestCase.php';

/**
 * DB integration test for getProjectedFinalistsForEvent.
 *
 * Writes real rows to the local ianseo DB under sentinel TourId 999999 and
 * deletes them in teardown. Skips entirely if the IANSEO DB layer cannot be
 * bootstrapped. DO NOT run against a production database.
 */
final class ProjectedFinalistsIntegrationTest extends LaneAssistDbTestCase
{
    // ---------- Individual event scenarios ----------

    public function testIndividualPrimaryCountsFlaggedEntrants(): void
    {
        // Use sentinel class 'XX' (not a real competition class) to prevent
        // cross-contamination: the JOIN filters ec.EcClass=e.EnClass, so only
        // entries in class XX match this EventClass row.
        self::seedRow('EventClass', [
            'EcCode' => 'TIP', 'EcTournament' => self::SENTINEL,
            'EcClass' => 'XX', 'EcDivision' => 'R', 'EcSubClass' => '',
            'EcExtraAddons' => 0, 'EcTeamEvent' => 0,
        ]);
        for ($i = 1; $i <= 3; $i++) {
            self::seedRow('Entries', [
                'EnId' => 990000 + $i, 'EnTournament' => self::SENTINEL,
                'EnDivision' => 'R', 'EnClass' => 'XX', 'EnCode' => "P$i",
                'EnName' => 'T', 'EnFirstName' => 'T', 'EnIndFEvent' => 1,
            ]);
        }
        // 3 flagged entrants, no numQualified cap.
        $this->assertSame(3, getProjectedFinalistsForEvent('TIP', 0, 0));
    }

    public function testIndividualFallbackCountsRegisteredWhenNoneFlagged(): void
    {
        // Use sentinel class 'XY' to isolate from test 1's XX entries.
        // Seed only EnIndFEvent=0 entries so the primary query (EnIndFEvent=1)
        // returns 0, triggering the fallback path that counts all registered
        // entrants in the matching class+division.
        self::seedRow('EventClass', [
            'EcCode' => 'TIF', 'EcTournament' => self::SENTINEL,
            'EcClass' => 'XY', 'EcDivision' => 'R', 'EcSubClass' => '',
            'EcExtraAddons' => 0, 'EcTeamEvent' => 0,
        ]);
        for ($i = 1; $i <= 4; $i++) {
            self::seedRow('Entries', [
                'EnId' => 991000 + $i, 'EnTournament' => self::SENTINEL,
                'EnDivision' => 'R', 'EnClass' => 'XY', 'EnCode' => "F$i",
                'EnName' => 'T', 'EnFirstName' => 'T', 'EnIndFEvent' => 0,
            ]);
        }
        // Primary (EnIndFEvent=1) yields 0 -> fallback counts registered entrants in class.
        $this->assertSame(4, getProjectedFinalistsForEvent('TIF', 0, 0));
    }

    public function testIndividualNumQualifiedCaps(): void
    {
        // Use sentinel class 'XZ' to isolate from tests 1 and 2.
        self::seedRow('EventClass', [
            'EcCode' => 'TIC', 'EcTournament' => self::SENTINEL,
            'EcClass' => 'XZ', 'EcDivision' => 'R', 'EcSubClass' => '',
            'EcExtraAddons' => 0, 'EcTeamEvent' => 0,
        ]);
        for ($i = 1; $i <= 30; $i++) {
            self::seedRow('Entries', [
                'EnId' => 992000 + $i, 'EnTournament' => self::SENTINEL,
                'EnDivision' => 'R', 'EnClass' => 'XZ', 'EnCode' => "C$i",
                'EnName' => 'T', 'EnFirstName' => 'T', 'EnIndFEvent' => 1,
            ]);
        }
        // 30 flagged, cap 8 -> 8 (distinct event code / numQualified avoids the static cache).
        $this->assertSame(8, getProjectedFinalistsForEvent('TIC', 0, 8));
        // Same data, no cap -> 30.
        $this->assertSame(30, getProjectedFinalistsForEvent('TIC', 0, 0));
    }

    // ---------- Team event scenarios ----------

    /** Seed one team (coId/subTeam) with a finals-flagged component entry. */
    private function seedTeamWithComponent(string $event, int $coId, int $subTeam, int $entryId, int $teFinEvent, int $enTeamFEvent): void
    {
        self::seedRow('Teams', [
            'TeCoId' => $coId, 'TeSubTeam' => $subTeam, 'TeEvent' => $event,
            'TeTournament' => self::SENTINEL, 'TeFinEvent' => $teFinEvent, 'TeSO' => 1,
        ]);
        self::seedRow('Entries', [
            'EnId' => $entryId, 'EnTournament' => self::SENTINEL,
            'EnDivision' => 'R', 'EnClass' => 'CM', 'EnCode' => "TC$entryId",
            'EnName' => 'T', 'EnFirstName' => 'T', 'EnTeamFEvent' => $enTeamFEvent,
        ]);
        self::seedRow('TeamFinComponent', [
            'TfcCoId' => $coId, 'TfcSubTeam' => $subTeam, 'TfcTournament' => self::SENTINEL,
            'TfcEvent' => $event, 'TfcId' => $entryId,
        ]);
    }

    public function testTeamPrimaryCountsFinalsFlaggedTeams(): void
    {
        self::seedRow('Events', [
            'EvCode' => 'TTP', 'EvTeamEvent' => 1, 'EvTournament' => self::SENTINEL,
            'EvEventName' => 'T', 'EvMixedTeam' => 0,
        ]);
        // Two distinct teams, each with a finals-flagged (EnTeamFEvent=1) component.
        $this->seedTeamWithComponent('TTP', 5001, 0, 993001, 1, 1);
        $this->seedTeamWithComponent('TTP', 5002, 0, 993002, 1, 1);
        $this->assertSame(2, getProjectedFinalistsForEvent('TTP', 1, 0));
    }

    public function testTeamFallbackCountsWhenFinalsFlagsCleared(): void
    {
        self::seedRow('Events', [
            'EvCode' => 'TTF', 'EvTeamEvent' => 1, 'EvTournament' => self::SENTINEL,
            'EvEventName' => 'T', 'EvMixedTeam' => 0,
        ]);
        // TeFinEvent=0 so primary yields 0; components exist so fallback counts team entries.
        $this->seedTeamWithComponent('TTF', 5101, 0, 994001, 0, 1);
        $this->seedTeamWithComponent('TTF', 5102, 0, 994002, 0, 1);
        $this->assertSame(2, getProjectedFinalistsForEvent('TTF', 1, 0));
    }

    public function testTeamDirectFallbackCountsFlaggedTeamsWithoutComponents(): void
    {
        self::seedRow('Events', [
            'EvCode' => 'TTD', 'EvTeamEvent' => 1, 'EvTournament' => self::SENTINEL,
            'EvEventName' => 'T', 'EvMixedTeam' => 0,
        ]);
        // TeFinEvent=1, TeSO!=0, NO TeamFinComponent -> primary & first fallback yield 0,
        // direct fallback counts the flagged teams.
        self::seedRow('Teams', [
            'TeCoId' => 5201, 'TeSubTeam' => 0, 'TeEvent' => 'TTD',
            'TeTournament' => self::SENTINEL, 'TeFinEvent' => 1, 'TeSO' => 1,
        ]);
        self::seedRow('Teams', [
            'TeCoId' => 5202, 'TeSubTeam' => 0, 'TeEvent' => 'TTD',
            'TeTournament' => self::SENTINEL, 'TeFinEvent' => 1, 'TeSO' => 1,
        ]);
        $this->assertSame(2, getProjectedFinalistsForEvent('TTD', 1, 0));
    }

    public function testTeamEventWithNoTeamsProjectsZero(): void
    {
        // Regression (arhS26): a team event whose bracket is configured but which
        // nobody entered. All three team fallbacks must yield 0 rather than a
        // non-zero "just in case" figure -- ManageFinals relies on the hard 0 to
        // keep the phantom bracket out of the unassigned pool.
        self::seedRow('Events', [
            'EvCode' => 'TTZ', 'EvTeamEvent' => 1, 'EvTournament' => self::SENTINEL,
            'EvEventName' => 'T', 'EvMixedTeam' => 0, 'EvFinalFirstPhase' => 8,
            'EvNumQualified' => 16,
        ]);
        self::seedRow('EventClass', [
            'EcCode' => 'TTZ', 'EcTournament' => self::SENTINEL,
            'EcClass' => 'ZT', 'EcDivision' => 'R', 'EcSubClass' => '',
            'EcExtraAddons' => 0, 'EcTeamEvent' => 1,
        ]);
        // No Teams rows at all for TTZ.
        $this->assertSame(0, getProjectedFinalistsForEvent('TTZ', 1, 16));
        // The numQualified cap must not manufacture entrants either.
        $this->assertSame(0, getProjectedFinalistsForEvent('TTZ', 1, 0));
    }
}
