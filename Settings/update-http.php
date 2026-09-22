<?php

function laneAssistParseHttpResponseHeaders($responseHeaders) {
    $headers = [];
    if (!is_array($responseHeaders)) {
        return $headers;
    }

    foreach ($responseHeaders as $headerLine) {
        $parts = explode(':', (string)$headerLine, 2);
        if (count($parts) !== 2) {
            continue;
        }

        $name = strtolower(trim($parts[0]));
        if ($name !== '') {
            $headers[$name] = trim($parts[1]);
        }
    }

    return $headers;
}

function laneAssistBuildRemoteRequestHeaders($accept) {
    $headers = [
        'User-Agent: LaneAssist-Updater',
        'Accept: ' . trim((string)$accept),
        'X-GitHub-Api-Version: 2022-11-28',
    ];

    return implode("\r\n", $headers) . "\r\n";
}

function laneAssistFormatRemoteHttpError($statusCode, $responseHeaders = [], $body = '') {
    $statusCode = intval($statusCode);
    $headers = is_array($responseHeaders) ? $responseHeaders : [];

    if ($statusCode === 403 && intval($headers['x-ratelimit-remaining'] ?? -1) === 0) {
        $resetAt = intval($headers['x-ratelimit-reset'] ?? 0);
        if ($resetAt > 0) {
            return 'GitHub API rate limit reached. Try again after ' . gmdate('Y-m-d H:i:s', $resetAt) . ' UTC';
        }

        return 'GitHub API rate limit reached. Try again later';
    }

    $payload = json_decode((string)$body, true);
    $message = is_array($payload) ? trim((string)($payload['message'] ?? '')) : '';
    if ($message !== '') {
        return 'Remote request failed with HTTP ' . $statusCode . ': ' . substr($message, 0, 200);
    }

    return 'Remote request failed with HTTP ' . $statusCode;
}