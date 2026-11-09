<?php
require_once __DIR__ . '/LaneAssistDbTestCase.php';

/**
 * DB integration test for Common/events-rebuild.php: loading the rebuild
 * input, summarising what a rebuild deletes, and applying a plan.
 *
 * Writes real rows to the local ianseo DB under sentinel TourId 999999 and
 * deletes them again (before every test and in teardown). Skips entirely if
 * the IANSEO DB layer cannot be bootstrapped. DO NOT run against a
 * production database.
 *
 * getDistanceProfileForCategory() keeps a per-process static cache keyed by
 * category only, so every test here seeds the identical TournamentDistances
 * row (see setUp()) and uses category codes (RHS, RDS, RXR, CHS, CDS) no
 * other test class uses.
 */
final class ChangeEventsIntegrationTest extends LaneAssistDbTestCase
{
    private const TOUR_TYPE = 6;

    private $previousTourType;
    private bool $hadTourType = false;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Pulls in the core helpers it needs (Fun_Phases, Modules/Sets/lib.php).
        require_once dirname(__DIR__) . '/Common/events-rebuild.php';
    }

    protected static function cleanupSentinel(): void
    {
        parent::cleanupSentinel();
        $sentinel = StrSafe_DB(self::SENTINEL);
        safe_w_sql("DELETE FROM Individuals WHERE IndTournament=$sentinel");
        safe_w_sql("DELETE FROM Eliminations WHERE ElTournament=$sentinel");
        safe_w_sql("DELETE FROM FinWarmup WHERE FwTournament=$sentinel");
        safe_w_sql("DELETE FROM Awards WHERE AwTournament=$sentinel");
        safe_w_sql("DELETE FROM TVParams WHERE TVPTournament=$sentinel");
        safe_w_sql("DELETE FROM TournamentDistances WHERE TdTournament=$sentinel");
        safe_w_sql("DELETE FROM Tournament WHERE ToId=$sentinel");
    }

    protected function setUp(): void
    {
        $this->hadTourType = array_key_exists('TourType', $_SESSION ?? []);
        $this->previousTourType = $this->hadTourType ? $_SESSION['TourType'] : null;
        $_SESSION['TourId'] = self::SENTINEL;
        $_SESSION['TourType'] = self::TOUR_TYPE;

        self::cleanupSentinel();

        // Distinctive golds/X-nine values so the apply test can tell the
        // Tournament row's values apart from anything else.
        self::seedRow('Tournament', [
            'ToId' => self::SENTINEL, 'ToType' => self::TOUR_TYPE, 'ToCode' => 'LATSTCE',
            'ToWhere' => 'Test', 'ToLocRule' => 'DK',
            'ToGolds' => '11', 'ToXNine' => '10', 'ToGoldsChars' => 'AB', 'ToXNineChars' => 'C',
        ]);

        self::seedRow('Divisions', ['DivId' => 'R', 'DivTournament' => self::SENTINEL, 'DivDescription' => 'Recurve', 'DivViewOrder' => 1, 'DivAthlete' => '1']);
        self::seedRow('Divisions', ['DivId' => 'C', 'DivTournament' => self::SENTINEL, 'DivDescription' => 'Compound', 'DivViewOrder' => 2, 'DivAthlete' => '1']);
        self::seedRow('Divisions', ['DivId' => 'Z', 'DivTournament' => self::SENTINEL, 'DivDescription' => 'Staff', 'DivViewOrder' => 3, 'DivAthlete' => '0']);

        self::seedClass('DS', 'Dame Senior', 1, 11, '');
        self::seedClass('HS', 'Herre Senior', 0, 12, '');
        self::seedClass('XR', 'Recurve Only', -1, 13, 'R');
        self::seedRow('Classes', [
            'ClId' => 'OF', 'ClTournament' => self::SENTINEL, 'ClDescription' => 'Official',
            'ClViewOrder' => 14, 'ClSex' => -1, 'ClAthlete' => '0', 'ClDivisionsAllowed' => '',
        ]);

        // Profile '18m-25m' -> shortest distance 18.
        self::seedRow('TournamentDistances', [
            'TdClasses' => '%', 'TdType' => self::TOUR_TYPE, 'TdTournament' => self::SENTINEL,
            'Td1' => '25m', 'Td2' => '18m',
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->hadTourType) {
            $_SESSION['TourType'] = $this->previousTourType;
        } else {
            unset($_SESSION['TourType']);
        }
    }

    private static function seedClass(string $id, string $name, int $sex, int $order, string $allowed): void
    {
        self::seedRow('Classes', [
            'ClId' => $id, 'ClTournament' => self::SENTINEL, 'ClDescription' => $name,
            'ClViewOrder' => $order, 'ClAgeFrom' => 21, 'ClAgeTo' => 49, 'ClSex' => $sex,
            'ClAthlete' => '1', 'ClDivisionsAllowed' => $allowed,
        ]);
    }

    private static function seedEntry(int $n, string $div, string $class, int $indFEvent = 1, int $status = 0): void
    {
        self::seedRow('Entries', [
            'EnId' => 997000 + $n, 'EnTournament' => self::SENTINEL,
            'EnDivision' => $div, 'EnClass' => $class, 'EnCode' => "CE$n",
            'EnName' => 'T', 'EnFirstName' => 'T', 'EnIndFEvent' => $indFEvent, 'EnStatus' => $status,
        ]);
    }

    private static function seedEvent(string $code, int $team, array $overrides = []): void
    {
        self::seedRow('Events', array_merge([
            'EvCode' => $code, 'EvTeamEvent' => $team, 'EvTournament' => self::SENTINEL,
            'EvEventName' => "Event $code", 'EvProgr' => 1, 'EvDistance' => '18',
            'EvFinalFirstPhase' => 8, 'EvNumQualified' => 16, 'EvMatchMode' => 1,
        ], $overrides));
    }

    private static function seedEventClass(string $code, int $team, string $div, string $class, array $overrides = []): void
    {
        self::seedRow('EventClass', array_merge([
            'EcCode' => $code, 'EcTeamEvent' => $team, 'EcTournament' => self::SENTINEL,
            'EcDivision' => $div, 'EcClass' => $class, 'EcSubClass' => '', 'EcExtraAddons' => 0, 'EcNumber' => 1,
        ], $overrides));
    }

    private static function countRows(string $sql): int
    {
        $row = safe_fetch(safe_r_sql($sql));
        return (int) $row->n;
    }

    private const DK_FULL = ['gender' => 1, 'class' => 1, 'division' => 1, 'distance' => 0];

    public function testLoadInputCountsActiveFinalsEntriesOnly(): void
    {
        self::seedEntry(1, 'R', 'HS');
        self::seedEntry(2, 'R', 'HS', 0);      // not in individual finals
        self::seedEntry(3, 'R', 'HS', 1, 6);   // inactive status

        $input = ceLoadInput(self::SENTINEL);
        $pairs = [];
        foreach ($input['pairs'] as $pair) {
            $pairs[$pair['div'] . $pair['class']] = $pair;
        }

        $this->assertSame(1, $pairs['RHS']['count']);
        $this->assertSame(0, $pairs['RDS']['count']);
        $this->assertSame('Recurve', $pairs['RHS']['divName']);
        $this->assertSame(1, $pairs['RHS']['divOrder']);
        $this->assertSame('18', $pairs['RHS']['qualShortest']);
        $this->assertSame([], $pairs['RHS']['oldEvents']);
        $this->assertSame(
            ['golds' => '11', 'xnine' => '10', 'goldsChars' => 'AB', 'xnineChars' => 'C', 'locRule' => 'DK'],
            $input['tour']
        );

        $classes = array_column($input['classes'], null, 'id');
        $this->assertSame(
            ['id' => 'HS', 'name' => 'Herre Senior', 'sex' => 0, 'ageFrom' => 21, 'ageTo' => 49, 'order' => 12],
            $classes['HS']
        );
        $this->assertArrayNotHasKey('OF', $classes, 'non-athlete classes are not candidates');
    }

    public function testLoadInputRespectsDivisionsAllowed(): void
    {
        $input = ceLoadInput(self::SENTINEL);
        $keys = array_map(function ($pair) {
            return $pair['div'] . '/' . $pair['class'];
        }, $input['pairs']);
        sort($keys);

        // XR allows only R; Z is a non-athlete division; OF a non-athlete class.
        $this->assertSame(['C/DS', 'C/HS', 'R/DS', 'R/HS', 'R/XR'], $keys);
    }

    public function testLoadInputMapsPairsToOldIndividualEventsOnly(): void
    {
        self::seedEvent('18R', 0, ['EvDistance' => '18 m', 'EvProgr' => 3, 'EvMatchMode' => 0, 'EvFinalTargetType' => 3]);
        self::seedEventClass('18R', 0, 'R', 'HS');
        self::seedEvent('R', 1);
        self::seedEventClass('R', 1, 'R', 'HS');
        self::seedEntry(11, 'R', 'HS');
        self::seedEntry(12, 'R', 'HS');

        $input = ceLoadInput(self::SENTINEL);
        $pairs = [];
        foreach ($input['pairs'] as $pair) {
            $pairs[$pair['div'] . $pair['class']] = $pair;
        }

        $this->assertSame(['18R'], $pairs['RHS']['oldEvents']);
        $this->assertSame(['18R'], array_keys($input['oldEvents']), 'team events are never listed');
        $old = $input['oldEvents']['18R'];
        $this->assertSame('Event 18R', $old['name']);
        $this->assertSame(3, $old['progr']);
        $this->assertSame('18', $old['distance']);
        $this->assertSame(2, $old['count']);
        $this->assertSame(0, $old['settings']['EvMatchMode']);
        $this->assertSame(3, $old['settings']['EvFinalTargetType']);
        $this->assertSame(
            ['EvMatchMode', 'EvMatchArrowsNo', 'EvFinalAthTarget', 'EvFinalTargetType', 'EvTargetSize',
             'EvElimEnds', 'EvElimArrows', 'EvElimSO', 'EvFinEnds', 'EvFinArrows', 'EvFinSO',
             'EvMedals', 'EvWinnerFinalRank'],
            array_keys($old['settings'])
        );
    }

    public function testLabelsFollowLocRule(): void
    {
        $dk = ceLabelsFor('DK');
        $this->assertSame([0 => 'H', 1 => 'D', -1 => 'U'], $dk['genderCode']);
        $this->assertSame([0 => 'Herre', 1 => 'Dame', -1 => 'Åben'], $dk['genderName']);
        $this->assertSame('ALL', $dk['allCode']);
        $this->assertSame('Alle', $dk['allName']);

        $other = ceLabelsFor('default');
        $this->assertSame([0 => 'M', 1 => 'W', -1 => 'U'], $other['genderCode']);
        $this->assertSame([0 => 'Men', 1 => 'Women', -1 => 'Open'], $other['genderName']);
        $this->assertSame('ALL', $other['allCode']);
        $this->assertSame('All', $other['allName']);
    }

    public function testDeletionSummaryCountsScoredAndScheduledMatches(): void
    {
        self::seedEvent('18R', 0);
        self::seedEvent('R', 1);
        self::seedRow('Finals', ['FinEvent' => '18R', 'FinMatchNo' => 8, 'FinTournament' => self::SENTINEL, 'FinScore' => 27]);
        self::seedRow('Finals', ['FinEvent' => '18R', 'FinMatchNo' => 9, 'FinTournament' => self::SENTINEL]);
        self::seedRow('Finals', ['FinEvent' => '18R', 'FinMatchNo' => 10, 'FinTournament' => self::SENTINEL]);
        self::seedRow('Finals', ['FinEvent' => '18R', 'FinMatchNo' => 11, 'FinTournament' => self::SENTINEL]);
        foreach ([8, 9] as $matchNo) {
            self::seedRow('FinSchedule', [
                'FSEvent' => '18R', 'FSTeamEvent' => 0, 'FSMatchNo' => $matchNo, 'FSTournament' => self::SENTINEL,
                'FSScheduledDate' => '2026-08-30', 'FSScheduledTime' => '10:00:00',
            ]);
        }
        // Unscheduled individual slot and a scheduled team match: neither counts.
        self::seedRow('FinSchedule', [
            'FSEvent' => '18R', 'FSTeamEvent' => 0, 'FSMatchNo' => 10, 'FSTournament' => self::SENTINEL,
            'FSScheduledDate' => '0000-00-00',
        ]);
        self::seedRow('FinSchedule', [
            'FSEvent' => 'R', 'FSTeamEvent' => 1, 'FSMatchNo' => 4, 'FSTournament' => self::SENTINEL,
            'FSScheduledDate' => '2026-08-30',
        ]);

        $summary = ceDeletionSummary(self::SENTINEL);

        $this->assertSame(1, $summary['scoredMatches']);
        $this->assertSame(['18R'], $summary['scoredEvents']);
        $this->assertSame(1, $summary['scheduledMatches']);
        $this->assertSame([['code' => '18R', 'name' => 'Event 18R', 'count' => 0]], $summary['events']);
    }

    public function testDeletionSummaryCountsRankingsCollapsedAndStaleReferences(): void
    {
        self::seedEvent('18R', 0, ['EvProgr' => 1]);
        self::seedEvent('18X', 0, ['EvProgr' => 2]);
        self::seedEvent('18C', 0, ['EvProgr' => 3]);
        self::seedEventClass('18R', 0, 'R', 'HS');
        self::seedEventClass('18X', 0, 'R', 'HS');                       // shares R/HS with 18R
        self::seedEventClass('18C', 0, 'C', 'HS', ['EcSubClass' => 'A']); // subclass split
        self::seedRow('Individuals', ['IndId' => 997101, 'IndEvent' => '18R', 'IndTournament' => self::SENTINEL, 'IndSO' => 1]);
        self::seedRow('Individuals', ['IndId' => 997102, 'IndEvent' => '18R', 'IndTournament' => self::SENTINEL]);
        self::seedRow('Eliminations', ['ElId' => 997101, 'ElEventCode' => '18R', 'ElTournament' => self::SENTINEL]);
        self::seedRow('Awards', ['AwTournament' => self::SENTINEL, 'AwEvent' => '18R', 'AwFinEvent' => 1, 'AwTeam' => 0]);
        self::seedRow('Awards', ['AwTournament' => self::SENTINEL, 'AwEvent' => 'R', 'AwFinEvent' => 1, 'AwTeam' => 1]);
        self::seedRow('TVParams', ['TVPId' => 1, 'TVPTournament' => self::SENTINEL, 'TVPEventInd' => 'ZZ|18C']);
        self::seedRow('TVParams', ['TVPId' => 2, 'TVPTournament' => self::SENTINEL, 'TVPEventInd' => '']);

        $summary = ceDeletionSummary(self::SENTINEL);

        $this->assertSame(2, $summary['rankedRows']);
        $this->assertSame(1, $summary['shootOffRows']);
        $this->assertSame(1, $summary['eliminationRows']);
        $this->assertSame(['18C', '18R', '18X'], $summary['collapsed']);
        $this->assertSame(1, $summary['stale']['awards']);
        $this->assertSame(1, $summary['stale']['tvRules']);
        $this->assertSame(0, $summary['stale']['iskPending']);
        $this->assertSame(0, $summary['stale']['hhtEvents']);
        $this->assertSame(['18R', '18X', '18C'], array_column($summary['events'], 'code'));
    }

    public function testApplyReplacesIndividualEventsAndKeepsTeams(): void
    {
        // 18R uses cumulative scoring (0), unlike the recurve default (1), so
        // a copied EvMatchMode is distinguishable from a defaulted one.
        self::seedEvent('18R', 0, ['EvMatchMode' => 0, 'EvGolds' => '10', 'EvFinEnds' => 4]);
        self::seedEventClass('18R', 0, 'R', 'HS');
        self::seedEventClass('18R', 0, 'R', 'DS');
        self::seedRow('Finals', ['FinEvent' => '18R', 'FinMatchNo' => 8, 'FinTournament' => self::SENTINEL]);
        self::seedEvent('R', 1, ['EvProgr' => 1]);
        self::seedEventClass('R', 1, 'R', 'HS', ['EcNumber' => 3]);
        self::seedEventClass('R', 1, 'R', 'DS', ['EcNumber' => 3]);
        foreach ([21, 22, 23] as $n) {
            self::seedEntry($n, 'R', 'HS');
        }
        foreach ([24, 25] as $n) {
            self::seedEntry($n, 'R', 'DS');
        }

        $preview = cePreview(self::SENTINEL, self::DK_FULL);
        $this->assertSame(['RDS', 'RHS'], array_column($preview['newEvents'], 'code'));
        $input = ceLoadInput(self::SENTINEL);

        $result = ceApplyPlan(self::SENTINEL, $preview['plan'], $input['tour']);

        $this->assertSame(['created' => 2, 'deleted' => 1], $result);
        $s = self::SENTINEL;
        $this->assertSame(0, self::countRows("SELECT COUNT(*) n FROM Events WHERE EvTournament=$s AND EvCode='18R'"));
        $this->assertSame(0, self::countRows("SELECT COUNT(*) n FROM Finals WHERE FinTournament=$s AND FinEvent='18R'"));
        $this->assertSame(0, self::countRows("SELECT COUNT(*) n FROM EventClass WHERE EcTournament=$s AND EcCode='18R'"));

        $this->assertSame(1, self::countRows("SELECT COUNT(*) n FROM Events WHERE EvTournament=$s AND EvCode='R' AND EvTeamEvent=1"));
        $this->assertSame(2, self::countRows("SELECT COUNT(*) n FROM EventClass WHERE EcTournament=$s AND EcCode='R' AND EcTeamEvent=1 AND EcNumber=3"));

        $rhs = safe_fetch(safe_r_sql("SELECT * FROM Events WHERE EvTournament=$s AND EvCode='RHS' AND EvTeamEvent=0"));
        $this->assertNotNull($rhs);
        $this->assertSame('Recurve Herre Senior', $rhs->EvEventName);
        $this->assertSame('18', $rhs->EvDistance);
        $this->assertSame('11', $rhs->EvGolds);
        $this->assertSame('10', $rhs->EvXNine);
        $this->assertSame('AB', $rhs->EvGoldsChars);
        $this->assertSame('C', $rhs->EvXNineChars);
        $this->assertSame(0, (int) $rhs->EvMatchMode);
        $this->assertSame(4, (int) $rhs->EvFinEnds);
        $this->assertSame(2, (int) $rhs->EvFinalFirstPhase);
        $this->assertSame(4, (int) $rhs->EvNumQualified);
        $this->assertSame(2, (int) $rhs->EvProgr);

        $mappings = [];
        $rs = safe_r_sql("SELECT EcCode, EcDivision, EcClass, EcNumber, EcSubClass, EcExtraAddons FROM EventClass
            WHERE EcTournament=$s AND EcTeamEvent=0 ORDER BY EcCode, EcDivision, EcClass");
        while ($row = safe_fetch($rs)) {
            $mappings[] = "$row->EcCode:$row->EcDivision/$row->EcClass:$row->EcNumber:$row->EcSubClass:$row->EcExtraAddons";
        }
        $this->assertSame(['RDS:R/DS:1::0', 'RHS:R/HS:1::0'], $mappings);

        // Phase 2 (semi-final) bracket: 4 semi + 2 bronze + 2 gold slots.
        $this->assertSame(8, self::countRows("SELECT COUNT(*) n FROM Finals WHERE FinTournament=$s AND FinEvent='RHS'"));
        $this->assertSame(8, self::countRows("SELECT COUNT(*) n FROM Finals WHERE FinTournament=$s AND FinEvent='RDS'"));
    }

    public function testApplyRefusesOnStaleFingerprint(): void
    {
        self::seedEvent('18R', 0);
        self::seedEventClass('18R', 0, 'R', 'HS');
        self::seedRow('Finals', ['FinEvent' => '18R', 'FinMatchNo' => 8, 'FinTournament' => self::SENTINEL]);
        self::seedEntry(31, 'R', 'HS');
        self::seedEntry(32, 'R', 'HS');

        $fingerprint = cePreview(self::SENTINEL, self::DK_FULL)['fingerprint'];
        // A finals score arrives between preview and confirm.
        self::seedRow('Finals', ['FinEvent' => '18R', 'FinMatchNo' => 9, 'FinTournament' => self::SENTINEL, 'FinSetScore' => 2]);

        $result = ceGuardedApply(self::SENTINEL, self::DK_FULL, $fingerprint);

        $this->assertSame(1, $result['error']);
        $this->assertTrue($result['stale']);
        $this->assertSame(1, $result['preview']['deletion']['scoredMatches']);
        $this->assertNotSame($fingerprint, $result['preview']['fingerprint']);
        $s = self::SENTINEL;
        $this->assertSame(1, self::countRows("SELECT COUNT(*) n FROM Events WHERE EvTournament=$s AND EvCode='18R'"));
        $this->assertSame(0, self::countRows("SELECT COUNT(*) n FROM Events WHERE EvTournament=$s AND EvCode='RHS'"));
    }

    public function testGuardedApplyAppliesWithFreshFingerprint(): void
    {
        self::seedEvent('18R', 0);
        self::seedEventClass('18R', 0, 'R', 'HS');
        self::seedEntry(41, 'R', 'HS');
        self::seedEntry(42, 'R', 'HS');

        $fingerprint = cePreview(self::SENTINEL, self::DK_FULL)['fingerprint'];
        $result = ceGuardedApply(self::SENTINEL, self::DK_FULL, $fingerprint);

        $this->assertSame(['error' => 0, 'created' => 1, 'deleted' => 1], $result);
        $s = self::SENTINEL;
        $this->assertSame(0, self::countRows("SELECT COUNT(*) n FROM Events WHERE EvTournament=$s AND EvCode='18R'"));
        $this->assertSame(1, self::countRows("SELECT COUNT(*) n FROM Events WHERE EvTournament=$s AND EvCode='RHS'"));
    }

    public function testPreviewShapesOldAndNewEvents(): void
    {
        self::seedEvent('18R', 0);
        self::seedEventClass('18R', 0, 'R', 'HS');
        self::seedEntry(51, 'R', 'HS');
        self::seedEntry(52, 'R', 'HS');

        $preview = cePreview(self::SENTINEL, ['division' => 1, 'distance' => 1]);

        $this->assertSame(['gender' => false, 'class' => false, 'division' => true, 'distance' => false], $preview['criteria']);
        $this->assertSame([['code' => '18R', 'name' => 'Event 18R', 'count' => 2]], $preview['oldEvents']);
        $this->assertSame(
            [['code' => 'R', 'name' => 'Recurve', 'count' => 2, 'firstPhase' => 2, 'distance' => '18']],
            $preview['newEvents']
        );
        $this->assertSame(cePlanFingerprint($preview['plan'], $preview['deletion']), $preview['fingerprint']);
    }
}
