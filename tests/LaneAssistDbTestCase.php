<?php
use PHPUnit\Framework\TestCase;

/**
 * Base for LaneAssist DB integration tests. Bootstraps the IANSEO DB layer,
 * skips cleanly when no DB is available, and provides sentinel-scoped seeding
 * and teardown. Writes to the real ianseo DB under TourId 999999. DO NOT run
 * against a production database.
 */
abstract class LaneAssistDbTestCase extends TestCase
{
    protected const SENTINEL = 999999;

    /** table => its tournament-id column. NOTE: Grids is intentionally absent
     *  (global reference data, never seeded or deleted). */
    protected static array $tables = [
        'Entries'          => 'EnTournament',
        'EventClass'       => 'EcTournament',
        'Teams'            => 'TeTournament',
        'Events'           => 'EvTournament',
        'TeamFinComponent' => 'TfcTournament',
        'Divisions'        => 'DivTournament',
        'Classes'          => 'ClTournament',
        'FinSchedule'      => 'FSTournament',
        'Finals'           => 'FinTournament',
        'TeamFinals'       => 'TfTournament',
    ];

    private static array $requiredColsCache = [];

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 4);
        if (!is_file($root . '/Common/config.inc.php')) {
            self::markTestSkipped('IANSEO config.inc.php not found; DB unavailable');
        }
        $GLOBALS['CFG'] = new stdClass();
        $CFG = $GLOBALS['CFG'];
        @include_once($root . '/Common/config.inc.php');
        require_once($root . '/Common/distro.inc.php');
        require_once($root . '/Common/Fun_DB.inc.php');

        foreach (['safe_r_sql', 'safe_w_sql', 'StrSafe_DB', 'safe_fetch'] as $fn) {
            if (!function_exists($fn)) {
                self::markTestSkipped("IANSEO function $fn missing; DB layer not loaded");
            }
        }

        // Raw-mysqli probe BEFORE any safe_* call (safe_error() exit()s, does not throw).
        $cfg = $GLOBALS['CFG'] ?? null;
        if (!$cfg || !isset($cfg->R_HOST, $cfg->R_USER, $cfg->R_PASS, $cfg->DB_NAME)) {
            self::markTestSkipped('IANSEO DB config incomplete; cannot probe');
        }
        mysqli_report(MYSQLI_REPORT_OFF);
        $probe = @mysqli_connect($cfg->R_HOST, $cfg->R_USER, $cfg->R_PASS, $cfg->DB_NAME);
        if (!$probe) {
            self::markTestSkipped('IANSEO DB not reachable');
        }
        mysqli_close($probe);

        $_SESSION['TourId'] = static::SENTINEL;
        static::cleanupSentinel();
    }

    public static function tearDownAfterClass(): void
    {
        if (function_exists('safe_w_sql') && function_exists('StrSafe_DB')) {
            static::cleanupSentinel();
        }
    }

    protected static function cleanupSentinel(): void
    {
        foreach (static::$tables as $table => $col) {
            safe_w_sql("DELETE FROM $table WHERE $col=" . StrSafe_DB(static::SENTINEL));
        }
    }

    protected static function requiredCols(string $table): array
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

    protected static function zeroFor(string $type)
    {
        if ($type === 'date') return '1970-01-01';
        if ($type === 'datetime' || $type === 'timestamp') return '1970-01-01 00:00:00';
        $numeric = ['int','tinyint','smallint','mediumint','bigint','decimal','float','double'];
        if (in_array($type, $numeric, true)) return 0;
        return '';
    }

    protected static function seedRow(string $table, array $overrides): void
    {
        $vals = [];
        foreach (static::requiredCols($table) as $col => $type) {
            $vals[$col] = static::zeroFor($type);
        }
        foreach ($overrides as $col => $v) {
            $vals[$col] = $v;
        }
        $cols = array_keys($vals);
        $sqlVals = array_map(fn($v) => StrSafe_DB($v), array_values($vals));
        safe_w_sql("INSERT INTO $table (" . implode(',', $cols) . ") VALUES (" . implode(',', $sqlVals) . ")");
    }
}
