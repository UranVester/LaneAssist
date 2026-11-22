<?php
/**
 * Finals lane sheet for one scheduled round (time slot): every lane with the
 * faces to hang on it. Setup only, no names. Refuses to print until every
 * archer in the round is placed (see Common/finals-print-logic.php).
 */

require_once(dirname(__FILE__, 3) . '/config.php');
require_once(dirname(__FILE__, 2) . '/Common/i18n.php');

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
<html lang="<?php echo fpH(laLang() === 'dk' ? 'da' : laLang()); ?>"><head><meta charset="utf-8">
<title><?php echo la_h('print.finalsTitle', ['slot' => $slotTime]); ?></title>
<style>
@page { size: A4 portrait; margin: 10mm; }
body { font-family: Arial, sans-serif; color: #000; background: #fff; margin: 0; font-size: 12px; }
.print-header { border-bottom: 1px solid #999; padding-bottom: 6px; margin-bottom: 8px; }
.print-header h1 { font-size: 16px; margin: 0 0 4px; }
.not-ready { margin-top: 20px; font-size: 14px; font-weight: bold; color: #b00; }
.lanes { display: flex; flex-wrap: wrap; gap: 3mm; align-items: flex-start; }
.lane-card { border: 1px solid #999; break-inside: avoid; page-break-inside: avoid; }
.lane-no { font-size: 16px; font-weight: bold; text-align: center; background: #eee; border-bottom: 1px solid #999; padding: 1px 0; }
.targets { display: flex; gap: 2mm; padding: 2mm; }
.target { width: 38mm; text-align: center; }
.target .letter { font-weight: bold; font-size: 14px; }
.target img { width: 28mm; height: 28mm; object-fit: contain; display: block; margin: 2px auto; }
.target .face { font-weight: bold; }
.target .meta { color: #333; font-size: 11px; }
.unassigned { margin-top: 10px; }
* { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
</style></head><body>
<div class="print-header">
    <h1><?php echo fpH($tournament); ?></h1>
    <div><?php echo la_h('print.finals'); ?> · <?php echo fpH($slotTime); ?> · <?php echo fpH(implode(', ', array_keys($scopes))); ?></div>
    <div><?php echo la_h('print.printed'); ?>: <?php echo fpH($printedAt); ?></div>
</div>
<?php if (!$readiness['ready']): ?>
    <div class="not-ready">
        <?php if ($readiness['waiting'] > 0): ?>
            <?php echo fpH(laPlural('print.notReadyWaiting', intval($readiness['waiting']))); ?>
        <?php else: ?>
            <?php echo la_h('print.nothingScheduled'); ?>
        <?php endif; ?>
    </div>
<?php else: ?>
    <?php echo fpRenderLanesHtml($lanes['lanes'], 'laneAssistPhaseLabel'); ?>
    <?php if (!empty($lanes['unassigned'])): ?>
        <div class="unassigned"><strong><?php echo la_h('print.notOnLane'); ?></strong>
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
