<?php
/**
 * Clone Tournament was removed. This stub overwrites the old page on installs
 * updated in place (the self-updater never deletes files).
 */

require_once(dirname(__FILE__, 3) . '/config.php');

header('Location: ' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/Settings/index.php');
exit;
