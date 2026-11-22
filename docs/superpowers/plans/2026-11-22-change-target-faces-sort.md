# Change Target Faces sort Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Show a Target column in Change Target Faces and let the user sort by Target or Archer via clickable headers.

**Architecture:** Server adds session/target/letter (and split names) to each archer row. A pure UMD module `ChangeTargetFaces/js/sort.js` sorts and formats; `app.js` renders headers, applies the sort after every load, and stores the choice in `localStorage`.

**Tech Stack:** PHP (IANSEO), jQuery IIFE, Node `node:test`, PHPUnit phar.

**Spec:** `docs/superpowers/specs/2026-11-22-change-target-faces-sort-design.md`

## Global Constraints
- All UI text via `laT()` with literal keys in `Lang/en.php` + `Lang/dk.php`, one `'key' => 'text',` per line.
- Every LaneAssist asset on the page loads with `?v=filemtime` cache buster.
- Stay in `Modules/Custom/LaneAssist/`; no core edits.
- Default sort: Archer ascending. Unassigned targets last in both directions.

## Review Focus
- Archer in `Qualifications` with session 0 / target 0 → treated as unassigned (blank, last).
- Archer without a `Qualifications` row at all (LEFT JOIN null) → unassigned, no PHP warning.
- Corrupt/unknown `localStorage` value → falls back to Archer ascending.
- After setting a face (reload), row order is unchanged.
- Names with Danish letters (Ø, Å) → compared with `localeCompare`, not byte order.

---

### Task 1: Server returns target and split names

**Files:**
- Modify: `Common/target-faces-data.php` (`tfLoadArchers()`, ~line 100-148)
- Test: `tests/ChangeTargetFacesIntegrationTest.php`

**Interfaces:**
- Produces: each archer row gains `session` (int), `target` (int, 0 = none), `letter` (string), `lastName` (EnFirstName), `firstName` (EnName). Server ORDER BY unchanged.

- [ ] **Step 1: Write failing test** `testLoadArchersIncludesTarget`: in the test, seed `Qualifications` for EN_R (`QuSession=2, QuTarget=12, QuLetter='B', QuTargetNo='2012B'`); leave EN_C with no row. Assert EN_R row has `session===2, target===12, letter==='B', lastName==='Anna', firstName==='Test'`; EN_C row has `session===0, target===0, letter===''`. Add `DELETE FROM Qualifications WHERE QuId IN (EN_* ids)` to `cleanupSentinel()`.
- [ ] **Step 2: Run** `php tools/phpunit.phar --configuration phpunit.xml --filter testLoadArchersIncludesTarget` → FAIL (undefined key).
- [ ] **Step 3: Implement**: `LEFT JOIN Qualifications ON QuId=EnId`, select `QuSession, QuTarget, QuLetter`; map with `intval()` / `(string)` (null-safe).
- [ ] **Step 4: Run** the full PHPUnit suite → all pass.
- [ ] **Step 5: Commit** `Change Target Faces: load each archer's target`.

### Task 2: Pure sort module

**Files:**
- Create: `ChangeTargetFaces/js/sort.js` (UMD like `request-gate.js`, global `ChangeTargetFacesSort`)
- Test: `tests/js/change-target-faces-sort.test.js`

**Interfaces:**
- Produces:
  - `sortArchers(archers, key, dir) -> new array` — `key` `'target'|'archer'`, `dir` `'asc'|'desc'`; does not mutate input.
  - `formatTarget(archer, multiSession) -> string` — `''` if unassigned, else `'12A'` or `'1-12A'`.
  - `hasMultipleSessions(archers) -> bool` — more than one distinct session among assigned archers.
  - `parseSort(stored) -> {key, dir}` — validates a stored JSON string; default `{key:'archer', dir:'asc'}`.
- Unassigned = `!session || !target`.

- [ ] **Step 1: Write failing tests:**
  - `target sorts by session, then target, then letter`: input (2,1,A),(1,12,B),(1,12,A),(1,3,A) → order (1,3,A),(1,12,A),(1,12,B),(2,1,A).
  - `unassigned last ascending and descending`.
  - `target ties broken by last name`.
  - `archer sorts by lastName, firstName, id; desc reverses`.
  - `Danish letters`: lastNames `['Øster','Aagaard','Zahle']` asc with `localeCompare(…,'da')` → `Zahle, Øster, Aagaard` (Danish: Z < Æ < Ø < Å/Aa). If the Node ICU build lacks `da`, assert only that Ø sorts after Z.
  - `does not mutate input`.
  - `formatTarget`: (1,12,'A',single) → `'12A'`; multi → `'1-12A'`; unassigned → `''`.
  - `hasMultipleSessions` ignores unassigned.
  - `parseSort`: `null`, `'garbage'`, `'{"key":"x"}'` → default; `'{"key":"target","dir":"desc"}'` → as given.
- [ ] **Step 2: Run** `node --test tests/js/change-target-faces-sort.test.js` → FAIL (module missing).
- [ ] **Step 3: Implement** `sort.js`. Name compare: `String.localeCompare(b, 'da', {sensitivity:'base'})`.
- [ ] **Step 4: Run** `node --test tests/js/` → all pass.
- [ ] **Step 5: Commit** `Change Target Faces: pure sort/format helpers`.

### Task 3: Wire into page

**Files:**
- Modify: `ChangeTargetFaces/js/app.js` (render head/rows, load, init)
- Modify: `ChangeTargetFaces/index.php` (load `sort.js` with `?v=` before `app.js`)
- Modify: `ChangeTargetFaces/css/style.css` (sortable header cursor/arrow)
- Modify: `Lang/en.php`, `Lang/dk.php`
- Test: `tests/js/asset-versioning.test.js` (add ChangeTargetFaces page check if not covered)

**Interfaces:**
- Consumes: Task 1 fields; Task 2 `ChangeTargetFacesSort.*`.

- Text keys: `faces.colTarget` = `Target` / `Mål`; `faces.sortBy` = `Sort by this column` / `Sortér efter denne kolonne`.
- localStorage key: `laneassist.changeTargetFaces.sort`, value JSON `{key,dir}`.

- [ ] **Step 1: Write failing test** in `asset-versioning.test.js`: `ChangeTargetFaces loads every LaneAssist asset with a ?v= cache buster`, and asserts `sort.js` is among them. Run → FAIL.
- [ ] **Step 2: Implement:**
  - `state.sort = parseSort(localStorage.getItem(...))` (wrap in try/catch).
  - On load success: `state.archers = sortArchers(response.archers, state.sort.key, state.sort.dir)`.
  - Header: new Target `<th>` after Code; Target and Archer `<th>` get `class="tf-sortable" data-sort="target|archer" title=laT('faces.sortBy')` with ▲/▼ on the active one. Set-all row `colspan` 5 → 6; empty rows' colspan likewise.
  - Click `#tf-head th.tf-sortable`: same key toggles dir, new key → asc; save; re-sort `state.archers`; `render()`.
  - Row: Target cell `formatTarget(a, hasMultipleSessions(state.archers))`, class `tf-center`.
  - After `setOne` replaces a row in place, order is unaffected (reload re-sorts identically).
- [ ] **Step 3: Run** `node --test tests/js/` and the PHPUnit suite (CatalogTest, i18n-keys, no-hardcoded-text) → all pass.
- [ ] **Step 4: Live check** on local IANSEO (see memory: localhost/ianseo): open Change Target Faces on a tournament with targets in 2 sessions; sort Target asc/desc, Archer asc/desc; set a face, confirm order holds; reload page, sort remembered; switch to Dansk, header reads `Mål`.
- [ ] **Step 5: Commit** `Change Target Faces: sort by target or last name`.
