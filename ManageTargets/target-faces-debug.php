<?php
require_once(dirname(__FILE__, 3) . '/config.php');

CheckTourSession(true);
checkACL(AclParticipants, AclReadOnly);
require_once('Common/Fun_Sessions.inc.php');
require_once('Common/Lib/CommonLib.php');
require_once('Common/Fun_FormatText.inc.php');
require_once('Common/Lib/ArrTargets.inc.php');

global $CFG;

$canEditTargetValues = hasFullACL(AclCompetition, 'cData', AclReadWrite) && !IsBlocked(BIT_BLOCK_TOURDATA);
$saveFeedback = '';
$saveError = false;

function dbValueForSql($value) {
    if ($value === null) {
        return 'NULL';
    }
    return StrSafe_DB((string)$value);
}

function buildVegas610DataFromSource($sourceRow, $newTarId) {
    $data = get_object_vars($sourceRow);
    $data['TarId'] = intval($newTarId);
    // Plain text label requested for this preset target.
    $data['TarDescr'] = 'TrgVegas6-10';
    $data['TarArray'] = 'AROSVegas6to10';
    $data['TarIskDefinition'] = trim((string)($sourceRow->TarIskDefinition ?? '') . "\nLANEASSIST_PRESET=VEGAS_6_10\nLANEASSIST_CLONE_FROM=16");

    foreach (array('K', 'P', 'Q', 'X', 'Y', 'Z') as $xKey) {
        $field = $xKey . '_size';
        if (array_key_exists($field, $data)) {
            $data[$field] = 0;
        }
    }

    return $data;
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($canEditTargetValues)
    && isset($_POST['action'])
    && $_POST['action'] === 'createVegas610'
) {
    $sourceId = 16;
    $newTarId = 98;

    $srcRs = safe_r_sql('SELECT * FROM Targets WHERE TarId=' . $sourceId);
    if (!$srcRs || !safe_num_rows($srcRs)) {
        $saveError = true;
        $saveFeedback = 'Source target 16 not found.';
    } else {
        $source = safe_fetch($srcRs);
        $data = buildVegas610DataFromSource($source, $newTarId);

        $existsRs = safe_r_sql('SELECT TarId FROM Targets WHERE TarId=' . $newTarId);
        $exists = ($existsRs && safe_num_rows($existsRs));

        if ($exists) {
            $updates = array();
            foreach ($data as $field => $value) {
                if ($field === 'TarId') {
                    continue;
                }
                $updates[] = '`' . $field . '`=' . dbValueForSql($value);
            }
            $ok = safe_w_sql('UPDATE Targets SET ' . implode(', ', $updates) . ' WHERE TarId=' . $newTarId);
        } else {
            $fields = array();
            $values = array();
            foreach ($data as $field => $value) {
                $fields[] = '`' . $field . '`';
                $values[] = dbValueForSql($value);
            }
            $ok = safe_w_sql('INSERT INTO Targets (' . implode(',', $fields) . ') VALUES (' . implode(',', $values) . ')');
        }

        if (!$ok) {
            $saveError = true;
            $saveFeedback = 'Failed to create/update target 98.';
        } else {
            $srcSvg = $CFG->DOCUMENT_PATH . 'Common/Images/Targets/16.svg';
            $srcSvgz = $CFG->DOCUMENT_PATH . 'Common/Images/Targets/16.svgz';
            $dstSvg = $CFG->DOCUMENT_PATH . 'Common/Images/Targets/98.svg';
            $dstSvgz = $CFG->DOCUMENT_PATH . 'Common/Images/Targets/98.svgz';

            $copyErrors = array();
            if (is_file($srcSvg) && !@copy($srcSvg, $dstSvg)) {
                $copyErrors[] = '98.svg';
            }
            if (is_file($srcSvgz) && !@copy($srcSvgz, $dstSvgz)) {
                $copyErrors[] = '98.svgz';
            }

            if (!empty($copyErrors)) {
                $saveError = true;
                $saveFeedback = 'Target 98 saved, but failed copying image file(s): ' . implode(', ', $copyErrors) . '.';
            } else {
                $saveFeedback = $exists
                    ? 'Vegas 6-10 target (id 98) refreshed successfully.'
                    : 'Vegas 6-10 target (id 98) created successfully.';
            }
        }
    }
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($canEditTargetValues)
    && isset($_POST['action'])
    && $_POST['action'] === 'deleteTarget'
) {
    $targetId = intval($_POST['targetId'] ?? 0);
    if ($targetId <= 0) {
        $saveError = true;
        $saveFeedback = 'Invalid target id.';
    } else {
        $targetRs = safe_r_sql('SELECT TarId, TarArray, TarIskDefinition FROM Targets WHERE TarId=' . $targetId);
        if (!$targetRs || !safe_num_rows($targetRs)) {
            $saveError = true;
            $saveFeedback = 'Target not found.';
        } else {
            $targetRow = safe_fetch($targetRs);
            $targetArray = (string)($targetRow->TarArray ?? '');
            $targetDef = (string)($targetRow->TarIskDefinition ?? '');
            $isClone = (strpos($targetArray, 'NoX') !== false) || (strpos($targetDef, 'LANEASSIST_CLONE_FROM=') !== false);

            if (!$isClone) {
                $saveError = true;
                $saveFeedback = 'Only cloned targets can be deleted from this page.';
            } else {
                $usageSql = 'SELECT COUNT(*) as Cnt FROM TargetFaces WHERE '
                    . 'TfT1=' . $targetId . ' OR TfT2=' . $targetId . ' OR TfT3=' . $targetId . ' OR TfT4=' . $targetId
                    . ' OR TfT5=' . $targetId . ' OR TfT6=' . $targetId . ' OR TfT7=' . $targetId . ' OR TfT8=' . $targetId;
                $usageRs = safe_r_sql($usageSql);
                $usage = $usageRs ? safe_fetch($usageRs) : null;
                $inUse = intval($usage->Cnt ?? 0);

                if ($inUse > 0) {
                    $saveError = true;
                    $saveFeedback = 'Cannot delete target ' . $targetId . ': it is used in ' . $inUse . ' target face configuration(s).';
                } else {
                    if (safe_w_sql('DELETE FROM Targets WHERE TarId=' . $targetId)) {
                        $saveFeedback = 'Deleted cloned target ' . $targetId . '.';
                    } else {
                        $saveError = true;
                        $saveFeedback = 'Failed to delete target ' . $targetId . '.';
                    }
                }
            }
        }
    }
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($canEditTargetValues)
    && isset($_POST['action'])
    && $_POST['action'] === 'cloneWithoutX'
) {
    $sourceId = intval($_POST['sourceTargetId'] ?? 0);
    if ($sourceId <= 0) {
        $saveError = true;
        $saveFeedback = 'Invalid source target id.';
    } else {
        $srcRs = safe_r_sql('SELECT * FROM Targets WHERE TarId=' . $sourceId);
        if (!$srcRs || !safe_num_rows($srcRs)) {
            $saveError = true;
            $saveFeedback = 'Source target not found.';
        } else {
            $source = safe_fetch($srcRs);
            $maxRs = safe_r_sql('SELECT MAX(TarId) as MaxTarId FROM Targets');
            $maxRow = $maxRs ? safe_fetch($maxRs) : null;
            $newTarId = intval($maxRow->MaxTarId ?? 0) + 1;

            if ($newTarId <= 0 || $newTarId > 255) {
                $saveError = true;
                $saveFeedback = 'Cannot create new target id.';
            } else {
                $data = get_object_vars($source);
                $data['TarId'] = $newTarId;
                // Keep the original language key so ManTargets can render a known label without core changes.
                $data['TarDescr'] = (string)$source->TarDescr;
                $data['TarArray'] = substr((string)$source->TarArray . 'NoX' . $newTarId, 0, 24);
                $data['TarIskDefinition'] = trim((string)($source->TarIskDefinition ?? '') . "\nLANEASSIST_CLONE_FROM=" . intval($sourceId));

                // Remove all letters that print as X in ISK keypad values.
                foreach (array('K', 'P', 'Q', 'X', 'Y', 'Z') as $xKey) {
                    $sizeField = $xKey . '_size';
                    if (array_key_exists($sizeField, $data)) {
                        $data[$sizeField] = 0;
                    }
                }

                $fields = array();
                $values = array();
                foreach ($data as $field => $value) {
                    $fields[] = '`' . $field . '`';
                    if ($value === null) {
                        $values[] = 'NULL';
                    } else {
                        $values[] = StrSafe_DB((string)$value);
                    }
                }

                $insertSql = 'INSERT INTO Targets (' . implode(',', $fields) . ') VALUES (' . implode(',', $values) . ')';
                if (safe_w_sql($insertSql)) {
                    $saveFeedback = 'Created target ' . $newTarId . ' cloned from ' . $sourceId . ' without X.';
                } else {
                    $saveError = true;
                    $saveFeedback = 'Failed to clone target ' . $sourceId . '.';
                }
            }
        }
    }
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($canEditTargetValues)
    && isset($_POST['action'])
    && $_POST['action'] === 'saveTargetValues'
) {
    $targetId = intval($_POST['targetId'] ?? 0);

    if ($targetId <= 0) {
        $saveError = true;
        $saveFeedback = 'Invalid target id.';
    } else {
        $editableKeys = array_merge(range('A', 'Z'), range('1', '9'));
        $updates = array();

        foreach ($editableKeys as $key) {
            $field = $key . '_size';
            $rawValue = isset($_POST[$field]) ? $_POST[$field] : 0;
            $value = max(0, min(999, intval($rawValue)));
            $updates[] = $field . '=' . $value;
        }

        if (empty($updates)) {
            $saveError = true;
            $saveFeedback = 'No fields to update.';
        } else {
            $sql = 'UPDATE Targets SET ' . implode(', ', $updates) . ' WHERE TarId=' . $targetId;
            if (safe_w_sql($sql)) {
                $saveFeedback = 'Target ' . $targetId . ' updated successfully.';
            } else {
                $saveError = true;
                $saveFeedback = 'Failed to update target ' . $targetId . '.';
            }
        }
    }
}

$PAGE_TITLE = 'Target Faces';
$IncludeFA = true;
$JS_SCRIPT = array(
    '<link href="css/target-faces-debug.css" rel="stylesheet" type="text/css">',
);

include('Common/Templates/head.php');

// Get all target faces from Targets table
$targetFaces = [];
$q = safe_r_sql("SELECT * FROM Targets ORDER BY TarId");

if (!$q) {
    echo '<div class="container">';
    echo '<p class="alert alert-warning">Unable to load target faces.</p>';
    echo '</div>';
    include(dirname(__FILE__, 2) . '/Common/disclaimer.php');
    include('Common/Templates/tail.php');
    exit;
}

while ($r = safe_fetch($q)) {
    $targetFaces[] = $r;
}

echo '<div class="container">';
echo '<div class="header-actions">';
echo '<a href="index.php" class="btn btn-secondary"><i class="fa fa-arrow-left"></i> Back to Target Assignment</a>';
if ($canEditTargetValues) {
    echo '<form method="post" class="header-inline-form">';
    echo '<input type="hidden" name="action" value="createVegas610">';
    echo '<button type="submit" class="btn btn-warning">Create Vegas 6-10</button>';
    echo '</form>';
}
echo '</div>';
echo '<h1>Target Faces</h1>';
echo '<p>Available target faces: ' . count($targetFaces) . '</p>';

if ($saveFeedback !== '') {
    echo '<p class="alert ' . ($saveError ? 'alert-warning' : 'alert-success') . '">' . htmlspecialchars($saveFeedback) . '</p>';
}

if (!$canEditTargetValues) {
    echo '<p class="alert alert-warning">Target values are read-only with your current permissions.</p>';
}

if (empty($targetFaces)) {
    echo '<p class="alert alert-warning">No target faces found in the database.</p>';
} else {
    echo '<div class="target-faces-grid">';

    foreach ($targetFaces as $tf) {
        $imageUrl = null;

        if (!empty($tf->TarId)) {
            $svgPath = $CFG->DOCUMENT_PATH . 'Common/Images/Targets/' . $tf->TarId . '.svg';
            if (@file_exists($svgPath)) {
                $imageUrl = $CFG->ROOT_DIR . 'Common/Images/Targets/' . $tf->TarId . '.svg';
            } else {
                $svgzPath = $CFG->DOCUMENT_PATH . 'Common/Images/Targets/' . $tf->TarId . '.svgz';
                if (@file_exists($svgzPath)) {
                    $imageUrl = $CFG->ROOT_DIR . 'Common/Images/Targets/' . $tf->TarId . '.svgz';
                }
            }
        }

        $translatedName = isset($tf->TarDescr) ? get_text($tf->TarDescr) : 'N/A';
        $tarArray = (string)($tf->TarArray ?? '');
        $tarDef = (string)($tf->TarIskDefinition ?? '');
        $isClonedTarget = (strpos($tarArray, 'NoX') !== false) || (strpos($tarDef, 'LANEASSIST_CLONE_FROM=') !== false);
        $activeValues = array();
        $ngInfo = GetTargetNgInfo(intval($tf->TarId));
        if (is_array($ngInfo)) {
            foreach ($ngInfo as $item) {
                $pointLabel = trim((string)($item['point'] ?? ''));
                if ($pointLabel !== '' && !in_array($pointLabel, $activeValues, true)) {
                    $activeValues[] = $pointLabel;
                }
            }
        }

        echo '<div class="target-face-card">';
        echo '<div class="target-face-image-wrapper">';

        if ($imageUrl) {
            echo '<img src="' . htmlspecialchars($imageUrl) . '" alt="' . htmlspecialchars($tf->TarId) . '" class="target-face-image">';
        } else {
            echo '<div class="no-image">No Image</div>';
        }

        echo '</div>';

        echo '<div class="target-face-info">';
        echo '<div><strong>Target ID:</strong> ' . htmlspecialchars(isset($tf->TarId) ? $tf->TarId : 'N/A') . '</div>';
        echo '<div><strong>Name:</strong> ' . htmlspecialchars($translatedName) . '</div>';
        echo '<div><strong>Description:</strong> ' . htmlspecialchars(isset($tf->TarDescr) ? $tf->TarDescr : 'N/A') . '</div>';
        echo '<div><strong>Stars/Rings:</strong> ' . htmlspecialchars(isset($tf->TarStars) ? $tf->TarStars : 'N/A') . '</div>';
        echo '<div><strong>Full Size (cm):</strong> ' . htmlspecialchars(isset($tf->TarFullSize) ? $tf->TarFullSize : 'N/A') . '</div>';
        echo '<div><strong>Order:</strong> ' . htmlspecialchars(isset($tf->TarOrder) ? $tf->TarOrder : 'N/A') . '</div>';
        echo '<div><strong>Array:</strong> ' . htmlspecialchars(isset($tf->TarArray) ? $tf->TarArray : 'N/A') . '</div>';
        echo '<div><strong>ISK Values:</strong> ' . htmlspecialchars(implode(', ', $activeValues)) . '</div>';
        echo '</div>';

        echo '<div class="target-value-editor">';
        echo '<h3>Edit Value Sizes</h3>';
        echo '<p class="editor-help">Set a size to 0 to remove that value from ISK keypad options.</p>';

        echo '<form method="post" class="target-edit-form">';
        echo '<input type="hidden" name="action" value="saveTargetValues">';
        echo '<input type="hidden" name="targetId" value="' . intval($tf->TarId) . '">';

        echo '<div class="value-grid">';
        foreach (range('A', 'Z') as $letter) {
            $field = $letter . '_size';
            $value = intval($tf->{$field} ?? 0);
            echo '<label>';
            echo '<span>' . htmlspecialchars($letter) . '</span>';
            echo '<input type="number" min="0" max="999" name="' . htmlspecialchars($field) . '" value="' . $value . '" ' . (!$canEditTargetValues ? 'disabled="disabled"' : '') . '>';
            echo '</label>';
        }
        foreach (range('1', '9') as $digit) {
            $field = $digit . '_size';
            $value = intval($tf->{$field} ?? 0);
            echo '<label>';
            echo '<span>' . htmlspecialchars($digit) . '</span>';
            echo '<input type="number" min="0" max="999" name="' . htmlspecialchars($field) . '" value="' . $value . '" ' . (!$canEditTargetValues ? 'disabled="disabled"' : '') . '>';
            echo '</label>';
        }
        echo '</div>';

        if ($canEditTargetValues) {
            echo '<button type="submit" class="btn btn-primary">Save Values</button>';
        }
        echo '</form>';

        if ($canEditTargetValues) {
            echo '<form method="post" class="clone-form">';
            echo '<input type="hidden" name="action" value="cloneWithoutX">';
            echo '<input type="hidden" name="sourceTargetId" value="' . intval($tf->TarId) . '">';
            // echo '<button type="submit" class="btn btn-warning">Clone Without X</button>';
            echo '</form>';

            if ($isClonedTarget) {
                echo '<form method="post" class="delete-form" onsubmit="return confirm(\'Delete cloned target ' . intval($tf->TarId) . '?\');">';
                echo '<input type="hidden" name="action" value="deleteTarget">';
                echo '<input type="hidden" name="targetId" value="' . intval($tf->TarId) . '">';
                echo '<button type="submit" class="btn btn-danger">Delete Clone</button>';
                echo '</form>';
            }
        }
        echo '</div>';

        echo '</div>';
    }

    echo '</div>';
}

echo '</div>';

include(dirname(__FILE__, 2) . '/Common/disclaimer.php');
include('Common/Templates/tail.php');
