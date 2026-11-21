# LaneAssist Translation (en + dk) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tournament-setup pages and Live View in LaneAssist show in the user's IANSEO language (English and Danish to start).

**Architecture:** Module-owned catalogs `Lang/en.php` / `Lang/dk.php` (named keys). PHP looks text up with `la_t()` (from `Common/i18n.php`); each page hands a prefix-filtered catalog to the browser as `LA_I18N`, and JS looks text up with `laT()` (from `Common/js/i18n.js`). Language = core `SelectLanguage(true)`, falling back to `en`. Static-scan tests keep the catalogs complete and stop new hard-coded English.

**Tech Stack:** PHP 8 (no composer), PHPUnit via `tools/phpunit.phar`, jQuery IIFEs, Node `node:test`.

**Spec:** `docs/superpowers/specs/2026-11-21-laneassist-translation-design.md`

All paths below are relative to `Modules/Custom/LaneAssist/` (its own git repo) unless stated.

## Global Constraints

- Do NOT edit core IANSEO files; do not add files under `Common/Languages/`.
- Language codes are IANSEO's: English `en`, Danish `dk` (not `da`).
- Screen and print text both use the user's language: `SelectLanguage(true)` (the `true` ignores `PRINTLANG`). Never `ToPrintLang`.
- Keys are `area.name`, lower camelCase; placeholders `{name}`; counts use `<base>.one` / `<base>.other`.
- Keys passed to `la_t` / `laT` / `laPlural` / `laTn` are **string literals** — no concatenated or variable keys (the scan tests depend on it). Where a key depends on data, use a literal map (`['a' => la_t('x.a'), ...]`).
- Stays English (never converted): real failures from `*/api.php` — not logged in/ACL, CSRF, malformed or invalid input, exceptions, DB errors (`CrackError`, `AUTH_REQUIRED`, "Invalid request"…); the whole `Settings/` page; `ManageTargets/layout-playground.php`, `target-faces-debug.php`, `target-faces-backup.php`, `target-faces-import.php`; DB data.
- Everything else a user sees in normal use on in-scope pages is translated, including server-sent success toasts, validation results, checklist rows, and everyday refusals sent with `error=1`.
- Existing `get_text(...)` calls reusing IANSEO's words stay as they are.
- Danish wording follows IANSEO's `Common/Languages/dk/`: Skive (target/boss), Skiver, Skytte, Klub (country/club), Klasse, Division, Gruppe (event), Fase, Session, Afstand, Skivetype, Ansigt (face), Finaler, Kvalifikation, Tidsplan, Afbryd (cancel), Luk (close). Literal UTF-8 æøå.
- Every new/changed LaneAssist asset is loaded with a `?v=` cache buster.
- No behaviour or layout changes; Danish must still fit buttons and narrow columns.
- Commits are local only. Never push.

## Review Focus

1. **Hard-coded English left in JS** (dialogs, `confirm()`, toasts, `title`/`placeholder` attributes, summary bar, print HTML) → the user should see none on a Danish page. Pinned by `tests/js/no-hardcoded-text.test.js`, which every page task extends with its files.
2. **Danish text with quotes, `<`, `&` or æøå reaching HTML/JS** → renders literally, never breaks the page or the `<script>` block. Pinned in Task 2 (`laJsCatalogScript` escaping) and by page tasks inserting `laT()` output through `escapeHtml()`.
3. **Placeholder values containing `{…}` or user data** (club names like `{Team}`) → inserted once, not re-expanded; unknown placeholders left as-is. Pinned in Task 2 (PHP + JS).
4. **Odd language values** (`DK`, `da`, `../../x`, empty, a code IANSEO has but LaneAssist doesn't, e.g. `de`) → English, never an include outside `Lang/`. Pinned in Task 2.
5. **ManageTargets print popup** opens a new window → its text must come from the opener's translator, not a missing `LA_I18N`. Pinned in Task 4 (`print-layout.js` takes a translator; test with a fake catalog).

---

### Task 1: Remove Clone Tournament for good

**Files:**
- Delete: `CloneTournament/` (index.php, api.php, js/app.js, css/style.css)
- Modify: `build-update.sh` (zip excludes)

- [ ] **Step 1:** Confirm nothing references it: `grep -rn "CloneTournament" --include=*.php --include=*.js --include=*.sh --include=*.md . | grep -v '^./CloneTournament' | grep -v docs/superpowers` → no output.
- [ ] **Step 2:** `git rm -r CloneTournament`.
- [ ] **Step 3:** Add `"*/docs/superpowers/*"` to the `zip -x` list in `build-update.sh` (next to `"*/docs/screenshots/*"`).
- [ ] **Step 4:** Run both suites (commands below); expected: same pass/skip counts as before the change.
  - `php tools/phpunit.phar --configuration phpunit.xml`
  - `node --test tests/js/`
- [ ] **Step 5:** Commit: `git commit -m "Remove the Clone Tournament stub; keep design docs out of release zips"`.

---

### Task 2: Translation core (PHP + JS) and the guard tests

**Files:**
- Create: `Common/i18n.php`, `Common/js/i18n.js`, `Lang/en.php`, `Lang/dk.php`
- Create tests: `tests/I18nTest.php`, `tests/CatalogTest.php`, `tests/js/i18n.test.js`, `tests/js/i18n-keys.test.js`, `tests/js/no-hardcoded-text.test.js`, `tests/js/hardcoded-text-allowlist.json`
- Modify: `tests/bootstrap.php` (require `Common/i18n.php`, then `laSetLang('en')`)

**Interfaces (Produces):**
- PHP, `Common/i18n.php` (pure: includable with no DB/session/config; safe to `require_once` from logic files):
  - `laSetLang(?string $code): void` — test/override; `null` clears.
  - `laLang(): string` — override if set, else `strtolower(SelectLanguage(true))` when `SelectLanguage` exists, else `'en'`; returns `'en'` unless the code matches `/^[a-z-]+$/` and `Lang/<code>.php` exists.
  - `laCatalog(string $code): array` — loads `Lang/<code>.php` once (static cache); `[]` for invalid/missing.
  - `la_t(string $key, array $params = []): string` — active catalog → `en` → the key itself; then placeholders.
  - `laPlural(string $baseKey, int $count, array $params = []): string` — `la_t("$baseKey.one"|"$baseKey.other", $params + ['count' => $count])` (`.one` iff `$count === 1`).
  - `laCatalogFor(array $prefixes): array` — `en` merged with active, keeping keys starting with any prefix (e.g. `'targets.'`).
  - `laJsCatalogScript(array $prefixes): string` — `<script>var LA_I18N = {json};</script>`, json via `JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE`.
- JS, `Common/js/i18n.js` (UMD like `finals-playability.js`; no jQuery/DOM):
  - `module.exports = { createTranslator }`; `createTranslator(catalog) → { t(key, params), tn(baseKey, count, params) }`; lookup catalog → key; same placeholder and `.one/.other` rules as PHP.
  - In the browser: `window.laT`, `window.laTn` bound to `createTranslator(window.LA_I18N || {})`.
- Placeholder rule (both): single pass; `{name}` replaced by `String(params[name])` when present, otherwise left as-is; replaced values are never re-scanned.
- `Lang/*.php`: `<?php return [ 'key' => 'text', ... ];` — start with `'common.languageCheck' => 'English'` / `'Dansk'` so parity has something to check (delete it once real keys exist, Task 7).

**Guard tests (Produces, extended by later tasks):**
- `CatalogTest` (PHP): `en` and `dk` have identical key sets; no empty values; for every key the set of `{placeholders}` in dk equals en; every file in `Lang/` returns an array.
- `i18n-keys.test.js` (Node, reads `Lang/en.php` keys by regex `'([a-zA-Z0-9.]+)'\s*=>`): scans all `.php`/`.js` under the module except `tests/`, `tools/`, `Settings/`, `docs/`; collects literal keys from `la_t('…')`, `laT('…')`, and from `laPlural('…'`/`laTn('…'` as `….one` + `….other`; asserts (a) every used key exists in en, (b) every en key is used somewhere, (c) no call `la_t(`/`laT(`/`laPlural(`/`laTn(` whose first argument isn't a quoted literal.
- `no-hardcoded-text.test.js`: a `CONVERTED` file list (empty now; page tasks add their files). For each file, strips comments and flags (1) quoted literals matching `/(['"`])[A-Z][a-z]+(?:[ ,.!?:…][^'"`\n]*)?\1/` and (2) HTML text nodes `/>\s*[A-Z][a-z]{2,}[^<]*</`, ignoring hits inside `la_t(`/`laT(`/`get_text(` and hits listed for that file in `hardcoded-text-allowlist.json` (`{ "path": ["exact string", …] }`). Asserts zero remaining hits per file and prints them. The builder may tune the regexes, but the allowlist — not regex loosening — is how a real-failure API message is kept English.

- [ ] **Step 1: Write failing tests** — `I18nTest` uses the real `Lang/` files plus a test-only helper `laCatalogInject(string $code, array $entries): void` (add it to `Common/i18n.php`, documented as test-only; merges into the static cache) for fixture keys. `tearDown` restores `laSetLang('en')`. Cases:
  - `la_t('common.languageCheck')` is `'English'` with `laSetLang('en')`, `'Dansk'` with `laSetLang('dk')`.
  - key injected only into en, asked for in dk → English; unknown everywhere → `'no.such.key'`.
  - `laLang()` after `laSetLang($x)` (the override is lowercased and validated like the cookie): `'DK'` → `'dk'`; `'da'`, `'../../x'`, `''`, `'de'` → `'en'`.
  - placeholders: `'{n} of {total}'` with `['n'=>1,'total'=>3]` → `'1 of 3'`; with `['n'=>'{total}','total'=>3]` → `'{total} of 3'`; missing param leaves `{total}`.
  - `laPlural` picks `.one` for 1, `.other` for 0 and 2, and fills `{count}`.
  - `laJsCatalogScript` for an entry `'x.y' => '</script>"\'&æ'` contains no literal `</script>` before the closing tag, and `json_decode` of the payload round-trips the value.
  - static: `Common/i18n.php` source contains `SelectLanguage(true)`.
  - `i18n.test.js`: same lookup/placeholder/plural cases against `createTranslator({...})`.
- [ ] **Step 2:** Run `php tools/phpunit.phar --configuration phpunit.xml --filter 'I18nTest|CatalogTest'` and `node --test tests/js/i18n.test.js` → FAIL (missing files/functions).
- [ ] **Step 3:** Implement `Common/i18n.php`, `Common/js/i18n.js`, `Lang/en.php`, `Lang/dk.php`, the three guard tests and the allowlist (`{}`), bootstrap change.
- [ ] **Step 4:** Run full PHP suite and `node --test tests/js/` → all pass (pre-existing skips unchanged).
- [ ] **Step 5:** Commit: `"Add translation core: Lang catalogs, la_t/laT, catalog and key-scan tests"`.

---

### Page task pattern (Tasks 3–6)

Each page task follows the same steps; only the files differ.

- [ ] **Step 1:** Add the task's files to `CONVERTED` in `tests/js/no-hardcoded-text.test.js`. Run `node --test tests/js/no-hardcoded-text.test.js` → FAIL, listing the English strings found (this is the work list).
- [ ] **Step 2:** In the page's `index.php`, require `Common/i18n.php`, add `laJsCatalogScript([...prefixes])` to `$JS_SCRIPT` before the page scripts, and load `Common/js/i18n.js?v=<filemtime>` before any script that calls `laT`. Prefixes: the page's own area plus each shared area it renders (`common.`, `colorBy.`, …).
- [ ] **Step 3:** Replace every user-visible string in the files with `la_t`/`laPlural` (PHP) or `laT`/`laTn` (JS), adding each key to **both** `Lang/en.php` (current English, unchanged) and `Lang/dk.php`. HTML built in JS inserts `laT()` output through the file's `escapeHtml()`; PHP output through `htmlspecialchars()`. JS fallbacks such as `response.message || '…'` become `response.message || laT('…')`. Real-failure API messages stay English and go into the allowlist.
- [ ] **Step 4:** Pure JS modules that produce text (`*.js` with Node tests) take the translator as a parameter or read `globalThis.laT` with an English-catalog default so their existing Node tests still pass unchanged.
- [ ] **Step 5:** Run the full PHP suite and `node --test tests/js/` → all pass, including `no-hardcoded-text` for the new files, `i18n-keys`, `CatalogTest`. Existing tests asserting English messages must pass unmodified (bootstrap pins `en`).
- [ ] **Step 6:** Live check (see memory: local IANSEO at `http://localhost/ianseo`, DB `ianseo`): with cookie `UseLanguage=dk`, fetch each page of the task and one representative API call; confirm Danish text, no `[[` markers, no raw keys (`grep -E '\b(common|menu|tournament|targets|finals|events|faces|live|print|colorBy)\.[a-z][A-Za-z.]*\b'` on visible text), and English again with `UseLanguage=en`. Open it in the browser in Danish to check buttons/columns still fit.
- [ ] **Step 7:** Commit: `"Translate <page> (en/dk)"`.

---

### Task 3: Menu + Manage Tournament

**Files:** `menu.php`; `ManageTournament/index.php`, `ManageTournament/js/app.js`, `ManageTournament/api.php` (checklist titles/rows, quickfix results like `"{n} session(s) updated"`, `"{n} logo(s) linked"`); `Common/status-logic.php`, `Common/js/status-logic.js`, `Common/js/tournament-settings.js`; `Common/disclaimer.php`. Prefixes: `menu.`, `common.`, `tournament.`, `status.`.

- `menu.php` runs inside core's menu build: require `Common/i18n.php` there. The "LaneAssist Settings" entry stays English (allowlist it).
- Counts like "N session(s) updated" become `laPlural('tournament.sessionsUpdated', $n)`.
- Tests: existing `StatusLogicTest`, `StatusSnapshotIntegrationTest`, `StatusWriteActionsIntegrationTest`, `tests/js/status-logic.test.js`, `tournament-settings.test.js` pass unchanged. Add to `StatusLogicTest`: with `laSetLang('dk')` one checklist row title comes back in Danish (restore `en` in `tearDown`).

### Task 4: Manage Targets (incl. print layout and Color by)

**Files:** `ManageTargets/index.php` (incl. the `$targetLayouts` `description` texts and the no-tournament message), `ManageTargets/js/app.js`, `ManageTargets/api.php` (success messages and validation results: "Multiple participants on target …", "N participant(s) without target assignment", "Mixed distances on the same mat …", "Updated", "No changes made", "Settings updated successfully"); `Common/targets-logic.php`, `Common/target-layout-info.php`, `Common/js/print-layout.js`, `Common/js/color-by.js`, `Common/js/shared.js` (if it renders text). Prefixes: `common.`, `targets.`, `print.`, `colorBy.`.

- `color-by.js` option labels move to `colorBy.*`; ManageFinals and LiveView (Tasks 5–6) reuse them via the `colorBy.` prefix.
- `print-layout.js`: functions that build print HTML take a translator `{ t, tn }` argument (callers pass `{ t: laT, tn: laTn }`); the popup window never reads its own `LA_I18N`. Add to `tests/js/print-layout.test.js`: building a sheet with `createTranslator({ 'print.noArcher': 'Ingen skytte', … })` yields `Ingen skytte` for a blank slot, and the unapplied-change note uses the translator.
- Add to `tests/js/color-by.test.js`: option labels come from the supplied translator.

### Task 5: Manage Finals

**Files:** `ManageFinals/index.php`, `ManageFinals/js/app.js`, `ManageFinals/api.php`; `Common/finals-logic.php` (`validateFinalRows()` messages, e.g. "Event X (Team): Gold finals must be before …", "Target N is assigned more than once …"); `Common/js/finals-playability.js`, `Common/js/distance-lane-plan.js` (only if they produce text). Prefixes: `common.`, `finals.`, `colorBy.`.

- Add to `tests/ValidateFinalRowsTest.php`: with `laSetLang('dk')`, one validation message comes back in Danish with the event code and target number filled in (restore `en` in `tearDown`).
- Summary-bar text such as "Hidden non-playable" is translated; the playability rules themselves don't change.

### Task 6: Change Events, Change Target Faces, Live View (+ finals print)

**Files:** `ChangeEvents/index.php`, `ChangeEvents/js/app.js`, `ChangeEvents/api.php`, `Common/events-rebuild.php` (e.g. "These settings would create no events, so nothing was changed."), `Common/events-logic.php` (user-visible messages only; DK criteria names that are data stay as they are); `ChangeTargetFaces/index.php`, `ChangeTargetFaces/js/app.js`, `ChangeTargetFaces/api.php`, `Common/target-faces-data.php`, `Common/target-face-logic.php`; `LiveView/index.php`, `LiveView/js/app.js`, `LiveView/api.php` ("Match advanced", "Bye marked complete"), `LiveView/print-finals.php`, `Common/live-view-logic.php` ("This archer has recorded arrows and cannot be forfeited here"), `Common/live-view-data.php`, `Common/finals-print-logic.php`, `Common/finals-print-data.php`, `Common/js/badge-render.js`, `Common/js/final-scores.js`. Prefixes: `common.`, `events.`, `faces.`, `live.`, `print.`, `colorBy.`.

- If this task grows past one reviewable diff, split it into 6a (Change Events + Change Target Faces) and 6b (Live View + finals print), each with the full page-task pattern.
- `print-finals.php` uses `la_t()` directly; the LiveView finals print button's "waiting" count uses `laTn`.
- Add to `tests/LiveViewLogicTest.php`: the forfeit refusal comes back in Danish with `laSetLang('dk')`; add to `tests/FinalsPrintLogicTest.php`: one sheet label in Danish.

---

### Task 7: Sweep and docs

**Files:** `Lang/en.php`, `Lang/dk.php` (remove `common.languageCheck`; update `I18nTest` to use a real key), `/opt/ianseo/CLAUDE.md` (outer repo — add manual checklist item 14 and a short "Translation" note under LaneAssist Key Patterns), `README.md` (one line: follows IANSEO language; add a language by adding `Lang/<code>.php`).

- [ ] **Step 1:** Full suites pass: `php tools/phpunit.phar --configuration phpunit.xml`, `node --test tests/js/`.
- [ ] **Step 2:** Read through `Lang/dk.php` start to end for consistent terms (Global Constraints glossary) and natural Danish; fix in place.
- [ ] **Step 3:** In Danish in the browser, walk every in-scope page and both prints (ManageTargets print, LiveView finals print); nothing English except data, real failures, and the Settings menu entry. Switch to English; all English.
- [ ] **Step 4:** CLAUDE.md checklist item 14: "Translation: set IANSEO to Dansk; open Manage Tournament, Manage Targets (+ print), Manage Finals, Change Events, Change Target Faces, Live View (+ finals print); no English left except data and real errors, no raw `area.key` text, buttons/columns fit; switch back to English." Key Patterns note: "**Text:** `la_t()`/`laPlural()` (PHP, `Common/i18n.php`) and `laT()`/`laTn()` (JS, `Common/js/i18n.js`); catalogs in `Lang/en.php` + `Lang/dk.php`; literal keys only; `tests/js/no-hardcoded-text.test.js` + `CatalogTest` guard them."
- [ ] **Step 5:** Commit in the LaneAssist repo (`"Translation sweep: wording pass, README"`) and separately in the outer repo (`"Document LaneAssist translation"`). No push.
