<?php
/**
 * LaneAssist Settings API
 */

require_once(dirname(__FILE__, 3) . '/config.php');
require_once(dirname(__FILE__, 2) . '/version.php');
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
    case 'applyUpdateFromGithub':
        applyUpdateFromGithub();
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
    $update = getGithubReleaseUpdateDescriptor();
    if (!empty($update['error'])) {
        echo json_encode([
            'error' => 1,
            'message' => (string)$update['message'],
        ]);
        return;
    }

    $hasUpdate = !empty($update['hasUpdate']) ? 1 : 0;
    $message = $hasUpdate
        ? ('Update available: ' . $update['latestVersion'])
        : 'No updates available';

    echo json_encode([
        'error' => 0,
        'hasUpdate' => $hasUpdate,
        'currentVersion' => (string)$update['currentVersion'],
        'latestVersion' => (string)$update['latestVersion'],
        'releaseTag' => (string)($update['releaseTag'] ?? ''),
        'publishedAt' => (string)($update['publishedAt'] ?? ''),
        'zipAssetName' => (string)($update['zipAssetName'] ?? ''),
        'message' => $message,
        'signature' => $update['signatureCheck'] ?? ['ok' => false, 'message' => ''],
    ]);
}

function applyUpdateFromGithub() {
    checkFullACL(AclRoot, '', AclReadWrite, false);

    $update = getGithubReleaseUpdateDescriptor();
    if (!empty($update['error'])) {
        echo json_encode(['error' => 1, 'message' => (string)$update['message']]);
        return;
    }

    if (empty($update['hasUpdate'])) {
        echo json_encode([
            'error' => 1,
            'message' => 'No newer update is available',
            'currentVersion' => (string)$update['currentVersion'],
            'latestVersion' => (string)$update['latestVersion'],
        ]);
        return;
    }

    $download = downloadRemoteToTempFile((string)$update['zipDownloadUrl'], 'laneassist-update-', '.zip');
    if (!empty($download['error'])) {
        echo json_encode(['error' => 1, 'message' => 'Failed to download update ZIP: ' . $download['error']]);
        return;
    }

    $tmpZipFile = (string)$download['path'];
    $actualSha = strtolower((string)@hash_file('sha256', $tmpZipFile));
    if ($actualSha === '' || $actualSha !== strtolower((string)$update['zipSha256'])) {
        @unlink($tmpZipFile);
        echo json_encode(['error' => 1, 'message' => 'Downloaded ZIP checksum does not match signed checksum']);
        return;
    }

    $apply = applyUpdateArchiveFile($tmpZipFile);
    @unlink($tmpZipFile);

    if (empty($apply['ok'])) {
        echo json_encode(['error' => 1, 'message' => (string)($apply['message'] ?? 'Failed to apply update archive')]);
        return;
    }

    echo json_encode([
        'error' => 0,
        'message' => 'Update installed: ' . (string)$update['latestVersion'],
        'currentVersion' => (string)$update['latestVersion'],
        'previousVersion' => (string)$update['currentVersion'],
        'releaseTag' => (string)($update['releaseTag'] ?? ''),
        'writtenFiles' => intval($apply['writtenFiles'] ?? 0),
        'backedUpFiles' => intval($apply['backedUpFiles'] ?? 0),
        'backupPath' => (string)($apply['backupPath'] ?? ''),
    ]);
}

function getGithubReleaseUpdateDescriptor() {
    $response = [
        'error' => 1,
        'message' => 'Unknown updater error',
    ];

    $repo = getGithubUpdateRepoConfig();
    if (empty($repo['ok'])) {
        $response['message'] = (string)($repo['message'] ?? 'Invalid updater configuration');
        return $response;
    }

    $releaseFetch = fetchRemoteJson((string)$repo['releaseApiUrl'], 'application/vnd.github+json');
    if (!empty($releaseFetch['error'])) {
        $response['message'] = 'GitHub release query failed: ' . $releaseFetch['error'];
        return $response;
    }

    $release = $releaseFetch['data'];
    if (!is_array($release)) {
        $response['message'] = 'GitHub release payload is invalid';
        return $response;
    }

    $assets = is_array($release['assets'] ?? null) ? $release['assets'] : [];
    if (!count($assets)) {
        $response['message'] = 'Latest release has no assets';
        return $response;
    }

    $assetByName = [];
    foreach ($assets as $asset) {
        $assetName = trim((string)($asset['name'] ?? ''));
        if ($assetName !== '') {
            $assetByName[$assetName] = $asset;
        }
    }

    $zipAsset = findReleaseZipAsset($assets, (string)$repo['zipAssetPattern']);
    if (!$zipAsset) {
        $response['message'] = 'No release ZIP asset matched expected naming pattern';
        return $response;
    }

    $zipAssetName = trim((string)($zipAsset['name'] ?? ''));
    $zipDownloadUrl = trim((string)($zipAsset['browser_download_url'] ?? ''));
    if ($zipAssetName === '' || $zipDownloadUrl === '') {
        $response['message'] = 'ZIP asset metadata is incomplete';
        return $response;
    }

    if (!isTrustedGithubRepoAssetUrl($zipDownloadUrl, (string)$repo['owner'], (string)$repo['repo'])) {
        $response['message'] = 'ZIP asset URL is not trusted for configured repository';
        return $response;
    }

    $shaAssetName = $zipAssetName . '.sha256';
    $sigAssetName = $zipAssetName . '.sig';
    if (empty($assetByName[$shaAssetName]) || empty($assetByName[$sigAssetName])) {
        $response['message'] = 'Release is missing required signature assets (.sha256 and .sig)';
        return $response;
    }

    $shaUrl = trim((string)($assetByName[$shaAssetName]['browser_download_url'] ?? ''));
    $sigUrl = trim((string)($assetByName[$sigAssetName]['browser_download_url'] ?? ''));
    if (!isTrustedGithubRepoAssetUrl($shaUrl, (string)$repo['owner'], (string)$repo['repo']) ||
        !isTrustedGithubRepoAssetUrl($sigUrl, (string)$repo['owner'], (string)$repo['repo'])) {
        $response['message'] = 'Signature asset URL is not trusted for configured repository';
        return $response;
    }

    $shaFetch = fetchRemoteText($shaUrl, 'text/plain');
    if (!empty($shaFetch['error'])) {
        $response['message'] = 'Failed to fetch SHA-256 asset: ' . $shaFetch['error'];
        return $response;
    }
    $zipSha = parseSha256AssetContent((string)$shaFetch['body'], $zipAssetName);
    if ($zipSha === '') {
        $response['message'] = 'Invalid SHA-256 asset format';
        return $response;
    }

    $sigFetch = fetchRemoteText($sigUrl, 'text/plain');
    if (!empty($sigFetch['error'])) {
        $response['message'] = 'Failed to fetch signature asset: ' . $sigFetch['error'];
        return $response;
    }
    $sigB64 = trim((string)$sigFetch['body']);
    if ($sigB64 === '') {
        $response['message'] = 'Signature asset is empty';
        return $response;
    }

    $signatureCheck = verifyHashSignatureWithConfiguredKey($zipSha, $sigB64);
    if (empty($signatureCheck['ok'])) {
        $response['message'] = 'Signature verification failed: ' . (string)($signatureCheck['message'] ?? 'Unknown error');
        return $response;
    }

    $currentVersion = getInstalledLaneAssistVersion();
    $latestVersion = resolveReleaseVersion($zipAssetName, (string)($release['tag_name'] ?? ''));
    $hasUpdate = version_compare($latestVersion, $currentVersion, '>');

    return [
        'error' => 0,
        'message' => '',
        'hasUpdate' => $hasUpdate ? 1 : 0,
        'currentVersion' => $currentVersion,
        'latestVersion' => $latestVersion,
        'releaseTag' => (string)($release['tag_name'] ?? ''),
        'publishedAt' => (string)($release['published_at'] ?? ''),
        'zipAssetName' => $zipAssetName,
        'zipDownloadUrl' => $zipDownloadUrl,
        'zipSha256' => $zipSha,
        'signatureCheck' => $signatureCheck,
    ];
}

function getInstalledLaneAssistVersion() {
    if (function_exists('getLaneAssistModuleVersion')) {
        $version = trim((string)getLaneAssistModuleVersion());
        if ($version !== '') {
            return $version;
        }
    }

    $updateConfig = getUpdateConfigFromFile();
    $fallback = trim((string)($updateConfig['installedVersion'] ?? '0.0.0-dev'));
    return $fallback !== '' ? $fallback : '0.0.0-dev';
}

function resolveReleaseVersion($zipAssetName, $tagName) {
    $zipAssetName = trim((string)$zipAssetName);
    if (preg_match('/^laneassist-module-v([0-9A-Za-z._-]+)\\.zip$/i', $zipAssetName, $m)) {
        return (string)$m[1];
    }

    $tagName = trim((string)$tagName);
    if ($tagName !== '' && preg_match('/^v(.+)$/i', $tagName, $m)) {
        return (string)$m[1];
    }

    return $tagName !== '' ? $tagName : '0.0.0-dev';
}

function findReleaseZipAsset($assets, $zipAssetPattern) {
    foreach ($assets as $asset) {
        $assetName = trim((string)($asset['name'] ?? ''));
        if ($assetName === '') {
            continue;
        }

        if (@preg_match($zipAssetPattern, $assetName)) {
            return $asset;
        }
    }

    return null;
}

function parseSha256AssetContent($body, $zipAssetName) {
    $body = trim((string)$body);
    if ($body === '') {
        return '';
    }

    $lines = preg_split('/\r\n|\n|\r/', $body);
    foreach ((array)$lines as $line) {
        $line = trim((string)$line);
        if ($line === '') {
            continue;
        }

        if (preg_match('/^([a-f0-9]{64})(?:\s+\*?(.+))?$/i', $line, $m)) {
            $hash = strtolower((string)$m[1]);
            $fileInLine = trim((string)($m[2] ?? ''));
            if ($fileInLine === '' || $fileInLine === $zipAssetName) {
                return $hash;
            }
        }
    }

    return '';
}

function verifyHashSignatureWithConfiguredKey($hashHex, $sigB64Input) {
    $result = [
        'ok' => false,
        'algorithm' => 'ed25519',
        'message' => '',
    ];

    $hashHex = strtolower(trim((string)$hashHex));
    if (!preg_match('/^[a-f0-9]{64}$/', $hashHex)) {
        $result['message'] = 'Invalid SHA-256 hash format';
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

    $sigB64 = trim((string)$sigB64Input);
    if ($sigB64 === '') {
        $result['message'] = 'Signature value is empty';
        return $result;
    }

    $publicKey = base64_decode($publicKeyB64, true);
    $sig = base64_decode($sigB64, true);
    if ($publicKey === false || $sig === false) {
        $result['message'] = 'Invalid base64 in public key or signature';
        return $result;
    }

    if (strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
        $result['message'] = 'Invalid key/signature length';
        return $result;
    }

    $isValid = @sodium_crypto_sign_verify_detached($sig, $hashHex, $publicKey);
    if (!$isValid) {
        $result['message'] = 'Detached signature validation failed';
        return $result;
    }

    $result['ok'] = true;
    $result['sha256'] = $hashHex;
    $result['message'] = 'Signature verified';
    return $result;
}

function getGithubUpdateRepoConfig() {
    $updateConfig = getUpdateConfigFromFile();
    $github = is_array($updateConfig['github'] ?? null) ? $updateConfig['github'] : [];

    $owner = trim((string)($github['owner'] ?? ''));
    $repo = trim((string)($github['repo'] ?? ''));
    $releaseApiUrl = trim((string)($github['releaseApiUrl'] ?? ''));
    $zipAssetPattern = trim((string)($github['zipAssetPattern'] ?? ''));

    if ($owner === '' || $repo === '') {
        return ['ok' => false, 'message' => 'Updater repo owner/repo are not configured'];
    }

    if (!preg_match('/^[A-Za-z0-9_.-]+$/', $owner) || !preg_match('/^[A-Za-z0-9_.-]+$/', $repo)) {
        return ['ok' => false, 'message' => 'Updater repo owner/repo format is invalid'];
    }

    if ($releaseApiUrl === '') {
        $releaseApiUrl = 'https://api.github.com/repos/' . $owner . '/' . $repo . '/releases/latest';
    }

    if ($zipAssetPattern === '') {
        $zipAssetPattern = '/^laneassist-module-v?[0-9A-Za-z._-]+\\.zip$/i';
    }

    if (!isTrustedGithubApiReleaseUrl($releaseApiUrl, $owner, $repo)) {
        return ['ok' => false, 'message' => 'releaseApiUrl is not trusted for configured repository'];
    }

    return [
        'ok' => true,
        'owner' => $owner,
        'repo' => $repo,
        'releaseApiUrl' => $releaseApiUrl,
        'zipAssetPattern' => $zipAssetPattern,
    ];
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

function fetchRemoteJson($url, $accept = 'application/json') {
    $fetch = fetchRemoteResource($url, $accept);
    if (!empty($fetch['error'])) {
        return ['error' => $fetch['error']];
    }

    $data = json_decode((string)$fetch['body'], true);
    if (!is_array($data)) {
        return ['error' => 'Response is not valid JSON'];
    }

    return ['error' => '', 'data' => $data];
}

function fetchRemoteText($url, $accept = 'text/plain') {
    $fetch = fetchRemoteResource($url, $accept);
    if (!empty($fetch['error'])) {
        return ['error' => $fetch['error']];
    }

    return [
        'error' => '',
        'body' => (string)$fetch['body'],
    ];
}

function fetchRemoteResource($url, $accept = '*/*') {
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 20,
            'header' => "User-Agent: LaneAssist-Updater\r\nAccept: " . $accept . "\r\n",
            'ignore_errors' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        return ['error' => 'Unable to download remote resource'];
    }

    $statusCode = 0;
    if (!empty($http_response_header) && is_array($http_response_header)) {
        // Keep the last HTTP status line to support redirect chains (e.g. GitHub 302 -> 200).
        foreach ($http_response_header as $headerLine) {
            if (preg_match('/^HTTP\/[0-9.]+\s+([0-9]{3})/i', $headerLine, $m)) {
                $statusCode = intval($m[1]);
            }
        }
    }

    if ($statusCode < 200 || $statusCode >= 300) {
        return ['error' => 'Remote request failed with HTTP ' . $statusCode];
    }

    return ['error' => '', 'body' => $body, 'statusCode' => $statusCode];
}

function downloadRemoteToTempFile($url, $prefix = 'laneassist-', $suffix = '') {
    $fetch = fetchRemoteResource($url, 'application/octet-stream');
    if (!empty($fetch['error'])) {
        return ['error' => $fetch['error']];
    }

    $tmpFile = tempnam(sys_get_temp_dir(), $prefix);
    if ($tmpFile === false) {
        return ['error' => 'Could not allocate temporary file'];
    }

    if ($suffix !== '') {
        $target = $tmpFile . $suffix;
        if (!@rename($tmpFile, $target)) {
            @unlink($tmpFile);
            return ['error' => 'Could not create temporary file for download'];
        }
        $tmpFile = $target;
    }

    if (@file_put_contents($tmpFile, (string)$fetch['body']) === false) {
        @unlink($tmpFile);
        return ['error' => 'Could not write downloaded content'];
    }

    return ['error' => '', 'path' => $tmpFile];
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

function isTrustedGithubApiReleaseUrl($url, $owner, $repo) {
    $u = @parse_url((string)$url);
    if (!$u || empty($u['scheme']) || empty($u['host']) || empty($u['path'])) {
        return false;
    }

    if (strtolower((string)$u['scheme']) !== 'https') {
        return false;
    }

    if (strtolower((string)$u['host']) !== 'api.github.com') {
        return false;
    }

    $expected = '/repos/' . $owner . '/' . $repo . '/releases/latest';
    return trim((string)$u['path']) === $expected;
}

function isTrustedGithubRepoAssetUrl($url, $owner, $repo) {
    $u = @parse_url((string)$url);
    if (!$u || empty($u['scheme']) || empty($u['host']) || empty($u['path'])) {
        return false;
    }

    if (strtolower((string)$u['scheme']) !== 'https') {
        return false;
    }

    $host = strtolower((string)$u['host']);
    if ($host !== 'github.com') {
        return false;
    }

    $prefix = '/' . $owner . '/' . $repo . '/releases/download/';
    return str_starts_with((string)$u['path'], $prefix);
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

    $apply = applyUpdateArchiveFile($tmpFile);
    if (empty($apply['ok'])) {
        echo json_encode(['error' => 1, 'message' => (string)($apply['message'] ?? 'Failed to apply update archive')]);
        return;
    }

    echo json_encode([
        'error' => 0,
        'message' => 'Update file applied successfully',
        'writtenFiles' => intval($apply['writtenFiles'] ?? 0),
        'backedUpFiles' => intval($apply['backedUpFiles'] ?? 0),
        'backupPath' => (string)($apply['backupPath'] ?? ''),
        'signature' => $zipSignatureCheck,
    ]);
}

function verifyUploadedZipSignature($zipTmpFile, $sigB64Input) {
    $actualSha = strtolower((string)@hash_file('sha256', $zipTmpFile));
    if ($actualSha === '' || !preg_match('/^[a-f0-9]{64}$/', $actualSha)) {
        return [
            'ok' => false,
            'algorithm' => 'ed25519',
            'message' => 'Could not compute ZIP checksum',
        ];
    }

    $result = verifyHashSignatureWithConfiguredKey($actualSha, $sigB64Input);
    if (!empty($result['ok'])) {
        $result['sha256'] = $actualSha;
        $result['message'] = 'ZIP signature verified';
    }

    return $result;
}

function applyUpdateArchiveFile($zipFilePath) {
    $zip = new ZipArchive();
    if ($zip->open($zipFilePath) !== true) {
        return ['ok' => false, 'message' => 'Cannot open ZIP archive'];
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
            return ['ok' => false, 'message' => 'Archive contains forbidden path: ' . $normalized];
        }

        $content = $zip->getFromIndex($i);
        if ($content === false) {
            $zip->close();
            return ['ok' => false, 'message' => 'Unable to read archive entry: ' . $normalized];
        }

        $filesToApply[] = [
            'path' => $normalized,
            'content' => $content,
        ];
    }

    $zip->close();

    if (!count($filesToApply)) {
        return ['ok' => false, 'message' => 'Archive contains no updatable files'];
    }

    $projectRoot = dirname(__FILE__, 5);
    $backupRootAbs = buildWritableBackupPath();
    if ($backupRootAbs === '') {
        return ['ok' => false, 'message' => 'Cannot create backup directory in module or system temp path'];
    }

    $written = 0;
    $backedUp = 0;

    foreach ($filesToApply as $entry) {
        $relPath = $entry['path'];
        $target = $projectRoot . '/' . $relPath;
        $targetDir = dirname($target);

        if (!is_dir($targetDir) && !@mkdir($targetDir, 0775, true)) {
            return ['ok' => false, 'message' => 'Cannot create target folder: ' . $targetDir];
        }

        if (file_exists($target)) {
            $existingContent = @file_get_contents($target);
            if ($existingContent !== false && hash('sha256', $existingContent) === hash('sha256', (string)$entry['content'])) {
                // Skip unchanged files to avoid unnecessary writes on restrictive file perms.
                continue;
            }

            $backupFile = $backupRootAbs . '/' . $relPath;
            $backupDir = dirname($backupFile);
            if (!is_dir($backupDir) && !@mkdir($backupDir, 0775, true)) {
                return ['ok' => false, 'message' => 'Cannot create backup subfolder'];
            }
            if (!@copy($target, $backupFile)) {
                return ['ok' => false, 'message' => 'Failed to backup file: ' . $relPath];
            }
            $backedUp++;
        }

        if (!writeFileAtomic($target, (string)$entry['content'])) {
            return ['ok' => false, 'message' => 'Failed to write file: ' . $relPath];
        }
        $written++;
    }

    $backupPathRel = str_replace(dirname(__FILE__, 5) . '/', '', $backupRootAbs);
    if ($backupPathRel === $backupRootAbs) {
        $backupPathRel = $backupRootAbs;
    }

    return [
        'ok' => true,
        'writtenFiles' => $written,
        'backedUpFiles' => $backedUp,
        'backupPath' => $backupPathRel,
    ];
}

function buildWritableBackupPath() {
    $suffix = 'update-' . date('Ymd-His') . '-' . substr(md5(uniqid('', true)), 0, 6);
    $candidates = [
        dirname(__FILE__) . '/backups/' . $suffix,
        rtrim((string)sys_get_temp_dir(), '/\\') . '/laneassist-backups/' . $suffix,
    ];

    foreach ($candidates as $candidate) {
        if (!is_dir($candidate) && !@mkdir($candidate, 0775, true)) {
            continue;
        }

        if (is_dir($candidate) && is_writable($candidate)) {
            return $candidate;
        }
    }

    return '';
}

function writeFileAtomic($targetFile, $content) {
    $targetDir = dirname($targetFile);
    if (!is_dir($targetDir) || !is_writable($targetDir)) {
        return false;
    }

    $tmp = tempnam($targetDir, '.laneassist-upd-');
    if ($tmp === false) {
        return false;
    }

    if (@file_put_contents($tmp, $content) === false) {
        @unlink($tmp);
        return false;
    }

    if (file_exists($targetFile)) {
        $perms = @fileperms($targetFile);
        if ($perms !== false) {
            @chmod($tmp, $perms & 0777);
        }
    }

    if (!@rename($tmp, $targetFile)) {
        @unlink($tmp);
        return false;
    }

    return true;
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
        'github' => [
            'owner' => 'UranVester',
            'repo' => 'LaneAssist',
            'releaseApiUrl' => 'https://api.github.com/repos/UranVester/LaneAssist/releases/latest',
            'zipAssetPattern' => '/^laneassist-module-v?[0-9A-Za-z._-]+\\.zip$/i',
        ],
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
