<?php
/**
 * API Endpoints for Interactive Target Assignment
 * Handles: getCurrent, validate, previewAutoAssign, apply
 */

require_once(dirname(__FILE__, 3) . '/config.php');
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

function respondJson($payload) {
    echo json_encode($payload);
}

function respondError($message, $code = 'BAD_REQUEST', $extra = array()) {
    $payload = array_merge(array(
        'error' => 1,
        'status' => 'error',
        'code' => $code,
        'message' => (string)$message,
    ), is_array($extra) ? $extra : array());

    respondJson($payload);
}

function respondSuccess($payload = array(), $code = 'OK') {
    $base = array(
        'error' => 0,
        'status' => 'ok',
        'code' => $code,
    );

    respondJson(array_merge($base, is_array($payload) ? $payload : array()));
}

// Security checks
if (!CheckTourSession()) {
    respondError(get_text('CrackError'), 'AUTH_REQUIRED');
    exit;
}

checkFullACL(AclParticipants, 'pTarget', AclReadWrite, false);

if (IsBlocked(BIT_BLOCK_PARTICIPANT)) {
    respondError(get_text('BlockedPhase', 'Tournament'), 'PHASE_BLOCKED');
    exit;
}

require_once('Common/Fun_Sessions.inc.php');
require_once('Common/Fun_FormatText.inc.php');
if (!function_exists('getModuleParameter')) {
    require_once(dirname(__FILE__, 5) . '/Common/Lib/Fun_Modules.php');
}

function requestString($key, $default = '') {
    if (!isset($_REQUEST[$key])) {
        return (string)$default;
    }

    return trim((string)$_REQUEST[$key]);
}

function requestInt($key, $default = 0) {
    if (!isset($_REQUEST[$key])) {
        return intval($default);
    }

    return intval($_REQUEST[$key]);
}

function requestNullableInt($key) {
    if (!isset($_REQUEST[$key]) || $_REQUEST[$key] === '') {
        return null;
    }

    return intval($_REQUEST[$key]);
}

function requestBoolInt($key, $default = 0) {
    if (!isset($_REQUEST[$key])) {
        return intval($default) ? 1 : 0;
    }

    $value = $_REQUEST[$key];
    if (is_bool($value)) {
        return $value ? 1 : 0;
    }

    $normalized = strtolower(trim((string)$value));
    if (in_array($normalized, array('1', 'true', 'yes', 'on'), true)) {
        return 1;
    }
    if (in_array($normalized, array('0', 'false', 'no', 'off', ''), true)) {
        return 0;
    }

    return intval($value) ? 1 : 0;
}

function requestJsonArray($key, $default = array()) {
    if (!isset($_REQUEST[$key])) {
        return is_array($default) ? $default : array();
    }

    $decoded = json_decode((string)$_REQUEST[$key], true);
    return is_array($decoded) ? $decoded : (is_array($default) ? $default : array());
}

function normalizeTargetRange($requestedFrom, $requestedTo, $sessionFirstTarget, $sessionTargetCount) {
    $sessionFirstTarget = intval($sessionFirstTarget);
    $sessionTargetCount = intval($sessionTargetCount);
    $sessionLastTarget = $sessionFirstTarget + max(0, $sessionTargetCount - 1);

    $requestedFrom = intval($requestedFrom);
    $requestedTo = intval($requestedTo);

    if ($requestedFrom <= 0) {
        $requestedFrom = $sessionFirstTarget;
    }
    if ($requestedTo <= 0) {
        $requestedTo = $sessionLastTarget;
    }

    if ($requestedFrom > $requestedTo) {
        $tmp = $requestedFrom;
        $requestedFrom = $requestedTo;
        $requestedTo = $tmp;
    }

    $fromTarget = max($requestedFrom, $sessionFirstTarget);
    $toTarget = min($requestedTo, $sessionLastTarget);

    // If requested range does not overlap session range, fallback to whole session range.
    if ($fromTarget > $toTarget) {
        $fromTarget = $sessionFirstTarget;
        $toTarget = $sessionLastTarget;
    }

    return array($fromTarget, $toTarget, $sessionLastTarget);
}

$action = requestString('action');

switch ($action) {
    case 'getCurrent':
        getCurrent();
        break;
    case 'validate':
        validateAssignments();
        break;
    case 'previewAutoAssign':
        previewAutoAssign();
        break;
    case 'apply':
        applyChanges();
        break;
    default:
        respondError('Invalid action', 'INVALID_ACTION');
}

/**
 * Get current target assignments for a session
 */
function getCurrent() {
    global $CFG;
    
    $session = requestInt('session', 0);
    $event = requestString('event', '%');
    $targetFrom = requestInt('targetFrom', 1);
    $targetTo = requestInt('targetTo', 999);
    
    if (!$session) {
        respondError('Session required', 'SESSION_REQUIRED');
        return;
    }
    
    // Get session info
    $sessionSql = "SELECT SesOrder, SesTar4Session, SesAth4Target, SesFirstTarget, SesName 
                   FROM Session 
                   WHERE SesTournament=" . StrSafe_DB($_SESSION['TourId']) . " 
                   AND SesType='Q' 
                   AND SesOrder=" . StrSafe_DB($session);
    $sessionRs = safe_r_sql($sessionSql);
    
    if (!safe_num_rows($sessionRs)) {
        respondError('Session not found', 'SESSION_NOT_FOUND');
        return;
    }
    
    $sessionInfo = safe_fetch($sessionRs);
    
    $detectionMethod = 'from session config'; // Track how we got the value
    
    // If SesAth4Target is 0 or not set, we need to get it from the Tournament table
    // This can happen with older tournaments or improperly configured sessions
    if (!$sessionInfo->SesAth4Target || $sessionInfo->SesAth4Target == 0) {
        // Try to get from Tournament table ToAth4Target field for this session
        $tourSql = "SELECT ToAth4Target" . $session . " as AthTarget FROM Tournament WHERE ToId=" . StrSafe_DB($_SESSION['TourId']);
        $tourRs = safe_r_sql($tourSql);
        if ($tourRow = safe_fetch($tourRs)) {
            $sessionInfo->SesAth4Target = intval($tourRow->AthTarget);
            if ($sessionInfo->SesAth4Target > 0) {
                $detectionMethod = 'from tournament config';
            }
        }
        
        // If still 0, try to auto-detect from existing participant assignments
        if (!$sessionInfo->SesAth4Target || $sessionInfo->SesAth4Target == 0) {
            $detectSql = "SELECT MAX(QuLetter) as MaxLetter 
                         FROM Qualifications 
                         WHERE QuSession=" . StrSafe_DB($session) . "
                         AND QuLetter != ''";
            $detectRs = safe_r_sql($detectSql);
            if ($detectRow = safe_fetch($detectRs)) {
                $maxLetter = $detectRow->MaxLetter;
                if ($maxLetter) {
                    // Convert letter to number (A=1, B=2, C=3, D=4, etc.)
                    $sessionInfo->SesAth4Target = ord($maxLetter) - ord('A') + 1;
                    $detectionMethod = 'auto-detected from assignments';
                }
            }
        }
        
        // If still 0, default to 3 (common for 40cm targets)
        if (!$sessionInfo->SesAth4Target || $sessionInfo->SesAth4Target == 0) {
            $sessionInfo->SesAth4Target = 3;
            $detectionMethod = 'using default value';
        }
    }
    
    $sessionFirstTarget = intval($sessionInfo->SesFirstTarget);
    list($fromTarget, $toTarget, $sessionLastTarget) = normalizeTargetRange(
        $targetFrom,
        $targetTo,
        $sessionFirstTarget,
        $sessionInfo->SesTar4Session
    );
    
    // Build event filter condition (handle OR patterns separated by |)
    $eventCondition = '';
    if ($event && $event !== '%') {
        $patterns = explode('|', $event);
        $conditions = array();
        foreach ($patterns as $pattern) {
            $pattern = trim($pattern);
            if ($pattern) {
                // Add % at end for LIKE matching if not already a wildcard
                if (strpos($pattern, '%') === false) {
                    $pattern .= '%';
                }
                $conditions[] = "CONCAT(TRIM(e.EnDivision), TRIM(e.EnClass)) LIKE " . StrSafe_DB($pattern);
            }
        }
        if (count($conditions) > 0) {
            $eventCondition = 'AND (' . implode(' OR ', $conditions) . ')';
        }
    }
    
    // Get participants with their current assignments and target face
    $sql = "SELECT 
                e.EnId, e.EnCode, e.EnName, e.EnFirstName, 
                e.EnDivision, e.EnClass, c.CoCode, c.CoName,
                q.QuTarget, q.QuLetter, e.EnTargetFace,
                tf.TfT1 as TargetFaceId,
                tf.TfW1 as TargetDiameter,
                CONCAT(TRIM(e.EnDivision), TRIM(e.EnClass)) as Event
            FROM Entries e
            INNER JOIN Countries c ON e.EnCountry = c.CoId
            INNER JOIN Qualifications q ON e.EnId = q.QuId
            LEFT JOIN TargetFaces tf ON e.EnTournament = tf.TfTournament AND e.EnTargetFace = tf.TfId
            WHERE e.EnTournament=" . StrSafe_DB($_SESSION['TourId']) . "
            AND q.QuSession=" . StrSafe_DB($session) . "
            $eventCondition
            ORDER BY q.QuTarget, q.QuLetter, e.EnName";
    
    $rs = safe_r_sql($sql);
    
    $participants = array();
    $participantCategories = array();
    $targetFaceIds = array(); // Collect unique target face IDs
    $targetDiameters = array(); // Collect diameters from TargetFaces
    while ($row = safe_fetch($rs)) {
        if ($row->TargetFaceId) {
            $targetFaceIds[$row->TargetFaceId] = true;
            // Track diameter from TargetFaces.TfW1
            if ($row->TargetDiameter) {
                $targetDiameters[$row->TargetFaceId] = intval($row->TargetDiameter);
            }
        }
        $participants[] = array(
            'id' => $row->EnId,
            'code' => $row->EnCode,
            'name' => trim($row->EnFirstName . ' ' . $row->EnName),
            'division' => $row->EnDivision,
            'class' => $row->EnClass,
            'event' => $row->Event,
            'country' => $row->CoCode,
            'countryName' => $row->CoName,
            'targetFaceId' => $row->TargetFaceId,
            'target' => $row->QuTarget,
            'letter' => $row->QuLetter,
            'targetFull' => $row->QuTarget ? str_pad($row->QuTarget, TargetNoPadding, '0', STR_PAD_LEFT) . $row->QuLetter : ''
        );

        $category = trim((string)$row->EnDivision) . trim((string)$row->EnClass);
        if ($category !== '') {
            $participantCategories[$category] = true;
        }
    }

    if (!empty($participants)) {
        $distanceProfiles = getDistanceProfilesByCategory(array_keys($participantCategories));
        foreach ($participants as &$participant) {
            $category = trim((string)$participant['division']) . trim((string)$participant['class']);
            $participant['distanceProfile'] = trim((string)($distanceProfiles[$category] ?? ''));
        }
        unset($participant);
    }
    
    // Generate available targets based on session config and requested range
    $availableTargets = array();
    $letters = range('A', chr(64 + $sessionInfo->SesAth4Target));
    
    for ($t = $fromTarget; $t <= $toTarget; $t++) {
        foreach ($letters as $letter) {
            $availableTargets[] = array(
                'target' => $t,
                'letter' => $letter,
                'targetFull' => str_pad($t, TargetNoPadding, '0', STR_PAD_LEFT) . $letter
            );
        }
    }
    
    // Get target face images for the faces used in this session
    $targetFaceImages = array();
    if (!empty($targetFaceIds)) {
        $tfSql = "SELECT TarId, TarDescr, TarFullSize FROM Targets WHERE TarId IN (" . implode(',', array_keys($targetFaceIds)) . ")";
        $tfRs = safe_r_sql($tfSql);
        while ($tfRow = safe_fetch($tfRs)) {
            // Try .svg first, then .svgz as fallback
            $imgPath = $CFG->DOCUMENT_PATH . 'Common/Images/Targets/' . $tfRow->TarId . '.svg';
            $imgUrl = $CFG->ROOT_DIR . 'Common/Images/Targets/' . $tfRow->TarId . '.svg';
            
            if (!file_exists($imgPath)) {
                $imgPath = $CFG->DOCUMENT_PATH . 'Common/Images/Targets/' . $tfRow->TarId . '.svgz';
                $imgUrl = $CFG->ROOT_DIR . 'Common/Images/Targets/' . $tfRow->TarId . '.svgz';
            }
            
            if (file_exists($imgPath)) {
                // Get diameter from TargetFaces data we collected earlier
                $diameter = isset($targetDiameters[$tfRow->TarId]) ? $targetDiameters[$tfRow->TarId] : null;
                
                $targetFaceImages[$tfRow->TarId] = array(
                    'id' => $tfRow->TarId,
                    'description' => $tfRow->TarDescr,
                    'diameter' => $diameter,
                    'url' => $imgUrl
                );
            }
        }
    }

    $savedLayoutId = getSavedTournamentLayoutPreference(intval($_SESSION['TourId']));
    
    respondSuccess([
        'session' => $sessionInfo->SesOrder,
        'sessionName' => $sessionInfo->SesName,
        'participants' => $participants,
        'availableTargets' => $availableTargets,
        'targetFaces' => array_values($targetFaceImages),
        'sessionInfo' => [
            'firstTarget' => $sessionInfo->SesFirstTarget,
            'targetCount' => $sessionInfo->SesTar4Session,
            'SesAth4Target' => $sessionInfo->SesAth4Target,
            'athPerTarget' => $sessionInfo->SesAth4Target,
            'loadedFrom' => $fromTarget,
            'loadedTo' => $toTarget,
            'detectionMethod' => $detectionMethod,
            'savedLayoutId' => $savedLayoutId
        ]
    ], 'GET_CURRENT_OK');
}

/**
 * Validate a set of assignments (check for conflicts)
 */
function validateAssignments() {
    $assignments = requestJsonArray('assignments', array());
    $currentAssignments = requestJsonArray('currentAssignments', array());
    $layoutId = requestString('layoutId', '');

    if ((!is_array($assignments) || empty($assignments)) && is_array($currentAssignments) && !empty($currentAssignments)) {
        $assignments = $currentAssignments;
    }
    
    $errors = array();
    $targetMap = array();
    
    // Check for duplicate assignments
    foreach ($assignments as $assignment) {
        $targetFull = $assignment['targetFull'] ?? '';
        $participantId = intval($assignment['participantId'] ?? 0);
        
        if (empty($targetFull)) {
            continue; // Unassigned is OK
        }
        
        if (!isset($targetMap[$targetFull])) {
            $targetMap[$targetFull] = array();
        }
        
        $targetMap[$targetFull][] = array(
            'id' => $participantId,
            'name' => $assignment['name'] ?? 'Unknown'
        );
    }
    
    // Find conflicts (multiple participants on same target)
    foreach ($targetMap as $target => $participants) {
        if (count($participants) > 1) {
            $names = array_map(function($p) { return $p['name']; }, $participants);
            $errors[] = array(
                'type' => 'duplicate',
                'target' => $target,
                'message' => "Multiple participants on target $target: " . implode(', ', $names)
            );
        }
    }
    
    // Check for unassigned participants (if all participants should have targets)
    $unassigned = array_filter($assignments, function($a) {
        return empty($a['targetFull']);
    });
    
    if (count($unassigned) > 0) {
        $errors[] = array(
            'type' => 'unassigned',
            'count' => count($unassigned),
            'message' => count($unassigned) . ' participant(s) without target assignment'
        );
    }

    $distanceErrors = validateMixedDistancesOnMat($assignments, $layoutId);
    if (!empty($distanceErrors)) {
        $errors = array_merge($errors, $distanceErrors);
    }
    
    respondSuccess([
        'valid' => count($errors) === 0,
        'errors' => $errors
    ], 'VALIDATE_OK');
}

function validateMixedDistancesOnMat($assignments, $layoutId) {
    $lanesPerMat = getLayoutLanesPerMat($layoutId);
    if ($lanesPerMat <= 0 || !is_array($assignments) || empty($assignments)) {
        return array();
    }

    $participantIds = array();
    $targetByParticipant = array();

    foreach ($assignments as $assignment) {
        $participantId = intval($assignment['participantId'] ?? 0);
        $targetFull = trim((string)($assignment['targetFull'] ?? ''));
        $target = parseTargetNumberFromTargetFull($targetFull);

        if ($participantId <= 0 || $target <= 0) {
            continue;
        }

        $participantIds[$participantId] = true;
        $targetByParticipant[$participantId] = $target;
    }

    if (empty($participantIds)) {
        return array();
    }

    $sql = "SELECT EnId, EnDivision, EnClass
            FROM Entries
            WHERE EnTournament=" . StrSafe_DB($_SESSION['TourId']) . "
              AND EnId IN (" . implode(',', array_map('intval', array_keys($participantIds))) . ")";
    $rs = safe_r_sql($sql);

    $categoryByParticipant = array();
    $categories = array();
    while ($row = safe_fetch($rs)) {
        $category = trim((string)$row->EnDivision) . trim((string)$row->EnClass);
        $participantId = intval($row->EnId);
        $categoryByParticipant[$participantId] = $category;
        if ($category !== '') {
            $categories[$category] = true;
        }
    }

    if (empty($categoryByParticipant) || empty($categories)) {
        return array();
    }

    $distanceProfileByCategory = getDistanceProfilesByCategory(array_keys($categories));

    $matProfiles = array();
    $matTargets = array();

    foreach ($targetByParticipant as $participantId => $targetNo) {
        $category = $categoryByParticipant[$participantId] ?? '';
        if ($category === '') {
            continue;
        }

        $distanceProfile = trim((string)($distanceProfileByCategory[$category] ?? ''));
        if ($distanceProfile === '') {
            continue;
        }

        $matIndex = intval(floor(($targetNo - 1) / $lanesPerMat));
        if (!isset($matProfiles[$matIndex])) {
            $matProfiles[$matIndex] = array();
            $matTargets[$matIndex] = array();
        }

        $matProfiles[$matIndex][$distanceProfile] = true;
        $matTargets[$matIndex][$targetNo] = true;
    }

    $errors = array();
    foreach ($matProfiles as $matIndex => $profiles) {
        $profileKeys = array_keys($profiles);
        if (count($profileKeys) <= 1) {
            continue;
        }

        sort($profileKeys);
        $targets = array_map('intval', array_keys($matTargets[$matIndex]));
        sort($targets);

        $errors[] = array(
            'type' => 'distance_mix',
            'targets' => $targets,
            'profiles' => $profileKeys,
            'message' => 'Mixed distances on the same mat (lanes ' . implode('/', $targets) . '): ' . implode(' vs ', $profileKeys)
        );
    }

    return $errors;
}

function parseTargetNumberFromTargetFull($targetFull) {
    if ($targetFull === '') {
        return 0;
    }

    if (!preg_match('/^(\d+)[A-Za-z]$/', $targetFull, $matches)) {
        return 0;
    }

    return intval($matches[1]);
}

function getLayoutLanesPerMat($layoutId) {
    $map = array(
        'layout_60cm_3_abc' => 2,       // 2 lanes per mat, 3 archers per lane
        'layout_60cm_4_split' => 1,     // 1 lane per mat, 4 archers per lane  
        'layout_40cm_4_quad' => 1,      // 1 lane per mat, 4 archers per lane
        'layout_40cm_6_triangle' => 2,  // 2 lanes per mat, 3 archers per lane
    );

    return isset($map[$layoutId]) ? intval($map[$layoutId]) : 0;
}

function getLayoutArchersPerTarget($layoutId) {
    $map = array(
        'layout_60cm_3_abc' => 3,
        'layout_40cm_6_triangle' => 3,
        'layout_60cm_4_split' => 4,
        'layout_40cm_4_quad' => 4,
        'layout_outdoor_mixed_2' => 2,
        'layout_outdoor_mixed_3' => 3,
        'layout_outdoor_mixed_4' => 4,
    );

    return isset($map[$layoutId]) ? intval($map[$layoutId]) : 0;
}

function getKnownLayoutIds() {
    return array(
        'layout_fallback_stacked',
        'layout_60cm_3_abc',
        'layout_40cm_6_triangle',
        'layout_60cm_4_split',
        'layout_40cm_4_quad',
        'layout_outdoor_mixed_2',
        'layout_outdoor_mixed_3',
        'layout_outdoor_mixed_4',
    );
}

function isKnownLayoutId($layoutId) {
    $layoutId = trim((string)$layoutId);
    if ($layoutId === '') {
        return false;
    }

    return in_array($layoutId, getKnownLayoutIds(), true);
}

function getSavedTournamentLayoutPreference($tourId) {
    $tourId = intval($tourId);
    if ($tourId <= 0 || !function_exists('getModuleParameter')) {
        return '';
    }

    $savedLayout = (string)getModuleParameter('LaneAssist', 'ManageTargetsLayout', '', $tourId);
    return isKnownLayoutId($savedLayout) ? $savedLayout : '';
}

function saveTournamentLayoutPreference($tourId, $layoutId) {
    $tourId = intval($tourId);
    $layoutId = trim((string)$layoutId);

    if ($tourId <= 0 || !function_exists('setModuleParameter')) {
        return;
    }

    if (!isKnownLayoutId($layoutId)) {
        return;
    }

    setModuleParameter('LaneAssist', 'ManageTargetsLayout', $layoutId, $tourId);
}

function getDistanceProfilesByCategory($categories) {
    $profiles = array();
    if (empty($categories)) {
        return $profiles;
    }

    foreach ($categories as $category) {
        $category = trim((string)$category);
        if ($category === '') {
            continue;
        }

        $sql = "SELECT Td1, Td2, Td3, Td4, Td5, Td6, Td7, Td8
                FROM TournamentDistances
                WHERE TdType=" . StrSafe_DB($_SESSION['TourType']) . "
                  AND TdTournament=" . StrSafe_DB($_SESSION['TourId']) . "
                  AND " . StrSafe_DB($category) . " LIKE TdClasses
                ORDER BY TdTournament=0,
                         " . StrSafe_DB($category) . " = TdClasses DESC,
                         LEFT(TdClasses,1)!='_' AND LEFT(TdClasses,1)!='%' DESC,
                         LEFT(TdClasses,1)='_' DESC,
                         TdClasses DESC,
                         TdClasses='%'
                LIMIT 1";

        $rs = safe_r_sql($sql);
        $profile = '';
        if ($row = safe_fetch($rs)) {
            $distances = array();
            for ($index = 1; $index <= 8; $index++) {
                $fieldName = 'Td' . $index;
                $normalized = normalizeDistanceToken((string)($row->$fieldName ?? ''));
                if ($normalized !== '') {
                    $distances[$normalized] = true;
                }
            }

            $distanceList = array_keys($distances);
            usort($distanceList, function($a, $b) {
                $na = intval($a);
                $nb = intval($b);
                if ($na === $nb) {
                    return strcmp($a, $b);
                }
                return $na - $nb;
            });

            $profile = implode('-', $distanceList);
        }

        $profiles[$category] = $profile;
    }

    return $profiles;
}

function normalizeDistanceToken($token) {
    $token = trim((string)$token);
    if ($token === '' || $token === '-') {
        return '';
    }

    if (preg_match('/(\d+)/', $token, $matches)) {
        return intval($matches[1]) . 'm';
    }

    return '';
}

function getDistFields() {
    $result = array();

    $query = "SELECT DISTINCT EnDivision, EnClass
              FROM Entries
              WHERE EnTournament=" . StrSafe_DB($_SESSION['TourId']);
    $rows = safe_r_sql($query);

    while ($row = safe_fetch($rows)) {
        $category = trim((string)$row->EnDivision) . trim((string)$row->EnClass);

        $distanceQuery = "SELECT CONCAT_WS('#', Td1, Td2, Td3, Td4, Td5, Td6, Td7, Td8) as QryFields
                          FROM TournamentDistances
                          WHERE TdType=" . StrSafe_DB($_SESSION['TourType']) . "
                            AND TdTournament=" . StrSafe_DB($_SESSION['TourId']) . "
                            AND " . StrSafe_DB($category) . " LIKE TdClasses
                          ORDER BY TdTournament=0,
                                   " . StrSafe_DB($category) . " = TdClasses DESC,
                                   LEFT(TdClasses,1)!='_' AND LEFT(TdClasses,1)!='%' DESC,
                                   LEFT(TdClasses,1)='_' DESC,
                                   TdClasses DESC,
                                   TdClasses='%'
                          LIMIT 1";

        $distanceRows = safe_r_sql($distanceQuery);
        if ($distanceRow = safe_fetch($distanceRows)) {
            $result[$category] = str_replace(array('-', '#'), array('', '-'), (string)$distanceRow->QryFields);
        }
    }

    return $result;
}

/**
 * Preview auto-assignment without making changes
 */
function previewAutoAssign() {
    $session = requestInt('session', 0);
    $event = requestString('event', '%');
    $targetFrom = requestInt('targetFrom', 1);
    $targetTo = requestInt('targetTo', 999);
    $drawType = requestInt('drawType', 0);
    $groupByDiv = requestBoolInt('groupByDiv', 0);
    $groupByClass = requestBoolInt('groupByClass', 0);
    $excludeAssigned = requestBoolInt('excludeAssigned', 1);
    $packGroups = requestBoolInt('packGroups', 0);
    if (!isset($_REQUEST['packGroups']) && isset($_REQUEST['reserveGroupBlock'])) {
        // Backward compatibility with old request key
        $packGroups = requestBoolInt('reserveGroupBlock', 1) ? 0 : 1;
    }
    $reserveGroupBlock = ($packGroups ? 0 : 1);
    $currentAssignments = requestJsonArray('currentAssignments', array());
    $archersPerTargetOverride = requestNullableInt('archersPerTarget');
    $layoutId = requestString('layoutId', '');
    
    if (!$session) {
        respondError('Session required', 'SESSION_REQUIRED');
        return;
    }
    // Session info
    $sessionSql = "SELECT SesOrder, SesTar4Session, SesAth4Target, SesFirstTarget 
                   FROM Session 
                   WHERE SesTournament=" . StrSafe_DB($_SESSION['TourId']) . " 
                   AND SesType='Q' 
                   AND SesOrder=" . StrSafe_DB($session);
    $sessionRs = safe_r_sql($sessionSql);
    if (!safe_num_rows($sessionRs)) {
        respondError('Session not found', 'SESSION_NOT_FOUND');
        return;
    }
    $sessionInfo = safe_fetch($sessionRs);

    // Archers per target from explicit override first
    $athPerTarget = $archersPerTargetOverride !== null ? $archersPerTargetOverride : $sessionInfo->SesAth4Target;

    // Safety: when layout is selected, use its configured archers count
    $layoutArchers = getLayoutArchersPerTarget($layoutId);
    if ($layoutArchers > 0) {
        $athPerTarget = $layoutArchers;
    }

    if (!$athPerTarget || $athPerTarget == 0) {
        $tourSql = "SELECT ToAth4Target" . $session . " as AthTarget FROM Tournament WHERE ToId=" . StrSafe_DB($_SESSION['TourId']);
        $tourRs = safe_r_sql($tourSql);
        if ($tourRow = safe_fetch($tourRs)) {
            $athPerTarget = intval($tourRow->AthTarget);
        }
        if (!$athPerTarget || $athPerTarget == 0) {
            $athPerTarget = 3;
        }
    }

    // Original letter ordering
    $TgtArray = array();
    if (intval($athPerTarget) === 4) {
        if ($drawType === 3) {
            $TgtArray = array('A', 'B', 'C', 'D');
        } else {
            $TgtArray = array('A', 'C', 'B', 'D');
        }
    } else {
        $startLetter = 'A';
        for ($i = 1; $i <= intval($athPerTarget); $i++) {
            $TgtArray[] = $startLetter++;
        }
    }

    $ArcPerButt = count($TgtArray);
    $nextTarget = ($drawType == 1 ? 2 : 1);
    $Offset2019 = ($drawType == 3 ? 1 : 0);

    // Build assignment snapshot maps from current unsaved UI state
    $snapshotAssignedByParticipant = array();
    $snapshotOccupiedTargets = array();
    if (is_array($currentAssignments)) {
        foreach ($currentAssignments as $assignment) {
            $participantId = intval($assignment['participantId'] ?? 0);
            $targetFull = strtoupper(trim((string)($assignment['targetFull'] ?? '')));
            if ($participantId > 0) {
                $snapshotAssignedByParticipant[$participantId] = $targetFull;
            }
            if ($targetFull !== '') {
                $snapshotOccupiedTargets[$targetFull] = true;
            }
        }
    }

    $sessionFirstTarget = intval($sessionInfo->SesFirstTarget);
    list($CurTarget, $EndTarget) = normalizeTargetRange(
        $targetFrom,
        $targetTo,
        $sessionFirstTarget,
        $sessionInfo->SesTar4Session
    );
    $CurPlace = 0;
    $EndPlace = $ArcPerButt - 1;

    $TgtAvailable = array();
    for ($i = $CurTarget; $i <= $EndTarget; $i++) {
        for ($j = 0; $j < $ArcPerButt; $j++) {
            $targetFull = str_pad($i . $TgtArray[$j], (TargetNoPadding + 1), '0', STR_PAD_LEFT);
            if (!empty($snapshotOccupiedTargets)) {
                $TgtAvailable[$targetFull] = (empty($snapshotOccupiedTargets[$targetFull]) ? 1 : 0);
            } else {
                $checkSql = "SELECT q.QuId
                             FROM Qualifications q
                             INNER JOIN Entries e ON q.QuId = e.EnId
                             WHERE q.QuSession=" . StrSafe_DB($session) . "
                               AND q.QuTarget=" . intval($i) . "
                               AND q.QuLetter=" . StrSafe_DB($TgtArray[$j]) . "
                               AND e.EnTournament=" . StrSafe_DB($_SESSION['TourId']);
                $checkRs = safe_r_sql($checkSql);
                $TgtAvailable[$targetFull] = (safe_num_rows($checkRs) == 1 ? 0 : 1);
            }
        }
    }

    $Distances = getDistFields();
    $sortOrder = array('DivViewOrder', 'ClViewOrder', 'rand()');

    // Use original single LIKE event filter
    $eventFilter = ($event && $event !== '' ? $event : '%');

    $sql = "SELECT
                e.EnId, e.EnFirstName, e.EnName,
                e.EnWChair, e.EnSitting, e.EnDoubleSpace,
                e.EnDivision, e.EnClass, e.EnTargetFace,
                c.CoCode as EnCountry,
                CONCAT(TRIM(e.EnDivision), TRIM(e.EnClass)) as Event,
                CONCAT_WS('|', CONCAT_WS('-', tf.TfW1, tf.TfW2, tf.TfW3, tf.TfW4, tf.TfW5, tf.TfW6, tf.TfW7, tf.TfW8),
                               CONCAT_WS('-', tf.TfT1, tf.TfT2, tf.TfT3, tf.TfT4, tf.TfT5, tf.TfT6, tf.TfT7, tf.TfT8)) as Target
            FROM Entries e
            INNER JOIN Countries c ON e.EnCountry = c.CoId
            INNER JOIN Qualifications q ON e.EnId = q.QuId
            INNER JOIN Divisions d ON e.EnDivision = d.DivId AND e.EnTournament = d.DivTournament AND d.DivAthlete=1
            INNER JOIN Classes cl ON e.EnClass = cl.ClId AND e.EnTournament = cl.ClTournament AND cl.ClAthlete=1
            INNER JOIN TargetFaces tf ON e.EnTargetFace = tf.TfId AND e.EnTournament = tf.TfTournament
            WHERE e.EnTournament=" . StrSafe_DB($_SESSION['TourId']) . "
              AND CONCAT(TRIM(e.EnDivision),TRIM(e.EnClass)) LIKE " . StrSafe_DB($eventFilter) . "
              AND q.QuSession=" . StrSafe_DB($session);

    if ($excludeAssigned && empty($snapshotAssignedByParticipant)) {
        $sql .= " AND q.QuTarget=0";
    }

    $sql .= " ORDER BY " . implode(',', $sortOrder);
    $rs = safe_r_sql($sql);

    // Original grouping structures
    $Entries = array();
    $Groups = array();
    $GroupsTotals = array();
    $Hspace = array();
    $Vspace = array();
    $participantIdsInQuery = array();
    $participantInfo = array();

    $GroupIndex = '%1$s|';
    if ($groupByDiv) {
        $GroupIndex .= '%2$s';
    }
    if ($groupByClass) {
        $GroupIndex .= '%3$s';
    }
    $GroupIndex .= '|%4$s';

    while ($row = safe_fetch($rs)) {
        $participantId = intval($row->EnId);

        if ($excludeAssigned && !empty($snapshotAssignedByParticipant)) {
            $snapshotTarget = trim((string)($snapshotAssignedByParticipant[$participantId] ?? ''));
            if ($snapshotTarget !== '') {
                continue;
            }
        }

        $participantIdsInQuery[] = $participantId;
        $participantInfo[$participantId] = array(
            'name' => trim($row->EnFirstName . ' ' . $row->EnName)
        );

        $eventKey = trim((string)$row->Event);
        $distanceKey = isset($Distances[$eventKey]) ? $Distances[$eventKey] : '';
        $index = sprintf($GroupIndex, $distanceKey, $row->EnDivision, $row->EnClass, $row->Target);

        if (!isset($Entries[$index])) {
            $Entries[$index] = array();
        }
        if (!isset($Entries[$index][$row->EnCountry])) {
            $Entries[$index][$row->EnCountry] = array();
        }
        $Entries[$index][$row->EnCountry][] = $participantId;

        if (empty($Groups[$index][$row->EnCountry])) {
            $Groups[$index][$row->EnCountry] = 0;
        }
        $Groups[$index][$row->EnCountry]++;

        if (empty($GroupsTotals[$index])) {
            $GroupsTotals[$index] = 0;
        }
        $GroupsTotals[$index]++;

        if ($row->EnWChair || $row->EnSitting || $row->EnDivision == 'VI') {
            if ($ArcPerButt >= 4) {
                $Vspace[] = $participantId;
                $Groups[$index][$row->EnCountry]++;
                $GroupsTotals[$index]++;
            } else {
                $Groups[$index][$row->EnCountry] += 0.3;
                $GroupsTotals[$index] += 0.3;
            }
        }

        if ($row->EnDoubleSpace) {
            $Hspace[] = $participantId;
            $extra = in_array($participantId, $Vspace) ? 2 : 1;
            $Groups[$index][$row->EnCountry] += $extra;
            $GroupsTotals[$index] += $extra;
        }
    }

    krsort($Groups);

    $assignmentsById = array();
    $LastLetter = $TgtArray[count($TgtArray) - 1];
    $firstLine = array('AB' . ($ArcPerButt > 4 ? 'CD' : ''), 'CD');
    $LeftSide = array('AC' . ($ArcPerButt > 4 ? 'E' : ''), 'BD');
    $IndGap = 0;

    foreach ($Groups as $Index => $Group) {
        $ToggleZigZag = 0;
        arsort($Group);

        // Optional reserve/jump alignment toggle
        if ($reserveGroupBlock) {
            $tmpIndex = array_search(1, $TgtAvailable, true);
            while ($tmpIndex && substr($tmpIndex, -1) != 'A') {
                $TgtAvailable[$tmpIndex] = 0;
                $tmpIndex = array_search(1, $TgtAvailable, true);
            }
        }

        $GroupAvailable = array();
        $freeKeys = array_keys($TgtAvailable, 1, true);
        $sliceCount = ($reserveGroupBlock ? ($ArcPerButt * (1 + intval($GroupsTotals[$Index]) / $ArcPerButt) - 1) : intval($GroupsTotals[$Index]));
        foreach (array_slice($freeKeys, 0, $sliceCount) as $key) {
            $GroupAvailable[$key] = $TgtAvailable[$key];
        }

        $CurIndices = array_keys($GroupAvailable, 1, true);
        $BottomCountry = false;
        $Assignments = array();
        $CurIndex = 0;

        while ($Group) {
            $Country = key($Group);
            if ($BottomCountry) {
                end($Group);
                $Country = key($Group);
                $Group = array_slice($Group, 0, -1, true);
            } else {
                $Group = array_slice($Group, 1, count($Group), true);
            }

            if (!$BottomCountry) {
                $CurIndices = array_keys($GroupAvailable, 1, true);
                if ($ToggleZigZag) {
                    $CurIndices = array_reverse($CurIndices);
                }
                if ($CurIndices) {
                    if ($ToggleZigZag) {
                        $CurIndex = array_search($CurIndices[0], array_reverse(array_keys($GroupAvailable), true), true);
                    } else {
                        $CurIndex = array_search($CurIndices[0], array_keys($GroupAvailable), true);
                    }
                }
            }

            if ($drawType == 2 || $drawType == 3) {
                if ($BottomCountry) {
                    $IndGap++;
                    if ($IndGap == 2) {
                        $BottomCountry = !$BottomCountry;
                    }
                } else {
                    $BottomCountry = !$BottomCountry;
                    $IndGap = 0;
                }
            }

            foreach (($Entries[$Index][$Country] ?? array()) as $Archer) {
                if (!$CurIndices) {
                    continue;
                }

                $slice = array_slice($GroupAvailable, intval($CurIndex), 1, true);
                $ThisTarget = key($slice);

                if (!($ThisTarget && !empty($GroupAvailable[$ThisTarget]))) {
                    while (($ToggleZigZag ? $CurIndex > 0 : $CurIndex < count($GroupAvailable)) && empty($GroupAvailable[$ThisTarget])) {
                        if ($ToggleZigZag) {
                            $CurIndex -= ($ArcPerButt * $nextTarget);
                        } else {
                            $CurIndex += ($ArcPerButt * $nextTarget);
                        }
                        $slice = array_slice($GroupAvailable, intval($CurIndex), 1, true);
                        $ThisTarget = key($slice);
                    }

                    if ($CurIndex >= count($GroupAvailable)) {
                        $CurIndices = array_keys($GroupAvailable, 1, true);
                        if ($ToggleZigZag) {
                            $CurIndices = array_reverse($CurIndices);
                        }
                        if ($CurIndices) {
                            if ($ToggleZigZag) {
                                $CurIndex = array_search($CurIndices[0], array_reverse(array_keys($GroupAvailable), true), true);
                            } else {
                                $CurIndex = array_search($CurIndices[0], array_keys($GroupAvailable), true);
                            }
                        }
                        $slice = array_slice($GroupAvailable, intval($CurIndex), 1, true);
                        $ThisTarget = key($slice);
                    }
                }

                if ($ArcPerButt >= 4 && in_array($Archer, $Vspace)) {
                    $ind = array_search($ThisTarget, $CurIndices, true);
                    $found = false;
                    while (!empty($CurIndices[$ind]) && !$found) {
                        $let = substr($CurIndices[$ind], -1);
                        $nextLetterIdx = array_search($let, $TgtArray, true) + ($ToggleZigZag ? -1 : 1);
                        $targ2 = str_replace($let, $TgtArray[$nextLetterIdx] ?? $let, $CurIndices[$ind]);
                        $found = (strstr($firstLine[$ToggleZigZag], $let)
                                  && in_array($targ2, $CurIndices, true)
                                  && !empty($GroupAvailable[$targ2])
                                  && !empty($GroupAvailable[$CurIndices[$ind]]));
                        if (!$found) {
                            $ind++;
                        }
                    }

                    if ($found) {
                        $Assignments[$CurIndices[$ind]] = intval($Archer);
                        $GroupAvailable[$CurIndices[$ind]] = 0;
                        $GroupAvailable[$targ2] = 0;
                        if ($ToggleZigZag) {
                            $CurIndex = array_search($CurIndices[$ind], array_reverse(array_keys($GroupAvailable), true), true);
                        } else {
                            $CurIndex = array_search($CurIndices[$ind], array_keys($GroupAvailable), true);
                        }
                    }
                } elseif ($ArcPerButt >= 4 && in_array($Archer, $Hspace)) {
                    $ind = 0;
                    $found = false;
                    while (!empty($CurIndices[$ind]) && !$found) {
                        $let = substr($CurIndices[$ind], -1);
                        $targ2 = str_replace($let, $TgtArray[2 + array_search($let, $TgtArray, true)] ?? $let, $CurIndices[$ind]);
                        $found = (strstr($LeftSide[$ToggleZigZag], $let)
                                  && in_array($targ2, $CurIndices, true)
                                  && !empty($GroupAvailable[$targ2])
                                  && !empty($GroupAvailable[$CurIndices[$ind]]));
                        if (!$found) {
                            $ind++;
                        }
                    }

                    if ($found) {
                        $Assignments[$CurIndices[$ind]] = intval($Archer);
                        $GroupAvailable[$CurIndices[$ind]] = 0;
                        $GroupAvailable[$targ2] = 0;
                        if ($ToggleZigZag) {
                            $CurIndex = array_search($CurIndices[$ind], array_reverse(array_keys($GroupAvailable), true), true);
                        } else {
                            $CurIndex = array_search($CurIndices[$ind], array_keys($GroupAvailable), true);
                        }
                    }
                } elseif (!empty($GroupAvailable[$ThisTarget])) {
                    $Assignments[$ThisTarget] = intval($Archer);
                    $GroupAvailable[$ThisTarget] = 0;
                }

                // Move to following slot
                if ($Offset2019) {
                    if (substr($ThisTarget, -1) == $LastLetter) {
                        if ($ToggleZigZag) {
                            $CurIndex -= $Offset2019;
                        } else {
                            $CurIndex += $Offset2019;
                        }
                    } else {
                        if ($ToggleZigZag) {
                            $CurIndex -= ($ArcPerButt * $nextTarget) - $Offset2019;
                        } else {
                            $CurIndex += ($ArcPerButt * $nextTarget) + $Offset2019;
                        }
                    }
                } else {
                    if ($ToggleZigZag) {
                        $CurIndex -= ($ArcPerButt * $nextTarget) - $Offset2019;
                    } else {
                        $CurIndex += ($ArcPerButt * $nextTarget) + $Offset2019;
                    }
                }

                if ($CurIndex >= count($GroupAvailable) || $CurIndex < 0) {
                    $CurIndices = array_keys($GroupAvailable, 1, true);
                    if ($ToggleZigZag) {
                        $CurIndices = array_reverse($CurIndices);
                    }
                    if ($CurIndices) {
                        if ($ToggleZigZag) {
                            $CurIndex = array_search($CurIndices[0], array_reverse(array_keys($GroupAvailable), true), true);
                        } else {
                            $CurIndex = array_search($CurIndices[0], array_keys($GroupAvailable), true);
                        }
                    }
                }
            }
        }

        ksort($Assignments);
        foreach ($Assignments as $targetFull => $participantId) {
            $assignmentsById[$participantId] = array(
                'participantId' => intval($participantId),
                'name' => $participantInfo[$participantId]['name'] ?? '',
                'target' => intval($targetFull),
                'letter' => strtoupper(substr($targetFull, -1)),
                'targetFull' => $targetFull
            );
        }

        // Occupy group slots in global availability map
        foreach ($GroupAvailable as $key => $value) {
            $TgtAvailable[$key] = $value;
        }
    }

    $assignments = array_values($assignmentsById);
    
    respondSuccess([
        'assignments' => $assignments,
        'participantIdsInQuery' => $participantIdsInQuery,
        'groupCount' => count($Groups),
        'participantCount' => count($assignments)
    ], 'PREVIEW_AUTO_ASSIGN_OK');
}

/**
 * Apply assignments to database
 */
function applyChanges() {
    $changes = requestJsonArray('changes', array());
    $session = requestInt('session', 0);
    $archersPerTarget = requestNullableInt('archersPerTarget');
    $layoutId = requestString('layoutId', '');
    $hasLayoutPreferenceUpdate = ($layoutId !== '');
    
    if (!$session) {
        respondError('Session required', 'SESSION_REQUIRED');
        return;
    }
    
    if ($hasLayoutPreferenceUpdate && !isKnownLayoutId($layoutId)) {
        respondError('Invalid layout', 'INVALID_LAYOUT');
        return;
    }

    if (empty($changes) && !$archersPerTarget && !$hasLayoutPreferenceUpdate) {
        respondError('No changes to apply', 'NO_CHANGES');
        return;
    }
    
    // Update archers per target if specified
    if ($archersPerTarget && $archersPerTarget > 0) {
        $updateSessionSql = "UPDATE Session 
                            SET SesAth4Target=" . intval($archersPerTarget) . " 
                            WHERE SesTournament=" . StrSafe_DB($_SESSION['TourId']) . " 
                            AND SesType='Q' 
                            AND SesOrder=" . StrSafe_DB($session);
        safe_w_sql($updateSessionSql);
    }
    
    // If there are no target assignment changes, just return success
    if (empty($changes)) {
        if ($hasLayoutPreferenceUpdate) {
            saveTournamentLayoutPreference(intval($_SESSION['TourId']), $layoutId);
        }

        respondSuccess([
            'results' => [],
            'successCount' => 0,
            'errorCount' => 0,
            'message' => 'Settings updated successfully'
        ], 'APPLY_OK');
        return;
    }
    
    $results = array();
    $successCount = 0;
    $errorCount = 0;
    
    foreach ($changes as $change) {
        $participantId = intval($change['participantId']);
        $targetFull = $change['targetFull'] ?? '';
        
        if (!$participantId) {
            continue;
        }
        
        // Parse target and letter
        if (empty($targetFull)) {
            // Clear assignment
            $target = 0;
            $letter = '';
        } else {
            $target = intval($targetFull);
            $letter = strtoupper(substr($targetFull, -1));
        }
        
        // Validate target is available for this session
        if ($target > 0) {
            $sessionSql = "SELECT SesOrder FROM Session 
                          WHERE SesTournament=" . StrSafe_DB($_SESSION['TourId']) . " 
                          AND SesType='Q' 
                          AND SesOrder=" . StrSafe_DB($session);
            $sessionRs = safe_r_sql($sessionSql);
            
            if (!safe_num_rows($sessionRs)) {
                $results[] = array(
                    'participantId' => $participantId,
                    'success' => false,
                    'message' => 'Invalid session'
                );
                $errorCount++;
                continue;
            }
            
            // Check target is available using existing validation
            $atSql = createAvailableTargetSQL($session, $_SESSION['TourId']);
            $checkSql = "SELECT * FROM ($atSql) at 
                        WHERE FullTgtTarget=" . intval($target) . " 
                        AND FullTgtLetter=" . StrSafe_DB($letter);
            $checkRs = safe_r_sql($checkSql);
            
            if (!safe_num_rows($checkRs)) {
                $results[] = array(
                    'participantId' => $participantId,
                    'success' => false,
                    'message' => 'Invalid target for session'
                );
                $errorCount++;
                continue;
            }
        }
        
        // Update the assignment
        $updateSql = "UPDATE Qualifications 
                     SET QuTarget=" . intval($target) . ", 
                         QuLetter=" . StrSafe_DB($letter) . ",
                         QuBacknoPrinted=0,
                         QuTimestamp=QuTimestamp 
                     WHERE QuId=" . StrSafe_DB($participantId);
        
        safe_w_sql($updateSql);
        
        if (safe_w_affected_rows() > 0) {
            safe_w_sql("UPDATE Entries SET EnTimestamp='" . date('Y-m-d H:i:s') . "' 
                       WHERE EnId=" . StrSafe_DB($participantId));
            
            $results[] = array(
                'participantId' => $participantId,
                'success' => true,
                'message' => 'Updated'
            );
            $successCount++;
        } else {
            $results[] = array(
                'participantId' => $participantId,
                'success' => false,
                'message' => 'No changes made'
            );
        }
    }
    
    $message = "$successCount change(s) applied successfully";
    if ($errorCount > 0) {
        $message .= ", $errorCount error(s)";
    }
    if ($archersPerTarget && $archersPerTarget > 0) {
        $message .= ". Archers per target updated to $archersPerTarget";
    }
    if ($hasLayoutPreferenceUpdate) {
        saveTournamentLayoutPreference(intval($_SESSION['TourId']), $layoutId);
        $message .= '. Layout preference saved';
    }
    
    respondSuccess([
        'results' => $results,
        'successCount' => $successCount,
        'errorCount' => $errorCount,
        'message' => $message
    ], 'APPLY_OK');
}
?>
