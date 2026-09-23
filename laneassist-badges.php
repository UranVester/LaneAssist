<?php

/**
 * LaneAssist's own badge provider: personal bests.
 *
 * Discovered by Common/badge-providers.php's glob of
 * Modules/Custom/*\/laneassist-badges.php, exactly like a third-party module --
 * LaneAssist gets no special case. Keeping personal bests on the same contract
 * leaves one rendering path in the page instead of two, and means the contract is
 * exercised by more than one provider from the first release.
 *
 * The fields read here are attached by laneAssistAttachPersonalBests() at
 * LiveView/api.php:103, which runs before laneAssistCollectBadges().
 */

if (!function_exists('laneAssistOwnBadges')) {

/**
 * @param array $archers each optionally carrying personalBest,
 *                       personalBestCompetitionName, hasNewPersonalBest
 * @return array participantId => list of badges
 */
function laneAssistOwnBadges(array $archers, array $context) {
    $out = [];

    foreach ($archers as $archer) {
        $id = intval($archer['participantId'] ?? 0);
        $badges = [];

        $personalBest = intval($archer['personalBest'] ?? 0);
        if ($personalBest > 0) {
            $competition = trim((string)($archer['personalBestCompetitionName'] ?? ''));
            $badges[] = [
                'kind' => 'pill',
                'label' => 'PB ' . $personalBest,
                // Neutral grey so a personal best never competes visually with a
                // badge colour that actually means something.
                'color' => '#e8edf0',
                'textColor' => '#475762',
                'title' => $competition === ''
                    ? 'Personal best ' . $personalBest . ' (competition name unavailable)'
                    : 'Personal best ' . $personalBest . ' at ' . $competition,
            ];
        }

        if (!empty($archer['hasNewPersonalBest'])) {
            $badges[] = [
                'kind' => 'icon',
                'label' => 'New personal best',
                'icon' => 'fa-trophy',
                'color' => '#b77900',
                'title' => 'New personal best',
            ];
        }

        if ($badges) {
            $out[$id] = $badges;
        }
    }

    return $out;
}

}

$GLOBALS['LANEASSIST_BADGE_PROVIDERS']['LaneAssist'] = 'laneAssistOwnBadges';
