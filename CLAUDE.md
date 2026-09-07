# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project state

**ShakeMetre**, a quantity-survey/metré module originally built in FileMaker (`ShakeMetre.fmp12`), rebuilt here as a Laravel 13 / Inertia / Vue 3 application. It is coupled to **ShakeDesign**, which stays in FileMaker for the whole migration and is reached over the FileMaker Data API.

The migration is well underway — this is **not** a skeleton. Already built and covered by **613 PHP tests and 64 Vitest tests**:

- All 13 domain tables migrated, with UUID primary keys preserved from FileMaker.
- Eloquent models per source table; `MetreLineObserver` / `MetreLineComponentObserver` driving `RecalculateMetreTotals` and `RecalculateMetreLineQuantitiesFromComponents`.
- `ShakeDesignClient` over the Data API: project/company/contact/VAT/user lookups, project search, company list, a company's contacts through JCPYCTC, and offer / supplier-order creation.
- Auth: Breeze password login **plus** a ShakeDesign SSO ticket flow, and a `readonly` role enforced server-side.
- Screens: dashboard project search → project page (métrés + lots) → métré page → the four money views of its lines (`achats-ventes-commandes` and its three narrower cuts, one page cut by a slug); plus the metré-line grid, the METC components panel, the supplier tender comparison, and the reference catalogue on `/references`.
- The reference catalogue is wired end to end: browsed and maintained on its own screen, inserted into a métré as lines, and its three levels drive how a métré's lines are grouped, numbered and subtotalled.
- On the line views: folding of sections and sub-sections, the source's constrain/extend **found set**, search that also matches a line's section (with every hit marked, case- and accent-insensitively), a menu per section and sub-section, bulk lot assignment over a selection, red/blue-italic line states, two free-text tag columns, and a line's supplier order opening in the FileMaker client.
- On the métré page: the lock, the **Fournisseurs frame** (choose one of the project's lots, then run its tender or create its supplier order in ShakeDesign), client-offer creation, the list of a métré's existing offers (each opening in the FileMaker client), and the seven printed documents, previewed and downloaded as PDF.

Run `git log --oneline` first — the commit messages carry the reasoning behind the non-obvious decisions and are the fastest way to understand why something is the way it is.

## FileMaker migration reference

`docs/filemaker-reference/` is the authoritative source-schema documentation. Read it before generating migrations, models, or business logic for any ShakeMetre domain:

- `ShakeMetre_Analyse_et_Plan_Migration_Laravel.md` — architecture analysis and phased plan (French): the 17 source tables, FileMaker naming conventions (`zkp`, `zkf_`, `zg_`, `_Stored`, `_ae`, …), the target Eloquent mapping, the UUID strategy, the `_Stored` → Observer/queued-job pattern.
- `ShakeMetre_data_dictionary.json` — all 17 tables, every field with its real calculation formula, 91 relationships, table occurrences, 274 scripts, 128 layouts, 92 custom functions, value lists.
- `ShakeDesign_boundary_tables.json` — the 7 ShakeDesign tables ShakeMetre is coupled to.
- `API_MIGRATION_layouts.md` — **the data migration's shopping list**: the seven layouts to create in the hosted ShakeMetre and the exact field to place on each, one per table to import. Written from the dictionary (a field name's case matters) and cross-checked against this database's columns. **The layouts now exist and carry every field of their table, not just these lists** — so this file is a record of what the import needs, not of what is there. See "Importing the live data" at the bottom of this file.

**Critical constraint:** `OFF_Offers.zkf_MET` and `SOR_SupplierOrders.zkf_MET` in ShakeDesign store **hard references** to `MET_Metre.zkp`. `MET_Metre`, `METL_MetreLines`, `LOT_Lot`, `REF_Reference` and `METC_MetreLineComponent` must keep their existing UUIDs verbatim, or those foreign keys break silently. `MAT`, `CAT`, `CATS`, `CART`, `JCARTMAT` and `TAG` are on numeric legacy keys and free to be redesigned. `REFS` and `REFSL` are **not**, whatever the export says: their live records carry UUIDs like every other table (checked on the server — see below), and this project keeps them.

Do not port: `ZZZ_Template`, `ZSET_Settings`/`ZVAR_Variables`/`ZSTRI_Strings` (use Laravel config + a `translations` table), the `BrowserNav` module, anything under `OLD/`/`TEMP/`/`__SAVE_AR_*`/`__OLD`, the `DEV/Raw`/`DEV/Blank` layouts. Most of the 92 custom functions need no equivalent — only the FR/date and privilege logic.

### Reading the old application directly

The FileMaker ShakeMetre being replaced is **hosted on a server and readable over the Data API**
with a dedicated account. Credentials live in `.env` under `SHAKEMETRE_FM_*` and are exposed as
`config('services.shakemetre_filemaker')`.

**Never use it from `app/`.** ShakeMetre becomes purely web: its data lives in this project's
database, and nothing the application does at runtime may depend on the thing it replaces. The
access exists for two purposes and no others — establishing how the FileMaker application behaves,
so the web version reproduces it instead of guessing, and **importing the live data once**, which
is the current thread. Throwaway scripts, or a console command that exists for the import and goes
with it. Nothing under `app/` that a request can reach. Both the config block and the environment
variables get deleted when the migration is done, which is also why they are absent from
`.env.example`.

What it answers, and what it does not:

- **Data** — the Data API. Any layout is addressable with a full-access account; `DEV/Raw/*_raw`
  expose every field of a table, so no API layout has to be created to read one. This is the only
  way to know what the data actually looks like, and it has already contradicted the export twice
  (see the two entries below).
- **Behaviour** — the XML export's script bodies, not the API, which cannot read a script.
- **Intent** — neither. Ask.

The local `.fmp12` copies (`~/dev/ShakeWeb/ShakeFilemaker/`) drift from the hosted one and are not
the reference for data; the XML export is dated, so say which date a script body comes from.

### What the export does and does not tell you

Hard-won and worth knowing before trusting it:

- **Script bodies ARE available** — an earlier version of this file said the opposite, and that error cost several formulas that had to be guessed instead of read. `Has_DDR_INFO="False"` refers to DDR-specific info, not to script steps: `~/dev/filemaker/ShakeMetre.xml` carries all 274 script bodies (16 633 steps) under `Structure/AddAction/StepsForScripts`, where each `<Script>` names itself in a child `<ScriptReference>` and holds its steps in `<ObjectList>`. The `ScriptCatalog` entries near the top of the file are name-only, which is what misled the extraction behind `ShakeMetre_data_dictionary.json`. A step's target field is `<FieldReference>`, its calculation is `<Parameter type="Calculation">`. **Read the script before guessing a rule.**
- **`~/dev/filemaker/ShakeDesign.xml` exists too, same export date, and carries ShakeDesign's script bodies the same way.** 73 MB against ShakeMetre's 22. Nothing in this file said so, and the SOR link sat in the open items for want of a script name that was readable all along. When a question crosses the boundary — what a ShakeDesign script expects, what a `Perform Script [ShakeDesign :: …]` lands on — read it there rather than declaring the answer unknowable. Beware the extraction trap: `str.find()` on a `<ScriptReference>` finds the *callers* first, since a script that is called appears in every caller's steps. Match on the `<Script>` block whose own leading `<ScriptReference>` carries the id.
- **What neither export records: which privilege sets hold which extended privileges.** Both list the extended privileges themselves (`fmurlscript`, `fmrest`, …) and both list the privilege sets, but no element ties the two together. So "is this account allowed to X" is not an export question — it is checked in FileMaker Pro, under Fichier → Gérer → Sécurité.
- **Summary fields carry `calc: null`** — no aggregation operator, no source field. Their meaning is unverified unless separately confirmed.
- The 250-character calc truncation **has been fixed** by a re-export (commit `3fb3a66`). Older comments referring to truncated formulas are historical.
- `isTenderLine_b`, `Omit_b` and `TENDER_Id` are referenced by no formula at all.
- **REFS and REFSL zkp are UUIDs, not numbers.** The export types them `Number`; the live records are UUIDs at all three levels, so the migration plan's "numeric legacy keys" no longer holds for them. `REF_Reference.Code`, on the other hand, really is text in places — the first section's code is the string `"00"`, which this project's `integer` column flattens to 0.
- **A métré line does not point at the reference catalogue.** `zkf_REF` / `zkf_REFS` / `zkf_REFSL` are EMPTY on every line of the live file (57 816 as of 05/08/2026); the section lives on the line as copied values. See the settled decision below.
- **The line's title is `REFSL_Title`, not `Description`.** Filled on 57 079 lines against 29 for `Description`, which holds a free note when it is used at all ("1374,07 € selon offre Collignon"). Both web grids currently edit `description` as the line title — see the settled decision below.
- **The export lists what the tables contain. It does not tell you what the API layouts expose.** Those are two different things, and the difference has caused real bugs. Probe the live layout metadata (`GET /fmi/data/vLatest/databases/{db}/layouts/{layout}`) before relying on a field being reachable.

### API layouts, as confirmed against the live server

| Layout | Fields exposed |
|---|---|
| `API_PRJ` | `zkp`, `Name`, `Number`, `Status`, `zkf_CPY`, `zkf_CTC` — **no `isActive_b`** |
| `API_CPY` | `zkp`, `Name`, `VAT`, `Phone1`, `LanguageMain`, billing address, `isSupplier_b`, `isActive_b` |
| `API_CTC` | `zkp`, `NameFirst`, `NameLast` — no combined name field |
| `API_JCPYCTC` | `zkp`, `zkf_CPY`, `zkf_CTC`, `Role` |
| `API_ZUSR` | `zkp`, `AccountName`, `NameFirst`, `NameLast`, `Mail_1`, `PrivilegeSet`, `isActiveAccount_b`, `isActiveUser_b` |

## Commands

```bash
composer install && npm install

php artisan migrate
composer run dev                 # server + queue listener + pail + vite, concurrently
npm run build                    # production frontend build

php artisan test                 # full PHP suite
php artisan test --filter=Name    # one test or class
npm test                         # Vitest (composables)
vendor/bin/pint                  # format PHP
```

Local dev DB is **MySQL** (`shakemetre`). Tests run against in-memory SQLite (`phpunit.xml`) regardless of `.env`. That difference bites: decimal columns come back as zero-padded strings on MySQL and as floats on SQLite, so compare decimals with `assertEquals`, not `assertSame`. `LEAST` does not exist on SQLite (multi-arg `MIN` is the scalar form) — see `Lot::scalarMinFunction()`.

## Architecture and conventions

### Write surfaces
One endpoint and one whitelist per record type, shared by every screen that edits it — so two views cannot disagree about what is writable:

- `PATCH /api/metre-lines/{id}` + `UpdateMetreLineRequest` — used by both the Phase-6 grid and the Achats/Ventes/Commandes view.
- `PATCH /api/lots/{id}` + `UpdateLotRequest` — used by the tender comparison **and** the lot manager.
- `PATCH /api/metres/{id}` + `UpdateMetreRequest`.

Every request class has two layers: rules, **plus** outright rejection of any key outside `EDITABLE`, with a message saying why. A `_stored` column or a computed accessor must fail loudly, never be silently dropped.

`MetreLineGridResource` is the **shared PATCH response**. Views replace their whole `computed` block from it, so a computed value any view displays has to be in there even if the other grid does not show it. Omitting one blanked a column on every save.

### Routes
JSON endpoints live under an `/api` URI prefix but stay in `routes/web.php` and the `web` middleware group — they are called by a logged-in human and need the session and CSRF. `routes/api.php` is stateless and reserved for the machine-to-machine ShakeDesign integration. The prefix also matters because validation failures render as JSON only for `api/*` (see `bootstrap/app.php`, `shouldRenderJsonWhen`).

### Authorisation
`role.write` middleware (`EnsureUserCanWrite`) is registered per write route, deliberately not inferred from the HTTP verb — a new POST that forgets it is visible in `route:list`. Hiding a control in the UI is never the guarantee. A readonly account must also be **told** it is readonly: an unexplained disabled field reads as a broken screen.

`UserRole::fromPrivilegeSet()` maps ShakeDesign privilege sets and defaults to the least privilege. Recognised: `[Full Access]`, `Admin`, `Administrateur`, `Manager` (→ `user`), `User`, `[Read-Only Access]`. Anything unrecognised → `readonly` **and a logged warning naming the value**. Never add a mapping by guessing a spelling — that grants write access to a value that may not exist.

### Frontend
Inertia v2 + Vue 3 + Tailwind v4. Editable grids use `useDebouncedRowSave` (batch per row, 500 ms, optimistic with rollback).

**A page holding a local working copy of its props must re-seed it when the record changes:**

```js
watch(() => props.record.id, () => { local.value = seed(props.record); });
```

Inertia can reuse a component across a navigation between two records of the same type. Without this the page shows the previous record's values while writes are keyed on the new id — it silently writes stale data onto the wrong record. Bitten once already (commit `22cd60a`).

**`<component>` takes its `is` from an explicit binding, never from a spread.** `<component v-bind="{ is: 'a', href }">` compiles to a plain element and renders a `<span>`-ish nothing: Vue's compiler looks for `is` *on the element* to emit `resolveDynamicComponent`, and a `v-bind` object is only applied at runtime, too late. It fails silently — no warning, no console error, just the wrong tag. So write `:is="x.is"` **and** `v-bind="x"`, accepting the double call. Cost one browser round-trip on the supplier-order link, which rendered as unclickable text with every test still green.

`Modal.vue` has two fixes that must not regress (commit `544de5a`): the content box carries `relative z-10`, without which the `fixed` backdrop stacks above it and swallows every click; and Escape is handled through the dialog's own `cancel` event, not a document listener, so nested modals close one at a time.

### Direction artistique

`resources/css/app.css` **is** the design system: read its header before styling anything. It carries the palette taken from shakedesign.be (declared there in `@property`), the role each colour plays, and a closed vocabulary of component classes — `.surface` / `.surface-head`, `.eyebrow` / `.field-label`, `.btn` + variants, `.badge-*`, `.data-table`, `.cell-input` (+ `.euro-suffix`), `.popover*`, `.banner*` (`-danger`, `-warning`), `.code-chip`, `.readonly-value`, `.num`, `.search-hit` (what a search found in a label) and `.line-tone-danger` / `.line-tone-info` (a métré line's state).

- **Encre `sand-950` (#35160e), fond `sand-100`, accent lime `accent-400` (#c9f862).** The lime is never a text colour on light (1.23:1) — it marks the active state and the selection. Focus rings are ink. Labels are `sand-600` or darker (`sand-500` is 4.13:1 and decorative only).
- **The modal veil is `scrim` (#1c1817), not `sand-950`.** The brand ink is a 59 %-saturated brown; spread as a translucent sheet over a whole page it stops reading as warm black and reads as a reddish wash. `scrim` keeps the hue and drops the saturation to 10 %. It is a veil colour only — never text, never a surface.
- **The money trio is a domain colour code, the same on every screen:** achats → `clay`, vendu client → `olive`, commande → `mallow`. The métré page's stat tiles and the grids' column blocks must keep agreeing.
- **The Tailwind ramps are remapped to the DA** (`gray`/`zinc`/`indigo`/`red`/`green`/`blue`/`emerald`/`amber`/`yellow` → sand / accent / clay / olive / mallow / semantics). A screen written by reflex in `text-gray-500` therefore stays on brand. In new code use the DA names: they say the role.
- **The shell is `AppTopBar.vue`** — ink bar, brand, breadcrumb, account menu, and the global `Lecture seule` badge. Full-height screens (the two grids, the tender comparison) mount it themselves rather than going through `AuthenticatedLayout`. A page must not re-render the readonly badge: it is a property of the account. A page badge is only for a reason of its own, like a locked métré.
- **Aspekta**, self-hosted from `resources/fonts/` (SIL OFL 1.1). It lives under `resources/` and is referenced relatively **on purpose**: `laravel-vite-plugin` sets `publicDir: false`, so an absolute `url('/fonts/…')` resolves against the Vite dev-server origin and 404s in `composer run dev`. No third-party font domain is required any more.

Two cascade traps, both found in a browser and both silent:

- **In Tailwind v4 the layer order (`theme, base, components, utilities`) beats specificity.** A `.cell-input:focus` rule in `components` loses to a plain `bg-clay-50/70` utility. That is why the grid cells carry `focus:bg-white` in the template: only a utility beats a utility, and Tailwind sorts variants last.
- **The z-index stack of a grid is explicit, and each level only beats the one below.** 10 for a focused `.cell-input` (which repaints its background white), 15 for the `.euro-suffix` over it, 20 for the sticky total row, 30 for popovers. Getting it wrong is silent until you scroll: with the symbol at 20 and the total row at 10, the lines' « € » drew on top of the total row.
- **A disabled *and* checked checkbox must stay visibly checked.** The forms plugin paints the tick with `background-color: currentColor` on `:checked`; a later `:disabled { background-color: … }` at equal specificity erased it, so on a readonly account `Accepté` read as "not accepted" and the grid's Est./Opt. columns reported the opposite of the data. The rule is split on `:not(:checked)` / `:checked`.
- **A focused `input[type=number]` eats the mouse wheel, and it eats it onto money.** Chrome and Firefox spend a wheel tick over a focused number field incrementing it instead of scrolling, so the list appears frozen while the figure under the cursor changes and the debounced save posts it. Measured on the Achats/Ventes/Commandes view: one tick took a quantity 45 → 44 with a `PATCH {"quantity":44}`; twelve trackpad-sized deltas took another from 1 to -10. `installNumberInputWheelGuard()` in `app.js` is the cure, installed once on the document because the same field type carries money on five screens. Reported as "the grid won't scroll when my cursor is over a Qté cell", which is why the symptom is worth recognising — the visible half is the harmless half.
  - **The first version blurred the field, and that fixed only the invisible half.** Blurring does stop the increment — only the *focused* field increments — but the scroll never came back: the list stayed frozen under the cursor, exactly as before the guard existed, and the user reported it again. A wheel gesture **latches**: the browser picks what consumes the gesture on its first event and keeps it there, so once the focused input has taken that tick, the rest of the gesture goes on being handed to a field that no longer does anything with it — and on a trackpad the gesture does not end while you keep scrolling. No blur timing fixes that; the choice is already made when the handler runs.
  - **So the guard cancels the event and scrolls the container itself.** `preventDefault()` — which an earlier version of this note called the wrong cure, on the grounds that it would stop the scroll too — is right *provided the scroll is then done by hand*, and by hand is one addition: the event's deltas already carry the trackpad's momentum, being exactly what the browser would have applied. `scrollTargetFor()` walks up to the nearest ancestor that scrolls on the axis asked for **and still has room that way**, which is what reproduces the browser's chaining at the ends of a list; `null` means the window, the case of the métré page and the catalogue, whose number fields sit in a document that scrolls as a whole. `wheelPixels()` converts line and page deltas, Firefox reporting a mouse tick in lines.
  - **The listener follows the pointer, not the focus:** armed on `pointerover`, removed on `pointerout`, on the field itself. A wheel event is delivered to the element *under the cursor*, so the hovered field is exactly the one that can spend it on the value, focused or not — which makes the guard independent of each browser's rule about when a number field takes the wheel (measured: Chromium increments only the focused field, current Firefox increments neither, and an older Firefox incremented on hover alone). It was armed on `focusin`/`focusout` first, which was right for Chromium only. A non-passive `wheel` listener also takes scrolling off the compositor's fast path for everything it covers, and the heaviest screen here is a several-hundred-line grid, so it covers one hovered cell at a time and nothing else. Consequence, and an improvement on the blur: **the cell keeps its focus** while you scroll, so scrolling no longer flushes the row's pending edit nor drops the keyboard out of the grid.
  - **Verified in a real Chromium and a real Firefox** (`playwright`, static pages mirroring the grid's `overflow-auto` scroller *with the built `app.css`*, so `.cell-input` is the real cell): value unchanged and the list scrolling the full native distance in all four combinations of browser × focused/unfocused, the window scrolled once the container is at its end, horizontal deltas honoured. Two measurements worth keeping: without the guard, Chromium's focused cell took 45 → 42 over three ticks and scrolled nothing, while its **unfocused** cell scrolled normally and changed nothing — so "the wheel edits my figure" is a focused-cell symptom in Chrome. And `.cell-input` is **not** itself a scroll box (`overflow: clip`, `scrollHeight === clientHeight`), which rules out the field's own content being what moves.
  - What headless **cannot** reproduce is the latched trackpad gesture — CDP wheel events arrive phase-less, and the blur guard therefore looks fine there. That is why the latching diagnosis rests on the report from the real browser, and why the fix deliberately does not depend on the browser scrolling anything over a number field.

## Settled decisions — do not "fix" these

Each of these looks like an inconsistency and is not. Ask before changing any of them.

- **The application can be mounted under a URL prefix, and only the JavaScript needed teaching.** It is served from `https://fms99.mycloud.fm/metre` in production, the domain root being FileMaker Server's. PHP needed **no change**: Symfony derives the base path from `SCRIPT_NAME`, so `url()`, `route()`, `asset()`, `@vite` and every redirect carry the prefix on their own — verified live under an emulated subpath (assets 200, the auth redirect's `Location` prefixed, Ziggy's `url` prefixed, Inertia's `page.url` = `/metre/login`). The browser was the whole problem: 42 internal URLs were written root-absolute in the frontend, and a `fetch('/api/metre-lines/…')` under a prefix leaves for the domain root, i.e. the neighbouring application, which answers 404 — **a 404 nobody sees**, since the visible effect is only that a line stops being saved. `resources/js/basePath.js` is the one place that knows the prefix: it reads it from a `<meta name="base-path">` written by `app.blade.php` from `request()->getBaseUrl()` (Laravel's own source, rather than one more `.env` variable to keep in step), and `u()` applies it. `joinBase()` is **idempotent** and that is load-bearing, not defensive: URLs handed back by the server already carry the prefix (Ziggy, `page.url`), and prefixing those again would give `/metre/metre/…`. It also leaves alone anything that is not an internal path — an absolute URL, an `fmp://` link, a relative path. Two edits absorbed 31 of the 42 sites: the prefix is applied inside `apiRequest()` and inside `useDebouncedRowSave`'s `patch()`, so every `endpoint:` prop and every `request('/api/…')` call site stays untouched. At the domain root the meta is empty and `u()` returns its argument — so the same build serves both, and nothing has to be configured for development.
  - **`grep` skips `Pages/Metres/Show.vue`, and that cost an incomplete inventory.** The file carries five deliberate NUL bytes — separators in composite group keys, `` `${ref_code}\0${ref_title}` `` — so `file` reports "data" and grep treats it as binary, matching nothing and saying nothing. The first sweep therefore missed that page's ten navigation URLs entirely. Use `grep -a` on this tree.
- **"Commandes" means a different column on different screens.** Project page: `Tot_Sum_TotalSales_Stored`. Métré page: `Tot_Sum_TotalOrdered_Stored`. Both confirmed by the user, both commented in place.
- **"Ratio" is two different divisions, likewise.** Project page (column *and* total row): **Commandes ÷ Travaux**, i.e. `Tot_Sum_TotalSales_Stored / Tot_Sum_TotalOrdered_Stored` — read off the row's own two cells so the figure is verifiable by eye, not off `Tot_Ratio_TotalFees_Stored`, which holds the same thing but only as of the last recalculation. Métré page ("Ratio réel") and the ShakeDesign portal replica: `Metre::ratio()` = `Ratio_c` = vendu ÷ acheté. Both confirmed by the user. `Metre::ratioFromSums()` is the shared guard — empty or zero denominator yields no ratio, never 0 and never an error.
- **Achats and Vendu client share one quantity.** `PriceTotalBuy` and `PriceTotalSales` both multiply by `METL::Quantity` in the source; only the order total has its own (`QuantityOrdered`). The UI binds both inputs to `quantity` on purpose.
- **A métré's ID is `ind_project`, not its UUID.** `MET_Metre::IndProject`: the métré's number *within its project*, from 1 up, restarting at 1 for the next project. The source field is a plain Number with no calculation and no formula referencing it, so the rule comes from the user, not the export. The UUID stays in the payload because the links are keyed on it, and is never displayed.
- **A métré ID is never handed out twice, even after the métré is deleted.** People refer to a métré by it, so a spent number is spent: 1, 2, 3 minus the third means the next one is 4. That cannot be derived from the surviving rows — deleting the highest lowers their maximum — so `metre_number_sequences` keeps a per-project high-water mark that only moves up. `Metre::nextIndProject()` takes the higher of that mark and the current maximum (the mark misses rows inserted with an explicit number by an import or a test; the maximum alone is the reuse bug), inside a transaction, with a `(project_id, ind_project)` unique index as the backstop. A duplicate takes the next number rather than the original's.
- **The four `Total_*_METL_Stored` columns are the ungated set, and are written on a stated assumption.** `Sales` / `Purchase` are the only inputs of `Ratio_c`, the export gives none of the four a formula, and nothing wrote them — so the "Ratio réel" read empty and the métré page's four tiles were empty too. `RecalculateMetreTotals` fills all four from the same `PriceTotal*_noOptions_c` sums as every other total (options excluded), **ungated**, unlike their `Tot_Sum_*` counterparts. Flag the assumption if the FileMaker script meant something else.
- **Which of the two sets a screen reads is a decision, not an accident.** Métré page (the four tiles) and `Ratio_c`: the **ungated** `Total_*_METL_Stored` — it is the screen a métré is worked on, and it showed four empty tiles until *Accepté* and *Site* were both ticked, which reads as a broken page rather than as "nothing is committed yet". Project page roll-up and the ShakeDesign portal replica: the **gated** `Tot_Sum_*`, which answer "how much is agreed, how much is on site". Same sums, two questions.
- **`Date_Agreement` follows `isAccepted_b`.** Filled with today's date when the box is ticked, emptied when it is unticked, and **no memory**: re-ticking stamps today rather than restoring the old date, which described an agreement that was taken back. The field stays editable (a métré accepted last Tuesday and recorded today has to be correctable) and the stamp only fires on the transition. **Which "today"**: the page sends the *browser's* civil date (`localToday()`, never `toISOString()`, which is UTC and reads as yesterday just after midnight) — only the machine of the person ticking knows the day they are living in. `MetreController::stampAgreementDate()` keeps the same rule as a fallback for a caller that sends no date, from the server clock (`config('app.timezone')`, `APP_TIMEZONE`); a date in the request always wins.
- **A line's state is said by the colour of its text: red when the price is estimated, blue and italic when it is an option.** The rule comes from the user; the conditional formatting it transposes is **not** in the export (`ShakeMetre.xml` carries no `ConditionalFormatting` at all — the DDR detail is absent), so the tints are the DA's `danger-700` / `info-700`. Only one colour is ever rendered: they are two utilities of equal specificity, so the order in the `class` attribute decides nothing and the compiled CSS order would decide by chance — `toneColour()` picks, and **estimated wins** (an unreliable figure outranks a status), while an option line stays italic either way. A cell that sets its own ink needs `toneInk()`; `.cell-input` sets its ink in the `components` layer, so `.line-tone-*` overrides it with two classes against one. The option line's own total keeps its separate grey italic — that says "excluded from every subtotal", which is a different fact.
- **Locking a métré has its own endpoint, and that is not incidental.** `MET_LockUnlock` is one line — `Set Field [ MET::isLocked_b ; GetAsBoolean ( Abs ( isLocked_b - 1 ) ) ]` — with no confirmation and no privilege check. Two deliberate differences: the web sends the **target state** (`POST /api/metres/{metre}/lock {locked}`) rather than toggling blind, so two tabs on one métré cannot pass the lock back and forth; and it stays **out of `UpdateMetreRequest::EDITABLE`**, because any "this métré is locked" guard later placed on the métré update would otherwise make unlocking impossible — the lock would close on its own key. A test pins that. What the lock gates is line writes (423), which the source confirms: `METL_Tag_AssignToSelection` opens with `If [ MET::isLocked_b ]` → "Métré verrouillé". The métré's own header fields stay editable, server and client alike.
- **The Tags switch shows two free-text columns in place of Lot, and a tag exists as soon as someone types it.** `TAG_Tags`, the per-métré tag table still in the export, is **abandoned** (confirmed by the user): only `METL::TAG1` / `TAG2` remain, two plain text fields with no list to respect. What stands in for a list is the set of values already used *in that métré*, offered per field — `TAG1` and `TAG2` have **separate** vocabularies, they are two distinct qualifications rather than two slots of one. Hence `<input list>` + one `<datalist>` per field: the browser suggests without forbidding, which is exactly the rule. Suggestions are computed from the local rows, so a tag typed on one line is offered to the next without a reload. No sort by `MET::Sort_OrderTags` for now, by decision — `TAG_Choice1_cU`/`TAG_Choice2_cU` stay unimplemented. Bulk tagging (`METL_Tag_AssignToSelection`) is not built either; the lot's bulk endpoint is the pattern to copy when it is wanted.
  - **The search covers all four columns of the switch, whichever side is on screen** — `lot_name`, `tag1`, `tag2` are in `SEARCHED_FIELDS` together. "Which lines carry that tag" is a question one asks without having flipped the display first, and a search whose result depended on the current view would answer the same word two different ways.
  - **A hit in the hidden column is announced on the button that would show it.** The tag fields are marked like the title (the overlay drawn over the field, hidden on focus), but a tag hit while the Lot column is displayed can be marked nowhere — so the *other* side of the Lots/Tags switch carries a lime count of the displayed lines whose hidden column matches, and its tooltip says so. Chosen with the user over a per-row marker: one landmark instead of a dot repeated on every line, and it is the control that fixes the problem it reports. The count is of "lines whose hidden column matches", not "lines found only that way" — the sentence stays true either way, and it needs no reasoning about which other fields happen to be markable.
- **The métré page's `Fournisseur` card is the lots-with-their-supplier breakdown, and its two halves answer different questions.** `Metre::lotBreakdown()`, transposed from the `Prj_LOT__` portal of `MET_Form` in the live file. Three métré-level figures — `Tot_LotAssignedBuy_cU` = `Sum ( METL::LOT_AmountAssignedBuy )` where the per-line field is `Case ( not IsEmpty ( zkf_LOT ) ; PriceTotalBuy_noOptions_c ; 0 )`, its `NotAssigned` mirror on `IsEmpty ( zkf_LOT )`, and the ordered equivalent — then one row per lot carrying an amount in this métré, with its code, its name in the métré's language, its supplier company (`lots.cpy_name_ae`, already denormalised locally, so no ShakeDesign call) and its purchase/ordered sums. **The gate is "the line has a lot", not "its lot has a supplier company"** — that second gate belongs to `GainOnPurchases_c`, which answers something else, so a lot with no supplier still counts as assigned here. Invariant worth keeping: assigned + unassigned = the métré's `Total_Purchase_METL_Stored`, verified on the 357-line demo. Lots of the project with no line in this métré are omitted (the card is a third of the width; the project page lists them all). The three `PriceTotal*_noOptions_c` SQL fragments now live as constants on `MetreLine`, shared with `RecalculateMetreTotals` — two copies of a money expression would drift.
- **The `Fournisseurs` frame is a chooser, not a dashboard — and the card was wrong about that twice.** First it was a passive list; then it became a chooser with the wrong furniture. The real frame, read off `MET_Form` object by object with its hide conditions:
  - **Two read-only fields and one button**, always. `LOT_Name_Chosen_g` and `LOT_CPYName_Chosen_g` at y606, and the button that opens the lot **popover** — the list is long on a real project and does not belong on the card permanently.
  - **A clear button** appearing beside them once a lot is chosen (`HIDE: IsEmpty ( zkg_LOT_Chosen )`).
  - **The lot's three totals** — achats, ventes, commandé — at y687, same hide condition, with a button to their right that opens the lines view **restricted to that lot** (`MET_LOT_ShowOrder_METL`, `?lot=` here, which the page announces and can leave).
  - **The two actions below, which appear rather than sit greyed out.** That was the visible mistake: showing six dead controls reads as a broken screen, and the source simply does not draw them until there is something to act on.
  Selection runs `MET_LOT_Set`, which writes `MET::zkg_LOT_Chosen` (and `zkg_MET_Chosen`). `zkg_` is a **global** field, so the choice is session state: it lives in the page here (`chosenLotId`), is never persisted, and two people on one métré cannot steal each other's selection. Clicking the chosen lot again clears it, which the source does not offer — but nothing there cancels a choice either, and a selection you cannot leave reads as a stuck screen.
  - **« Appel d'offres » is `METT_LOT_ShowLOT`**: it finds `zkf_LOT = lot AND zkf_MET = métré` and lands on the comparison screen. So the web passes `?metre=`, and **the restriction reaches the scores**, not just the list: FileMaker's sub-summaries total the *found set*, so filtering the lines while the scores still covered the whole lot would be a screen contradicting itself. `Lot::restrictToMetre()` carries it — set on the model rather than threaded through the six score methods, which call one another (`finalScore` → `priceScore` → `bestPricePercentage` → `sum`) and would otherwise need the argument passed correctly at every hop. Without the parameter the screen stays the lot's, which is what the project page links to.
  - **« Commande fournisseur » is two steps, and the first one is not politeness.** `Action = "Validation"` shows the lines that would go — and `METL_SupplierOrderValidation` is **grouped by REF_Code then REFS_Title with a subtotal each, exactly like the Achats/Ventes/Commandes view, and its unit, ordered quantity, price and `isOption_b` are in edit mode**. It is where a purchase is adjusted before being committed, and ticking `isOption_b` is *how* the source drops a line from the order — which is the third argument for excluding options from the set. Edits go through `PATCH /api/metre-lines/{id}`, the shared write surface, and the subtotals recompute in the page as you type; `Action = "SOR_Creation"` writes. The write goes to another application, on money, and **cannot be undone from here** — the ShakeDesign API account has no delete privilege at all (found the hard way: a test record had to be removed with the full-access account). The four refusals of the source are returned together rather than as successive dialogs: métré accepted, lot has a supplier, lines exist, no line already on an order.
  - **ShakeDesign receives ONE line carrying the total**, not one per métré line: `SOR_NewFromMetre` calls `SOL_New` with `Qty = 1`, `priceUnit = zsm_SumTotalOrdered_noOptions`, `vatRate = 0` and `Title = IndProject & " - " & Name` — the same shape as the client offer. The detail stays in the métré, which is what the supplier-budget PDF is for. The company and contact come from the **lot**, not the project.
  - **The one deliberate divergence: options are excluded from the ordered set.** The source's `If [ $Action = "Order" ] → isOption_b = 0` sits inside the `"Validation"` branch and therefore never fires — a leftover; the same block in `MET_LOT_ShowOrder_METL` is reachable and does exclude them. Since the amount sent is unambiguously `_noOptions`, including option lines would show lines weighing nothing on the check screen and stamp an order reference onto an option line, locking it. Argued in `Metre::supplierOrderLines()`; it is one `where` to remove if the letter is preferred to the sense.
  - **Numbering runs `ZSET_Numbering`, and it is the only FileMaker script this project executes.** Proven on the copy: a record created through the Data API comes back with `Number` empty, because the number is assigned by that script and nothing else. It matters that the *script* does it — it opens the counter with `Open Record/Request` before incrementing, and that lock is exactly what stops a web-created order and a FileMaker-created one from colliding. Reimplementing the sequence in PHP would buy duplicate order numbers. The script already carries "run with full access", so the API account only needs execute rights on it; without them FileMaker answers code 104 "script is missing", indistinguishable from an absent script, so `nextNumber()` logs and returns null and the order is created **unnumbered** rather than refused. Order of operations follows the source: create, number, write the number, then stamp the lines.
  - **Not reproduced, by decision:** the supplier-budget PDF filed in ShakeDesign as the order's contractual document (`DOC_New` then `SOR_DOC_SetAsContractual`). Both are ShakeDesign scripts and filing a document means uploading a container, which this project cannot do yet.
- **A lot is named in the métré's language on the line views, and in no particular language anywhere else.** `LOT_Lot::TitleFull` is `Code & " - " & Upper ( Case ( lot_MET::Language = "FR" and not IsEmpty ( Title_FR ) ; Title_FR ; = "NL" and not IsEmpty ( Title_NL ) ; Title_NL ; Title_EN ) )`, so the language is the *document's* and **English is the default branch** — the opposite of the catalogue, whose fallback is French (see `HasLocalisedTitle`, whose fallback order is now overridable for exactly this reason). `Lot::displayTitle()` implements it, `title_custom` still winning as it did before. The project page sidebar and the tender comparison are **not** localised: they are project-level, a project holds métrés of different languages, and there is no document to take the language from — inventing one would be worse than a stable title. So the same lot can read "Dak" on a Dutch métré's lines and its French title on the project page. Ask before changing that; the source's own formula went through the same hesitation (a commented-out `prj_lot_MET__::Language` branch). Note also that the source's label is `12 - TOITURE`, code-prefixed and uppercased; the web shows the code as a chip beside the name, in its own case.
- **A client offer carries one line per VAT rate, not one per métré line.** `MET_OFF_CreateClientOffer` groups the métré's lines by `METL::zkf_VAT_ae` and posts, per group, `Quantity = 1`, `Price = zsm_SumTotalSales_noOptions` **for that group** (options excluded) and `Title = MET::IndProject & " - " & MET::Name`. `Metre::offerLinesByVat()` does the same, grouping on the **key** (`vat_value_id`) rather than the rate — two VAT values could share a rate and are two groups to ShakeDesign — and taking the rate from `vat_ae`, the auto-enter that follows the key (`GetAsNumber ( ZVAL::Value )`), so no remote resolution is needed. Lines with no VAT form a last group and are sent **without** `VATRate`: the source passes an empty `vatZkf`, and inventing a rate would be worse than letting ShakeDesign decide. Three deliberate differences from the source: one confirmation dialog instead of its validation screen plus dialog (decided with the user); records written straight to `API_OFF` / `API_OFL` through `ShakeDesignClient` rather than by triggering the `OFF_New` script, which is the path already chosen and tested for supplier orders — **its stated reason no longer holds** (`ShakeDesign.xml` gives us the script bodies, and the Data API *can* run a script, `/layouts/{layout}/script/{name}`), what remains is that direct writes are proven here; and the PDF plus its `DOC_New` filing are **not** reproduced, documents being on hold. Consequence, and it is a real gap: **an offer created from the web carries no number**, `OFF_New` being what calls `ZSET_Numbering`. The supplier order now closes that hole for `SOR`; the same one-line call with `<Type>OFF</Type>` would close it here. Totals are recalculated inline before sending — the amount offered to a client must be the one on screen. `zkf_MET` in the header is the hard reference this project must preserve.
- **The métré page reads its offers from ShakeDesign, and degrades rather than failing.** `listOffersForMetre()` finds `API_OFF` on `zkf_MET` and reads **`OFL_Total_PriceNoTax_cU`** — the unstored twin, because FileMaker evaluates it on read and it is therefore always current, where `_Stored` depends on a recalculation we know nothing about from here; and it is the amount **excluding VAT**, the one comparable to the métré's own sales total, which carries no VAT either. A métré with no offer is FileMaker error 401, swallowed into an empty list. A genuine failure gives `offers = null`, which the card reads as "could not be read" — not the same thing as "none", and the page still renders. Each listed offer opens in the FileMaker client; see the `fmp://` decision below.
- **A supplier order and a client offer open in the FileMaker client through an `fmp://` link, and the target is read off the export, not chosen.** The three unknowns this item waited on are answered, all three in `~/dev/filemaker/`. ShakeMetre's `SOR_GoTo`: `xml2var ( Get ( ScriptParameter ) )` → `$SOR`, exit `-1` if empty, `Open File [ShakeDesign]`, `Perform Script [ShakeDesign :: SOR_GoTo ; xmlSet ( "SOR" ; $SOR )]`. ShakeDesign's `SOR_GoTo` (id 144): same `xml2var`, `Go to Layout [SOR_blank]`, find on `zkp = $SOR`, `Go to Layout [SOR_Form]`. The button's own parameter is `xmlSet ( "SOR" ; metl__METL__::zkf_SOR )` and it hides on `IsEmpty ( zkf_SOR )`. So: file **ShakeDesign**, script **`SOR_GoTo`**, parameter **`<SOR>{zkp}</SOR>`** — `xmlSet` being `"<" & _tag & ">" & _data & "</" & _tag & ">"`, nothing more. The web's one departure is forced: a link cannot open the file and then call the script in two steps, so the URL does both at once.
  - **`OFF_GoTo` is the same script with the tag `OFF`** (ShakeDesign id 89), so the offers card on the métré page links the same way, off `OFF_Offers::zkp` which `listOffersForMetre()` already returns. Its one difference is on ShakeDesign's side: a zkp that finds nothing raises an "Offer not found" snackbar there, where `SOR_GoTo` exits in silence. Nothing to do about either from here. `recordUrl()` in `fileMakerLink.js` is the shared half; a third `*_GoTo` would be one line.
  - **A record with no zkp keeps its label and loses its link.** `listOffersForMetre()` builds `zkp` with `(string) ($row['zkp'] ?? '')`, so a missing field arrives as `''` rather than null — hence the guard is on "is this a zkp", not on "is this set". Pinned by a test, and seen in the browser: an offer without a key renders as plain text next to one that links.
  - **The host is normalised server-side, and that is not fussiness.** `SHAKEDESIGN_HOST` carries a scheme in production (`https://fms23.mycloud.fm`) because `ShakeDesignClient` accepts it either way; `fmp://https://…` leads nowhere. `FileMakerClientTarget` strips the scheme and any path, keeps a port, and is overridable through `SHAKEDESIGN_FMP_HOST` — the Data API host is resolved by the *web server*, this one by the *clicking machine*, and they need not be the same name. Found by reading the real config, not by supposing: the link would simply not have rendered in production, silently.
  - **Unconfigured or unparseable, no link is rendered at all** — the order's title stays, as plain text. A broken `fmp://` surfaces as a FileMaker dialog that names nothing; an inert label promises nothing. Same reasoning as the offers card degrading to "could not be read".
  - **The target is a page prop, not a per-line field.** It is a property of the installation; only `supplier_order_id` varies, and it is already on the line. Sending an assembled URL per row would repeat a hundred bytes across the thousands of lines of a métré. The URL is assembled in `resources/js/fileMakerLink.js`, which also refuses a `supplier_order_id` that is not a zkp rather than escaping it — the receiving `xml2var` would not undo an escape the source never applies.
  - **Still to verify, once, in FileMaker Pro:** the account opening ShakeDesign needs the extended privilege `fmurlscript` ("Autoriser les URL à exécuter des scripts FileMaker"). Without it FileMaker opens the file and ignores the script **in silence**. Neither export records which privilege sets hold it.
- **The seven printed documents are one Blade template, cut by an enum — and FileMaker generates no PDF.** `METL_GoTo_Print` (id 208) finds the métré's lines, goes to a print layout, sorts, and flips a new window into **Preview mode**. That preview *is* the aperçu; the PDF comes from the toolbar's "Save as PDF". Nothing is stored, nothing is dated — the document is recomputed on every click, which is the property that matters when it carries money. The web does the same: rendered on demand, never written to disk. `App\Documents\MetreDocument` declares what each of the seven is; `MetreDocumentBuilder` turns a métré into the printed band; `resources/views/documents/metre.blade.php` dresses it.
  - **A FileMaker printed report is not a tree, so the builder does not produce one.** It is a strip of parts printed in sorted-record order, a sub-summary printing whenever its sort key changes. The builder emits a **flat** list — group heading, line, component, total — which is the source's own rendering model, renders in Blade without recursion, and makes it structurally impossible for the headings to disagree with the sort order.
  - **What the seven documents actually differ by** is only the body: the two "complet" carry code/title/unit/quantity/unit price/total, the two "simplifié" carry code and title alone, the two summaries have **no Body part at all** (only group subtotals — and "catégories" also drops the REFS level, which is its entire difference from "sous-catégories"), and the supplier budget swaps the sales columns for the ordered ones. Everything else — title header, grouping levels, comment block, footer — is shared.
  - **A client budget prints its options; the supplier budget never loads them.** `$Option` defaults to 1 and is set to 0 for the supplier layout, which then constrains the found set on `isOption_b = 0`. On a client budget the options form their own block **after** the total, with the column headers reprinted and **no total of their own** — both objects of the trailing sub-summary carry `Hide when: isOption_b`. An option states what it would cost without adding to what is owed.
  - **The two tag levels only exist when `MET::Sort_OrderTags` is 1 or 2.** `TAG_Choice1_cU` is `Case ( Sort_OrderTags = 1 ; TAG1 ; = 2 ; TAG2 ; "" )` and `TAG_Choice2_cU` the reverse; empty, both collapse and no group is produced. That is today's state and it is deliberate — the Lots/Tags switch is unresolved. The supplier budget still *sorts* by tag when one is declared while having no tag sub-summary, which chops it into repeated sections: a quirk of the source, reproduced rather than fixed.
  - **Money on a document is computed by the database, not by the model accessors.** `round()` in PHP works on a binary float and the database on a decimal, and the two do not settle a half-cent the same way: summing the demo métré's 357 lines in PHP gave 2 426 250,06 € against the métré's own stored 2 426 250,08 €. Two cents, on a page that shows one figure next to a document carrying the other. Hence `MetreLine::SQL_SALES_ALL` / `SQL_ORDERED_ALL` beside the existing no-options fragments, selected as `document_amount`. All seven documents now agree with the métré's stored totals to the cent.
  - **Labels come from `config/print_labels.php`**, the 23 `ZSTRI` records with `isPrintLabel = 1`, read off the hosted ShakeMetre rather than retyped. Two deliberate divergences, both in that file's header: a missing string falls back to French (the source would print a blank Dutch column header), and the language is the **métré's**, not the interface's — the source loads it twice and the interface wins, an override added later.
  - **dompdf, and it is expensive.** 128 MB peak for the 357-line, 12-page budget — exactly PHP's default limit, so `MetreDocumentController::allowRoomForDompdf()` raises it to 512 MB for that request only, and only when it is lower. Font subsetting is on in `config/dompdf.php`: without it every PDF embedded the whole of DejaVu Sans, 1 291 kB against 93 kB. Page numbers are stamped on the canvas after render rather than by enabling `enable_php` in templates. Aspekta is **not** used: only a variable `woff2` is in the repo and dompdf needs TTF/OTF.
  - **Preview and download are the same URL**, `?download=1` choosing `download()` over `stream()` — that wrapper's `stream()` is always inline and its `Attachment` option belongs to raw dompdf, not to it. What you look at and what you save cannot diverge. Printing is a read: neither `role.write` nor the métré lock gates it.
- **The four line views are one page, cut by a slug.** `/metres/{metre}/lines/{view}` with `view` in `MetreLineDetailController::VIEWS`, which maps each slug to its money blocks (`achats-ventes` → `['achats', 'ventes']`) and is also the route whitelist — so what a view *is* is one fact, not two that can drift. The payload is identical for all four: a line carries the same fields and the same computed values everywhere, including `price_ratio`, which the narrow views simply do not draw. The page owns only how a block *looks* (`BLOCKS` in `LinesDetail.vue`, with literal Tailwind classes — a computed `bg-${tone}-50/70` would never be generated). The ratio column and the shared-quantity note appear only where both achats and ventes are on screen, because that is where they mean something. Adding a fifth cut is one line in `VIEWS`.
- **A line's section is copied onto the line, never joined.** The reference catalogue (REF → REFS → REFSL, 19/118/507 rows in the live file) is a source to copy FROM, maintained on `/references` — reached from the dashboard, three panes instead of the source's three tabs, creation as an empty row filled in afterwards (REF_New) and deletion cascading with the confirmation worded per level (REF_Delete). Deleting a catalogue entry clears the provenance keys on the lines that came from it and changes nothing else about them. A line carries `ref_code`, `ref_title`, `refs_code`, `refs_title`, `refsl_title` and `ref_order`; the foreign keys are written as provenance only and nothing reads them to display a line. That is what lets a section be renamed — or invented on the spot, which FileMaker allows — without rewriting métrés already sent to a client, and it is verified by a test. `ref_order` counts from 1 inside one `(métré, ref_code, refs_code)` group and is the third component of the printed code `MetreLine::refLineCode()` = "20.8.1" (METL::REFSL_Code_c). - **The line views are laid out like `METL_MetreComplete_List_Full`.** That layout is two leading sub-summaries over the body plus a trailing grand summary, and the web views reproduce it: a heading row per section (sub-summary by `REF_Code`) and per sub-section (by `REFS_Title`), each carrying the three money subtotals, then the lines, then the métré's total. Headings exist only for groups that have a line, because a sub-summary prints only when a record triggers it. Subtotals are computed in the page, not on the server: the cells already recompute as you type, so a server-side subtotal would be the one stale figure on screen.
- **`PriceTotal*All_c` per line, `zsm_SumTotal*_noOptions` per group.** The source's body column has no option guard - an option line shows what it would cost - and only the subtotals and the métré's stored totals exclude it. Showing 0,00 € on an option line, which this application used to do, hides the number the option exists to state. Both are in the payload (`price_total_*_all` and `price_total_*_no_options`); the option line's own total is greyed and italic so the difference is visible.
- **A line can be filed under a sub-section that exists in no catalogue.** METL_NewFromREF's second branch: a code and a title typed in the section heading (`zg_REF_SelectedNewCode` / `zg_REF_SelectedNewTitle` there, the same two fields in the same place here). Which is the other half of why a line's section is a copy and not a link — there is nothing to link to. Those four columns are writable at creation (`StoreMetreLineRequest`) and never afterwards: re-filing a line would change the printed code of something already on a document, and the source does not offer it either.
  - **A new line is always classed, so the line views have no « + Ligne » button any more** (removed at the user's request: an unfiled line served nobody). Lines arrive from the catalogue, or from the menu of a section, which files them as it creates them. `addLine()` therefore always takes a section, and the empty state of a métré with no line at all — which has no section heading to start from — opens the catalogue instead. The other grid (`MetreLines/Index.vue`) is untouched; only this view was asked about.
- **The line views' search matches a line's *group* as well as the line, so it returns lines that do not contain the term.** Searching a section's name brings back every line of that section (21 for DEMOLITION, against the 6 that name it), because `ref_title` / `refs_title` are copied onto each line and are in the searched set. Confirmed with the user. What keeps it readable is that **every match is marked** (`.search-hit`, lime on ink): a marked heading means the whole group was brought in by its name, an unmarked one means only its own matching lines are there. In an editable cell the mark cannot live inside the `<input>`, so the marked text is drawn over the field and the field shows its own only on focus (`text-transparent` + `focus:`, doubling hidden by `peer-focus:hidden`) — pure CSS, no state, and the editing path is untouched. A `<select>` (the unit) and a field that is searched but never displayed (`description`) therefore cannot be marked at all. The third unmarkable case — a column the Lots/Tags switch is currently hiding — is answered by the count on the switch itself, see that entry.
- **That search folds case *and* accents, and `searchMatch.js` is the one place that knows how.** `electricite` finds `Électricité` and the reverse, which matters because the live data mixes spellings — the catalogue holds both `Démolition lourde` and `Demolition/Evacution béton`. Folding forces an index map: `"é".normalize('NFD')` is two characters, so a position found in the folded string designates nothing in the original, and marking at it would shift the highlight by one per accent standing before it. `foldWithMap()` keeps one entry **per UTF-16 unit** of the fold, not per code point — an astral character occupies two, and counting one shifted everything after it (caught by a test, not by the build). Marked text always carries the **original** spelling: what is written is underlined, not a folded rewrite. The same helper backs the ShakeDesign picker and the catalogue modal; two search boxes on one screen behaving differently is worse than either behaviour.
- **`recomputeLocally()` must carry over the computed values it does not itself recompute.** It rebuilds seven of the nine keys `MetreLineGridResource` sends, so without a `...row.computed` spread first, `ref_line_code` and `price_total_gain_no_options` vanished on the first keystroke and the Code column read `—` until the page was reloaded. The server does return them; `flushRow()` simply reassigns nothing when the debounced save has already gone out by itself. Same trap as the one documented for the PATCH response above, on the client side this time.
- **« Réduire » and « étendre » the display are FileMaker's found set, and the asymmetry is the point.** `METL_View_Constrain_REF` / `_REFS` intersect, `METL_View_Extend_REF` unions, `METL_View_Extend_All` restores the whole métré — read off the four script bodies, not guessed. So reducing never adds (reduce to a section that showed 2 of its 4 sub-sections still shows 2) and extending never removes (extending a section while everything is shown does not narrow to it). Held as a set of **sub-section keys**, the only level the four scripts search on. There is deliberately no "extend" on a sub-section: the source has no `METL_View_Extend_REFS`. The search stays a separate live filter — merging the two would freeze its result into the found set, and clearing the box would no longer unfilter.
- Lines are listed in section order — METL_Sort as the working screens call it: `ref_code, refs_code, refs_title, ref_order`, ascending with no special treatment of empties, so a sectionless line leads (an empty number sorts before 0 in FileMaker). `sort_order` only breaks ties. The source's two other sort flavours are deliberately not reproduced: `isOption_b` first belongs to the client offer, the accounting and the printouts, and the TAG_Choice1/2 variant depends on `MET::Sort_OrderTags`, part of the unresolved Lots/Tags switch. Inserting from the catalogue (METL_New_Multi) copies the item's title, its unit and its price — into **`price_buy`**: the catalogue is a purchase-price book and nothing in it feeds the client price. **One article, one line**, by decision of the user: the modal used to let the same article be ticked N times to create N copies, and that is removed — two identical doors are said by a quantity of 2 in the purchase estimate, not by two lines that then have to be edited together. The picker's list stays an ordered list rather than a `Set` (ticking order is the order the lines arrive in) but carries no duplicates. Its **reset is attached to the closing, not to the button that closes**: the caller closes the modal itself after a successful insert, so clearing on « Annuler » alone left the ticks in place and the next insert recreated the same lines. A `watch` on `show` covers all five exits, and the one path that must *keep* the selection — an insert that failed, where the modal stays open — is exactly the one it does not touch. A row is ticked by clicking anywhere on it, through a `<label>` wrapping the row rather than a `@click`, so the browser handles the toggle, the keyboard, and the double-count when the box itself is clicked. Titles are copied in the **métré's** language (the source used the interface's) with a fallback (the source blanks the title when the translation is missing, and `Title_NL` is empty on all 644 catalogue rows).
- **A line's title is `refsl_title`, not `description`.** METL::REFSL_Title, confirmed on the live data (57 079 lines against 29) and settled with the user: both grids' title column writes it, and `description` stays what the source uses it for - a rare free note ("1374,07 € selon offre Collignon"), still editable. The line search covers both.
- **A lot's `code` is a number, and every list of lots sorts on it numerically** (2 before 10), with codeless lots last — at the top, where MySQL's NULL ordering puts them, they read as the head of the list. The project page re-sorts client-side too, because the lot manager edits the sidebar's array in place: a lot renumbered to 2 afterwards would otherwise stay below the 10. The manager's own rows are only re-sorted on open, so a row does not jump out from under the cursor while its code is being typed.
- **The line ratio is computed, not read.** `price_sales / price_buy`, rounded to 2, null when either price is absent or the purchase price is 0. It deliberately replaces the stored `METL::Ratio` column, which nothing in the export claims to maintain. The column is left untouched in the database.
- **Active/inactive was dropped from the dashboard.** `PRJ_Projects.isActive_b` is not on `API_PRJ`; coercing its absence to `false` made every project claim to be inactive.
- **`bestPriceAmongSuppliers()` ignores `is_tender_line_b`.** The source guard reads `Case ( isTenderLine_b ; 0 ; _min )`, which literally zeroes the benchmark for exactly the lines it exists to compare. Filtering happens at the query that selects the lines, never inside the price calculation.
- **Only the METL final-score formula is implemented.** `LOT_Lot::TENDER_Supp1_Total_cU` has its weighted price term commented out and scores supplier 1 on a different scale from the other four. Supp2–5 agree with the METL formula.
- **A duplicated métré keeps `isStatus_Site_b` but not `isAccepted_b`.** Site is an internal status; accepted is a claim about a client having agreed. Consequence, pinned by a test: `Tot_Sum_TotalBuy` is gated on acceptance, so a fresh duplicate's "achats" reads empty **in the project page's roll-up and in the portal replica** until it is accepted. Its own page shows the figure, reading the ungated column.
- **`Mail_2` is never a fallback for `Mail_1`.** An empty `Mail_1` gets a synthetic `{zkp}@shakedesign.local`. Access depends on `isActiveAccount_b` and `isActiveUser_b` and nothing else.
- **PHP float arithmetic can disagree with FileMaker by one cent** (`3 × 1.005` → 3.01 here, 3.02 there). Recorded in a named test rather than hidden. Fixing it means carrying decimals across the whole chain deliberately.

## Testing

- **No test may touch a real FileMaker server.** `Http::fake()` always. Two traps, both paid for:
  - Stubs *stack* — the first match keeps winning; use `Http::sequence()` for successive responses.
  - **An unmatched pattern is NOT blocked — the request goes out for real.** Adding the offers lookup to the métré page broke six existing tests that faked only `*/sessions` and `*/layouts/API_PRJ/_find`: the new call left the fake and tried to resolve `fms.example.test`. When a screen gains a remote call, every test that renders it needs the new stub. `Http::preventStrayRequests()` would turn that into a loud failure instead of a slow one.
- **Verifying a path that WRITES to ShakeDesign means a fake Data API server, not a stub.** Point `SHAKEDESIGN_HOST` at a tiny `php -S` script that answers `/sessions`, the `_find` and the `records` endpoints and *logs what it received* — then assert on the log: that is how the offer's `fieldData` was checked field by field without creating a real offer. Restore `.env` afterwards, and never let a browser check reach the live ShakeDesign.
- Money and formula work gets **numeric** tests with hand-computed expected values, not just shape assertions.
- Watch out for helper names that collide with Laravel's `TestCase`: `session()` and `component()` are taken. This has cost time twice.
- Verify UI work **in a real browser**, not only through the suite. Several real bugs (the modal backdrop, the stale working copy, a blanked column, the readonly account) passed every PHP test and were only visible when driven. Install Playwright + Chromium in a scratch directory, point `SHAKEDESIGN_HOST` at a small fake Data API server if the flow reads ShakeDesign, drive it, **look at the screenshot**, and check `console --errors`. Restore `.env` and delete any seeded demo rows afterwards.
- When a browser check disagrees with the suite, suspect the check first. Every single disagreement in this project so far has been the check: `input` `value` is not in `textContent`; `:has-text("Livré")` also matches "Non livré"; `div.grid.bg-sand-100` matches the section headings and the total row as well as the column labels; `.eyebrow` uppercases its text through CSS so `innerText` returns `TAG 1`, not `Tag 1`; the Lot column is the **second-to-last** cell (the last is Commande fourn., empty in the demo data, which made a "detached" assertion pass vacuously); Playwright counts elements hidden by `display:none`, so use `:visible` when checking that something disappeared; and `page.mouse.click(x, y)` skips actionability, so a coordinate a few pixels off silently hits the neighbouring button.
- **A browser check that writes is a data migration.** Several runs here changed the demo métré (a lot assignment, an option flag, tags) and one value could not be reconstructed afterwards, because the old display could not distinguish "no lot" from "unnamed lot". Before driving a write path: note the exact prior values you are about to touch, prefer a fixture you created over a row that was already there, and assert the restoration afterwards. A screenshot taken *before* is worth more than reasoning after.

## Workflow

- **Never guess a business formula.** If the export is unclear or silent, list what you found and ask. There is money behind these.
- Commit in small, reversible steps, split by concern — bug fixes to shared infrastructure separate from feature work. Verify each intermediate state (`git stash --include-untracked --keep-index`, run the suite) rather than reconstructing plausible history.
- Commit messages carry the *reasoning*, especially for anything counter-intuitive or deliberately divergent from the source.
- Only commit when asked.

## Critical test

Any migration/seeder touching `MET_Metre`, `METL_MetreLines`, `LOT_Lot`, `REF_Reference` or `METC_MetreLineComponent` must have a test asserting a given source `zkp` round-trips unchanged. See `tests/Feature/ZkpPreservationTest.php`.

## Open items

**Paused or dropped by the user — do not start these without asking again:**

- **Excel exports** (`METL_XLSX_Export` and six `__SAVE_AR_*` variants). Paused. When it resumes, ask for one example XLSX per export rather than reading the script: the column layout is what matters and a produced file is more reliable than a re-read script.
- **Documents are rendered from print layouts and stored on the ShakeDesign side.** Half of this was read off the live ShakeMetre file: it has **no document table** (`DEV/Raw/*` covers CART, CAT, CATS, JCARTMAT, LOT, MAT, MET, METC, METL, PRJ, REF, REFS, REFSL, SUP, TAG, ZSET, ZSTRI, ZVAR and nothing else) and no container field, but a `METL_Print` layout folder holding the seven rows this page lists: `METL_ClientBudgetSummary_Print`, `METL_ClientBudgetSummaryBasic_Print`, `METL_ClientBudgetSimplifiedComposition_Print`, `METL_SupplierBudgetPurchase_Print`, `METL_SupplierBudgetOrdered_Print`, `METL_MetreComplete_List_PurchasesSales_Print`, plus `METT_SupplierTenderProcess_Print` / `METT_AllSuppliersTenderProcess_Print`. The other half comes from `MET_OFF_CreateClientOffer`, which after creating an offer saves `METL_ClientBudget_Print` as a PDF and calls **`DOC_New`** with `Context = "OFF"`, `Zkf = <the offer>`, `isMail = 1`, `isPrint = 1` — so a generated document **is** kept, in ShakeDesign, keyed to what it documents. An earlier note here said documents were not stored at all; that was wrong, and only true of the ShakeMetre file. What each layout contains still has to be read layout by layout or supplied as example PDFs.
- **Changing a line's reference / sub-reference. Dropped.** The user abandoned it after it was scoped, so the settled decision above — the four section columns are writable at creation and never afterwards — still stands. The server side would have supported it (`reference_id` / `sub_reference_id` are in the whitelist and the observer re-copies the snapshot), which is exactly why this note exists: do not read that capability as an invitation.

**Waiting on an answer:**

- **A client offer still has no number.** `ZSET_Numbering` is now reachable (see the Fournisseurs frame) and the supplier order uses it; the offer path predates that and does not. One call with `<Type>OFF</Type>` after `createOffer()`, plus `Number` on `API_OFF`, which the layout does not expose today.
- **`OFL_Total_PriceNoTax_Stored` can go.** Nothing reads it; the offers card reads `_cU`, and the two agreed on every live offer checked. Left in place until said otherwise. Historical note worth keeping: `_cU` was once removed from `API_OFF` while the code read it, and every offer amount on the métré page silently read as "—". A layout is part of the contract.

- **A métré line carries no VAT today**, so `Metre::offerLinesByVat()` collapses to a single untaxed line — correct behaviour, useless output. In the source `METL::zkf_VAT_ae` defaults from `ZSET::VAT_Default`, and `ZSET` is deliberately not ported (Laravel config instead). To settle: where does the default VAT come from here, and is it editable per line or per métré? Nothing on screen exposes it yet.
- **The per-line lock is not implemented.** There are **two** locks in the source: `MET_Metre.isLocked_b`, the manual one this application now has a button for, and `METL_MetreLines.isLocked_bcU`, a **calculated** one — `GetAsBoolean ( Case ( not IsEmpty ( metl_SOR__::zkp ) ; 1 ; 0 ) )`, i.e. *a line is locked because it sits on a supplier order*. Nothing here honours the second, so an ordered line is still editable. It concerns money and was raised but not decided.

**Still open, nothing blocking:**

- **The ShakeDesign API account cannot delete.** Removing a test supplier order needed the full-access account (`Record access is denied`, code 200). Nothing needs it today - nothing here deletes over there - but cancelling an order from the web would.

- **`metre_lines.lot_name_stored` is written by nobody *in the application* — but the import fills it.** `shakemetre:import` copies `METL::LOT_Name_Stored` verbatim, because it is source data and dropping it would lose information the source holds. So after the import the column is populated for imported lines and stays untouched afterwards, which makes the disagreement below concrete rather than hypothetical: an imported line carries FileMaker's name, a line assigned a lot on the web carries nothing, and both display the name derived from the lot. Nothing reads the column, so nothing is wrong today. The question below is what settles it. The rest of the original note: the column exists, and `METL_Lot_AssignToSelection` maintains its FileMaker counterpart with a second `Replace Field Contents`: `Case ( MET::Language = "FR" ; LOT::Title_FR ; = "NL" ; Title_NL ; Title_EN )`. This application does not write it — neither the single-line PATCH nor the bulk assignment — because the displayed name is derived from the lot at read time (`title_custom ?: title_fr ?: title_en ?: title_nl`), which is strictly better: renaming a lot updates every line at once. Filling the column in one write path only would put the two in disagreement, and the derivation rule is not the source's rule either (it ignores `title_custom` and follows the métré's language). To settle: does anything outside this application — a printout, a ShakeDesign screen — read `LOT_Name_Stored`? If so it needs maintaining on both paths, with one agreed rule.
- **Supplier company filtering** in the picker is limited to `isSupplier_b` + `isActive_b`. No company in ShakeDesign currently has `isActive_b = 0`, so that half of the filter has never been exercised against real data.
- **The profile and password screens are still in Breeze's English** ("Profile Information", "Save", "Delete Account"). They now carry the DA but not the language of the rest of the application. `Welcome.vue`, Breeze's landing page on `/`, is untouched beyond the ramp remap.
- **Three things the FileMaker line list does and this one does not yet.** Drag-and-drop reordering inside a sub-section (`zg_DRAG_N_DROP` + `Order`, which `METL_Reorder` renumbers 1..n); selection of a whole section or sub-section (`METL_Select_REF` / `_REFS`, which keep tri-state group flags in `MET::zkm_REF_Selection_g`); and pushing a line's unit price back into the catalogue (`METL_Update_REFSL_Price`, confirmation dialog included — it writes `REFSL::Price`, the direction one would not guess). Bulk **tagging** (`METL_Tag_AssignToSelection`) is not built either, though the bulk lot endpoint is the pattern to copy. The tag levels of the sort (`MET::Sort_OrderTags`, `TAG_Choice1_cU` / `TAG_Choice2_cU`) are deliberately deferred by the user.
- **`REF_Reference.Code` is text in the source and an integer here.** The live catalogue's first section is the string `"00"`, which this project's column flattens to `0` — the line codes it feeds are numeric anyway (`0.0.1`), but the catalogue screen shows `0` where FileMaker shows `00`.
- **The live data IS imported** — `php artisan shakemetre:import` has run against the hosted file and the database holds the real 877 métrés and 57 803 lines, audited against the source. See "Importing the live data" at the bottom of this file before re-running anything.
- **The breadcrumb on the Achats/Ventes/Commandes view stops at the métré.** The project's *name* lives in ShakeDesign, and fetching it over the Data API for a single label would put a remote call on the heaviest screen of the application. `metre.project_id` is in the payload if that trade-off is ever revisited.

## Importing the live data — where this stands

**This is the current thread. The import has run against the live file, and the local database now
holds the real data** — 877 métrés, 57 803 lines, 3 717 components, the 852 lots and the whole
catalogue, audited métré by métré against the source (see "Checking what actually landed" below).
Read this section before touching it; the command stays re-runnable and idempotent.

    php artisan shakemetre:import --dry-run     # read and check everything, write nothing
    php artisan shakemetre:import --fresh       # start from an empty database
    php artisan shakemetre:import               # top up / correct without wiping
    php artisan shakemetre:import --only=METL,METC   # replay one phase
    php artisan shakemetre:import --audit       # compare both sides métré by métré, write nothing
    php artisan shakemetre:import --repair      # re-read the métrés the audit found incomplete

`app/Console/Commands/ImportLegacyShakeMetre.php` plus `app/Console/Commands/LegacyImport/`
(the Data API reader and the field map). All three are **temporary and go with the migration**,
which is why they sit together — the folder is deletable in one move. Covered by
`tests/Feature/ImportLegacyShakeMetreTest.php`, 18 tests over a fake Data API.

A full run takes **35–45 minutes**, almost all of it METL. Measured on a real dry run.

### What there is to move, measured on the server (05/08/2026)

| Source | Rows | Note |
|---|---|---|
| `METL` metre lines | **57 816** | the bulk of it; paged reads |
| `METC` components | 3 717 | |
| `MET` metres | 877 | |
| `LOT` lots | 852 | |
| `REFSL` / `REFS` / `REF` | 507 / 118 / 19 | the catalogue |
| `CART`, `CAT`, `CATS`, `JCARTMAT`, `MAT`, `SUP`, `TAG` | **0** | empty, nothing to move |

Seven tables are empty: the cart, the material catalogue, suppliers and the per-métré tags were
never used. Their web tables stay empty, and that removes a large slice of the assumed work.
`PRJ` (677) is a local mirror of ShakeDesign's projects and is **not** migrated — a métré's
`project_id` is a ShakeDesign zkp and is read live.

### The layouts exist, and they carry every field of their table

**A Data API read only sees the fields placed on the layout it targets** — the reason the seven
`API_MIGRATION_*` layouts had to be built at all, `DEV/Raw/*` being made for inspection and
missing `IndProject`, `Language`, `isAccepted_b` and every total. They now exist in the hosted
ShakeMetre, and the user put **all** of each table's fields on them rather than only the list in
`docs/filemaker-reference/API_MIGRATION_layouts.md`. Probed and confirmed: 124 / 235 / 35 / 107 /
40 / 35 / 43 fields, row counts matching the table above exactly.

`preflight()` re-checks this on every run and **refuses to import** if a mapped field is absent,
because a missing field does not fail a read: the column simply arrives null, silently, on money.

Two consequences of "all the fields", both real:

- **`zsm_zkf_VAT_List` on `API_MIGRATION_METL` weighs 2 138 821 bytes per record.** It is a
  Summary "list of" field, so it returns the newline-joined list of **all 57 816 VAT keys on every
  single row** — everything else on that layout totals 929 bytes. Read whole, the table is ~124 GB.
  A summary is computed over the *found set*, so the fix is to read **per métré**: the found set
  drops to one métré (470 lines at the largest), the field to 17 kB, the total to ~370 MB. That is
  why `importMetreLines()` loops on métrés instead of paging the table, and it is not a
  micro-optimisation — the naive read does not finish.

  **Per-métré is necessary but not sufficient, and only a full run showed it.** At 17 kB a line, the
  470-line métré answers 11,6 MB, and Laravel keeps the raw body *and* the decoded array alive at
  once (`Response::body()` memoises, `json()` decodes on top of it). The first complete dry run died
  at **13 % of the lines phase** on "Allowed memory size of 134217728 bytes exhausted", inside
  Guzzle's `fwrite`. So each métré is **also** paged, 100 records a request (`findPages()`), which
  bounds a response at ~1,8 MB — the summary field does not shrink with the page, since it is
  computed over the whole métré, so it is the *record count per response* that bounds the peak. Plus
  `allowRoomForTheBiggestMetre()`, 512 MB, the same guard `MetreDocumentController` already uses for
  dompdf: response size depends on a summary field in the other application, so the margin cannot be
  computed here.

  Both paging levels are pinned by tests, and the second one only exists because breaking the
  offset of `pages()` on purpose made **nothing** fail — every fixture fitted in one page, so that
  path had no coverage at all. `--page=1` now forces the six whole-layout phases to paginate over
  tiny fixtures.

  **The METL read strategy is detected, not configured.** `preflight()` looks for `zsm_*` fields on
  `API_MIGRATION_METL`: while any is present the lines are read per métré to shrink the found set
  (~733 MB, ~1 500 requests, 27 min — all measured); with none, the table is read straight through in
  pages of 500 (~51 MB, ~100 requests). Detected rather than flagged because the layout is editable
  by someone else, and an option to tick would drift from reality the first time it was forgotten.
  The chosen strategy is announced at startup, and both paths are tested to produce the same rows —
  a speedup that changed the data would be no speedup. Note the fixtures deliberately carry
  `zsm_zkf_VAT_List` so the default tests exercise the path production actually takes; adding the
  fast path without that made all 26 tests silently switch to it.
- **`_offset` is 1-based.** `_offset=0` answers 960 "Parameter is invalid", which reads like a
  missing layout.

Removing `zsm_zkf_VAT_List` from the layout would take the METL read from ~370 MB to ~50 MB. The
command prints a note saying so and works either way; it is an optimisation, not a prerequisite.

### Settled, so do not re-litigate

- **UUIDs are preserved verbatim** for MET, METL, LOT, REF, REFS, REFSL and METC. The whole point:
  `OFF_Offers.zkf_MET` and `SOR_SupplierOrders.zkf_MET` in ShakeDesign are hard references.
  `tests/Feature/ZkpPreservationTest.php` is the guard.
- **`metre_number_sequences` must be seeded** per project at the maximum imported `IndProject`.
  Skip it and the "a métré number is never handed out twice" rule is false for the first métré
  created afterwards.
- **The file is live** — 57 816 lines where an earlier note said 57 809. The user will run the
  import at night, on a quiet file, so a freeze is not needed; the script simply has to be
  re-runnable and idempotent.
- **A métré pointing at a project deleted in ShakeDesign** shows no project name. Accepted: such a
  métré should not be reachable anyway.
- **Line images are out of scope for the first pass.** `METL::Image_500x500` is a container: the
  Data API returns a temporary URL to download file by file, and this project has no file storage.
  It is deliberately absent from the layout lists. Consequence: the "budget client — complet"
  document loses the thumbnails the source prints.
- **Authorship does not survive.** FileMaker records an account *name* (`zlg_creaUserName`);
  `created_by` / `updated_by` are `char(36)` expecting a user UUID. The fields are on the lists so
  a mapping stays possible later. Creation and modification *timestamps* do carry over.
- **`sort_order`, `sequence_number`, `lot_names_cache`, `offer_id`** have no source and need none —
  web-side conveniences. Ordering comes from `ref_order`. **One exception, and the layout list was
  wrong about it: `METC::z_Order` IS the source for `metre_line_components.sort_order`.** Read off
  the export, not guessed — a stored `Number` whose auto-enter is `metc_METC__Ordering_s::z_Order + 1`
  (next rank, taken through a self-join sorted descending), which the `$$DRAG.ORDER` / `$$DROP.ORDER`
  variables read for drag-and-drop and which every portal sorts ascending on. It is a component's
  rank inside its line; dropping it would lose the display order.
- **The phase order is the foreign keys, and nothing else.** REF → REFS → REFSL → MET → LOT → METL
  → METC. MET and LOT have no local parent (`project_id` is a ShakeDesign zkp, deliberately
  unconstrained here). Wiping runs the reverse. A test asserts the order by the *sequence of Data
  API calls*, not by the result: an import that succeeds by luck on a fixture can still fail on the
  real file.
- **The import writes through `DB::table()`, never Eloquent, and that is load-bearing.** Speed is
  the lesser half. The sharp half is the observers: `MetreLineObserver` rewrites a line's section
  snapshot from `reference_id` — which is empty in the source — so it would **erase the copy the
  line carries, the only true one**; and it dispatches `RecalculateMetreTotals` on every save,
  which would queue 57 816 jobs to recompute 877 métrés. `HasUuids` staying out of the way is what
  lets the source zkp through verbatim. Pinned by a test.
- **The `_Stored` totals are imported verbatim, and `--recalculate` is deliberately opt-in.** They
  are the figures FileMaker was showing; an import that recomputes them is not migrating data, it
  is manufacturing it. And the four `Total_*_METL_Stored` are written by `RecalculateMetreTotals`
  on a stated assumption the export does not confirm — one more reason the recompute is a separate,
  conscious act rather than a side effect of importing.
- **What the real data actually contains**, all counted on the server (05/08/2026) and all handled:
  - `zkf_MAT`, `zkf_JCARTMAT`, `zkf_REF`, `zkf_REFS`, `zkf_REFSL` are empty on **all** 57 816
    lines. So the empty `materials` / `cart_materials` tables are no obstacle, and a line's link to
    the catalogue really is provenance-only.
  - `zkf_LOT` on 13 379 lines, `zkf_SOR` on 3 598, `zkf_CPY` and `TENDER_Id` on 1 569,
    `zkf_VAT_ae` on 57 806, `zkf_AccountingCode_ae` on **1**.
  - **13 lines are unreachable**: 3 have an empty `zkf_MET`, ~10 point at a métré that no longer
    exists (`Tot_Count_METL` sums to 57 803 against 57 816 rows). `metre_lines.metre_id` is NOT
    NULL with a foreign key, so they cannot exist here. The per-métré read skips them structurally;
    the command reports the gap rather than letting it pass unmentioned.
  - **2 `REFS` rows have no parent**, no code and no title — blank records, no children. Skipped.
  - **No duplicate `(zkf_PRJ, IndProject)`** among the 877 métrés, so the unique index is safe.
  - Métré languages: **EN 728, FR 132, NL 17**. `Title_NL` is empty on all 507 catalogue articles.
  - Largest métré: **470 lines**; none over 500.
- **A missing *required* parent skips the record; a dangling *optional* key is nulled and the record
  kept.** A line whose lot was deleted is still a real line with a real price — dropping it would
  lose money to preserve a reference that is already worthless. A sub-reference without a reference
  cannot exist at all. Both cases are tested.
- **Dates come back `MM/DD/YYYY`**, timestamps `MM/DD/YYYY HH:MM:SS`. Parsed by hand, not with
  `Carbon::createFromFormat`, which **rolls out-of-range components over instead of failing** — a
  13th month becomes January of the next year in silence. On a file set to day/month that would
  shift agreement dates by months without a word, so the import checks `checkdate()` and names the
  day/month case explicitly.
- **Three `Tot_*_TotalFees_Stored` are NOT imported, and their `_Stored` name is a lie.** The
  dictionary gives their formulas: `Tot_Sum_TotalFees_Stored` = `zsm_SumTotalSales_Stored -
  zsm_SumTotalOrdered_Stored`, `Tot_Percentage_TotalFees_Stored` = `(Tot_Sum_TotalFees_Stored /
  zsm_SumTotalSales_Stored) * 100`, `Tot_Ratio_TotalFees_Stored` = `zsm_SumTotalSales_Stored /
  zsm_SumTotalOrdered_Stored`. They are **Calculated fields over Summary fields**, so they total the
  *found set*, not the métré — the same argument that already kept `zsm_*` out of `metres` applies to
  the calculations that read them. Observed, not deduced: on a real read `Tot_Sum_TotalFees_Stored`
  was 6 494 969,76 and `Tot_Ratio_TotalFees_Stored` 1,5450183058739, **identical on every métré**,
  and one came back as 3,5275847787813E+17. The columns stay null, which reads as "not computed yet"
  where a found-set aggregate would read as "wrong"; `RecalculateMetreTotals` fills them per record,
  which is what `--recalculate` is for. The four `PROG_*_Valid_Stored_c` are Calculated too and *are*
  imported — their formulas are `PROG_..._Stored * isAccepted_b` / `* IsStatus_Site_b`, per-record
  throughout. **A full audit of every mapped field against the dictionary found no other case**, so
  this is settled rather than sampled.
- **FileMaker has no numeric bounds; these columns do.** `decimal(15,4)` is 11 digits before the
  point, and an out-of-range value made MySQL reject the whole `upsert` — the métré phase died on
  **record 1 of 877** with "Numeric value out of range". `LegacyFieldMap::withinRange()` now nulls and
  reports anything a column cannot hold. Never clamped: rounding 3,5e17 down to 99 999 999 999,9999
  would invent a figure, and on money that is worse than admitting there isn't one.
- **A row the database refuses costs only itself.** Batches carry ~159 records, so one refusal used
  to fail the other 158 and abort the phase — 27 minutes of reading lost for one line, on a job that
  runs overnight. `salvage()` replays a refused batch record by record, skipping and naming the
  offender. Its test forces the refusal with a **SQL trigger**, deliberately: the coercion and
  key-validation layers already pre-empt everything SQLite can enforce, and the first attempt (a
  300-character `unit`) proved nothing because SQLite does not check varchar length. What `salvage()`
  is for is the refusal nobody predicted.
- **A FileMaker Number field accepts text and still reads it as a number**, so the import does the
  same instead of refusing it. `TENDER_Supp3_Quantity` is **`"59²"`** on 6 lines — a superscript
  typed into a quantity — and FileMaker holds that as 59, confirmed by querying the server with a
  numeric comparison, which returns those very rows. Nulling it would drop a tender quantity the
  source uses in its own arithmetic, so `LegacyFieldMap::number()` extracts the numeric part the way
  `GetAsNumber` does and **reports every reinterpretation**; text with no number in it stays null.
  Found only because the full run reported it — no test would have invented that value.
- **`"?"` is how FileMaker renders a calculation error in a stored field**, typically a division by
  zero. It is the value of `PROG_ProgressSuppTotal_Percent_Stored` on 519 of the 877 métrés and of
  the client twin on 226 — the ones whose total is zero. **null is the correct reading**, so it is
  reported under its own label rather than as an unreadable number: an anomaly list with 1 490
  expected entries teaches the reader to ignore the anomaly list.
- **`REFS.zkp` and `REFSL.zkp` are UUIDs, and so are `LOT.zkf_TENDER_Supp1..5`**, all typed
  `Number` by FileMaker. Re-confirmed on live records. The declared type proves nothing, so every
  key is shape-checked and a non-UUID becomes null and is reported — writing `12` into a `char(36)`
  of UUIDs would pass the database and break the first join.
- **The HTTP timeout is 300 s, not the config's 30.** A batch read is not a web request: merely
  *counting* `API_MIGRATION_METL` pulls 2,1 MB and blew through 30 s on the first real run.
  `--timeout=` overrides.
- **`--fresh` wipes the 13 domain tables plus `metre_number_sequences`, and never `users`.**
  Emptying the site's database does not mean logging yourself out. It asks for confirmation
  (`--force` to skip), lists what it is about to delete, and **declining aborts the whole import**
  rather than importing on top — `--fresh` without the wipe would produce a mixture.
- **Everything is `upsert` on the primary key, so the command is re-runnable**, which is the whole
  recovery story for a network drop or a dead Data API session mid-run: relaunch without `--fresh`
  and what landed is rewritten identically while the rest arrives. Chunk size is *computed*
  (`15000 / column count`), not chosen: MySQL caps a statement at 65 535 placeholders and
  `metre_lines` has ~90 columns, so a fixed 500-row chunk would work on six tables and blow up on
  the only one with 57 816 rows.

### Checking what actually landed — `--audit` / `--repair`

**The gap is closed, and the cause was a column of ours, not a defect of the source.** The live
import left the database 414 lines short (57 402 against 57 816, 13 structurally out of reach).
`--audit` named 54 métrés and 401 missing lines; re-reading them showed **401 lines whose
`METL::REFSL_Title` exceeds 255 characters** — 681 at the longest — against a `varchar(255)`
column, which MySQL in strict mode **refused one by one**. The correspondence is exact, and no
other `varchar` overflows: every refused row lives in a gap métré, and all 7 264 lines of those 54
métrés were re-read and measured field by field. `2026_08_10_000001_widen_metre_line_title_to_text`
makes `refsl_title` a `text` (like `description` and the two comments, which are the same kind of
free text — `comment_supplier` reaches 1 128 characters in the real data and never failed,
precisely because it was already `text`), `UpdateMetreLineRequest` follows at `max:65535` so an
imported long title stays saveable, and `--repair` brought the 401 lines in. Truncating was never
an option: a title cut at 255 still reads as a title while describing something other than what
the client ordered — the same argument as `withinRange()` for numbers.

The reason the trouble could not be read off the import's own report is settled too, and fixed:

- **A refusal repeated 401 times printed 401 lines.** `salvage()` put the record's zkp *inside* the
  note, so the deduplication never took and the report scrolled past as noise. The message now
  carries the reason alone, the count is in front of it and a few zkp follow — one line saying
  `METL — 401 × Data too long for column 'refsl_title' → enregistrement écarté. (dont …)`. Pinned
  by a test that refuses 250 rows through a SQL trigger and asserts the report holds **one** line.
- **`$inserted` counted the rows handed to `upsert`, not the rows that landed.** An `upsert` on a
  duplicate primary key overwrites in silence, so a zkp the source carries twice cost a row while
  the report kept announcing the source's total. The report now carries `Source | Lus | Écartés |
  Doublons | En base | Écart` per phase and warns when `lus − écartés − doublons ≠ en base` (only
  on `--fresh`; without it the table already holds rows this pass did not write). Duplicate zkps
  are counted and named — a duplicate is a **source** defect, re-running changes nothing.
- **`php artisan shakemetre:import --audit`** counts both sides métré by métré — `foundCount` on
  `zkf_MET`, one record fetched per métré, ~877 requests — and prints the métrés in disagreement
  with their delta. It separates *hors de portée* (`zkf_MET` empty or pointing at a deleted métré:
  nothing to do) from *missing* (relisible). A total against a total would only say "414 short";
  per métré it says which ones to read again.
- **`--repair`** re-reads exactly those métrés through `readLinesOf()` — the same code path as the
  import, so a repair that succeeded by reading differently would prove nothing — then recounts.
  What survives the re-read has a cause the re-read does not treat (duplicate zkp, a row the
  database refuses, a métré deleted at the source) and the pass's notes name it.

Run `--audit` first; it writes nothing. It is also the right reflex after any schema change that
widens a column: what MySQL refused once, it accepts now, and only a count on both sides says so.
Do **not** reach for `--fresh` to close a gap — it would discard verified rows to re-run a read
whose failure mode is not yet understood, where `--repair` re-reads only what is missing.

**The three deltas the audit still shows are each accounted for, and none is a defect to fix:**

- **`REFS` 118 → 116.** The two blank sub-references of the source, with no code, no title, no
  parent and no children. Skipped by design (`sub_references.reference_id` is NOT NULL).
- **`LOT` 852 → 853.** One lot *more* here: a lot created **on the web** (UUIDv7 key, no title, no
  line, a supplier company set), not something the import produced. The audit compares totals, so
  anything created here after the import reads as a surplus — the sign is what tells the two apart.
- **`METC` 3 717 → 3 716.** The one component whose line is among the 13 unreachable ones: its
  `zkf_METL` names a real line of the source, but that line's `zkf_MET` points at a métré that no
  longer exists, so the line cannot exist here and neither can its component. Structural, verified
  on the server rather than assumed — replaying `--only=METC` changes nothing.

**FileMaker error 802 is not an authentication failure.** It means the file will not open — closed
on the server, in backup, or offline — and it arrives with perfectly valid credentials. If
ShakeDesign answers 802 too, it is the server and not this file. The reader now says so instead of
pointing at the `fmrest` privilege, which is what the message used to suggest.

### Owed by ShakeDesign, after the import

When a project is deleted there, ShakeDesign must call this application to delete the métrés and
their lines. Nothing does that today, so a deleted project would leave orphans no screen can
reach. Agreed with the user, deliberately postponed until the import is done.
