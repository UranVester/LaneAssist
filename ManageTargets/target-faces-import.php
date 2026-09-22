<?php
require_once(dirname(__FILE__, 3) . '/config.php');

if (empty($_SESSION['debug'])) {
    header('HTTP/1.0 403 Forbidden');
    die('Debug mode required');
}

checkFullACL(AclRoot, '', AclReadWrite);
require_once('Common/Fun_TourDelete.php');
require_once(dirname(__FILE__, 2) . '/Common/tournament-import-logic.php');

const LANEASSIST_MAX_IMPORT_ENTRIES = 2000;
const LANEASSIST_MAX_IMPORT_ENTRY_BYTES = 67108864;
const LANEASSIST_IMPORT_BATCH_SIZE = 5;

$results = [];
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'startImport') {
    if (empty($_POST['confirmReplace'])) {
        $error = 'Confirm that tournaments with matching competition codes may be replaced.';
    } elseif (!class_exists('ZipArchive')) {
        $error = 'ZIP support is unavailable.';
    } elseif (empty($_FILES['tournamentArchive']) || $_FILES['tournamentArchive']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Choose a ZIP archive exported by Download Tournament Backup.';
    } else {
        $archivePath = tempnam(sys_get_temp_dir(), 'laneassist-restore-');
        if ($archivePath === false || !move_uploaded_file($_FILES['tournamentArchive']['tmp_name'], $archivePath)) {
            $error = 'The uploaded archive could not be stored for import.';
        } else {
            $zip = new ZipArchive();
            if ($zip->open($archivePath) !== true) {
                @unlink($archivePath);
                $error = 'The uploaded file is not a readable ZIP archive.';
            } elseif ($zip->numFiles > LANEASSIST_MAX_IMPORT_ENTRIES) {
                @unlink($archivePath);
                $error = 'The archive contains too many entries. Maximum: ' . LANEASSIST_MAX_IMPORT_ENTRIES . '.';
                $zip->close();
            } else {
                $_SESSION['LaneAssistTournamentRestore'] = [
                    'archivePath' => $archivePath,
                    'nextIndex' => 0,
                    'totalEntries' => $zip->numFiles,
                    'importedCodes' => [],
                    'completed' => 0,
                ];
                $zip->close();
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'continueImport') {
    $restore = $_SESSION['LaneAssistTournamentRestore'] ?? null;
    if (!is_array($restore) || empty($restore['archivePath']) || !is_file($restore['archivePath'])) {
        $error = 'The restore session has expired. Upload the backup archive again.';
        unset($_SESSION['LaneAssistTournamentRestore']);
    } else {
        $zip = new ZipArchive();
        if ($zip->open($restore['archivePath']) !== true) {
            $error = 'The uploaded file is not a readable ZIP archive.';
        } else {
            $processed = 0;
            for ($index = intval($restore['nextIndex']); $index < $zip->numFiles && $processed < LANEASSIST_IMPORT_BATCH_SIZE; $index++) {
                $restore['nextIndex'] = $index + 1;
                $stat = $zip->statIndex($index);
                $name = $stat['name'] ?? ('entry ' . ($index + 1));
                if (!laneAssistIsTournamentArchiveEntry($name)) {
                    continue;
                }
                if (intval($stat['size'] ?? 0) > LANEASSIST_MAX_IMPORT_ENTRY_BYTES) {
                    $results[] = ['name' => $name, 'error' => 'Entry is larger than 64 MB.'];
                    $processed++;
                    continue;
                }
                $contents = $zip->getFromIndex($index);
                [$payload, $decodeError, $metadata] = laneAssistDecodeTournamentImportPayload($contents);
                if ($decodeError !== '') {
                    $results[] = ['name' => $name, 'error' => $decodeError];
                    $processed++;
                    continue;
                }
                $code = $metadata['code'];
                if (isset($restore['importedCodes'][$code])) {
                    $results[] = ['name' => $name, 'error' => 'Duplicate competition code ' . $code . ' in this archive.'];
                    $processed++;
                    continue;
                }
                $restore['importedCodes'][$code] = true;
                $compatibilityError = laneAssistTournamentImportCompatibilityError($metadata['dbVersion'], GetParameter('DBUpdate'));
                if ($compatibilityError !== '') {
                    $results[] = ['name' => $name, 'error' => $compatibilityError];
                    $processed++;
                    continue;
                }
                $tourId = tour_import($payload, true);
                $results[] = $tourId
                    ? ['name' => $name, 'code' => $code, 'tourId' => intval($tourId)]
                    : ['name' => $name, 'error' => 'IANSEO rejected this export as incompatible.'];
                $processed++;
            }
            $zip->close();
            $restore['completed'] += $processed;
            if ($restore['nextIndex'] >= $restore['totalEntries']) {
                @unlink($restore['archivePath']);
                unset($_SESSION['LaneAssistTournamentRestore']);
            } else {
                $_SESSION['LaneAssistTournamentRestore'] = $restore;
            }
        }
    }
}

$restore = $_SESSION['LaneAssistTournamentRestore'] ?? null;

$PAGE_TITLE = 'Restore Tournament Backup';
$IncludeFA = true;
$JS_SCRIPT = ['<link href="css/target-faces-debug.css" rel="stylesheet" type="text/css">'];
include('Common/Templates/head.php');
?>
<main class="container">
    <div class="header-actions">
        <a href="target-faces-debug.php" class="btn btn-secondary"><i class="fa fa-arrow-left"></i> Back to Target Faces</a>
    </div>
    <h1>Restore Tournament Backup</h1>
    <p>This imports every top-level <code>.ianseo</code> file from a ZIP created by Download Tournament Backup.</p>
    <p class="alert alert-warning">Imports replace any existing tournament with the same competition code. This cannot be undone.</p>

    <?php if ($error !== ''): ?>
        <p class="alert alert-warning"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>

    <?php if ($results): ?>
        <section class="target-value-legend">
            <h2>Import Results</h2>
            <?php foreach ($results as $result): ?>
                <p class="<?php echo isset($result['error']) ? 'alert alert-warning' : 'alert alert-success'; ?>">
                    <strong><?php echo htmlspecialchars($result['name'], ENT_QUOTES, 'UTF-8'); ?>:</strong>
                    <?php echo isset($result['error'])
                        ? htmlspecialchars($result['error'], ENT_QUOTES, 'UTF-8')
                        : 'Imported ' . htmlspecialchars($result['code'], ENT_QUOTES, 'UTF-8') . ' (tournament #' . intval($result['tourId']) . ').'; ?>
                </p>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <?php if (is_array($restore)): ?>
        <section class="target-value-legend">
            <h2>Restore Progress</h2>
            <p>Processed <?php echo intval($restore['completed']); ?> archive entries. The next batch imports up to <?php echo LANEASSIST_IMPORT_BATCH_SIZE; ?> tournaments.</p>
            <form method="post">
                <input type="hidden" name="action" value="continueImport">
                <button type="submit" class="btn btn-danger"><i class="fa fa-step-forward"></i> Import Next Batch</button>
            </form>
        </section>
    <?php else: ?>

    <form method="post" enctype="multipart/form-data" class="target-value-legend">
        <h2>Backup Archive</h2>
        <input type="hidden" name="action" value="startImport">
        <p><input type="file" name="tournamentArchive" accept=".zip,application/zip" required></p>
        <p><label><input type="checkbox" name="confirmReplace" value="1" required> I understand matching competition codes will be replaced.</label></p>
        <button type="submit" class="btn btn-danger"><i class="fa fa-upload"></i> Import Tournament Backup</button>
    </form>
    <?php endif; ?>
</main>
<?php
include(dirname(__FILE__, 2) . '/Common/disclaimer.php');
include('Common/Templates/tail.php');