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
        $permissions = @fileperms($path);
        if (!is_file($path) || !is_readable($path) || $permissions === false || ($permissions & 0004) === 0) {
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

    if (@file_put_contents($tmp, $content) === false || !normalizeLaneAssistUpdateFilePermissions($tmp)) {
        @unlink($tmp);
        return false;
    }

    if (!@rename($tmp, $targetFile)) {
        @unlink($tmp);
        return false;
    }

    return true;
}

function normalizeLaneAssistUpdateFilePermissions($path) {
    return @chmod($path, 0664);
}

function repairLaneAssistModulePermissions($moduleRoot) {
    if (!is_dir($moduleRoot)) {
        return ['ok' => false, 'changed' => 0, 'failedPaths' => [$moduleRoot]];
    }

    $changed = 0;
    $failedPaths = [];
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($moduleRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($entries as $entry) {
        if ($entry->isLink()) {
            continue;
        }

        $path = $entry->getPathname();
        $targetPermissions = $entry->isDir() ? 0775 : 0664;
        $currentPermissions = $entry->getPerms() & 0777;
        if ($currentPermissions === $targetPermissions) {
            continue;
        }

        if (!@chmod($path, $targetPermissions)) {
            $failedPaths[] = $path;
            continue;
        }
        $changed++;
    }

    $rootPermissions = @fileperms($moduleRoot);
    if ($rootPermissions === false || ($rootPermissions & 0777) !== 0775) {
        if (!@chmod($moduleRoot, 0775)) {
            $failedPaths[] = $moduleRoot;
        } else {
            $changed++;
        }
    }

    return [
        'ok' => empty($failedPaths),
        'changed' => $changed,
        'failedPaths' => $failedPaths,
    ];
}
