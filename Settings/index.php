<?php
/**
 * LaneAssist Settings
 */

require_once(dirname(__FILE__, 3) . '/config.php');
require_once(dirname(__FILE__, 2) . '/version.php');
require_once(dirname(__FILE__) . '/update-http.php');

$hasCompetition = CheckTourSession();
if ($hasCompetition) {
    checkFullACL(AclCompetition, 'cSchedule', AclReadWrite);
}

$isDebugMode = !empty($_SESSION['debug']);
$moduleVersion = getLaneAssistModuleVersion();
$isAdminSettings = false;
if (!empty($CFG->USERAUTH) && !empty($_SESSION['AUTH_ENABLE']) && function_exists('hasFullACL')) {
    $isAdminSettings = hasFullACL(AclRoot, '', AclReadWrite);
}
$isAdminOrDebugSettings = $isDebugMode || $isAdminSettings;
$hasRootAcl = function_exists('hasFullACL') && hasFullACL(AclRoot, '', AclReadWrite);
$canInstallUpdates = laneAssistCanApplyUpdates($isDebugMode, $hasRootAcl, !empty($INFO->ACLEnabled));
$activeSettingsOwner = 'Shared default';
if (!empty($CFG->USERAUTH)) {
    $activeSettingsOwner = !empty($_SESSION['AUTH_User'])
        ? ('User: ' . $_SESSION['AUTH_User'])
        : 'Unknown user';
}

$PAGE_TITLE = 'LaneAssist Settings';
$IncludeJquery = true;
$IncludeFA = true;
$assetVersion = rawurlencode(getLaneAssistModuleVersion());

$JS_SCRIPT = array(
    '<script type="text/javascript">var ROOT_DIR = "' . $CFG->ROOT_DIR . '"; var LANEASSIST_DEBUG_MODE = ' . ($isDebugMode ? '1' : '0') . ';</script>',
    '<link href="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/Common/css/shared.css" rel="stylesheet" type="text/css">',
    '<link href="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/Settings/css/style.css" rel="stylesheet" type="text/css">',
    '<script src="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/Common/js/update-status.js?v=' . $assetVersion . '"></script>',
    '<script src="' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/Settings/js/app.js?v=' . $assetVersion . '"></script>',
);

include('Common/Templates/head.php');
?>

<div class="laneassist-settings-container">
    <div class="toolbar">
        <div class="toolbar-left">
            <h2>LaneAssist Settings <span class="module-version">v<?php echo htmlentities($moduleVersion); ?></span></h2>
        </div>
    </div>

    <div id="settings-status" class="status-message hidden"></div>

    <div class="settings-grid">
        <?php if ($isAdminOrDebugSettings): ?>
        <section class="settings-card">
            <h3><i class="fa fa-shield"></i> Admin settings</h3>
            <div class="settings-field">
                <label for="admin-default-finals-length">Admin default finals length (minutes)</label>
                <input id="admin-default-finals-length" type="number" min="1" max="480" step="1" value="30">
                <small>Base default used for all users unless they set their own value.</small>
            </div>
            <div class="settings-field">
                <label class="checkbox-inline">
                    <input id="admin-hide-ianseo-update-menu" type="checkbox">
                    Hide "Update Ianseo" menu entry
                </label>
            </div>
            <div class="settings-field">
                <!--<label class="checkbox-inline">
                    <input id="admin-hide-target-faces-menu" type="checkbox">
                    Hide "Target Faces" menu entry
                </label>-->
                <small>Admin controls for menu visibility.</small>
            </div>
            <div class="settings-actions">
                <button id="btn-save-admin-settings" class="btn btn-warning"><i class="fa fa-save"></i> Save Admin Settings</button>
            </div>
        </section>
        <?php endif; ?>

        <section class="settings-card">
            <h3><i class="fa fa-user"></i> User settings</h3>
            <?php if ($isDebugMode): ?>
                <p class="inline-note"><strong>Debug:</strong> active owner = <?php echo htmlentities($activeSettingsOwner); ?></p>
            <?php endif; ?>
            <div class="settings-field">
                <label for="global-default-finals-length">Default finals length (minutes)</label>
                <input id="global-default-finals-length" type="number" min="1" max="480" step="1" value="30">
                <small>When authentication is enabled, this value is saved per user. Without authentication, this acts as the shared default.</small>
            </div>
            <div class="settings-actions">
                <button id="btn-save-global-settings" class="btn btn-success"><i class="fa fa-save"></i> Save User Settings</button>
            </div>
        </section>

        <section class="settings-card">
            <h3><i class="fa fa-cloud-download"></i> Updates</h3>
            <p class="inline-note">Checks the latest signed release from <strong>GitHub</strong>.</p>
            <div id="update-github-summary" class="inline-note">Updates have not been checked yet.</div>
            <div id="update-github-meta" class="inline-note"></div>
            <div class="settings-actions">
                <button id="btn-check-updates" class="btn btn-info"><i class="fa fa-refresh"></i> Check Updates</button>
                <?php if ($canInstallUpdates): ?>
                    <button id="btn-apply-update-github" class="btn btn-warning" disabled><i class="fa fa-download"></i> Update Now</button>
                <?php endif; ?>
            </div>
            <small>Only signed releases with your configured Ed25519 key are accepted.</small>
            <?php if (!$canInstallUpdates): ?>
                <small class="inline-note">Installing updates requires a root administrator account.</small>
            <?php endif; ?>
        </section>

        <section class="settings-card">
            <h3><i class="fa fa-info-circle"></i> About</h3>
            <p>
                LaneAssist for IANSEO provides interactive workflows for finals and qualification operations.
                Will also be the home of future features.
            </p>
            <p>
                Made because I felt the need for more user-friendly interfaces for managing finals and targets, and to have a place for future tools and features.
            </p>
            <p>
                This module is not affiliated with IANSEO, but is built on top of the IANSEO platform and uses its APIs. It is developed and maintained by volunteers in the archery community.
            </p>
            <p>
                Developed by Mikkel Vester, Aarhus Bueskyttelaug.<br/>
                Reach out on <a href="mailto:ianseo@vester.net">ianseo@vester.net</a>
            </p>
        </section>

        <?php if ($isDebugMode): ?>
        <section class="settings-card">
            <h3><i class="fa fa-upload"></i> Debug: Update by file</h3>
            <?php if ($canInstallUpdates): ?>
            <p class="inline-note">Debug-only local ZIP updater for development/testing using ZIP + Ed25519 signature text.</p>
            <div class="settings-field">
                <label for="update-file-input">Update ZIP file</label>
                <input id="update-file-input" type="file" accept=".zip,application/zip">
                <small>Only files under <strong>Modules/Custom/LaneAssist/</strong> are allowed from the archive. Existing files are backed up first.</small>
            </div>
            <div class="settings-field">
                <label for="update-signature-text">Signature (base64)</label>
                <textarea id="update-signature-text" rows="3" placeholder="Paste base64 signature"></textarea>
                <small>Paste contents of the generated <strong>.sha256.sig</strong> file. Server computes SHA-256 from the uploaded ZIP.</small>
            </div>
            <div class="settings-actions">
                <button id="btn-apply-update-file" class="btn btn-warning"><i class="fa fa-upload"></i> Apply Update File</button>
            </div>
            <div id="update-file-result" class="inline-note"></div>
            <div class="inline-note">Config file: <strong>Modules/Custom/LaneAssist/Settings/update-config.php</strong></div>
            <?php else: ?>
            <p class="inline-note">Installing update files requires a root administrator account.</p>
            <?php endif; ?>
        </section>
        
        <?php endif; ?>
        
        <section class="settings-card">
            <h3><i class="fa fa-list"></i> Plans/TODO</h3>
            <p>Items currently planned but not done yet:</p>
            <ul>
                <li>Look at teams finals</li>
                <li>Look at multi-session tournaments</li>
                <li>Testing</li>
                <li>Bugfixing</li>
                <li>Live view for qualifications</li>
                <li>Live view for finals</li>
                <li>Hiding of various unused menu items?</li>
                <li>Translations</li>
            </ul>
        </section>
        
        <section class="settings-card">
            <h3><i class="fa fa-bug"></i> Feature requests & bugs</h3>
            <p>Have a feature request, bug report, or general suggestion?</p>
            <p>Please send an email to <a href="mailto:ianseo@vester.net">ianseo@vester.net</a> with a description of your request or issue.</p>
        </section>


        
    </div>
</div>

<?php
include(dirname(__FILE__, 2) . '/Common/disclaimer.php');
include('Common/Templates/tail.php');
?>
