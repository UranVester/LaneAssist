<?php
/**
 * LaneAssist distance helpers shared by ManageTargets, ManageFinals and the
 * color-by groups. normalizeDistanceToken() is pure; getDistanceProfilesByCategory()
 * reads TournamentDistances for $_SESSION['TourId'] / $_SESSION['TourType'].
 */

function getDistanceProfilesByCategory($categories) {
    $profiles = array();
    if (empty($categories)) {
        return $profiles;
    }

    foreach ($categories as $category) {
        $category = trim((string)$category);
        if ($category === '') {
            continue;
        }

        $sql = "SELECT Td1, Td2, Td3, Td4, Td5, Td6, Td7, Td8
                FROM TournamentDistances
                WHERE TdType=" . StrSafe_DB($_SESSION['TourType']) . "
                  AND TdTournament=" . StrSafe_DB($_SESSION['TourId']) . "
                  AND " . StrSafe_DB($category) . " LIKE TdClasses
                ORDER BY TdTournament=0,
                         " . StrSafe_DB($category) . " = TdClasses DESC,
                         LEFT(TdClasses,1)!='_' AND LEFT(TdClasses,1)!='%' DESC,
                         LEFT(TdClasses,1)='_' DESC,
                         TdClasses DESC,
                         TdClasses='%'
                LIMIT 1";

        $rs = safe_r_sql($sql);
        $profile = '';
        if ($row = safe_fetch($rs)) {
            $distances = array();
            for ($index = 1; $index <= 8; $index++) {
                $fieldName = 'Td' . $index;
                $normalized = normalizeDistanceToken((string)($row->$fieldName ?? ''));
                if ($normalized !== '') {
                    $distances[$normalized] = true;
                }
            }

            $distanceList = array_keys($distances);
            usort($distanceList, function($a, $b) {
                $na = intval($a);
                $nb = intval($b);
                if ($na === $nb) {
                    return strcmp($a, $b);
                }
                return $na - $nb;
            });

            $profile = implode('-', $distanceList);
        }

        $profiles[$category] = $profile;
    }

    return $profiles;
}

function normalizeDistanceToken($token) {
    $token = trim((string)$token);
    if ($token === '' || $token === '-') {
        return '';
    }

    if (preg_match('/(\d+)/', $token, $matches)) {
        return intval($matches[1]) . 'm';
    }

    return '';
}
