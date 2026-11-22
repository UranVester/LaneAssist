# Finals print: lane cards that wrap

## Intent
The LiveView finals lane sheet (`LiveView/print-finals.php`) prints one full-width table
row per lane. Finals lanes carry one or two faces, so most of each row is blank and a
round spreads over several pages. Goal: less paper, same information.

## Design (approved)
- Each lane is a bordered card: lane number in a strip on top, its faces side by side below.
- Cards flow left to right in lane order and wrap (flex-wrap); card width follows its face
  count (one face ≈ 44 mm → four per line on A4 portrait).
- Face image stays 28 mm; face name, event · phase, distance unchanged.
- A card never breaks across pages. "Not on a lane" list unchanged at the bottom.
- No change to lane data, readiness, or byes.

## Approaches
- **Flex-wrapped lane cards** (picked): simple, width adapts to one- vs two-face lanes.
- Fixed N-column grid: wastes space on mixed rounds (two-face lanes need a double cell).
- Keep the table, put several lanes per row: awkward cell spanning, same waste.

## Builder's calls
- Lane markup moves into a pure renderer (`fpRenderLanesHtml()` in
  `Common/finals-print-logic.php`) so a PHPUnit test can assert one wrapping container of
  lane cards instead of a table row per lane.
