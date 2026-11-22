<?php
require_once __DIR__ . '/LaneAssistDbTestCase.php';

/**
 * DB integration test for Common/target-faces-data.php (Change target faces).
 * Seeds the VJL24 face shape under sentinel TourId 999999 and cleans up.
 * DO NOT run against a production database.
 */
final class ChangeTargetFacesIntegrationTest extends LaneAssistDbTestCase
{
    private const CLUB = 994101;
    private const CLUB2 = 994102;
    private const EN_R = 994001;
    private const EN_C = 994002;
    private const EN_L = 994003;
    private const EN_T = 994004;
    private const EN_STAFF = 994005;
    private const STAMP = '2020-01-02 03:04:05';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        require_once dirname(__DIR__) . '/Common/target-faces-data.php';
    }

    protected static function cleanupSentinel(): void
    {
        parent::cleanupSentinel();
        safe_w_sql('DELETE FROM Countries WHERE CoTournament=' . StrSafe_DB(self::SENTINEL));
        safe_w_sql('DELETE FROM TargetFaces WHERE TfTournament=' . StrSafe_DB(self::SENTINEL));
        safe_w_sql('DELETE FROM Qualifications WHERE QuId BETWEEN ' . self::EN_R . ' AND ' . self::EN_STAFF);
    }

    private static function clubOf(string $div): int
    {
        return $div === 'C' ? self::CLUB2 : self::CLUB;
    }

    protected function setUp(): void
    {
        self::cleanupSentinel();
        $tour = self::SENTINEL;

        foreach (['R' => 1, 'C' => 2, 'L' => 3, 'T' => 4] as $div => $order) {
            self::seedRow('Divisions', ['DivId' => $div, 'DivTournament' => $tour, 'DivDescription' => $div, 'DivAthlete' => 1, 'DivViewOrder' => $order]);
        }
        // T is an athlete division the class doesn't allow, so T/S has no faces.
        self::seedRow('Classes', ['ClId' => 'S', 'ClTournament' => $tour, 'ClDescription' => 'Senior', 'ClAthlete' => 1, 'ClViewOrder' => 1, 'ClDivisionsAllowed' => 'R,C,L']);

        $faces = [
            [1, 'Full Face', '', '^R|^B|^L', 0],
            [2, 'Full Face', '', '^C', 0],
            [3, 'Trippel', '', '^R|^B|^L', 0],
            [4, 'Trippel', '', '^C', 1],
            [5, 'Full Face', 'L%', '', 1],
            [6, 'Full Face', 'B%', '', 1],
            [7, 'Trippel', 'R%', '', 1],
        ];
        foreach ($faces as [$id, $name, $classes, $regexp, $default]) {
            self::seedRow('TargetFaces', ['TfId' => $id, 'TfTournament' => $tour, 'TfName' => $name, 'TfClasses' => $classes, 'TfRegExp' => $regexp, 'TfDefault' => $default, 'TfT1' => 1, 'TfW1' => 40]);
        }

        self::seedRow('Countries', ['CoId' => self::CLUB, 'CoTournament' => $tour, 'CoCode' => 'TFCLUB', 'CoName' => 'Face Club']);
        self::seedRow('Countries', ['CoId' => self::CLUB2, 'CoTournament' => $tour, 'CoCode' => 'TFAAA', 'CoName' => 'Other Club']);
        // A club with no athletes: not offered in the Club dropdown.
        self::seedRow('Countries', ['CoId' => 994103, 'CoTournament' => $tour, 'CoCode' => 'TFEMPTY', 'CoName' => 'Empty']);

        self::seedRow('Events', ['EvCode' => 'TFRS', 'EvTeamEvent' => 0, 'EvTournament' => $tour, 'EvEventName' => 'Recurve Senior', 'EvProgr' => 2]);
        self::seedRow('Events', ['EvCode' => 'TFCS', 'EvTeamEvent' => 0, 'EvTournament' => $tour, 'EvEventName' => 'Compound Senior', 'EvProgr' => 1]);
        self::seedRow('Events', ['EvCode' => 'TFTEAM', 'EvTeamEvent' => 1, 'EvTournament' => $tour, 'EvEventName' => 'Team', 'EvProgr' => 3]);
        self::seedRow('EventClass', ['EcCode' => 'TFRS', 'EcTeamEvent' => 0, 'EcTournament' => $tour, 'EcDivision' => 'R', 'EcClass' => 'S']);
        self::seedRow('EventClass', ['EcCode' => 'TFCS', 'EcTeamEvent' => 0, 'EcTournament' => $tour, 'EcDivision' => 'C', 'EcClass' => 'S']);
        self::seedRow('EventClass', ['EcCode' => 'TFTEAM', 'EcTeamEvent' => 1, 'EcTournament' => $tour, 'EcDivision' => 'L', 'EcClass' => 'S']);
        $entries = [
            [self::EN_R, 'R', 7, 'Anna', 1],
            [self::EN_C, 'C', 4, 'Bo', 1],
            [self::EN_L, 'L', 5, 'Carl', 1],
            [self::EN_T, 'T', 0, 'Dina', 1],
            [self::EN_STAFF, 'R', 7, 'Erik', 0],
        ];
        foreach ($entries as [$id, $div, $face, $first, $athlete]) {
            self::seedRow('Entries', [
                'EnId' => $id, 'EnTournament' => $tour, 'EnCountry' => self::clubOf($div), 'EnDivision' => $div, 'EnClass' => 'S',
                'EnAthlete' => $athlete, 'EnTargetFace' => $face, 'EnFirstName' => $first, 'EnName' => 'Test',
                'EnTimestamp' => self::STAMP,
            ]);
        }
    }

    private static function faceOf(int $enId): int
    {
        $r = safe_fetch(safe_r_sql("SELECT EnTargetFace FROM Entries WHERE EnId=$enId"));
        return intval($r->EnTargetFace);
    }

    public function testLoadLegalSetsMatchesCoreRule(): void
    {
        $legal = tfLoadLegalSets(self::SENTINEL);
        $this->assertSame([7, 1, 3], $legal['R']['S']);
        $this->assertSame([4, 2], $legal['C']['S']);
        $this->assertSame([5, 1, 3], $legal['L']['S']);
        $this->assertArrayNotHasKey('T', $legal);
    }

    public function testContextGroupsMergeByName(): void
    {
        $ctx = tfLoadContext(self::SENTINEL);
        $this->assertSame(['full face', 'trippel'], array_column($ctx['groups'], 'key'));
    }

    public function testLoadArchersExcludesNonAthletes(): void
    {
        $rows = tfLoadArchers(self::SENTINEL, [], tfLoadContext(self::SENTINEL));
        $this->assertSame([self::EN_R, self::EN_C, self::EN_L, self::EN_T], array_column($rows, 'id'));
        $this->assertSame('TFCLUB', $rows[0]['coCode']);
        $this->assertTrue($rows[3]['noFace']);
    }

    public function testLoadArchersIncludesTarget(): void
    {
        // EN_R is on mat 12B of session 2; EN_C has no Qualifications row at all.
        self::seedRow('Qualifications', ['QuId' => self::EN_R, 'QuSession' => 2, 'QuTarget' => 12, 'QuLetter' => 'B', 'QuTargetNo' => '2012B']);
        $rows = array_column(tfLoadArchers(self::SENTINEL, [], tfLoadContext(self::SENTINEL)), null, 'id');
        $r = $rows[self::EN_R];
        $this->assertSame([2, 12, 'B', 'Anna', 'Test'], [$r['session'], $r['target'], $r['letter'], $r['lastName'], $r['firstName']]);
        $c = $rows[self::EN_C];
        $this->assertSame([0, 0, ''], [$c['session'], $c['target'], $c['letter']]);
    }

    public function testFaceFilterUsesCurrentFace(): void
    {
        safe_w_sql('UPDATE Entries SET EnTargetFace=1 WHERE EnId=' . self::EN_C);
        $rows = tfLoadArchers(self::SENTINEL, ['face' => 'full face'], tfLoadContext(self::SENTINEL));
        $this->assertSame([self::EN_C, self::EN_L], array_column($rows, 'id'));
        $this->assertTrue($rows[0]['currentIllegal']);
        $this->assertNull($rows[0]['selected']);
    }

    private static function ids(array $filters): array
    {
        return array_column(tfLoadArchers(self::SENTINEL, $filters, tfLoadContext(self::SENTINEL)), 'id');
    }

    public function testMultiDivisionFilterMatchesAny(): void
    {
        $this->assertSame([self::EN_R, self::EN_L], self::ids(['div' => ['R', 'L']]));
    }

    public function testDivisionFilterIsExactNotLike(): void
    {
        $this->assertSame([], self::ids(['div' => ['_']]));
        $this->assertSame([], self::ids(['div' => ['%']]));
    }

    public function testAllOptionMeansNoFilter(): void
    {
        $this->assertCount(4, self::ids(['div' => [''], 'class' => [], 'country' => [''], 'event' => ['']]));
    }

    public function testClubFilterByCountryId(): void
    {
        $this->assertSame([self::EN_C], self::ids(['country' => [(string)self::CLUB2]]));
        $this->assertCount(4, self::ids(['country' => [(string)self::CLUB, (string)self::CLUB2]]));
    }

    public function testClassFilter(): void
    {
        $this->assertCount(4, self::ids(['class' => ['S']]));
        $this->assertSame([], self::ids(['class' => ['X']]));
    }

    public function testEventFilterMatchesAnyIndividualEvent(): void
    {
        $this->assertSame([self::EN_R, self::EN_C], self::ids(['event' => ['TFRS', 'TFCS']]));
        // Team event classes don't count, same as ManEventAccess.
        $this->assertSame([], self::ids(['event' => ['TFTEAM']]));
    }

    public function testFiltersCombineAcrossFields(): void
    {
        $this->assertSame([self::EN_R], self::ids(['div' => ['R', 'C'], 'country' => [(string)self::CLUB]]));
    }

    public function testNameFilter(): void
    {
        $this->assertSame([self::EN_C], self::ids(['name' => 'bo']));
    }

    public function testBulkUsesSameMultiFilter(): void
    {
        $res = tfSetAll(self::SENTINEL, ['div' => ['R', 'L']], 'full face', 2, tfLoadContext(self::SENTINEL));
        $this->assertSame(0, $res['error']);
        $this->assertSame(1, $res['changed']);
        $this->assertSame(1, $res['unchanged']);
        $this->assertSame(1, self::faceOf(self::EN_R));
        $this->assertSame(4, self::faceOf(self::EN_C));
    }

    public function testLoadFilterOptions(): void
    {
        $o = tfLoadFilterOptions(self::SENTINEL);
        $this->assertSame([
            ['value' => (string)self::CLUB2, 'label' => 'TFAAA - Other Club'],
            ['value' => (string)self::CLUB, 'label' => 'TFCLUB - Face Club'],
        ], $o['country']);
        $this->assertSame(['R', 'C', 'L', 'T'], array_column($o['div'], 'value'));
        $this->assertSame([['value' => 'S', 'label' => 'S - Senior']], $o['class']);
        $this->assertSame([
            ['value' => 'TFCS', 'label' => 'TFCS - Compound Senior'],
            ['value' => 'TFRS', 'label' => 'TFRS - Recurve Senior'],
        ], $o['event']);
    }

    public function testSetOneWritesLegalMember(): void
    {
        $res = tfSetOne(self::SENTINEL, self::EN_C, 'full face', tfLoadContext(self::SENTINEL));
        $this->assertSame(['error' => 0, 'face' => 2], $res);
        $this->assertSame(2, self::faceOf(self::EN_C));
    }

    public function testLoadArcherReturnsOneFreshRow(): void
    {
        $ctx = tfLoadContext(self::SENTINEL);
        tfSetOne(self::SENTINEL, self::EN_C, 'full face', $ctx);
        $row = tfLoadArcher(self::SENTINEL, self::EN_C, $ctx);
        $this->assertSame(2, $row['face']);
        $this->assertSame('full face', $row['selected']);
        $this->assertNull(tfLoadArcher(self::SENTINEL, self::EN_STAFF, $ctx));
    }

    public function testSetOneRefusesIllegalGroup(): void
    {
        $res = tfSetOne(self::SENTINEL, self::EN_T, 'trippel', tfLoadContext(self::SENTINEL));
        $this->assertSame(1, $res['error']);
        $this->assertSame(0, self::faceOf(self::EN_T));
    }

    public function testSetOneRefusesOtherTournamentsArcher(): void
    {
        $res = tfSetOne(self::SENTINEL + 1, self::EN_C, 'full face', tfLoadContext(self::SENTINEL));
        $this->assertSame(1, $res['error']);
        $this->assertSame(4, self::faceOf(self::EN_C));
    }

    public function testSetOneRefusesNonAthlete(): void
    {
        $res = tfSetOne(self::SENTINEL, self::EN_STAFF, 'full face', tfLoadContext(self::SENTINEL));
        $this->assertSame(1, $res['error']);
        $this->assertSame(7, self::faceOf(self::EN_STAFF));
    }

    public function testBulkLeavesIllegalArchersUntouched(): void
    {
        $res = tfSetAll(self::SENTINEL, [], 'trippel', 4, tfLoadContext(self::SENTINEL));
        $this->assertSame(0, $res['error']);
        $this->assertSame(1, $res['changed']);
        $this->assertSame(2, $res['unchanged']);
        $this->assertSame(['TS' => 1], $res['skipped']);
        $this->assertSame(3, self::faceOf(self::EN_L));
        $this->assertSame(0, self::faceOf(self::EN_T));
        $this->assertSame(7, self::faceOf(self::EN_STAFF));
    }

    public function testBulkRefusesStaleCount(): void
    {
        $res = tfSetAll(self::SENTINEL, [], 'full face', 3, tfLoadContext(self::SENTINEL));
        $this->assertSame(1, $res['error']);
        $this->assertTrue($res['stale']);
        $this->assertSame('View changed, reload', $res['message']);
        $this->assertSame(7, self::faceOf(self::EN_R));
        $this->assertSame(4, self::faceOf(self::EN_C));
    }

    public function testBulkRefusesUnknownGroup(): void
    {
        $res = tfSetAll(self::SENTINEL, [], 'nope', 4, tfLoadContext(self::SENTINEL));
        $this->assertSame(1, $res['error']);
    }

    public function testWriteKeepsTimestamp(): void
    {
        tfSetOne(self::SENTINEL, self::EN_C, 'full face', tfLoadContext(self::SENTINEL));
        $r = safe_fetch(safe_r_sql('SELECT EnTimestamp FROM Entries WHERE EnId=' . self::EN_C));
        $this->assertSame(self::STAMP, $r->EnTimestamp);
    }
}
