<?php
require_once __DIR__ . '/LaneAssistDbTestCase.php';

/**
 * DB integration test for laLoadColorGroups() (Common/color-logic.php).
 * Seeds under sentinel TourId 999999 and cleans up. DO NOT run against a
 * production database.
 */
final class ColorGroupsIntegrationTest extends LaneAssistDbTestCase
{
    private const CLUB_A = 993101;
    private const CLUB_B = 993102;

    protected static function cleanupSentinel(): void
    {
        parent::cleanupSentinel();
        // Countries and TargetFaces are tournament-scoped but not in the generic map.
        safe_w_sql('DELETE FROM Countries WHERE CoTournament=' . StrSafe_DB(self::SENTINEL));
        safe_w_sql('DELETE FROM TargetFaces WHERE TfTournament=' . StrSafe_DB(self::SENTINEL));
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $_SESSION['TourType'] = 0;
        $tour = self::SENTINEL;

        static::seedRow('Divisions', ['DivId' => 'C', 'DivTournament' => $tour, 'DivDescription' => 'Compound', 'DivAthlete' => 1, 'DivViewOrder' => 2]);
        static::seedRow('Divisions', ['DivId' => 'R', 'DivTournament' => $tour, 'DivDescription' => 'Recurve', 'DivAthlete' => 1, 'DivViewOrder' => 1]);
        static::seedRow('Classes', ['ClId' => 'W', 'ClTournament' => $tour, 'ClDescription' => 'Women', 'ClAthlete' => 1, 'ClViewOrder' => 1]);
        static::seedRow('Classes', ['ClId' => 'M', 'ClTournament' => $tour, 'ClDescription' => 'Men', 'ClAthlete' => 1, 'ClViewOrder' => 2]);

        static::seedRow('Countries', ['CoId' => self::CLUB_B, 'CoTournament' => $tour, 'CoCode' => 'ZZCLUB', 'CoName' => 'Zed']);
        static::seedRow('Countries', ['CoId' => self::CLUB_A, 'CoTournament' => $tour, 'CoCode' => 'AACLUB', 'CoName' => 'Aye']);

        static::seedRow('Entries', ['EnId' => 993001, 'EnTournament' => $tour, 'EnCountry' => self::CLUB_B, 'EnDivision' => 'C', 'EnClass' => 'M', 'EnAthlete' => 1]);
        static::seedRow('Entries', ['EnId' => 993002, 'EnTournament' => $tour, 'EnCountry' => self::CLUB_A, 'EnDivision' => 'R', 'EnClass' => 'M', 'EnAthlete' => 1]);
        static::seedRow('Entries', ['EnId' => 993003, 'EnTournament' => $tour, 'EnCountry' => self::CLUB_A, 'EnDivision' => 'R', 'EnClass' => 'W', 'EnAthlete' => 1]);

        static::seedRow('Events', ['EvCode' => 'CLRM', 'EvTeamEvent' => 1, 'EvTournament' => $tour, 'EvProgr' => 1]);
        static::seedRow('Events', ['EvCode' => 'CLRM', 'EvTeamEvent' => 0, 'EvTournament' => $tour, 'EvProgr' => 2]);
        static::seedRow('Events', ['EvCode' => 'CLRW', 'EvTeamEvent' => 0, 'EvTournament' => $tour, 'EvProgr' => 1]);

        static::seedRow('TargetFaces', ['TfId' => 2, 'TfTournament' => $tour, 'TfT1' => 5]);
        static::seedRow('TargetFaces', ['TfId' => 1, 'TfTournament' => $tour, 'TfT1' => 1]);
    }

    public function testLoadsTournamentWideGroupsInOrder(): void
    {
        $groups = laLoadColorGroups(self::SENTINEL);

        $this->assertSame(['AACLUB', 'ZZCLUB'], $groups['country']);
        $this->assertSame([
            ['id' => 'R', 'description' => 'Recurve'],
            ['id' => 'C', 'description' => 'Compound'],
        ], $groups['division']);
        $this->assertSame(['W', 'M'], $groups['class']);
        $this->assertSame(['RW', 'RM', 'CM'], $groups['category']);
        $this->assertSame(['0|CLRW', '0|CLRM', '1|CLRM'], $groups['event']);
        $this->assertSame(['1', '5'], $groups['target-face']);
    }
}
