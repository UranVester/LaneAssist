<?php
require_once(dirname(__FILE__, 3) . '/config.php');

if (empty($_SESSION['debug'])) {
    header('HTTP/1.0 403 Forbidden');
    die('Debug mode required');
}

checkFullACL(AclRoot, 'cExport', AclReadWrite);
require_once('Common/Fun_Export.php');

if (!class_exists('ZipArchive')) {
    header('HTTP/1.0 500 Internal Server Error');
    die('ZIP support is unavailable.');
}

$archivePath = tempnam(sys_get_temp_dir(), 'laneassist-tournaments-');
if ($archivePath === false) {
    header('HTTP/1.0 500 Internal Server Error');
    die('Could not prepare the tournament backup.');
}

$zip = new ZipArchive();
if ($zip->open($archivePath, ZipArchive::OVERWRITE) !== true) {
    @unlink($archivePath);
    header('HTTP/1.0 500 Internal Server Error');
    die('Could not create the tournament backup.');
}

$query = safe_r_sql('SELECT ToId, ToCode FROM Tournament ORDER BY ToCode');
$usedNames = array();
while ($query && ($tournament = safe_fetch($query))) {
    $baseName = preg_replace('/[^A-Za-z0-9._-]/', '_', (string)$tournament->ToCode);
    if ($baseName === '') {
        $baseName = 'tournament-' . intval($tournament->ToId);
    }

    $fileName = $baseName . '.ianseo';
    $suffix = 2;
    while (isset($usedNames[$fileName])) {
        $fileName = $baseName . '-' . $suffix . '.ianseo';
        $suffix++;
    }
    $usedNames[$fileName] = true;

    $export = export_tournament(intval($tournament->ToId), true);
    if (!$zip->addFromString($fileName, gzcompress(serialize($export), 9))) {
        $zip->close();
        @unlink($archivePath);
        header('HTTP/1.0 500 Internal Server Error');
        die('Could not add a tournament to the backup.');
    }
}
$zip->close();

$downloadName = 'IanseoCompetitions-' . date('Ymd-His') . '.zip';
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Length: ' . filesize($archivePath));
header('Cache-Control: no-store');
readfile($archivePath);
@unlink($archivePath);
exit;