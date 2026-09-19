<?php

function verifyLaneAssistUpdateIntegrity($projectRoot) {
    $requiredFiles = [
        'Modules/Custom/LaneAssist/Settings/api.php',
        'Modules/Custom/LaneAssist/Settings/index.php',
        'Modules/Custom/LaneAssist/Settings/js/app.js',
        'Modules/Custom/LaneAssist/Common/js/update-status.js',
    ];
    $missingFiles = [];

    foreach ($requiredFiles as $relativePath) {
        $path = rtrim((string)$projectRoot, '/\\') . '/' . $relativePath;
        if (!is_file($path) || !is_readable($path)) {
            $missingFiles[] = $relativePath;
        }
    }

    return [
        'ok' => empty($missingFiles),
        'missingFiles' => $missingFiles,
    ];
}

function writeLaneAssistUpdateFileAtomically($targetFile, $content) {
    $targetDir = dirname($targetFile);
    if (!is_dir($targetDir) || !is_writable($targetDir)) {
        return false;
    }

    $tmp = tempnam($targetDir, '.laneassist-upd-');
    if ($tmp === false) {
        return false;
    }

    if (@file_put_contents($tmp, $content) === false || !@chmod($tmp, 0664)) {
        @unlink($tmp);
        return false;
    }

    if (!@rename($tmp, $targetFile)) {
        @unlink($tmp);
        return false;
    }

    return true;
}