<?php
use PHPUnit\Framework\TestCase;

/**
 * DB integration test for getProjectedFinalistsForEvent.
 *
 * Writes real rows to the local ianseo DB under sentinel TourId 999999 and
 * deletes them in teardown. Skips entirely if the IANSEO DB layer cannot be
 * bootstrapped. DO NOT run against a production database.
 */
final class ProjectedFinalistsIntegrationTest extends TestCase
{
    private const SENTINEL = 999999;

    /** table => its tournament-id column */
    private static array $tables = [
        'Entries'          => 'EnTournament',
        'EventClass'       => 'EcTournament',
        'Teams'            => 'TeTournament',
        'Events'           => 'EvTournament',
        'TeamFinComponent' => 'TfcTournament',
    ];

    /** cache of required (NOT NULL, no default) columns per table */
    private static array $requiredColsCache = [];

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 4); // .../LaneAssist/tests -> repo root /opt/ianseo
        // Bootstrap IANSEO DB layer without triggering auth/dispatch.
        if (!is_file($root . '/Common/config.inc.php')) {
            self::markTestSkipped('IANSEO config.inc.php not found; DB unavailable');
        }
        $GLOBALS['CFG'] = new stdClass();
        // config.inc.php assigns $CFG->R_HOST etc.; alias our global to $CFG.
        $CFG = $GLOBALS['CFG'];
        @include_once($root . '/Common/config.inc.php');
        require_once($root . '/Common/distro.inc.php');
        require_once($root . '/Common/Fun_DB.inc.php');

        foreach (['safe_r_sql', 'safe_w_sql', 'StrSafe_DB', 'safe_fetch'] as $fn) {
            if (!function_exists($fn)) {
                self::markTestSkipped("IANSEO function $fn missing; DB layer not loaded");
            }
        }

        // Probe the connection; skip if unreachable.
        try {
            $rs = safe_r_sql('SELECT 1 AS ok');
            if (!$rs || !safe_fetch($rs)) {
                self::markTestSkipped('IANSEO DB not reachable');
            }
        } catch (\Throwable $e) {
            self::markTestSkipped('IANSEO DB not reachable: ' . $e->getMessage());
        }

        $_SESSION['TourId'] = self::SENTINEL;
        self::cleanupSentinel(); // recover from any prior killed run
    }

    public static function tearDownAfterClass(): void
    {
        if (function_exists('safe_w_sql')) {
            self::cleanupSentinel();
        }
    }

    private static function cleanupSentinel(): void
    {
        foreach (self::$tables as $table => $col) {
            safe_w_sql("DELETE FROM $table WHERE $col=" . StrSafe_DB(self::SENTINEL));
        }
    }

    private static function requiredCols(string $table): array
    {
        if (isset(self::$requiredColsCache[$table])) {
            return self::$requiredColsCache[$table];
        }
        global $CFG;
        $sql = "SELECT COLUMN_NAME, DATA_TYPE
                FROM information_schema.columns
                WHERE table_schema=" . StrSafe_DB($CFG->DB_NAME) . "
                  AND table_name=" . StrSafe_DB($table) . "
                  AND IS_NULLABLE='NO' AND COLUMN_DEFAULT IS NULL";
        $rs = safe_r_sql($sql);
        $cols = [];
        while ($r = safe_fetch($rs)) {
            $cols[$r->COLUMN_NAME] = $r->DATA_TYPE;
        }
        return self::$requiredColsCache[$table] = $cols;
    }

    private static function zeroFor(string $type)
    {
        if ($type === 'date') return '1970-01-01';
        if ($type === 'datetime' || $type === 'timestamp') return '1970-01-01 00:00:00';
        $numeric = ['int','tinyint','smallint','mediumint','bigint','decimal','float','double'];
        if (in_array($type, $numeric, true)) return 0;
        return '';
    }

    /** Insert a row filling all required columns with zero-values, then overrides. */
    private static function seedRow(string $table, array $overrides): void
    {
        $vals = [];
        foreach (self::requiredCols($table) as $col => $type) {
            $vals[$col] = self::zeroFor($type);
        }
        foreach ($overrides as $col => $v) {
            $vals[$col] = $v;
        }
        $cols = array_keys($vals);
        $sqlVals = array_map(fn($v) => StrSafe_DB($v), array_values($vals));
        $sql = "INSERT INTO $table (" . implode(',', $cols) . ") VALUES (" . implode(',', $sqlVals) . ")";
        safe_w_sql($sql);
    }

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
}
