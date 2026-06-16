# Changelog — Indexing Page Manager

All notable changes to this plugin are documented here. Versioning follows the
plugin's `version.xml` `<release>` value; every shipped change bumps it.

## 0.1.14 — 2026-06-16

- **Admin forms now show real language names.** The per-locale tabs/badges on the
  index, section and settings forms display the language name (e.g. *English*,
  *Türkçe*) instead of the raw locale code (`en_US`, `tr_TR`). The forms pass a
  code→name map (`AppLocale::getAllLocales()`) and the badge style no longer
  force-uppercases the label.
- **Public-facing documentation.** Rewrote `README.md` as a journal-owner-friendly
  guide in English and Turkish, with screenshots, requirements (OJS 3.3.0.x, PHP
  7.4–8.2) and install/usage steps. Added a `screenshots/` folder for the README
  images.
- Removed the internal developer note file from the package.

## 0.1.13 — 2026-06-16

Pre-release hardening pass (security / compatibility / accessibility review).

- **Fix: journal-delete cleanup never ran.** The cleanup hook was registered on
  `JournalDAO::deleteJournalById`, which OJS 3.3 never fires — so deleting a
  journal orphaned its `ipm_*` rows and logo files. Now registered on the real
  `Context::delete` hook, with the callback adapted to the Context object it
  passes.
- **Fix: LICENSE carried the wrong product name** ("Editorial Board Manager" →
  "Indexing Page Manager").
- **Security/robustness:** the logo directory's `.htaccess` PHP-execution guard
  now fails loudly (logged) instead of silently when it can't be written, and
  the file header documents the nginx/IIS equivalent; Schema.org JSON-LD now
  `strip_tags()`es name/description (defence-in-depth on top of the existing
  JSON hex-encoding); logo URLs are `|escape`d at every template output site.
- **PHP 8.1:** the index settings eager-loader now decodes object values with
  `json_decode()` (matching OJS core) and casts to string before `unserialize`,
  avoiding a null-to-string deprecation.
- **Accessibility:** active-state toggles expose `aria-pressed` (+ `aria-label`)
  and keep it in sync in JS; section titles on the public page are now `<h2>`;
  admin secondary text raised to a WCAG-AA contrast colour.
- **i18n:** all remaining hard-coded UI strings (nav aria-label, slug label, and
  the JS saving/saved/failed/network/invalid-request messages) moved to locale
  keys, added to both en_US and tr_TR (full parity retained).
- **Cleanup:** removed the dead `templates/admin/_assets.tpl`, all debug
  `console.*` calls, and internal cross-references in shipped code/comments; raw
  exception text is no longer surfaced to users (generic localized message).

## 0.1.12 — 2026-06-15

- **Sections are no longer collapsible — always shown in full.** Dropped the
  native `<details>/<summary>` accordion (and its chevron, hover/marker styling
  and open animation) in favour of a plain `<section>` + static header. Every
  section's logos are always visible; there is no expand/collapse control. The
  section title + count badge and the underline separator are unchanged, so the
  look is identical apart from the removed toggle.

## 0.1.11 — 2026-06-15

- **Page title is now centred on every theme, with proper vertical spacing.**
  The page body is the plugin's own space, so the header is treated as our
  content: the `<h1>` is centred and the intro is centred + width-limited, while
  STILL inheriting the active theme's font, size, weight and colour (we set only
  alignment + margins, at normal specificity so it wins over a theme's
  left-aligned content-h1 default). `.ipm-page` also gained top/bottom padding
  (`clamp(20–36px)` top, `clamp(28–48px)` bottom) so the title no longer sits
  flush against the theme's masthead ("stuck to the top") and the grid clears at
  the end. Browser-verified centred on default, defaultManuscript, journalplus,
  nivo and axis. (Atlas is the one exception by design: it prints the page title
  in its own masthead nameplate for ALL pages, so our duplicate stays hidden and
  the title follows Atlas there — consistent with Atlas's own pages.)
- Supersedes the 0.1.10 "stay theme-agnostic/left-aligned" stance at the user's
  request: a tidy, consistently-centred header reads better across themes than a
  top-hugging left title.

## 0.1.10 — 2026-06-15

- **Page now uses the OJS-canonical `page page_*` wrapper.** The showcase is
  wrapped in `<div class="page page_databases …">` — exactly the structure core
  OJS pages (about/contact) and themes such as JournalPlus emit — so each active
  theme treats it like one of its OWN content pages (page padding, the
  `.page h1` rules, the heading underline, the zeroed title top-margin, etc.)
  WITHOUT the plugin coupling to any single theme. Our `.ipm-*` classes ride
  alongside for the grid/cards we own.
- **Verified the title is theme-compatible across ALL six installed themes**
  (each theme's own `/about/contact` title vs our `/about/databases` title,
  computed styles compared):
  - **Pixel-identical** on **default**, **defaultManuscript**, **journalplus**
    (same font, size, weight, colour, alignment, margins — including
    JournalPlus's title underline) and on **atlas** (Atlas prints the page title
    in its masthead nameplate from `pageTitleTranslated`; our de-dupe hides the
    duplicate, leaving one fully Atlas-styled title).
  - **Base-typography match** on **axis** and **nivo**: font, colour (and, on
    nivo, alignment) are inherited automatically, but these themes give *their
    own* hand-built pages a slightly larger bespoke title via a private class
    (`.axis-page-title` 48px/centred, `.nivo-page-title` 36px/800) that is not
    reachable by any plugin — or by core pages those themes don't override —
    without theme coupling. This is the intended, portable "plugin, not theme"
    behaviour.

## 0.1.9 — 2026-06-15

- **Page title now truly follows the theme.** 0.1.8 styled the title with the
  plugin's own typography, so it didn't match the theme's headings. We're a
  plugin, not a theme — so the title is now a plain semantic `<h1>` with NO
  plugin typography and NO theme-specific classes; the active theme styles it
  like its own content headings. Our only CSS is a `:where()` (0-specificity)
  bottom-margin fallback. (Verified on Axis: the heading inherits the theme's
  font, weight, colour and alignment automatically.)
- **Logos render in their own colours.** Removed the grayscale filter; the
  card's hover shadow/lift is the only effect.
- **Better logo-upload hint:** recommends ~400×150 px and notes that
  consistent, small logos look tidier and load faster (transparent PNG best;
  JPG/PNG/WebP accepted).

## 0.1.8 — 2026-06-15

- **Fix: page title disappeared on most themes.** 0.1.5 had removed the
  plugin's own `<h1>` and relied on the theme to print the title — but only some
  themes (e.g. Atlas, in compact masthead mode) render a visible title from
  `pageTitleTranslated`; the default OJS theme and most others render only a
  screen-reader heading, so the title vanished. The layout now ALWAYS prints its
  own visible `<h1 class="ipm-page-title">` (so the title shows on every theme),
  keeps passing `pageTitleTranslated` (correct `<title>` tag), and a small
  script HIDES our `<h1>` when the theme already shows a matching visible
  heading — yielding exactly one title on every theme (no duplicate on Atlas,
  no missing title on the default theme).

## 0.1.7 — 2026-06-15

- **Theme-agnostic page width.** Instead of the fixed `1280px` from 0.1.6 (which
  only matched the Atlas theme), the layout now measures the *active theme's own*
  content-column width at runtime — the widest constrained wrapper in the
  theme's masthead/footer — and applies it to `.ipm-page` (centred), so the
  showcase lines up with whatever theme is active. The CSS `var(--ipm-max-width,
  1280px)` remains as the pre-JS / no-match fallback, and a debounced resize
  handler keeps it matched. Page LAYOUT follows the theme; the grid/cards
  (content) stay ours. Still no imposed background, card, font or colour.

## 0.1.6 — 2026-06-15

- **Respect the theme's content width.** 0.1.5 dropped the plugin's own
  max-width container (to stop imposing a card), which made the showcase run
  full-bleed and ignore the theme's centred content column. The frontend now
  constrains + centres `.ipm-page` to `var(--ipm-max-width, 1280px)` with a
  responsive side gutter — listening to the theme's width like its own
  header/footer rows do — while still NOT imposing a background, card, font or
  colour. The container can't exceed its parent, so narrower themes cap it.

## 0.1.5 — 2026-06-15

- **Removed the duplicate page heading.** The active theme already renders the
  page title (via `header.tpl`'s `pageTitleTranslated`); the plugin no longer
  adds its own `<h1>`, so the title shows once.
- **Theme-harmonious frontend.** The plugin no longer imposes a page/body
  background, its own "card", font-family, or text colour — the showcase now
  inherits the active theme's page chrome (incl. dark mode). `_layout.tpl` drops
  the forced wrappers + `nivo-*` coupling; captions inherit the theme text
  colour; only the small logo tiles keep a light surface so logos stay legible.
- **Four display templates** (was two): `logos` (logo only), `logo-name`
  (logo + name), `logo-name-desc` (logo + name + description), `logo-desc`
  (logo + description). Rendering is now a single `template-grid.tpl` driven by
  `withName` / `withDesc` flags from `IndexingPageManagerPlugin::templateFlags()`;
  the template selector shows four SVG-preview cards. The legacy `named` value
  normalises to `logo-name-desc` (`normalizeTemplate()`), so existing installs
  don't break. Default is `logo-name-desc`.

## 0.1.4 — 2026-06-15

- **Re-organised the built-in sections + demo data into 4 real categories**
  (replacing the previous 3): `indexing-and-abstracting` "Indexing &
  Abstracting" (8), `discovery-and-search` "Discovery & Search" (7),
  `identifiers-and-registration` "Identifiers & Registration" (5),
  `archiving-and-preservation` "Archiving & Preservation" (4) — 24 demo
  entries (Scopus, WoS, DOAJ, PubMed/MEDLINE, TR Dizin, ERIH PLUS, EBSCO,
  Index Copernicus; Google Scholar, BASE, CORE, OpenAlex, Dimensions, WorldCat,
  EBSCO Discovery Service; Crossref, ORCID, ISSN/ROAD, DataCite, ROR; LOCKSS,
  CLOCKSS, Portico, PKP PN). Built-in slugs + EN/TR labels + locale keys updated.
- **`getBuiltInSections()`** public getter added; `seed-demo.php` now (re)creates
  the built-in sections in a CLI context before seeding indexes.
- **Demo logos:** 11 real (Scopus, WoS/Clarivate, DOAJ, PubMed, TR Dizin, EBSCO,
  EBSCO Discovery Service, Index Copernicus, Google Scholar, BASE, ISSN/ROAD,
  sourced from ojsdergi.com) + 13 clean text placeholders (ERIH PLUS, CORE,
  OpenAlex, Dimensions, WorldCat, Crossref, ORCID, DataCite, ROR, LOCKSS,
  CLOCKSS, Portico, PKP PN) — drop a real PNG into `demo-data/logos/<name>.png`
  to replace any placeholder.
- Upgrade note: changing the built-in slugs means an existing seeded install
  would get the 4 new sections added alongside the old ones; for a clean switch,
  delete the old sections first (the dev/demo journal was wiped + reseeded).

## 0.1.3 — 2026-06-15

- **Fix: admin form "Save" showed raw JSON instead of saving + redirecting.**
  Each form fragment's inline `<script>` called `window.ipmSubmitWithFiles(...)`
  immediately, but that helper is defined later in `_page.tpl` and the `<form>`
  is parsed after the script — so the call no-op'd, the submit was never
  intercepted, and the browser did a normal POST that displayed the
  `{"ok":true,...}` JSON response. Fixed by deferring every form/list bind to
  `DOMContentLoaded` (matching Editorial Board Manager's `$(function(){…})`
  approach). The re-inject-after-validation path still binds because the
  document is already complete then.
- **Demo logos:** replaced the generated text placeholders with real index
  logos for 16 of the 20 demo entries (Scopus, Web of Science/Clarivate, DOAJ,
  PubMed, PubMed Central, Embase, Engineering Village, TR Dizin, ULAKBİM,
  Reaxys, İDEAL, EBSCO, ProQuest, GALE, J-Gate, British Library). CNKI,
  Crossref, Galenos and Manuscript Manager keep text placeholders.
- **Browser-verified on a live OJS 3.3 install** (journal "nivol"): add, edit,
  delete, active-toggle, logo upload (multipart FormData path), section rename,
  section toggle, settings save, template save, and the public `/about/databases`
  page (20 logos, 3 collapsible sections, 4-column grid, Schema.org JSON-LD).

## 0.1.2 — 2026-06-15

- **Critical fix: global class-name collision with OJS core.** The plugin's
  data/DAO classes were named `Section` and `SectionDAO`, identical to OJS core
  (`classes/journal/Section.inc.php`, `SectionDAO.inc.php`). Because the plugin
  loads its `SectionDAO` on every front-end page (via the `TemplateManager::display`
  hooks) and core loads its own `Section`/`SectionDAO` to render articles/issues,
  the two met in one request and PHP fatally aborted with *"Cannot declare class
  Section, because the name is already in use"* — taking down every article and
  the journal homepage.
- Fix: prefixed **all** generically-named plugin classes with `Ipm` to follow
  the Editorial Board Manager discipline (every global class uniquely prefixed)
  and remove all latent collision risk:
  `Section`→`IpmSection`, `SectionDAO`→`IpmSectionDAO`, `Index`→`IpmIndex`,
  `IndexDAO`→`IpmIndexDAO`, `IndexSectionDAO`→`IpmIndexSectionDAO`,
  `IndexLogoStore`→`IpmLogoStore`, `IndexForm`→`IpmIndexForm`,
  `SectionForm`→`IpmSectionForm`, `SettingsForm`→`IpmSettingsForm`,
  `TemplateForm`→`IpmTemplateForm`. Files renamed to match.
- DAORegistry keys were already prefixed (`IpmSectionDAO`, `IpmIndexDAO`,
  `IpmIndexSectionDAO`), so no `getDAO()` call sites changed — only the
  registered class and `import()`/`new` sites. Verified: no plugin class
  collides with OJS core, and the classes load cleanly even when core's
  `Section`/`SectionDAO` are already declared.

## 0.1.1 — 2026-06-15

- Fix: removed the unused `indexSave` / `sectionSave` cases from the plugin's
  legacy `manage()` verb switch — those saves are handled exclusively by the
  URL-based manage handler (multipart fetch), and the controller has no such
  methods, so the dead cases could fatal if ever dispatched. Every other
  `manage()` verb maps 1:1 to a controller method.

## 0.1.0 — 2026-06-15

Initial release. Built on the architecture proven by the Editorial Board
Manager plugin; the gotchas it documented are pre-applied here.

### Features
- Index entries with logo, multilingual name + description, and external URL.
- Three auto-seeded, immutable built-in sections (Abstracting & Indexing,
  Discovery Services, Publishing Systems) plus admin-defined custom sections.
- Backend admin (URL-addressable, inside the OJS sidebar chrome): index list,
  section list, index form with logo upload, section form, template selector,
  settings. Drag-drop ordering via jQuery UI sortable.
- Public page at `/about/<slug>` (default `databases`, configurable) with two
  templates — **logos only** and **logos + names** — and a 3/4/5-column grid
  that collapses to 2 columns < 640px and 1 column < 420px. Sections render as
  collapsible groups (native `<details>`).
- Navigation Menu integration: the page is registered as a selectable
  destination type (`NMI_TYPE_IPM_DATABASES`) via the
  `NavigationMenus::itemTypes` + `NavigationMenus::displaySettings` hooks.
- `{ipm_blocks}` Smarty function for theme homepage embeds (assign the data, or
  render a ready-made self-styled logo strip).
- Schema.org `CollectionPage` + `Organization` (name/url/logo) JSON-LD, with
  `JSON_HEX_*` escaping so entry names can't break out of the `<script>` tag.
- Turkish + English locale files.

### Architecture notes (gotchas pre-applied from Editorial Board Manager)
- **File-upload forms use `ipmSubmitWithFiles` (FormData + fetch), never
  `AjaxFormHandler`** — the latter serialises via jQuery `$.ajax` and drops
  `<input type="file">` from the multipart body. For a uniform response
  contract, *all* admin forms use this helper and the `{ok, redirect, message,
  formHtml}` envelope keyed on `data.ok`.
- **Asset cache-busting:** every CSS/JS URL carries `?v=<plugin version>` read
  from `Plugin::getCurrentVersion()->getVersionString()` (not
  `VersionDAO::getCurrentVersion`, which without `$isPlugin=true` returns the
  OJS app version).
- **Toast colours are set inline** (`#16a34a` / `#dc2626`) so a host theme
  overriding `.ipm-toast-*` can't change them; the class only handles layout.
- **Backend pages** set `$this->_isBackendPage = true` and call
  `setupTemplate()`; templates extend `layouts/backend.tpl` and put content in
  `{block name="page"}`. No inline `<style>` in that block (Vue strips it) —
  all admin styling is in `styles/compiled/admin.css`.
- **Sidebar detection** keys off `$templateMgr->getState('menu')` rather than
  a template-path allowlist, so other plugins' admin pages aren't broken.
- **Plugin typography is parent-scoped** (`.ipm-page h1`, `.ipm-page
  .ipm-section-title`, …) to win the PKP `.pkp_structure_main hN` specificity
  war.
- **Locale components** (`PKP_USER`, `PKP_COMMON`, `APP_COMMON`) are required
  in the frontend handler before render so theme header strings don't fall
  through to `##key##` sentinels.
- **Idempotent migration:** `hasTable` guards, MyISAM→InnoDB conversion, orphan
  cleanup, and try/catch FKs so re-running on every `register()` is safe.
- **PHP 7.4 / 8.x dual-target:** explicit `(string)` casts; no `match`, `?->`,
  named args, `readonly`, enums, or 8.0-only string functions.
- **Smarty brace trap:** template SVG previews use literal coordinates, not
  computed `{$x*76}` expressions, to avoid the PKP Smarty fork's quirks.
