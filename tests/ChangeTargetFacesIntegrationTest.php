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
        $entries = [
            [self::EN_R, 'R', 7, 'Anna', 1],
            [self::EN_C, 'C', 4, 'Bo', 1],
            [self::EN_L, 'L', 5, 'Carl', 1],
            [self::EN_T, 'T', 0, 'Dina', 1],
            [self::EN_STAFF, 'R', 7, 'Erik', 0],
        ];
        foreach ($entries as [$id, $div, $face, $first, $athlete]) {
            self::seedRow('Entries', [
                'EnId' => $id, 'EnTournament' => $tour, 'EnCountry' => self::CLUB, 'EnDivision' => $div, 'EnClass' => 'S',
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

    public function testFaceFilterUsesCurrentFace(): void
    {
        safe_w_sql('UPDATE Entries SET EnTargetFace=1 WHERE EnId=' . self::EN_C);
        $rows = tfLoadArchers(self::SENTINEL, ['face' => 'full face'], tfLoadContext(self::SENTINEL));
        $this->assertSame([self::EN_C, self::EN_L], array_column($rows, 'id'));
        $this->assertTrue($rows[0]['currentIllegal']);
        $this->assertNull($rows[0]['selected']);
    }

    public function testFilterSemanticsMatchOnLoadAndBulk(): void
    {
        $ctx = tfLoadContext(self::SENTINEL);
        $rows = tfLoadArchers(self::SENTINEL, ['div' => 'r'], $ctx);
        $this->assertSame([self::EN_R], array_column($rows, 'id'));
        $this->assertCount(4, tfLoadArchers(self::SENTINEL, ['div' => '_'], $ctx));

        $res = tfSetAll(self::SENTINEL, ['div' => 'r'], 'full face', 1, $ctx);
        $this->assertSame(0, $res['error']);
        $this->assertSame(1, $res['changed']);
        $this->assertSame(1, self::faceOf(self::EN_R));
    }

    public function testNameAndCountryFilters(): void
    {
        $ctx = tfLoadContext(self::SENTINEL);
        $this->assertSame([self::EN_C], array_column(tfLoadArchers(self::SENTINEL, ['name' => 'bo'], $ctx), 'id'));
        $this->assertCount(4, tfLoadArchers(self::SENTINEL, ['country' => 'face club'], $ctx));
        $this->assertCount(0, tfLoadArchers(self::SENTINEL, ['event' => 'NOPE'], $ctx));
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
