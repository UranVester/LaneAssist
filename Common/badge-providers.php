<?php

/**
 * Badge provider discovery for Live View.
 *
 * A provider module ships Modules/Custom/<Name>/laneassist-badges.php, which
 * registers callables in $GLOBALS['LANEASSIST_BADGE_PROVIDERS']. Each takes
 * (array $archers, array $context) and returns [participantId => [badge, ...]].
 * A badge needs at least 'label' and 'color'; LaneAssist does not interpret it
 * further, so a later module can contribute records or club awards with no
 * change here.
 *
 * This file knows about no specific provider, deliberately.
 *
 * Why not menu.php: core's Common/Menu.php include()s every
 * Modules/Custom/*\/menu.php (lines 547 and 753), but only from PrintMenu() at
 * Common/Templates/head.php:67 -- a page render. LiveView/api.php serves JSON
 * and never loads the menu, so a hook there would never run for the request
 * that actually builds the archer list.
 */

if (!function_exists('laneAssistBadgeProviderFiles')) {

/**
 * Validate one badge, or null if it cannot be rendered.
 *
 * Providers are third-party code, so nothing is taken on trust. An unknown kind
 * is dropped rather than guessed at, which keeps a future kind from rendering as
 * something wrong on an older LaneAssist. A missing kind means 'pill', so the
 * simplest useful provider is a label and a colour.
 *
 * The icon pattern matters: the value ends up in a class attribute, and the
 * renderer must never be handed something it has to sanitise again.
 */
function laneAssistNormalizeBadge($badge) {
    if (!is_array($badge) || !isset($badge['label']) || $badge['label'] === '') {
        return null;
    }

    $kind = isset($badge['kind']) ? strtolower(trim((string)$badge['kind'])) : 'pill';
    if (!in_array($kind, ['edge', 'pill', 'icon'], true)) {
        return null;
    }

    if ($kind === 'icon'
        && !preg_match('/^fa-[a-z0-9-]{1,40}$/', (string)($badge['icon'] ?? ''))) {
        return null;
    }

    $badge['kind'] = $kind;
    return $badge;
}

/**
 * Provider files under a Modules/Custom root, sorted for deterministic order.
 *
 * @param string $customRoot absolute path to .../Modules/Custom
 * @return array absolute file paths
 */
function laneAssistBadgeProviderFiles($customRoot) {
    $pattern = rtrim((string)$customRoot, '/') . '/*/laneassist-badges.php';
    $files = glob($pattern);
    if (!is_array($files)) {
        return [];
    }
    sort($files, SORT_STRING);
    return $files;
}

/**
 * Ask every installed provider what badges these archers have earned.
 *
 * Failure isolation lives here rather than in the providers: LiveView/api.php
 * emits JSON, so one uncaught throw or one echoed notice from a third-party
 * module would break the page. Each include and each call therefore runs inside
 * its own output buffer, discarded afterwards, wrapped in try/catch (Throwable).
 * A provider that fails contributes nothing and is otherwise ignored.
 *
 * @param array       $archers    entries carrying at least 'participantId'
 * @param array       $context    tournament facts (toTypeName, toNumDist, ...)
 * @param string|null $customRoot override for tests; defaults to this install's
 * @return array participantId => list of badges (absent when none)
 */
function laneAssistCollectBadges(array $archers, array $context, $customRoot = null) {
    if ($customRoot === null) {
        // .../Modules/Custom/LaneAssist/Common/badge-providers.php -> Custom
        $customRoot = dirname(__DIR__, 2);
    }

    if (!isset($GLOBALS['LANEASSIST_BADGE_PROVIDERS'])
        || !is_array($GLOBALS['LANEASSIST_BADGE_PROVIDERS'])) {
        $GLOBALS['LANEASSIST_BADGE_PROVIDERS'] = [];
    }

    foreach (laneAssistBadgeProviderFiles($customRoot) as $file) {
        ob_start();
        try {
            require_once $file;
        } catch (Throwable $e) {
            // A broken provider must not take the snapshot down with it.
        }
        ob_end_clean();
    }

    $collected = [];
    foreach ($GLOBALS['LANEASSIST_BADGE_PROVIDERS'] as $provider) {
        if (!is_callable($provider)) {
            continue;
        }

        ob_start();
        try {
            $result = $provider($archers, $context);
        } catch (Throwable $e) {
            $result = null;
        }
        ob_end_clean();

        if (!is_array($result)) {
            continue;
        }

        foreach ($result as $participantId => $badges) {
            if (!is_array($badges)) {
                continue;
            }
            foreach ($badges as $badge) {
                $badge = laneAssistNormalizeBadge($badge);
                if ($badge === null) {
                    continue;
                }
                $collected[intval($participantId)][] = $badge;
            }
        }
    }

    return $collected;
}

}
