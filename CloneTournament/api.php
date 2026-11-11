<?php
/**
 * Clone Tournament was removed. This stub overwrites the old API on installs
 * updated in place (the self-updater never deletes files).
 */

http_response_code(410);
header('Content-Type: application/json');
echo json_encode(['error' => 1, 'message' => 'Clone Tournament has been removed from LaneAssist.']);
