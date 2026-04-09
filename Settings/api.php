<?php
/**
 * LaneAssist Settings API
 */

require_once(dirname(__FILE__, 3) . '/config.php');
header('Content-Type: application/json');

$hasCompetition = CheckTourSession();
if ($hasCompetition) {
    checkFullACL(AclCompetition, 'cSchedule', AclReadWrite, false);
}

if (!function_exists('getModuleParameter')) {
    require_once(dirname(__FILE__, 5) . '/Common/Lib/Fun_Modules.php');
}

$action = $_REQUEST['action'] ?? '';

switch ($action) {
    case 'getSettings':
        getSettings();
        break;
    case 'saveAdminSettings':
        saveAdminSettings();
        break;
    case 'saveUserSettings':
        saveUserSettings();
        break;
    case 'saveGlobalSettings':
        saveUserSettings();
        break;
    case 'saveCompetitionSettings':
        saveTournamentSettings();
        break;
    case 'saveTournamentSettings':
        saveTournamentSettings();
        break;
    case 'checkUpdates':
        checkUpdates();
        break;
    case 'applyUpdateFromFile':
        applyUpdateFromFile();
        break;
    case 'submitFeedback':
        submitFeedback();
        break;
    case 'getFeedbackQueue':
        getFeedbackQueue();
        break;
    case 'donateIntent':
        donateIntent();
        break;
    default:
        echo json_encode(['error' => 1, 'message' => 'Invalid action']);
}

function getSettings() {
    $isAdmin = isLaneAssistAdmin();

    $adminDefaultFinalsLength = intval(getGlobalModuleParameter('LaneAssist', 'AdminDefaultFinalsLength', 30));
    if ($adminDefaultFinalsLength <= 0) {
        $adminDefaultFinalsLength = 30;
    }

    $adminHideIanseoUpdateMenuEntry = intval(getGlobalModuleParameter('LaneAssist', 'AdminHideIanseoUpdateMenuEntry', 0)) ? 1 : 0;
    $adminHideCloneTournamentEntry = intval(getGlobalModuleParameter('LaneAssist', 'AdminHideCloneTournamentEntry', 0)) ? 1 : 0;
    $adminHideTargetFacesEntry = intval(getGlobalModuleParameter('LaneAssist', 'AdminHideTargetFacesEntry', 0)) ? 1 : 0;

    $userDefaultFinalsLength = intval(getUserScopedGlobalSetting('LaneAssist', 'DefaultFinalsLength', $adminDefaultFinalsLength));
    if ($userDefaultFinalsLength <= 0) {
        $userDefaultFinalsLength = $adminDefaultFinalsLength;
    }

    $hasCompetition = !empty($_SESSION['TourId']);
    $tournamentDefaultFinalsLength = 0;
    $tournamentManageTargetsLayout = '';
    if ($hasCompetition) {
        $tournamentDefaultFinalsLength = intval(getModuleParameter('LaneAssist', 'DefaultFinalsLength', 0, intval($_SESSION['TourId'])));
        if ($tournamentDefaultFinalsLength < 0) {
            $tournamentDefaultFinalsLength = 0;
        }

        $savedLayout = trim((string)getModuleParameter('LaneAssist', 'ManageTargetsLayout', '', intval($_SESSION['TourId'])));
        $tournamentManageTargetsLayout = isKnownManageTargetsLayout($savedLayout) ? $savedLayout : '';
    }

    $effective = $tournamentDefaultFinalsLength > 0 ? $tournamentDefaultFinalsLength : $userDefaultFinalsLength;

    echo json_encode([
        'error' => 0,
        'settings' => [
            'access' => [
                'isAdmin' => $isAdmin ? 1 : 0,
            ],
            'admin' => [
                'defaultFinalsLength' => $adminDefaultFinalsLength,
                'menu' => [
                    'hideIanseoUpdateEntry' => $adminHideIanseoUpdateMenuEntry,
                    'hideCloneTournamentEntry' => $adminHideCloneTournamentEntry,
                    'hideTargetFacesEntry' => $adminHideTargetFacesEntry,
                ],
            ],
            'user' => [
                'defaultFinalsLength' => $userDefaultFinalsLength,
            ],
            'tournament' => [
                'available' => $hasCompetition ? 1 : 0,
                'tourId' => $hasCompetition ? intval($_SESSION['TourId']) : 0,
                'defaultFinalsLength' => $tournamentDefaultFinalsLength,
                'manageTargetsLayout' => $tournamentManageTargetsLayout,
            ],
            'effective' => [
                'defaultFinalsLength' => $effective,
            ],
        ]
    ]);
}

function getManageTargetsLayouts() {
    return [
        'layout_fallback_stacked' => 'No layout',
        'layout_60cm_3_abc' => '60cm ABC (3 archers, 2 lanes/mat)',
        'layout_40cm_6_triangle' => '40cm Triangle (3 archers, 6 targets/mat)',
        'layout_60cm_4_split' => '60cm Split (4 archers, 2 targets/mat)',
        'layout_40cm_4_quad' => '40cm Quad (4 archers, 4 targets/mat)',
        'layout_outdoor_mixed_2' => 'Outdoor Mixed (2 archers)',
        'layout_outdoor_mixed_3' => 'Outdoor Mixed (3 archers)',
        'layout_outdoor_mixed_4' => 'Outdoor Mixed (4 archers)',
    ];
}

function isKnownManageTargetsLayout($layoutId) {
    $layoutId = trim((string)$layoutId);
    if ($layoutId === '') {
        return false;
    }

    $layouts = getManageTargetsLayouts();
    return isset($layouts[$layoutId]);
}

function saveAdminSettings() {
    if (!isLaneAssistAdmin()) {
        echo json_encode(['error' => 1, 'message' => 'Admin access required']);
        return;
    }

    $defaultFinalsLength = intval($_REQUEST['defaultFinalsLength'] ?? 30);
    if ($defaultFinalsLength < 1 || $defaultFinalsLength > 480) {
        echo json_encode(['error' => 1, 'message' => 'Admin finals length must be between 1 and 480 minutes']);
        return;
    }

    $hideIanseoUpdateEntry = intval($_REQUEST['hideIanseoUpdateEntry'] ?? ($_REQUEST['hideUpdateEntry'] ?? 0)) ? 1 : 0;
    $hideCloneTournamentEntry = intval($_REQUEST['hideCloneTournamentEntry'] ?? 0) ? 1 : 0;
    $hideTargetFacesEntry = intval($_REQUEST['hideTargetFacesEntry'] ?? 0) ? 1 : 0;

    setGlobalModuleParameter('LaneAssist', 'AdminDefaultFinalsLength', $defaultFinalsLength);
    setGlobalModuleParameter('LaneAssist', 'AdminHideIanseoUpdateMenuEntry', $hideIanseoUpdateEntry);
    setGlobalModuleParameter('LaneAssist', 'AdminHideCloneTournamentEntry', $hideCloneTournamentEntry);
    setGlobalModuleParameter('LaneAssist', 'AdminHideTargetFacesEntry', $hideTargetFacesEntry);

    echo json_encode([
        'error' => 0,
        'message' => 'Admin settings saved',
        'settings' => [
            'defaultFinalsLength' => $defaultFinalsLength,
            'hideIanseoUpdateEntry' => $hideIanseoUpdateEntry,
            'hideCloneTournamentEntry' => $hideCloneTournamentEntry,
            'hideTargetFacesEntry' => $hideTargetFacesEntry,
        ]
    ]);
}

function saveUserSettings() {
    $defaultFinalsLength = intval($_REQUEST['defaultFinalsLength'] ?? 30);
    if ($defaultFinalsLength < 1 || $defaultFinalsLength > 480) {
        echo json_encode(['error' => 1, 'message' => 'Default finals length must be between 1 and 480 minutes']);
        return;
    }

    setUserScopedGlobalSetting('LaneAssist', 'DefaultFinalsLength', $defaultFinalsLength);

    echo json_encode([
        'error' => 0,
        'message' => 'User settings saved',
        'settings' => [
            'defaultFinalsLength' => $defaultFinalsLength,
        ]
    ]);
}

function saveTournamentSettings() {
    if (empty($_SESSION['TourId'])) {
        echo json_encode(['error' => 1, 'message' => 'No competition selected']);
        return;
    }

    $defaultFinalsLength = intval($_REQUEST['defaultFinalsLength'] ?? 0);
    $manageTargetsLayout = trim((string)($_REQUEST['manageTargetsLayout'] ?? ''));
    if ($defaultFinalsLength < 0 || $defaultFinalsLength > 480) {
        echo json_encode(['error' => 1, 'message' => 'Competition finals length must be between 0 and 480 minutes']);
        return;
    }

    if ($manageTargetsLayout !== '' && !isKnownManageTargetsLayout($manageTargetsLayout)) {
        echo json_encode(['error' => 1, 'message' => 'Invalid Manage Targets layout selection']);
        return;
    }

    if ($defaultFinalsLength === 0) {
        delModuleParameter('LaneAssist', 'DefaultFinalsLength', intval($_SESSION['TourId']));
    } else {
        setModuleParameter('LaneAssist', 'DefaultFinalsLength', $defaultFinalsLength, intval($_SESSION['TourId']));
    }

    if ($manageTargetsLayout === '') {
        delModuleParameter('LaneAssist', 'ManageTargetsLayout', intval($_SESSION['TourId']));
    } else {
        setModuleParameter('LaneAssist', 'ManageTargetsLayout', $manageTargetsLayout, intval($_SESSION['TourId']));
    }

    $message = 'Competition settings saved';
    if ($defaultFinalsLength === 0 && $manageTargetsLayout === '') {
        $message = 'Competition overrides cleared. User/admin defaults will be used.';
    }

    echo json_encode([
        'error' => 0,
        'message' => $message,
        'settings' => [
            'defaultFinalsLength' => $defaultFinalsLength,
            'manageTargetsLayout' => $manageTargetsLayout,
        ]
    ]);
}

function isLaneAssistAdmin() {
    global $CFG;

    if (!empty($_SESSION['debug'])) {
        return true;
    }

    if (empty($CFG->USERAUTH) || empty($_SESSION['AUTH_ENABLE'])) {
        return false;
    }

    if (function_exists('hasFullACL')) {
        return hasFullACL(AclRoot, '', AclReadWrite);
    }

    return false;
}

function checkUpdates() {
    $isDebugMode = !empty($_SESSION['debug']);
    $updateConfig = getUpdateConfigFromFile();

    $localVersion = trim((string)($updateConfig['installedVersion'] ?? '0.0.0-dev'));
    if ($localVersion === '') {
        $localVersion = '0.0.0-dev';
    }

    $manifestUrl = trim((string)($updateConfig['manifestUrl'] ?? ''));
    if ($manifestUrl === '') {
        echo json_encode([
            'error' => 0,
            'hasUpdate' => 0,
            'currentVersion' => $localVersion,
            'message' => 'Update source is not configured. Set manifestUrl in Modules/Custom/LaneAssist/Settings/update-config.php.',
        ]);
        return;
    }

    if (!isTrustedGithubUrl($manifestUrl)) {
        echo json_encode([
            'error' => 1,
            'message' => 'Manifest URL is not a trusted GitHub URL',
        ]);
        return;
    }

    $fetch = fetchRemoteJson($manifestUrl);
    if (!empty($fetch['error'])) {
        echo json_encode([
            'error' => 1,
            'message' => 'Update check failed: ' . $fetch['error'],
        ]);
        return;
    }

    $manifest = $fetch['data'];
    $validationError = validateUpdateManifest($manifest);
    if ($validationError !== '') {
        echo json_encode([
            'error' => 1,
            'message' => 'Invalid update manifest: ' . $validationError,
        ]);
        return;
    }

    if (!isTrustedGithubUrl($manifest['package']['url'])) {
        echo json_encode([
            'error' => 1,
            'message' => 'Package URL is not a trusted GitHub URL',
        ]);
        return;
    }

    $signatureCheck = verifyManifestSignature($manifest);
    if (!$signatureCheck['ok'] && !$isDebugMode) {
        echo json_encode([
            'error' => 1,
            'message' => 'Manifest signature verification failed: ' . $signatureCheck['message'],
        ]);
        return;
    }

    $minIanseoVersion = trim((string)($manifest['minIanseoVersion'] ?? ''));
    if ($minIanseoVersion !== '' && version_compare(ProgramVersion, $minIanseoVersion, '<')) {
        echo json_encode([
            'error' => 0,
            'hasUpdate' => 0,
            'currentVersion' => $localVersion,
            'latestVersion' => $manifest['version'],
            'message' => 'Update available but not compatible with this IANSEO version (requires >= ' . $minIanseoVersion . ').',
            'signature' => $signatureCheck,
        ]);
        return;
    }

    $hasUpdate = version_compare($manifest['version'], $localVersion, '>') ? 1 : 0;
    $message = $hasUpdate
        ? ('Update available: ' . $manifest['version'])
        : 'No updates available';

    if (!$signatureCheck['ok']) {
        $message .= ' (debug mode: unverified manifest accepted)';
    }

    echo json_encode([
        'error' => 0,
        'hasUpdate' => $hasUpdate,
        'currentVersion' => $localVersion,
        'latestVersion' => $manifest['version'],
        'channel' => $manifest['channel'],
        'publishedAt' => $manifest['publishedAt'],
        'packageUrl' => $manifest['package']['url'],
        'packageSha256' => $manifest['package']['sha256'],
        'message' => $message,
        'signature' => $signatureCheck,
    ]);
}

function submitFeedback() {
    $type = trim((string)($_REQUEST['type'] ?? 'general'));
    $message = trim((string)($_REQUEST['message'] ?? ''));

    if (!in_array($type, ['feature', 'bug', 'general'], true)) {
        $type = 'general';
    }

    if ($message === '') {
        echo json_encode(['error' => 1, 'message' => 'Feedback message cannot be empty']);
        return;
    }

    $entry = [
        'id' => uniqid('fb_', true),
        'author' => resolveFeedbackAuthor(),
        'type' => $type,
        'message' => $message,
        'createdAt' => date('Y-m-d H:i:s'),
        'tournament' => intval($_SESSION['TourId'] ?? 0),
        'scope' => 'global',
    ];

    $globalQueue = getGlobalModuleParameter('LaneAssist', 'FeedbackQueue', []);
    if (!is_array($globalQueue)) {
        $globalQueue = [];
    }
    $globalQueue[] = $entry;
    if (count($globalQueue) > 100) {
        $globalQueue = array_slice($globalQueue, -100);
    }
    setGlobalModuleParameter('LaneAssist', 'FeedbackQueue', $globalQueue);

    $competitionQueueCount = null;
    if (!empty($_SESSION['TourId'])) {
        $tourId = intval($_SESSION['TourId']);
        $competitionQueue = getModuleParameter('LaneAssist', 'FeedbackQueue', [], $tourId);
        if (!is_array($competitionQueue)) {
            $competitionQueue = [];
        }

        $entryCompetition = $entry;
        $entryCompetition['scope'] = 'competition';
        $competitionQueue[] = $entryCompetition;
        if (count($competitionQueue) > 100) {
            $competitionQueue = array_slice($competitionQueue, -100);
        }
        setModuleParameter('LaneAssist', 'FeedbackQueue', $competitionQueue, $tourId);
        $competitionQueueCount = count($competitionQueue);
    }

    echo json_encode([
        'error' => 0,
        'message' => 'Feedback received. Thank you!',
        'entry' => $entry,
        'stored' => [
            'globalCount' => count($globalQueue),
            'competitionCount' => $competitionQueueCount,
        ]
    ]);
}

function getFeedbackQueue() {
    if (empty($_SESSION['debug'])) {
        echo json_encode(['error' => 1, 'message' => 'Feedback queue is available only in debug mode']);
        return;
    }

    $items = [];

    if (!empty($_SESSION['TourId'])) {
        $competitionQueue = getModuleParameter('LaneAssist', 'FeedbackQueue', [], intval($_SESSION['TourId']));
        if (is_array($competitionQueue)) {
            foreach ($competitionQueue as $entry) {
                $entry = normalizeFeedbackEntry($entry);
                if (empty($entry['scope'])) {
                    $entry['scope'] = 'competition';
                }
                $items[] = $entry;
            }
        }
    }

    $globalQueue = getGlobalModuleParameter('LaneAssist', 'FeedbackQueue', []);
    if (is_array($globalQueue)) {
        foreach ($globalQueue as $entry) {
            $entry = normalizeFeedbackEntry($entry);
            if (empty($entry['scope'])) {
                $entry['scope'] = 'global';
            }
            $items[] = $entry;
        }
    }

    $deduped = [];
    $seen = [];
    foreach ($items as $entry) {
        $fingerprint = ($entry['id'] ?? '') . '|' . ($entry['createdAt'] ?? '') . '|' . ($entry['author'] ?? '') . '|' . ($entry['type'] ?? '') . '|' . ($entry['message'] ?? '') . '|' . intval($entry['tournament'] ?? 0);
        if (isset($seen[$fingerprint])) {
            continue;
        }
        $seen[$fingerprint] = true;
        $deduped[] = $entry;
    }

    usort($deduped, function($a, $b) {
        return strcmp((string)$b['createdAt'], (string)$a['createdAt']);
    });

    if (count($deduped) > 200) {
        $deduped = array_slice($deduped, 0, 200);
    }

    echo json_encode([
        'error' => 0,
        'items' => $deduped,
        'count' => count($deduped),
    ]);
}

function donateIntent() {
    echo json_encode([
        'error' => 0,
        'message' => 'Donate flow is ready to be connected. Payment logic will be added later.'
    ]);
}

function getGlobalModuleParameter($module, $param, $defaultValue='') {
    $query = "SELECT MpValue
        FROM ModulesParameters
        WHERE MpModule=" . StrSafe_DB($module) . "
        AND MpParameter=" . StrSafe_DB($param) . "
        AND MpTournament=0
        LIMIT 1";
    $result = safe_r_sql($query);
    if ($row = safe_fetch($result)) {
        return decodeModuleParameterValue($row->MpValue);
    }
    return $defaultValue;
}

function setGlobalModuleParameter($module, $param, $value) {
    $query = "INSERT INTO ModulesParameters
        SET MpValue=" . StrSafe_DB(serialize($value)) . ",
            MpModule=" . StrSafe_DB($module) . ",
            MpParameter=" . StrSafe_DB($param) . ",
            MpTournament=0
        ON DUPLICATE KEY UPDATE MpValue=" . StrSafe_DB(serialize($value));
    safe_w_sql($query);
    return getGlobalModuleParameter($module, $param, $value);
}

function decodeModuleParameterValue($rawValue) {
    if ($rawValue !== '' && $rawValue !== null) {
        $decoded = @unserialize($rawValue);
        if ($decoded !== false) {
            return $decoded;
        }
    }
    return $rawValue;
}

function normalizeFeedbackEntry($entry) {
    if (!is_array($entry)) {
        $entry = [
            'id' => uniqid('fb_', true),
            'author' => 'Unknown',
            'type' => 'general',
            'message' => (string)$entry,
            'createdAt' => '',
            'tournament' => 0,
            'scope' => 'global',
        ];
    }

    $entry['id'] = trim((string)($entry['id'] ?? ''));
    $entry['author'] = trim((string)($entry['author'] ?? ''));
    if ($entry['author'] === '') {
        $entry['author'] = 'Unknown';
    }
    $entry['type'] = in_array(($entry['type'] ?? 'general'), ['feature', 'bug', 'general'], true) ? $entry['type'] : 'general';
    $entry['message'] = trim((string)($entry['message'] ?? ''));
    $entry['createdAt'] = trim((string)($entry['createdAt'] ?? ''));
    $entry['tournament'] = intval($entry['tournament'] ?? 0);
    $entry['scope'] = trim((string)($entry['scope'] ?? ''));

    return $entry;
}

function resolveFeedbackAuthor() {
    global $CFG;

    $authEnabled = !empty($CFG->USERAUTH);
    if ($authEnabled) {
        $user = trim((string)($_SESSION['AUTH_User'] ?? ''));
        if ($user !== '') {
            return $user;
        }
    }

    return 'Unknown';
}

function getUserScopedGlobalSetting($module, $param, $defaultValue='') {
    $userParam = buildUserScopedParamName($param);
    if ($userParam !== '') {
        $userValue = getGlobalModuleParameter($module, $userParam, null);
        if ($userValue !== null && $userValue !== '') {
            return $userValue;
        }
    }

    return getGlobalModuleParameter($module, $param, $defaultValue);
}

function setUserScopedGlobalSetting($module, $param, $value) {
    $userParam = buildUserScopedParamName($param);
    if ($userParam !== '') {
        return setGlobalModuleParameter($module, $userParam, $value);
    }

    return setGlobalModuleParameter($module, $param, $value);
}

function buildUserScopedParamName($param) {
    global $CFG;

    if (empty($CFG->USERAUTH)) {
        return '';
    }

    $authUser = trim((string)($_SESSION['AUTH_User'] ?? ''));
    if ($authUser === '') {
        return '';
    }

    return 'user:' . strtolower($authUser) . ':' . $param;
}

function fetchRemoteJson($url) {
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 10,
            'header' => "User-Agent: LaneAssist-Update-Checker\r\nAccept: application/json\r\n",
            'ignore_errors' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        return ['error' => 'Unable to download manifest'];
    }

    $statusCode = 0;
    if (!empty($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $headerLine) {
            if (preg_match('/^HTTP\/[0-9.]+\s+([0-9]{3})/i', $headerLine, $m)) {
                $statusCode = intval($m[1]);
                break;
            }
        }
    }

    if ($statusCode < 200 || $statusCode >= 300) {
        return ['error' => 'Manifest request failed with HTTP ' . $statusCode];
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        return ['error' => 'Manifest is not valid JSON'];
    }

    return ['error' => '', 'data' => $data];
}

function validateUpdateManifest($manifest) {
    if (!is_array($manifest)) {
        return 'Manifest payload is not an object';
    }

    foreach (['schema', 'channel', 'version', 'publishedAt', 'package'] as $required) {
        if (!array_key_exists($required, $manifest)) {
            return 'Missing field: ' . $required;
        }
    }

    if (intval($manifest['schema']) !== 1) {
        return 'Unsupported schema version';
    }

    if (!preg_match('/^[0-9A-Za-z._-]+$/', (string)$manifest['channel'])) {
        return 'Invalid channel value';
    }

    if (!preg_match('/^[0-9A-Za-z._-]+$/', (string)$manifest['version'])) {
        return 'Invalid version value';
    }

    if (!is_array($manifest['package'])) {
        return 'Invalid package object';
    }

    if (empty($manifest['package']['url']) || empty($manifest['package']['sha256'])) {
        return 'Package url/sha256 are required';
    }

    if (!preg_match('/^[a-f0-9]{64}$/i', (string)$manifest['package']['sha256'])) {
        return 'Invalid package sha256 format';
    }

    if (isset($manifest['signature']) && !is_array($manifest['signature'])) {
        return 'Invalid signature object';
    }

    return '';
}

function isTrustedGithubUrl($url) {
    $u = @parse_url((string)$url);
    if (!$u || empty($u['scheme']) || empty($u['host'])) {
        return false;
    }

    if (strtolower($u['scheme']) !== 'https') {
        return false;
    }

    $host = strtolower($u['host']);
    if (!in_array($host, ['github.com', 'raw.githubusercontent.com', 'objects.githubusercontent.com'], true)) {
        return false;
    }

    return true;
}

function verifyManifestSignature($manifest) {
    $result = [
        'ok' => false,
        'algorithm' => 'ed25519',
        'message' => '',
    ];

    $updateConfig = getUpdateConfigFromFile();
    $publicKeyB64 = trim((string)($updateConfig['publicKeyEd25519'] ?? ''));
    if ($publicKeyB64 === '') {
        $result['message'] = 'No public key configured in update-config.php (publicKeyEd25519)';
        return $result;
    }

    if (empty($manifest['signature']) || !is_array($manifest['signature'])) {
        $result['message'] = 'Manifest signature block is missing';
        return $result;
    }

    $signature = $manifest['signature'];
    if (($signature['algorithm'] ?? '') !== 'ed25519') {
        $result['message'] = 'Unsupported signature algorithm';
        return $result;
    }

    $payloadB64 = trim((string)($signature['payload'] ?? ''));
    $sigB64 = trim((string)($signature['sig'] ?? ''));
    if ($payloadB64 === '' || $sigB64 === '') {
        $result['message'] = 'Signature payload/sig fields are required';
        return $result;
    }

    if (!function_exists('sodium_crypto_sign_verify_detached')) {
        $result['message'] = 'Libsodium extension is not available';
        return $result;
    }

    $publicKey = base64_decode($publicKeyB64, true);
    $payload = base64_decode($payloadB64, true);
    $sig = base64_decode($sigB64, true);

    if ($publicKey === false || $payload === false || $sig === false) {
        $result['message'] = 'Invalid base64 encoding in key/signature';
        return $result;
    }

    if (strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
        $result['message'] = 'Invalid key/signature length';
        return $result;
    }

    $isValid = @sodium_crypto_sign_verify_detached($sig, $payload, $publicKey);
    if (!$isValid) {
        $result['message'] = 'Detached signature validation failed';
        return $result;
    }

    $payloadObj = json_decode($payload, true);
    if (!is_array($payloadObj)) {
        $result['message'] = 'Signature payload is not valid JSON';
        return $result;
    }

    $checks = [
        ['version', $manifest['version'] ?? ''],
        ['channel', $manifest['channel'] ?? ''],
        ['publishedAt', $manifest['publishedAt'] ?? ''],
    ];

    foreach ($checks as $check) {
        if (!array_key_exists($check[0], $payloadObj) || (string)$payloadObj[$check[0]] !== (string)$check[1]) {
            $result['message'] = 'Signed payload does not match manifest field: ' . $check[0];
            return $result;
        }
    }

    $payloadPackage = $payloadObj['package'] ?? null;
    $manifestPackage = $manifest['package'] ?? null;
    if (!is_array($payloadPackage) || !is_array($manifestPackage)) {
        $result['message'] = 'Signed payload package block is invalid';
        return $result;
    }

    if ((string)($payloadPackage['url'] ?? '') !== (string)($manifestPackage['url'] ?? '') ||
        (string)($payloadPackage['sha256'] ?? '') !== (string)($manifestPackage['sha256'] ?? '')) {
        $result['message'] = 'Signed payload package does not match manifest package';
        return $result;
    }

    $result['ok'] = true;
    $result['message'] = 'Signature verified';
    return $result;
}

function applyUpdateFromFile() {
    if (empty($_SESSION['debug'])) {
        echo json_encode(['error' => 1, 'message' => 'Update by file is available only in debug mode']);
        return;
    }

    checkFullACL(AclRoot, '', AclReadWrite, false);

    if (empty($_FILES['updateFile']) || !is_array($_FILES['updateFile'])) {
        echo json_encode(['error' => 1, 'message' => 'No update file uploaded']);
        return;
    }

    $file = $_FILES['updateFile'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        echo json_encode(['error' => 1, 'message' => 'Upload failed with code ' . intval($file['error'] ?? -1)]);
        return;
    }

    $originalName = trim((string)($file['name'] ?? 'update.zip'));
    if (!preg_match('/\.zip$/i', $originalName)) {
        echo json_encode(['error' => 1, 'message' => 'Only .zip update files are supported']);
        return;
    }

    $tmpFile = (string)($file['tmp_name'] ?? '');
    if ($tmpFile === '' || !is_uploaded_file($tmpFile)) {
        echo json_encode(['error' => 1, 'message' => 'Uploaded file is not valid']);
        return;
    }

    $zipSignatureCheck = verifyUploadedZipSignature($tmpFile, trim((string)($_REQUEST['updateSigB64'] ?? '')));
    if (!$zipSignatureCheck['ok']) {
        echo json_encode(['error' => 1, 'message' => 'ZIP signature verification failed: ' . $zipSignatureCheck['message']]);
        return;
    }

    $zip = new ZipArchive();
    if ($zip->open($tmpFile) !== true) {
        echo json_encode(['error' => 1, 'message' => 'Cannot open ZIP archive']);
        return;
    }

    $allowedPrefix = 'Modules/Custom/LaneAssist/';
    $filesToApply = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entryName = (string)$zip->getNameIndex($i);
        $normalized = normalizeZipEntryPath($entryName);
        if ($normalized === '') {
            continue;
        }

        if (substr($entryName, -1) === '/') {
            continue;
        }

        if (!str_starts_with($normalized, $allowedPrefix)) {
            $zip->close();
            echo json_encode(['error' => 1, 'message' => 'Archive contains forbidden path: ' . $normalized]);
            return;
        }

        $content = $zip->getFromIndex($i);
        if ($content === false) {
            $zip->close();
            echo json_encode(['error' => 1, 'message' => 'Unable to read archive entry: ' . $normalized]);
            return;
        }

        $filesToApply[] = [
            'path' => $normalized,
            'content' => $content,
        ];
    }

    $zip->close();

    if (!count($filesToApply)) {
        echo json_encode(['error' => 1, 'message' => 'Archive contains no updatable files']);
        return;
    }

    $projectRoot = dirname(__FILE__, 5);
    $backupRoot = dirname(__FILE__) . '/backups/update-' . date('Ymd-His') . '-' . substr(md5(uniqid('', true)), 0, 6);
    if (!is_dir($backupRoot) && !@mkdir($backupRoot, 0775, true)) {
        echo json_encode(['error' => 1, 'message' => 'Cannot create backup directory']);
        return;
    }

    $written = 0;
    $backedUp = 0;

    foreach ($filesToApply as $entry) {
        $relPath = $entry['path'];
        $target = $projectRoot . '/' . $relPath;
        $targetDir = dirname($target);

        if (!is_dir($targetDir) && !@mkdir($targetDir, 0775, true)) {
            echo json_encode(['error' => 1, 'message' => 'Cannot create target folder: ' . $targetDir]);
            return;
        }

        if (file_exists($target)) {
            $backupFile = $backupRoot . '/' . $relPath;
            $backupDir = dirname($backupFile);
            if (!is_dir($backupDir) && !@mkdir($backupDir, 0775, true)) {
                echo json_encode(['error' => 1, 'message' => 'Cannot create backup subfolder']);
                return;
            }
            if (!@copy($target, $backupFile)) {
                echo json_encode(['error' => 1, 'message' => 'Failed to backup file: ' . $relPath]);
                return;
            }
            $backedUp++;
        }

        if (@file_put_contents($target, $entry['content']) === false) {
            echo json_encode(['error' => 1, 'message' => 'Failed to write file: ' . $relPath]);
            return;
        }
        $written++;
    }

    echo json_encode([
        'error' => 0,
        'message' => 'Update file applied successfully',
        'writtenFiles' => $written,
        'backedUpFiles' => $backedUp,
        'backupPath' => str_replace(dirname(__FILE__, 5) . '/', '', $backupRoot),
        'signature' => $zipSignatureCheck,
    ]);
}

function verifyUploadedZipSignature($zipTmpFile, $sigB64Input) {
    $result = [
        'ok' => false,
        'algorithm' => 'ed25519',
        'message' => '',
    ];

    $sigB64 = trim((string)$sigB64Input);
    if ($sigB64 === '') {
        $result['message'] = 'Missing signature value';
        return $result;
    }

    $actualSha = strtolower((string)@hash_file('sha256', $zipTmpFile));
    if ($actualSha === '' || !preg_match('/^[a-f0-9]{64}$/', $actualSha)) {
        $result['message'] = 'Could not compute ZIP checksum';
        return $result;
    }

    if (!function_exists('sodium_crypto_sign_verify_detached')) {
        $result['message'] = 'Libsodium extension is not available';
        return $result;
    }

    if (!defined('SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES') || !defined('SODIUM_CRYPTO_SIGN_BYTES')) {
        $result['message'] = 'Libsodium constants are not available';
        return $result;
    }

    $updateConfig = getUpdateConfigFromFile();
    $publicKeyB64 = trim((string)($updateConfig['publicKeyEd25519'] ?? ''));
    if ($publicKeyB64 === '') {
        $result['message'] = 'No public key configured in update-config.php';
        return $result;
    }

    $publicKey = base64_decode($publicKeyB64, true);
    $sig = base64_decode($sigB64, true);
    if ($publicKey === false || $sig === false) {
        $result['message'] = 'Invalid base64 in key/signature';
        return $result;
    }

    if (strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
        $result['message'] = 'Invalid key/signature length';
        return $result;
    }

    $payload = $actualSha;
    $isValid = @sodium_crypto_sign_verify_detached($sig, $payload, $publicKey);
    if (!$isValid) {
        $result['message'] = 'Detached signature validation failed';
        return $result;
    }

    $result['ok'] = true;
    $result['sha256'] = $actualSha;
    $result['message'] = 'ZIP signature verified';
    return $result;
}

function normalizeZipEntryPath($entryName) {
    $path = str_replace('\\', '/', trim((string)$entryName));
    if ($path === '') {
        return '';
    }

    if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\//', $path)) {
        return '';
    }

    $parts = [];
    foreach (explode('/', $path) as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            return '';
        }
        $parts[] = $part;
    }

    return implode('/', $parts);
}

function getUpdateConfigFromFile() {
    $defaults = [
        'manifestUrl' => '',
        'publicKeyEd25519' => '',
        'installedVersion' => '0.0.0-dev',
    ];

    $configFile = dirname(__FILE__) . '/update-config.php';
    if (!is_file($configFile)) {
        return $defaults;
    }

    $config = include($configFile);
    if (!is_array($config)) {
        return $defaults;
    }

    return array_merge($defaults, $config);
}
