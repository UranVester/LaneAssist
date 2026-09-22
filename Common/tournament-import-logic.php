<?php

function laneAssistDecodeTournamentImportPayload($payload) {
    if (!is_string($payload) || $payload === '') {
        return [null, 'The archive entry is empty.'];
    }

    $decoded = @gzuncompress($payload, 134217728);
    if ($decoded === false) {
        return [null, 'The archive entry is not a valid compressed IANSEO export.'];
    }

    $tournament = @unserialize($decoded, ['allowed_classes' => false]);
    if (!is_array($tournament) || !isset($tournament['Tournament']) || !is_array($tournament['Tournament'])) {
        return [null, 'The archive entry does not contain an IANSEO tournament export.'];
    }
    if (laneAssistTournamentImportContainsObject($tournament)) {
        return [null, 'The archive entry contains unsupported serialized objects.'];
    }

    $code = trim((string)($tournament['Tournament']['ToCode'] ?? ''));
    if ($code === '') {
        return [null, 'The tournament export has no competition code.'];
    }

    return [gzcompress(serialize($tournament), 9), '', [
        'code' => $code,
        'dbVersion' => trim((string)($tournament['Tournament']['ToDbVersion'] ?? '')),
    ]];
}

function laneAssistTournamentImportCompatibilityError($exportDbVersion, $targetDbVersion) {
    $exportDbVersion = trim((string)$exportDbVersion);
    $targetDbVersion = trim((string)$targetDbVersion);
    if ($exportDbVersion !== '' && $targetDbVersion !== '' && $exportDbVersion > $targetDbVersion) {
        return 'Requires IANSEO database version ' . $exportDbVersion . '; this installation is ' . $targetDbVersion . '. Update IANSEO before importing this tournament.';
    }
    return '';
}

function laneAssistIsTournamentArchiveEntry($name) {
    $name = (string)$name;
    return $name !== '' && strpos($name, '/') === false && preg_match('/\.ianseo$/i', $name) === 1;
}

function laneAssistTournamentImportContainsObject($value) {
    if (is_object($value)) {
        return true;
    }
    if (!is_array($value)) {
        return false;
    }
    foreach ($value as $item) {
        if (laneAssistTournamentImportContainsObject($item)) {
            return true;
        }
    }
    return false;
}