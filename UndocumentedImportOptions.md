# Undocumented Import Options (ListLoad)

This note documents special import tags handled by `Partecipants/ListLoad.php` that are not fully documented in the List Load page UI.

Scope:
- Source behavior is from `Partecipants/ListLoad.php` in core.
- This file is documentation only, stored in LaneAssist to keep custom notes local.
- Behavior may change with upstream updates.

## 1) ##PHOTONAME##
Purpose:
- Save a custom photo name into `ExtraData` with `EdType='X'` for the entry.

Format (tab separated):
- `##PHOTONAME##`
- `BIB`
- `PHOTO_NAME`

Example:
- `##PHOTONAME##\tA123\tathlete_2026_001.jpg`

Validation/behavior:
- Exactly 3 fields required.
- BIB must exist in current tournament.
- Upsert into `ExtraData (EdType='X', EdExtra=PHOTO_NAME)`.

## 2) ##EXTRAS##
Purpose:
- Save extras payload into `ExtraData` with `EdType='P'` for the entry.

Format (tab separated):
- `##EXTRAS##`
- `BIB`
- `EXTRAS_VALUE`

Example:
- `##EXTRAS##\tA123\tDAYPASS`

Validation/behavior:
- Exactly 3 fields required.
- BIB and value are validated with regex `[a-z0-9_.-]+` (case-insensitive in code).
- BIB must exist in current tournament.
- Upsert into `ExtraData (EdType='P', EdExtra=EXTRAS_VALUE)`.

## 3) ##QUAL-ARROWS##
Purpose:
- Import arrow string for one qualification distance and recalculate related score fields.

Format (tab separated):
- `##QUAL-ARROWS##`
- `BIB`
- `DISTANCE_INDEX`
- `ARROWS`

`ARROWS` is comma-separated values.

Example:
- `##QUAL-ARROWS##\tA123\t1\t10,9,9,X,8,M`

Validation/behavior:
- Minimum 4 fields required.
- BIB regex check: `[a-z0-9_.-]+` (case-insensitive in code).
- Distance must be numeric `1..9`.
- Entry must exist.
- Uses target-face/tournament chars to evaluate arrows.
- Updates `Qualifications` fields for that distance (`ArrowString`, `Score`, `Gold`, `Xnine`, `Hits`) and total fields.
- Clears confirm/signed bit for the distance.
- Triggers rank recalculation for impacted division/class and distance.

Notes:
- Arrow conversion is done through internal `GetLetterFromPrint(...)` mapping.
- Max processed arrows is limited by configured `DiEnds * DiArrows`.

## 4) ##MATCHNO-ARROWS##
Purpose:
- Handler exists but appears incomplete/placeholder.

Format (tab separated):
- `##MATCHNO-ARROWS##`
- plus at least 4 more fields (minimum 5 total)

Current behavior:
- If fewer than 5 fields, row is refused.
- If 5+ fields, no import action is performed in current code block.

Practical conclusion:
- Do not rely on `##MATCHNO-ARROWS##` for data changes unless core implementation is completed.

## Operational Notes
- Each special row must start with tag in first field (column 1).
- Rows are tab separated; semicolons are converted to tabs by loader before parsing.
- You can mix normal participant rows and special-tag rows in the same textbox.
- Errors are reported in List Load result table as refused rows.
