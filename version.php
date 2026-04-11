<?php

if (!defined('LANEASSIST_MODULE_VERSION')) {
    // Bump this version on every release.
    define('LANEASSIST_MODULE_VERSION', '1.1');
}

if (!function_exists('getLaneAssistModuleVersion')) {
    function getLaneAssistModuleVersion() {
        return LANEASSIST_MODULE_VERSION;
    }
}
