<?php

require_once __DIR__ . '/LaneAssistDbTestCase.php';

/**
 * Regression test for PR #1: getCurrent() must synthesize bracket matches that
 * are NOT yet in FinSchedule, even when SOME matches already are (partial
 * schedule). On pre-PR#1 code these tests fail (synthesis only fired on a fully
 * empty FinSchedule). Writes under sentinel TourId 999999; skips without a DB
 * or without Grids reference data. DO NOT run against production.
 */
final class PartialScheduleSynthesisTest extends LaneAssistDbTestCase
{
    /** The full bracket matchNo set for EvFinalFirstPhase=2 (phases 0,1,2). */
    private const BRACKET_MATCHNOS = [0, 1, 2, 3, 4, 5, 6, 7];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        // Grids is global reference data we must not seed. Skip if it lacks the
        // bracket rows the synthesis query needs (phases 0,1,2).
        $rs = safe_r_sql("SELECT COUNT(*) AS n FROM Grids WHERE GrPhase IN (0,1,2)");
        $row = safe_fetch($rs);
        if (!$row || intval($row->n) < count(self::BRACKET_MATCHNOS)) {
            self::markTestSkipped('Grids bracket definitions unavailable');
        }
    }

    /** Set $_REQUEST, call getCurrent(), capture & decode the echoed JSON, restore $_REQUEST. */
    private function callGetCurrent(array $request): array
    {
        $saved = $_REQUEST;
        $_REQUEST = $request;
        try {
            ob_start();
            getCurrent();
            $out = ob_get_clean();
        } finally {
            $_REQUEST = $saved;
        }
        $decoded = json_decode($out, true);
        $this->assertIsArray($decoded, 'getCurrent did not emit valid JSON');
        $this->assertSame(0, $decoded['error'] ?? null, 'getCurrent returned an error envelope');
        return $decoded;
    }

    /** matchNos present in the response for a given event code. */
    private function matchNosFor(array $response, string $eventCode): array
    {
        $out = [];
        foreach ($response['rows'] ?? [] as $r) {
            if (($r['event'] ?? null) === $eventCode) {
                $out[] = intval($r['matchNo']);
            }
        }
        sort($out);
        return $out;
    }

    private function seedIndividualEvent(string $eventCode): void
    {
        self::seedRow('Events', [
            'EvCode' => $eventCode, 'EvTeamEvent' => 0, 'EvTournament' => self::SENTINEL,
            'EvEventName' => 'PS Ind', 'EvFinalFirstPhase' => 2, 'EvNumQualified' => 0,
        ]);
        self::seedRow('EventClass', [
            'EcCode' => $eventCode, 'EcTournament' => self::SENTINEL,
            'EcClass' => 'PX', 'EcDivision' => 'R', 'EcSubClass' => '',
            'EcExtraAddons' => 0, 'EcTeamEvent' => 0,
        ]);
    }

    private function scheduleMatch(string $eventCode, int $teamEvent, int $matchNo): void
    {
        self::seedRow('FinSchedule', [
            'FSTournament' => self::SENTINEL, 'FSTeamEvent' => $teamEvent,
            'FSEvent' => $eventCode, 'FSMatchNo' => $matchNo, 'FSGroup' => 0,
            'FSTarget' => '0001', 'FSLetter' => '0001',
            'FSScheduledDate' => '2026-08-20', 'FSScheduledLen' => 15,
        ]);
    }

    public function testIndividualPartialScheduleSynthesizesMissingMatches(): void
    {
        $ev = 'PSIP';
        $this->seedIndividualEvent($ev);
        // Schedule 3 of the 8 bracket matches.
        foreach ([0, 1, 2] as $mn) {
            $this->scheduleMatch($ev, 0, $mn);
        }
        $response = $this->callGetCurrent(['teamEvent' => '0']);
        $matchNos = $this->matchNosFor($response, $ev);
        // All 8 appear (3 scheduled + 5 synthesized), each exactly once.
        $this->assertSame(self::BRACKET_MATCHNOS, $matchNos,
            'partial schedule should show scheduled + synthesized matches, no gaps');
        $this->assertSame(count($matchNos), count(array_unique($matchNos)),
            'no match should appear twice');
    }

    public function testIndividualFullScheduleNoDuplicates(): void
    {
        $ev = 'PSIF';
        $this->seedIndividualEvent($ev);
        foreach (self::BRACKET_MATCHNOS as $mn) {
            $this->scheduleMatch($ev, 0, $mn);
        }
        $response = $this->callGetCurrent(['teamEvent' => '0']);
        $matchNos = $this->matchNosFor($response, $ev);
        // Every match appears exactly once; synthesis adds nothing.
        $this->assertSame(self::BRACKET_MATCHNOS, $matchNos);
        $this->assertSame(count($matchNos), count(array_unique($matchNos)), 'no duplicates');
    }

    public function testTeamPartialScheduleSynthesizesMissingMatches(): void
    {
        $ev = 'PSTP';
        self::seedRow('Events', [
            'EvCode' => $ev, 'EvTeamEvent' => 1, 'EvTournament' => self::SENTINEL,
            'EvEventName' => 'PS Team', 'EvFinalFirstPhase' => 2, 'EvNumQualified' => 0,
            'EvMixedTeam' => 0,
        ]);
        self::seedRow('EventClass', [
            'EcCode' => $ev, 'EcTournament' => self::SENTINEL,
            'EcClass' => 'PT', 'EcDivision' => 'R', 'EcSubClass' => '',
            'EcExtraAddons' => 0, 'EcTeamEvent' => 1,
        ]);
        foreach ([0, 1, 2] as $mn) {
            $this->scheduleMatch($ev, 1, $mn);
        }
        $response = $this->callGetCurrent(['teamEvent' => '1']);
        $matchNos = $this->matchNosFor($response, $ev);
        $this->assertSame(self::BRACKET_MATCHNOS, $matchNos,
            'team partial schedule should show scheduled + synthesized matches');
        $this->assertSame(count($matchNos), count(array_unique($matchNos)), 'no duplicates');
    }

    public function testZeroTeamEventStillSynthesizesButProjectsZero(): void
    {
        // Regression (arhS26): 14 team events with 1/8 brackets and zero Teams rows
        // put 448 phantom match rows in the unassigned pool. The fix is NOT to stop
        // synthesizing -- the rows still cross the wire so the UI can count them as
        // "hidden non-playable" -- but to have each carry projectedParticipants=0,
        // which the frontend rule (Common/js/finals-playability.js) treats as
        // unplayable. This test pins that server-side contract.
        $ev = 'PSTZ';
        self::seedRow('Events', [
            'EvCode' => $ev, 'EvTeamEvent' => 1, 'EvTournament' => self::SENTINEL,
            'EvEventName' => 'PS Team Zero', 'EvFinalFirstPhase' => 2,
            'EvNumQualified' => 16, 'EvMixedTeam' => 0,
        ]);
        self::seedRow('EventClass', [
            'EcCode' => $ev, 'EcTournament' => self::SENTINEL,
            'EcClass' => 'PZ', 'EcDivision' => 'R', 'EcSubClass' => '',
            'EcExtraAddons' => 0, 'EcTeamEvent' => 1,
        ]);
        // Deliberately no Teams rows and no FinSchedule rows for this event.

        $response = $this->callGetCurrent(['teamEvent' => '1']);
        $this->assertSame(self::BRACKET_MATCHNOS, $this->matchNosFor($response, $ev),
            'synthesis must still emit the full bracket so the UI can report it as hidden');

        $rows = array_values(array_filter($response['rows'] ?? [], static function ($r) use ($ev) {
            return ($r['event'] ?? null) === $ev;
        }));
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertSame(0, intval($row['projectedParticipants']),
                'match ' . $row['matchNo'] . ' of an unentered team event must project 0 finalists');
            $this->assertSame(0, intval($row['hasParticipant']),
                'match ' . $row['matchNo'] . ' of an unentered team event must have no seated team');
        }
    }

    public function testZeroEntrantIndividualEventStillSynthesizesButProjectsZero(): void
    {
        // Individual counterpart of testZeroTeamEventStillSynthesizesButProjectsZero.
        // Real case: tournament CloneTes, 11 individual events with brackets configured
        // and no entries, showed 42 phantom bundles in the unassigned pool. Individual
        // and team events must be treated identically here -- the frontend rule in
        // Common/js/finals-playability.js never looks at teamEvent.
        $ev = 'PSIZ';
        self::seedRow('Events', [
            'EvCode' => $ev, 'EvTeamEvent' => 0, 'EvTournament' => self::SENTINEL,
            'EvEventName' => 'PS Ind Zero', 'EvFinalFirstPhase' => 2, 'EvNumQualified' => 16,
        ]);
        self::seedRow('EventClass', [
            'EcCode' => $ev, 'EcTournament' => self::SENTINEL,
            'EcClass' => 'PQ', 'EcDivision' => 'R', 'EcSubClass' => '',
            'EcExtraAddons' => 0, 'EcTeamEvent' => 0,
        ]);
        // Deliberately no Entries rows and no FinSchedule rows for this event.

        $response = $this->callGetCurrent(['teamEvent' => '0']);
        $this->assertSame(self::BRACKET_MATCHNOS, $this->matchNosFor($response, $ev),
            'synthesis must still emit the full bracket so the UI can report it as hidden');

        $rows = array_values(array_filter($response['rows'] ?? [], static function ($r) use ($ev) {
            return ($r['event'] ?? null) === $ev;
        }));
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertSame(0, intval($row['projectedParticipants']),
                'match ' . $row['matchNo'] . ' of an unentered individual event must project 0 finalists');
            $this->assertSame(0, intval($row['hasParticipant']),
                'match ' . $row['matchNo'] . ' of an unentered individual event must have no seated athlete');
        }
    }
}
