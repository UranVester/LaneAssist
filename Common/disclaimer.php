<?php
global $CFG;
require_once __DIR__ . '/i18n.php';
$settingsUrl = htmlspecialchars($CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/Settings/index.php', ENT_QUOTES, 'UTF-8');

echo '<div class="laneassist-disclaimer" style="position:fixed; left:50%; transform:translateX(-50%); bottom:12px; width:min(94vw, 920px); z-index:2147483000; padding:8px 12px; border:1px solid #ddd; border-radius:4px; background:#f5f5f5; font-size:12px; line-height:1.35; text-align:center; color:#333; box-shadow:0 2px 8px rgba(0,0,0,0.2); pointer-events:auto;">';
echo la_h('common.disclaimer') . ' (<a href="' . $settingsUrl . '">LaneAssist for IANSEO</a>. ' . la_h('common.disclaimerBy') . ' <a href="mailto:ianseo@vester.net">Mikkel Vester</a>)';
echo '</div>';
