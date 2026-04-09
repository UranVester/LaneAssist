<?php

$hasSelectedTour = !empty($_SESSION['TourId']);

function laneAssistGetAdminMenuToggle($paramName) {
	$query = "SELECT MpValue
		FROM ModulesParameters
		WHERE MpModule='LaneAssist'
		AND MpParameter=" . StrSafe_DB($paramName) . "
		AND MpTournament=0
		LIMIT 1";
	$res = safe_r_sql($query);
	if ($row = safe_fetch($res)) {
		$raw = $row->MpValue;
		$decoded = @unserialize($raw);
		$value = ($decoded !== false || $raw === 'b:0;') ? $decoded : $raw;
		return intval($value) ? true : false;
	}
	return false;
}

function laneAssistRemoveCoreUpdateEntry(&$mods, $rootDir) {
	if (empty($mods) || !is_array($mods)) {
		return;
	}

	$normalizedRoot = rtrim((string)$rootDir, '/') . '/';
	$normalizedUpdate = $normalizedRoot . 'Update';
	$filtered = [];

	foreach ($mods as $item) {
		if (!is_string($item)) {
			$filtered[] = $item;
			continue;
		}

		$parts = explode('|', $item);
		$link = trim((string)($parts[1] ?? ''));
		if ($link !== '') {
			$linkNormalized = rtrim($link, '/');
			if ($linkNormalized === $normalizedUpdate) {
				continue;
			}
		}

		$filtered[] = $item;
	}

	$mods = array_values($filtered);
}

$hideIanseoUpdateEntry = laneAssistGetAdminMenuToggle('AdminHideIanseoUpdateMenuEntry');
$hideCloneTournamentEntry = laneAssistGetAdminMenuToggle('AdminHideCloneTournamentEntry');
$hideTargetFacesEntry = laneAssistGetAdminMenuToggle('AdminHideTargetFacesEntry');

if ($hideIanseoUpdateEntry) {
	laneAssistRemoveCoreUpdateEntry($ret['MODS'], $CFG->ROOT_DIR);
}

$ret['MODS'][] = MENU_DIVIDER;
if (!$hideCloneTournamentEntry) {
	$ret['MODS'][] = 'Clone Tournament' . '|' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/CloneTournament/index.php';
}
if ($hasSelectedTour) {
	$ret['MODS'][] = 'Manage Targets - Interactive' . '|' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/ManageTargets/index.php';
	$ret['MODS'][] = 'Manage Finals - Interactive' . '|' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/ManageFinals/index.php';
}
$ret['MODS'][] = 'LaneAssist Settings' . '|' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/Settings/index.php';
$ret['MODS'][] = MENU_DIVIDER;
if (!$hideTargetFacesEntry) {
	$ret['MODS'][] = 'Target Faces' . '|' . $CFG->ROOT_DIR . 'Modules/Custom/LaneAssist/ManageTargets/target-faces-debug.php';
}
?>
