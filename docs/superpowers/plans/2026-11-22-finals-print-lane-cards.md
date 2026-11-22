# Finals print lane cards Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Lay the finals lane sheet out as wrapping lane cards instead of one table row per lane.

**Architecture:** Move the lane markup out of `LiveView/print-finals.php` into a pure renderer in
`Common/finals-print-logic.php` (DB-free, testable); the page calls it and swaps its table CSS
for flex-wrap card CSS.

**Tech Stack:** PHP, plain HTML/CSS print page, PHPUnit (`tools/phpunit.phar`).

**Spec:** `docs/superpowers/specs/2026-11-22-finals-print-lane-cards-design.md`

## Global Constraints
- Face image stays 28 mm; face name, event · phase, distance unchanged.
- A lane card never breaks across pages (`break-inside: avoid`).
- Lane data, readiness, byes, "Not on a lane" list unchanged.
- No new UI text (no catalog changes); all output escaped with `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`.

## Review Focus
- Lane with three or more faces (mixed events on one lane): card grows, still wraps — covered by the multi-face test.
- Lane with no face image (`faceUrl` empty): no `<img>`, card still renders — test in Task 1.
- Event names with HTML characters are escaped — test in Task 1.
- Empty distance: no "m" line — test in Task 1.

---

### Task 1: Pure lane-card renderer + page switch

**Files:**
- Modify: `Common/finals-print-logic.php` (append function)
- Modify: `LiveView/print-finals.php` (CSS block, replace `<table>` loop)
- Test: `tests/FinalsPrintLogicTest.php`

**Interfaces:**
- Produces: `fpRenderLanesHtml(array $lanes, callable $phaseLabel): string` — `$lanes` is
  `fpBuildLanes()['lanes']`; returns `<div class="lanes">` containing one
  `<div class="lane-card">` per lane, each with `<div class="lane-no">N</div>` and
  `<div class="targets">` holding the existing `.target` blocks.

- [ ] **Step 1: Write failing tests** in `FinalsPrintLogicTest`:
  - `testLanesRenderAsWrappingCards`: three single-face lanes → output has exactly one
    `class="lanes"`, three `class="lane-card"`, no `<table`/`<tr`, lane numbers in order.
  - `testLaneCardHoldsAllItsFaces`: lane 2 with two sides → that card contains two `class="target"`.
  - `testLaneCardEscapesAndSkipsMissingBits`: eventName `A<b>&` appears as `A&lt;b&gt;&amp;`;
    `faceUrl` '' → no `<img`; distance '' → no ` m<`.
- [ ] **Step 2:** Run `php tools/phpunit.phar --configuration phpunit.xml --filter FinalsPrintLogicTest` — expect FAIL (undefined function).
- [ ] **Step 3:** Implement `fpRenderLanesHtml()`, markup per target as in the current page.
- [ ] **Step 4:** In `print-finals.php` replace the table with `echo fpRenderLanesHtml($lanes['lanes'], 'laneAssistPhaseLabel');`
  and CSS: `.lanes { display:flex; flex-wrap:wrap; gap:3mm; }`,
  `.lane-card { border:1px solid #999; break-inside:avoid; page-break-inside:avoid; }`,
  `.lane-no { font-size:16px; font-weight:bold; text-align:center; border-bottom:1px solid #999; background:#eee; }`,
  `.targets { display:flex; gap:2mm; padding:2mm; }`, `.target { width:42mm; }` (drop table/td rules).
- [ ] **Step 5:** Full suite passes: `php tools/phpunit.phar --configuration phpunit.xml` and `node --test tests/js/`.
- [ ] **Step 6:** Render a real round in the local IANSEO (headless browser print to PDF) and confirm several lanes per line, fewer pages.
- [ ] **Step 7:** Commit.
