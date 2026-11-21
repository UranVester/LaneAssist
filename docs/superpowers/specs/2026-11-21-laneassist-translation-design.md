# LaneAssist translation (English + Danish) — design

Date: 2026-11-21

## Intent

IANSEO itself is translated; LaneAssist is hard-coded English. The people who set up
tournaments and the people watching Live View should see LaneAssist in the language they
picked in IANSEO. Start with English and Danish; adding a language later should be
"add one file".

## Goals

- Tournament-setup pages and Live View show in the user's IANSEO language (en, dk).
- Danish wording matches IANSEO's own Danish (`Common/Languages/dk/*.php`), so LaneAssist
  reads like the rest of the program (e.g. use the same word IANSEO uses for target,
  session, division, class, phase).
- New text can't land untranslated unnoticed: a test fails when a key exists in English
  but not in Danish.
- Adding a third language = add `Lang/<code>.php`; no code changes.

## Non-goals

- Real failures from `*/api.php` stay English: not logged in / ACL, CSRF, malformed or
  invalid input, exceptions, DB errors (e.g. `CrackError`, `AUTH_REQUIRED`, "Invalid request").
- The Settings page (admin settings, user settings, updater, about) stays English.
- `ManageTargets/layout-playground.php`, `target-faces-debug.php`, `target-faces-backup.php`,
  `target-faces-import.php` (dev/debug tooling) stay English.
- No LaneAssist-specific language switch. Language always comes from IANSEO.
- No translation of data (event names, club names, target face names from the DB —
  those that already go through core `get_text()` keep doing so).

## Anti-goals

- Editing core IANSEO files, or dropping files into `Common/Languages/` (core's language
  updater would overwrite them, and it breaks the "don't edit core" rule).
- A half-translated page: within an in-scope page every user-visible string is translated,
  including JS-rendered text (dialogs, toasts, summary bar, tooltips, confirm()s, print sheets).
- Changing behaviour or layout. Danish strings are often longer; check that buttons and
  narrow columns still fit, but this is not a redesign.

## Scope (in)

Pages, including their JS and their print output:

- `menu.php` — the LaneAssist menu entries (except "LaneAssist Settings", which may stay English — builder's call to translate it too, it's one string)
- `ManageTournament/` (stage/status checklist, quickfixes, tournament settings card — incl. `Common/js/tournament-settings.js`, `Common/js/status-logic.js` text)
- `ManageTargets/index.php` + `js/` (incl. print layout `Common/js/print-layout.js`, auto-assign, color-by)
- `ManageFinals/index.php` + `js/` (incl. `Common/js/finals-playability.js` labels, summary bar)
- `ChangeEvents/`, `ChangeTargetFaces/`
- `LiveView/index.php` + `js/` + `print-finals.php`
- Shared UI text in `Common/js/*.js` and `Common/*.php` that these pages render
  (e.g. `color-by.js` option labels, `badge-render.js`, `final-scores.js`, `Common/disclaimer.php`).
- **Server-built text the user sees in normal use** (decided by Mikkel): everything except
  the real failures listed under Non-goals. This includes:
  - success toasts sent by the API (`"{n} session(s) updated"`, `"{n} logo(s) linked"`,
    `"Match advanced"`, `"Bye marked complete"`, `"Updated"`, `"No changes made"`,
    `"Settings updated successfully"`) — and the JS fallbacks next to them
    (`response.message || '…'`);
  - validation results (`ManageTargets/api.php` "Multiple participants on target …",
    "N participant(s) without target assignment", "Mixed distances on the same mat …";
    `validateFinalRows()` in `Common/finals-logic.php`);
  - Manage Tournament status checklist titles/rows (built in `ManageTournament/api.php`
    and `Common/status-logic.php`);
  - everyday refusals, even when sent with `error=1` ("This archer has recorded arrows and
    cannot be forfeited here" in `Common/live-view-logic.php`, "These settings would create
    no events, so nothing was changed." in `Common/events-rebuild.php`, "Qualification
    results are locked", "Participants are locked for this tournament.").
  These are translated on the server with `la_t()` in the request's language (the
  `UseLanguage` cookie is sent with every AJAX call). Pure logic files keep working without
  a DB/session: `la_t` must be includable from them and fall back to English in tests.

## Approaches considered

- **A. Named keys, own catalogs (chosen).** `Lang/en.php`, `Lang/dk.php` return arrays
  `key => text`. PHP looks up `la_t('targets.applyChanges')`; pages pass the needed
  catalog to JS, which calls `t('targets.applyChanges')`. Fallback to English. A test
  enforces key parity. Cost: code shows keys rather than English.
- **B. English sentence as key** (gettext style). Easier retrofit, readable code, but
  rewording English silently loses the Danish, and one English word can't map to two
  Danish meanings ("Target" = mat vs face).
- **C. Core `get_text()` where IANSEO has the word, own catalog for the rest.** Fewest
  new strings, but text comes from two places and core wording changes leak in.

Mikkel picked **A**: the parity test keeps Danish complete as features keep being added.

## Design

### Language selection

- Use core `SelectLanguage(true)` — the `true` ignores the `PRINTLANG` constant, which core
  defines as a side effect (`getIdFromCode()`, `Obj_Rank`) and would otherwise flip the
  rest of the page/request to the tournament print language. Resolution order is then (`?Lang=`, cookie `UseLanguage`, browser
  `Accept-Language`, fallback `en`). IANSEO's Danish code is `dk`.
- If `Lang/<code>.php` doesn't exist in LaneAssist, use `en`.
- **Print output uses the user's language too** (decided by Mikkel), not the tournament's
  print language (`ToPrintLang`): you print what you see. So the print popup in
  ManageTargets uses the same `LA_I18N` as the page, and `LiveView/print-finals.php` uses
  `la_t()` like any page.

### Catalog files

- `Modules/Custom/LaneAssist/Lang/en.php`, `Lang/dk.php`, each `return [ 'key' => 'text', ... ];`
  (plain PHP array, UTF-8, ships in the release ZIP).
- Keys: `area.name`, lower camelCase, area = page or shared component
  (`common.*`, `menu.*`, `tournament.*`, `targets.*`, `finals.*`, `events.*`, `faces.*`,
  `clone.*`, `live.*`, `print.*`, `colorBy.*`…). Exact naming is the builder's call.
- Placeholders: named, `{name}` style (e.g. `'{count} archers waiting'`), same in PHP and JS.
- Plurals: keep simple — separate keys (`.one` / `.other`) where a count is shown; both
  en and dk only need one/other.

### PHP helper

- `Common/i18n.php` (pure, includable without DB/auth, testable):
  - `laLang()` → active code (wraps `SelectLanguage()`, falls back to `en` if no catalog).
  - `la_t($key, $params = [])` → text in active language, else English, else the key
    itself (visibly, so a missing key shows up in manual testing rather than a blank).
  - `laCatalogFor($prefixes)` → merged en+active array filtered to the given key prefixes,
    for handing to JS.
- Output into HTML is escaped by the caller as today (`htmlspecialchars`); `la_t` returns
  raw text.

### JS side

- Each in-scope page emits, before its scripts:
  `<script>var LA_I18N = <?= json_encode(laCatalogFor([...]), JSON_HEX_TAG|JSON_HEX_AMP) ?>;</script>`
  with the prefixes that page uses (keeps payload small).
- `Common/js/i18n.js` (pure, no jQuery/DOM, Node-testable) exposes `t(key, params)` with
  the same fallback/placeholder rules as PHP. Loaded with a `?v=` cache buster like the
  other assets (see `tests/js/asset-versioning.test.js`).
- Pure JS modules that currently produce user-visible text (e.g. `finals-playability.js`,
  `status-logic.js`) take `t` / read `LA_I18N` without breaking their Node tests — the
  existing Node suites must keep passing (they can run with an English catalog loaded).
- Dates/numbers: unchanged unless already locale-dependent.

### Existing core `get_text()` calls

The ~46 existing `get_text()` calls that reuse IANSEO's words (Session, Division, target
face names, `CrackError`, …) stay as they are.

### Tests (per CLAUDE.md: tests live in `tests/`, `tests/js/`)

- PHP: key parity — every key in `en.php` exists in `dk.php` and vice versa; no empty
  values; placeholders in each dk text match the en text's placeholders.
- PHP: every `la_t('…')` key used in in-scope PHP files exists in `en.php` (static scan).
- JS: every `t('…')` key used in in-scope JS files exists in `en.php` (static scan, Node).
- JS: `i18n.js` unit tests — lookup, fallback to English, fallback to key, placeholders.
- PHP: `la_t` unit tests — same cases, plus unknown language → en.

### Remove Clone Tournament

`CloneTournament/` is already a "has been removed" stub; Mikkel wants it gone for good.
Delete the whole directory (`index.php`, `api.php`, `js/`, `css/`). Nothing else references
it (checked: menu.php and the other pages don't). Not translated, not coming back.

### Release

- `Lang/` and `Common/i18n.php`, `Common/js/i18n.js` ship.
- Spec/plan docs under `docs/superpowers/` should be excluded from the ZIP
  (add `"*/docs/superpowers/*"` to `build-update.sh` excludes).

### Manual check (add to the CLAUDE.md checklist)

With IANSEO set to Dansk, open each in-scope page and its print output; no English left
(other than API errors and data), no `key.names` showing, buttons/columns still fit.
Switch to English; everything is back in English.

## Left to the builder

- Exact key names and how to group them.
- Order of conversion (page by page is fine; each page complete before moving on).
- Danish wording, guided by IANSEO's `Common/Languages/dk/` terminology.
- Whether "LaneAssist Settings" menu entry is translated.
