<?php
/**
 * Finals lane sheet for one scheduled round (time slot): every lane with the
 * faces to hang on it. Setup only, no names. Refuses to print until every
 * archer in the round is placed (see Common/finals-print-logic.php).
 */

require_once(dirname(__FILE__, 3) . '/config.php');

if (!CheckTourSession()) {
    header('HTTP/1.0 403 Forbidden');
    die('No competition selected');
}
checkFullACL(AclCompetition, '', AclReadOnly);
require_once(dirname(__FILE__, 2) . '/Common/finals-print-data.php');

header('Content-Type: text/html; charset=utf-8');

$slot = (string)($_GET['slot'] ?? '');
if (!fpIsValidSlot($slot)) {
    header('HTTP/1.0 400 Bad Request');
    die('Invalid round');
}

function fpH($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$round = fpLoadRound($slot);
$readiness = $round['readiness'];
$tournament = $_SESSION['TourName'] ?? '';
$slotTime = date('j M Y H:i', strtotime($slot));
$printedAt = date('j M Y H:i');

$lanes = ['lanes' => [], 'unassigned' => []];
if ($readiness['ready']) {
    $lanes = fpBuildLanes($round['sides'], $round['sidesByEvent']);
}

// Scopes come from what is printed, so a phantom event (nobody entered) that is
// scheduled in the slot doesn't appear in the header without any lanes.
$printedSides = $lanes['unassigned'];
foreach ($lanes['lanes'] as $lane) {
    $printedSides = array_merge($printedSides, $lane['targets']);
}
$scopes = [];
foreach ($readiness['ready'] ? $printedSides : $round['sides'] as $side) {
    $scopes[$side['eventName'] . ' · ' . laneAssistPhaseLabel($side['phase'])] = true;
}
?><!doctype html>
<html><head><meta charset="utf-8">
<title>Finals lanes - <?php echo fpH($slotTime); ?></title>
<style>
@page { size: A4 portrait; margin: 10mm; }
body { font-family: Arial, sans-serif; color: #000; background: #fff; margin: 0; font-size: 12px; }
.print-header { border-bottom: 1px solid #999; padding-bottom: 6px; margin-bottom: 8px; }
.print-header h1 { font-size: 16px; margin: 0 0 4px; }
.not-ready { margin-top: 20px; font-size: 14px; font-weight: bold; color: #b00; }
table { width: 100%; border-collapse: collapse; }
tr { break-inside: avoid; page-break-inside: avoid; }
td { border: 1px solid #999; padding: 4px; vertical-align: top; }
td.lane { width: 14mm; font-size: 20px; font-weight: bold; text-align: center; vertical-align: middle; }
.targets { display: flex; flex-wrap: wrap; gap: 6px; }
.target { width: 42mm; text-align: center; }
.target .letter { font-weight: bold; font-size: 14px; }
.target img { width: 28mm; height: 28mm; object-fit: contain; display: block; margin: 2px auto; }
.target .face { font-weight: bold; }
.target .meta { color: #333; font-size: 11px; }
.unassigned { margin-top: 10px; }
* { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
</style></head><body>
<div class="print-header">
    <h1><?php echo fpH($tournament); ?></h1>
    <div>Finals · <?php echo fpH($slotTime); ?> · <?php echo fpH(implode(', ', array_keys($scopes))); ?></div>
    <div>Printed: <?php echo fpH($printedAt); ?></div>
</div>
<?php if (!$readiness['ready']): ?>
    <div class="not-ready">
        <?php if ($readiness['waiting'] > 0): ?>
            Not ready to print: waiting for <?php echo intval($readiness['waiting']); ?> match<?php echo $readiness['waiting'] === 1 ? '' : 'es'; ?> to finish.
        <?php else: ?>
            Nothing is scheduled in this round.
        <?php endif; ?>
    </div>
<?php else: ?>
    <table>
    <?php foreach ($lanes['lanes'] as $lane): ?>
        <tr>
            <td class="lane"><?php echo intval($lane['lane']); ?></td>
            <td><div class="targets">
            <?php foreach ($lane['targets'] as $target): ?>
                <div class="target">
                    <div class="letter"><?php echo fpH($target['letterLabel']); ?></div>
                    <?php if ($target['faceUrl'] !== ''): ?>
                        <img src="<?php echo fpH($target['faceUrl']); ?>" alt="">
                    <?php endif; ?>
                    <div class="face"><?php echo fpH($target['faceLabel']); ?></div>
                    <div class="meta"><?php echo fpH($target['eventName'] . ' · ' . laneAssistPhaseLabel($target['phase'])); ?></div>
                    <?php if ($target['distance'] !== ''): ?>
                        <div class="meta"><?php echo fpH($target['distance']); ?> m</div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            </div></td>
        </tr>
    <?php endforeach; ?>
    </table>
    <?php if (!empty($lanes['unassigned'])): ?>
        <div class="unassigned"><strong>Not on a lane:</strong>
            <ul>
            <?php foreach ($lanes['unassigned'] as $side): ?>
                <li><?php echo fpH($side['eventName'] . ' · ' . laneAssistPhaseLabel($side['phase'])); ?></li>
            <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    <script>window.addEventListener('load', function () { window.print(); });</script>
<?php endif; ?>
</body></html>
