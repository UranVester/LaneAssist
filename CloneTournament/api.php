<?php
/**
 * Clone Tournament API
 */

require_once(dirname(__FILE__, 3) . '/config.php');
header('Content-Type: application/json');

checkFullACL(AclRoot, '', AclReadWrite, false);

$action = $_REQUEST['action'] ?? '';

switch ($action) {
    case 'getMeta':
        getMeta();
        break;
    case 'cloneTournament':
        cloneTournament();
        break;
    default:
        echo json_encode(['error' => 1, 'message' => 'Invalid action']);
}

function getClonePartDefinitions() {
    return [
        [
            'key' => 'categories',
            'label' => 'Categories',
            'description' => 'Divisions, classes and subclass mappings',
            'defaultSelected' => true,
            'tables' => [
                'Divisions' => 'DivTournament',
                'Classes' => 'ClTournament',
                'SubClass' => 'ScTournament',
                'ClassWaEquivalents' => 'ClWaEqTournament'
            ]
        ],
        [
            'key' => 'competition',
            'label' => 'Competition Setup',
            'description' => 'Events, event-class links, awards and records setup',
            'defaultSelected' => true,
            'tables' => [
                'Events' => 'EvTournament',
                'EventClass' => 'EcTournament',
                'Awards' => 'AwTournament',
                'TourRecords' => 'TrTournament',
                'RecTournament' => 'RtTournament',
                'VegasAwards' => 'VaTournament'
            ]
        ],
        [
            'key' => 'targets',
            'label' => 'Targets & Sessions',
            'description' => 'Available targets, sessions, scheduler and target faces/groups',
            'defaultSelected' => true,
            'tables' => [
                'AvailableTarget' => 'AtTournament',
                'Session' => 'SesTournament',
                'Scheduler' => 'SchTournament',
                'TargetFaces' => 'TfTournament',
                'TargetGroups' => 'TgTournament',
                'TournamentDistances' => 'TdTournament',
                'DistanceInformation' => 'DiTournament'
            ]
        ],
        [
            'key' => 'finals',
            'label' => 'Finals Setup',
            'description' => 'Finals schedule and warmup settings',
            'defaultSelected' => false,
            'tables' => [
                'FinSchedule' => 'FSTournament',
                'FinWarmup' => 'FwTournament'
            ]
        ],
        [
            'key' => 'visual',
            'label' => 'Visual & IDs',
            'description' => 'Back numbers, images and ID card layouts',
            'defaultSelected' => false,
            'tables' => [
                'BackNumber' => 'BnTournament',
                'Images' => 'ImTournament',
                'IdCards' => 'IcTournament',
                'IdCardElements' => 'IceTournament'
            ]
        ],
        [
            'key' => 'tv',
            'label' => 'TV & ODF',
            'description' => 'TV sequences/rules/contents and ODF parameter tables',
            'defaultSelected' => false,
            'tables' => [
                'TVParams' => 'TVPTournament',
                'TVRules' => 'TVRTournament',
                'TVSequence' => 'TVSTournament',
                'TVContents' => 'TVCTournament',
                'OdfDocuments' => 'OdfDocTournament',
                'OdfTranslations' => 'OdfTrTournament',
                'OdfMessageStatus' => 'OmsTournament'
            ]
        ],
        [
            'key' => 'modules',
            'label' => 'Module Parameters',
            'description' => 'Module-level settings (excluding protected credentials)',
            'defaultSelected' => false,
            'tables' => [
                'ModulesParameters' => 'MpTournament'
            ]
        ]
    ];
}

function getMeta() {
    $showAllInDebug = !empty($_SESSION['debug']);
    $authFilterWhere = '';

    if (!$showAllInDebug) {
        $authFilter = buildTournamentAuthFilter();
        if (!empty($authFilter)) {
            $authFilterWhere = ' WHERE ' . implode(' OR ', $authFilter);
        }
    }

    $tournaments = [];
    $sql = "SELECT ToId, ToCode, ToName, ToWhenFrom, ToWhenTo
            FROM Tournament
            " . $authFilterWhere . "
            ORDER BY ToWhenFrom DESC, ToId DESC";
    $rs = safe_r_sql($sql);
    while ($row = safe_fetch($rs)) {
        $tournaments[] = [
            'id' => intval($row->ToId),
            'code' => trim((string)$row->ToCode),
            'name' => trim((string)$row->ToName),
            'whenFrom' => trim((string)$row->ToWhenFrom),
            'whenTo' => trim((string)$row->ToWhenTo),
        ];
    }

    $parts = [];
    foreach (getClonePartDefinitions() as $part) {
        $parts[] = [
            'key' => $part['key'],
            'label' => $part['label'],
            'description' => $part['description'],
            'defaultSelected' => !empty($part['defaultSelected'])
        ];
    }

    echo json_encode([
        'error' => 0,
        'tournaments' => $tournaments,
        'parts' => $parts
    ]);
}

function buildTournamentAuthFilter() {
    $authFilter = [];

    if (defined('AuthModule') && AuthModule) {
        if (!empty($_SESSION['AUTH_ENABLE']) && empty($_SESSION['AUTH_ROOT'])) {
            $compList = [];
            foreach (($_SESSION['AUTH_COMP'] ?? []) as $comp) {
                $compCode = trim((string)$comp);
                if ($compCode === '') {
                    continue;
                }

                if (strpos($compCode, '%') !== false) {
                    $authFilter[] = 'ToCode LIKE ' . StrSafe_DB($compCode);
                } else {
                    $compList[] = $compCode;
                }
            }

            if (count($compList)) {
                $authFilter[] = 'FIND_IN_SET(ToCode, ' . StrSafe_DB(implode(',', $compList)) . ') != 0';
            } else {
                $authFilter[] = 'ToCode IS NULL';
            }
        }
    }

    return $authFilter;
}

function sanitizeTournamentCode($name) {
    $code = trim((string)$name);
    $code = preg_replace('/[^0-9a-z._-]+/sim', '_', $code);
    if ($code === '') {
        $code = 'CLONE';
    }
    return substr($code, 0, 8);
}

function tournamentCodeExists($code) {
    $q = safe_r_sql("SELECT ToId FROM Tournament WHERE ToCode=" . StrSafe_DB($code));
    return ($q && safe_num_rows($q) > 0);
}

function getTableColumns($tableName) {
    $columns = [];
    $autoIncrementColumns = [];

    $rs = safe_r_sql("SHOW COLUMNS FROM `" . $tableName . "`");
    while ($row = safe_fetch_assoc($rs)) {
        $field = $row['Field'];
        $columns[] = $field;
        if (stripos((string)$row['Extra'], 'auto_increment') !== false) {
            $autoIncrementColumns[$field] = true;
        }
    }

    return [
        'columns' => $columns,
        'auto' => $autoIncrementColumns,
    ];
}

function buildInsertSet(array $row, array $allowedColumns) {
    $parts = [];
    foreach ($allowedColumns as $column) {
        if (!array_key_exists($column, $row)) {
            continue;
        }

        $value = $row[$column];
        if (is_null($value)) {
            $parts[] = '`' . $column . '`=NULL';
        } else {
            $parts[] = '`' . $column . '`=' . StrSafe_DB($value);
        }
    }

    return implode(', ', $parts);
}

function cloneTournamentRow($sourceTournamentId, $newName, $newCodeRaw) {
    $sourceRs = safe_r_sql("SELECT * FROM Tournament WHERE ToId=" . StrSafe_DB($sourceTournamentId));
    if (!$sourceRs || safe_num_rows($sourceRs) !== 1) {
        return [0, '', 'Source tournament not found'];
    }

    $source = safe_fetch_assoc($sourceRs);
    $newCodeRaw = trim((string)$newCodeRaw);
    if (strlen($newCodeRaw) > 8) {
        return [0, '', 'Competition code must be max 8 characters'];
    }

    $newCode = sanitizeTournamentCode($newCodeRaw);
    if ($newCode === '') {
        return [0, '', 'Competition code is required'];
    }
    if (tournamentCodeExists($newCode)) {
        return [0, '', 'Competition code already exists'];
    }

    unset($source['ToId']);
    $source['ToName'] = $newName;
    $source['ToCode'] = $newCode;

    $set = [];
    foreach ($source as $col => $val) {
        if (is_null($val)) {
            $set[] = '`' . $col . '`=NULL';
        } else {
            $set[] = '`' . $col . '`=' . StrSafe_DB($val);
        }
    }

    safe_w_sql('INSERT INTO Tournament SET ' . implode(', ', $set));
    $newId = intval(safe_w_last_id());
    if ($newId <= 0) {
        return [0, '', 'Failed to create new tournament'];
    }

    return [$newId, $newCode, ''];
}

function copyTournamentTable($tableName, $tournamentColumn, $sourceTournamentId, $newTournamentId) {
    $meta = getTableColumns($tableName);
    $columns = $meta['columns'];
    $auto = $meta['auto'];

    if (empty($columns)) {
        return;
    }

    if (!in_array($tournamentColumn, $columns, true)) {
        return;
    }

    $allowedColumns = [];
    foreach ($columns as $column) {
        if (!isset($auto[$column])) {
            $allowedColumns[] = $column;
        }
    }

    $whereSql = "SELECT * FROM `" . $tableName . "` WHERE `" . $tournamentColumn . "`=" . StrSafe_DB($sourceTournamentId);

    if ($tableName === 'ModulesParameters') {
        $whereSql .= " AND !(MpModule='Mailing' and MpParameter='SmtpServer')";
        $whereSql .= " AND !(MpModule='SendToIanseo' and MpParameter='Credentials')";
    }

    $rs = safe_r_sql($whereSql);
    while ($row = safe_fetch_assoc($rs)) {
        $row[$tournamentColumn] = $newTournamentId;
        $set = buildInsertSet($row, $allowedColumns);
        if ($set === '') {
            continue;
        }
        safe_w_sql("INSERT INTO `" . $tableName . "` SET " . $set);
    }
}

function cloneTournament() {
    $sourceTournamentId = intval($_REQUEST['sourceTournamentId'] ?? 0);
    $newName = trim((string)($_REQUEST['newName'] ?? ''));
    $newCode = trim((string)($_REQUEST['newCode'] ?? ''));
    $partsRaw = $_REQUEST['parts'] ?? '[]';
    $parts = json_decode($partsRaw, true);

    if ($sourceTournamentId <= 0) {
        echo json_encode(['error' => 1, 'message' => 'Invalid source tournament']);
        return;
    }

    if ($newName === '') {
        echo json_encode(['error' => 1, 'message' => 'New tournament name is required']);
        return;
    }

    if ($newCode === '') {
        echo json_encode(['error' => 1, 'message' => 'Competition code is required']);
        return;
    }

    if (!is_array($parts) || !count($parts)) {
        echo json_encode(['error' => 1, 'message' => 'Select at least one part to clone']);
        return;
    }

    $partsMap = [];
    foreach (getClonePartDefinitions() as $def) {
        $partsMap[$def['key']] = $def;
    }

    $selectedDefs = [];
    foreach ($parts as $partKey) {
        $k = trim((string)$partKey);
        if ($k !== '' && isset($partsMap[$k])) {
            $selectedDefs[] = $partsMap[$k];
        }
    }

    if (!count($selectedDefs)) {
        echo json_encode(['error' => 1, 'message' => 'No valid clone parts selected']);
        return;
    }

    safe_w_BeginTransaction();

    list($newTournamentId, $createdCode, $createError) = cloneTournamentRow($sourceTournamentId, $newName, $newCode);
    if ($newTournamentId <= 0) {
        safe_w_Rollback();
        echo json_encode(['error' => 1, 'message' => ($createError ?: 'Failed to create tournament')]);
        return;
    }

    foreach ($selectedDefs as $def) {
        foreach ($def['tables'] as $tableName => $tournamentColumn) {
            copyTournamentTable($tableName, $tournamentColumn, $sourceTournamentId, $newTournamentId);
        }
    }

    safe_w_Commit();

    echo json_encode([
        'error' => 0,
        'message' => 'Tournament cloned successfully',
        'newTournamentId' => $newTournamentId,
        'newCode' => $createdCode,
        'newName' => $newName,
    ]);
}
