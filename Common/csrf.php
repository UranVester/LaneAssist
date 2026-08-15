<?php

function laneAssistRequirePost() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('HTTP/1.1 405 Method Not Allowed');
        header('Allow: POST');
        echo json_encode(['error' => 1, 'message' => 'POST method required']);
        exit;
    }

    if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
        header('HTTP/1.1 403 Forbidden');
        echo json_encode(['error' => 1, 'message' => 'Invalid request origin']);
        exit;
    }
}
