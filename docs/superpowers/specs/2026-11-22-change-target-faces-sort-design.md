# Change Target Faces: sort by target or last name

## Why
During an event an archer switches face (e.g. to triple). The organiser needs to
find that archer's row by their mat quickly and click the new face.

## Goals / non-goals
- Goal: a visible Target column; sort the list by Target or by Archer (last name).
- Non-goal: a target filter box (option C, not chosen), sorting by other columns.
- Anti-goal: the order jumping around after setting a face.

## Approaches
- **A. Clickable column headers (chosen).** Target + Archer headers sort; arrow shows
  direction; click again reverses. Client-side, instant.
- B. "Sort by" dropdown in the filter bar: crowds the bar, no hint in the table.
- C. A + Target filter: not wanted now.

## Design
- `tfLoadArchers()` LEFT JOINs `Qualifications` (QuId=EnId) and returns
  `session`, `target` (int, 0 = none), `letter` per archer.
- New Target column after Code. Shows `12A`; when more than one session has
  assigned archers, `1-12A` (session prefix). Unassigned shows blank. *(my call)*
- Sorting is a pure function in a new `ChangeTargetFaces/js/sort.js`
  (Node-testable, like `finals-playability.js`):
  - **Target:** session, target, letter; unassigned always last (both directions);
    ties by last name.
  - **Archer:** last name (`EnFirstName`), first name, id — same as today's server order.
- Default: Archer ascending (today's behaviour). Last choice remembered in
  `localStorage`. *(my call)*
- Sort is re-applied on every reload, so a row stays where it is after a set.
- Header arrows ▲/▼; new text keys in `Lang/en.php` + `dk.php` (`faces.colTarget`,
  sort tooltip).
- Set all is unaffected (it acts on the filter, not the order).

## Tests
- Node: `tests/js/change-target-faces-sort.test.js` (sessions, letters, unassigned
  last in both directions, name tiebreak).
- PHPUnit/catalog tests pick up the new keys.
