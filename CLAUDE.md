# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project state

**ShakeMetre**, a quantity-survey/metré module originally built in FileMaker (`ShakeMetre.fmp12`), rebuilt here as a Laravel 13 / Inertia / Vue 3 application. It is coupled to **ShakeDesign**, which stays in FileMaker for the whole migration and is reached over the FileMaker Data API.

The migration is well underway — this is **not** a skeleton. Already built and covered by ~480 PHP tests and 29 Vitest tests:

- All 13 domain tables migrated, with UUID primary keys preserved from FileMaker.
- Eloquent models per source table; `MetreLineObserver` / `MetreLineComponentObserver` driving `RecalculateMetreTotals` and `RecalculateMetreLineQuantitiesFromComponents`.
- `ShakeDesignClient` over the Data API: project/company/contact/VAT/user lookups, project search, company list, a company's contacts through JCPYCTC, and offer / supplier-order creation.
- Auth: Breeze password login **plus** a ShakeDesign SSO ticket flow, and a `readonly` role enforced server-side.
- Screens: dashboard project search → project page (métrés + lots) → métré page → the four money views of its lines (`achats-ventes-commandes` and its three narrower cuts, one page cut by a slug); plus the metré-line grid, the METC components panel, the supplier tender comparison, and the reference catalogue on `/references`.
- The reference catalogue is wired end to end: browsed and maintained on its own screen, inserted into a métré as lines, and its three levels drive how a métré's lines are grouped, numbered and subtotalled.

Run `git log --oneline` first — the commit messages carry the reasoning behind the non-obvious decisions and are the fastest way to understand why something is the way it is.

## FileMaker migration reference

`docs/filemaker-reference/` is the authoritative source-schema documentation. Read it before generating migrations, models, or business logic for any ShakeMetre domain:

- `ShakeMetre_Analyse_et_Plan_Migration_Laravel.md` — architecture analysis and phased plan (French): the 17 source tables, FileMaker naming conventions (`zkp`, `zkf_`, `zg_`, `_Stored`, `_ae`, …), the target Eloquent mapping, the UUID strategy, the `_Stored` → Observer/queued-job pattern.
- `ShakeMetre_data_dictionary.json` — all 17 tables, every field with its real calculation formula, 91 relationships, table occurrences, 274 scripts, 128 layouts, 92 custom functions, value lists.
- `ShakeDesign_boundary_tables.json` — the 7 ShakeDesign tables ShakeMetre is coupled to.

**Critical constraint:** `OFF_Offers.zkf_MET` and `SOR_SupplierOrders.zkf_MET` in ShakeDesign store **hard references** to `MET_Metre.zkp`. `MET_Metre`, `METL_MetreLines`, `LOT_Lot`, `REF_Reference` and `METC_MetreLineComponent` must keep their existing UUIDs verbatim, or those foreign keys break silently. `MAT`, `CAT`, `CATS`, `CART`, `JCARTMAT` and `TAG` are on numeric legacy keys and free to be redesigned. `REFS` and `REFSL` are **not**, whatever the export says: their live records carry UUIDs like every other table (checked on the server — see below), and this project keeps them.

Do not port: `ZZZ_Template`, `ZSET_Settings`/`ZVAR_Variables`/`ZSTRI_Strings` (use Laravel config + a `translations` table), the `BrowserNav` module, anything under `OLD/`/`TEMP/`/`__SAVE_AR_*`/`__OLD`, the `DEV/Raw`/`DEV/Blank` layouts. Most of the 92 custom functions need no equivalent — only the FR/date and privilege logic.

### Reading the old application directly

The FileMaker ShakeMetre being replaced is **hosted on a server and readable over the Data API**
with a dedicated account. Credentials live in `.env` under `SHAKEMETRE_FM_*` and are exposed as
`config('services.shakemetre_filemaker')`.

**Never use it from `app/`.** ShakeMetre becomes purely web: its data lives in this project's
database, and nothing the application does at runtime may depend on the thing it replaces. The
access exists for one purpose — establishing how the FileMaker application behaves, so the web
version reproduces it instead of guessing. Throwaway scripts only. Both the config block and the
environment variables get deleted when the migration is done, which is also why they are absent
from `.env.example`.

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
- **Summary fields carry `calc: null`** — no aggregation operator, no source field. Their meaning is unverified unless separately confirmed.
- The 250-character calc truncation **has been fixed** by a re-export (commit `3fb3a66`). Older comments referring to truncated formulas are historical.
- `isTenderLine_b`, `Omit_b` and `TENDER_Id` are referenced by no formula at all.
- **REFS and REFSL zkp are UUIDs, not numbers.** The export types them `Number`; the live records are UUIDs at all three levels, so the migration plan's "numeric legacy keys" no longer holds for them. `REF_Reference.Code`, on the other hand, really is text in places — the first section's code is the string `"00"`, which this project's `integer` column flattens to 0.
- **A métré line does not point at the reference catalogue.** `zkf_REF` / `zkf_REFS` / `zkf_REFSL` are EMPTY on all 57 809 lines of the live file; the section lives on the line as copied values. See the settled decision below.
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

`Modal.vue` has two fixes that must not regress (commit `544de5a`): the content box carries `relative z-10`, without which the `fixed` backdrop stacks above it and swallows every click; and Escape is handled through the dialog's own `cancel` event, not a document listener, so nested modals close one at a time.

### Direction artistique

`resources/css/app.css` **is** the design system: read its header before styling anything. It carries the palette taken from shakedesign.be (declared there in `@property`), the role each colour plays, and a closed vocabulary of component classes — `.surface` / `.surface-head`, `.eyebrow` / `.field-label`, `.btn` + variants, `.badge-*`, `.data-table`, `.cell-input` (+ `.euro-suffix`), `.popover*`, `.banner*`, `.code-chip`, `.readonly-value`, `.num`.

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
- **A focused `input[type=number]` eats the mouse wheel, and it eats it onto money.** Chrome and Firefox spend a wheel tick over a focused number field incrementing it instead of scrolling, so the list appears frozen while the figure under the cursor changes and the debounced save posts it. Measured on the Achats/Ventes/Commandes view: one tick took a quantity 45 → 44 with a `PATCH {"quantity":44}`; twelve trackpad-sized deltas took another from 1 to -10. `installNumberInputWheelGuard()` in `app.js` blurs the field so the wheel falls through to the scroll container — one document listener, because the same field type carries money on five screens. `preventDefault()` is the wrong cure: it would stop the scroll as well. Reported as "the grid won't scroll when my cursor is over a Qté cell", which is why the symptom is worth recognising — the visible half is the harmless half.

## Settled decisions — do not "fix" these

Each of these looks like an inconsistency and is not. Ask before changing any of them.

- **"Commandes" means a different column on different screens.** Project page: `Tot_Sum_TotalSales_Stored`. Métré page: `Tot_Sum_TotalOrdered_Stored`. Both confirmed by the user, both commented in place.
- **"Ratio" is two different divisions, likewise.** Project page (column *and* total row): **Commandes ÷ Travaux**, i.e. `Tot_Sum_TotalSales_Stored / Tot_Sum_TotalOrdered_Stored` — read off the row's own two cells so the figure is verifiable by eye, not off `Tot_Ratio_TotalFees_Stored`, which holds the same thing but only as of the last recalculation. Métré page ("Ratio réel") and the ShakeDesign portal replica: `Metre::ratio()` = `Ratio_c` = vendu ÷ acheté. Both confirmed by the user. `Metre::ratioFromSums()` is the shared guard — empty or zero denominator yields no ratio, never 0 and never an error.
- **Achats and Vendu client share one quantity.** `PriceTotalBuy` and `PriceTotalSales` both multiply by `METL::Quantity` in the source; only the order total has its own (`QuantityOrdered`). The UI binds both inputs to `quantity` on purpose.
- **A métré's ID is `ind_project`, not its UUID.** `MET_Metre::IndProject`: the métré's number *within its project*, from 1 up, restarting at 1 for the next project. The source field is a plain Number with no calculation and no formula referencing it, so the rule comes from the user, not the export. The UUID stays in the payload because the links are keyed on it, and is never displayed.
- **A métré ID is never handed out twice, even after the métré is deleted.** People refer to a métré by it, so a spent number is spent: 1, 2, 3 minus the third means the next one is 4. That cannot be derived from the surviving rows — deleting the highest lowers their maximum — so `metre_number_sequences` keeps a per-project high-water mark that only moves up. `Metre::nextIndProject()` takes the higher of that mark and the current maximum (the mark misses rows inserted with an explicit number by an import or a test; the maximum alone is the reuse bug), inside a transaction, with a `(project_id, ind_project)` unique index as the backstop. A duplicate takes the next number rather than the original's.
- **The four `Total_*_METL_Stored` columns are the ungated set, and are written on a stated assumption.** `Sales` / `Purchase` are the only inputs of `Ratio_c`, the export gives none of the four a formula, and nothing wrote them — so the "Ratio réel" read empty and the métré page's four tiles were empty too. `RecalculateMetreTotals` fills all four from the same `PriceTotal*_noOptions_c` sums as every other total (options excluded), **ungated**, unlike their `Tot_Sum_*` counterparts. Flag the assumption if the FileMaker script meant something else.
- **Which of the two sets a screen reads is a decision, not an accident.** Métré page (the four tiles) and `Ratio_c`: the **ungated** `Total_*_METL_Stored` — it is the screen a métré is worked on, and it showed four empty tiles until *Accepté* and *Site* were both ticked, which reads as a broken page rather than as "nothing is committed yet". Project page roll-up and the ShakeDesign portal replica: the **gated** `Tot_Sum_*`, which answer "how much is agreed, how much is on site". Same sums, two questions.
- **`Date_Agreement` follows `isAccepted_b`.** Filled with today's date when the box is ticked, emptied when it is unticked, and **no memory**: re-ticking stamps today rather than restoring the old date, which described an agreement that was taken back. The field stays editable (a métré accepted last Tuesday and recorded today has to be correctable) and the stamp only fires on the transition. **Which "today"**: the page sends the *browser's* civil date (`localToday()`, never `toISOString()`, which is UTC and reads as yesterday just after midnight) — only the machine of the person ticking knows the day they are living in. `MetreController::stampAgreementDate()` keeps the same rule as a fallback for a caller that sends no date, from the server clock (`config('app.timezone')`, `APP_TIMEZONE`); a date in the request always wins.
- **The four line views are one page, cut by a slug.** `/metres/{metre}/lines/{view}` with `view` in `MetreLineDetailController::VIEWS`, which maps each slug to its money blocks (`achats-ventes` → `['achats', 'ventes']`) and is also the route whitelist — so what a view *is* is one fact, not two that can drift. The payload is identical for all four: a line carries the same fields and the same computed values everywhere, including `price_ratio`, which the narrow views simply do not draw. The page owns only how a block *looks* (`BLOCKS` in `LinesDetail.vue`, with literal Tailwind classes — a computed `bg-${tone}-50/70` would never be generated). The ratio column and the shared-quantity note appear only where both achats and ventes are on screen, because that is where they mean something. Adding a fifth cut is one line in `VIEWS`.
- **A line's section is copied onto the line, never joined.** The reference catalogue (REF → REFS → REFSL, 19/118/507 rows in the live file) is a source to copy FROM, maintained on `/references` — reached from the dashboard, three panes instead of the source's three tabs, creation as an empty row filled in afterwards (REF_New) and deletion cascading with the confirmation worded per level (REF_Delete). Deleting a catalogue entry clears the provenance keys on the lines that came from it and changes nothing else about them. A line carries `ref_code`, `ref_title`, `refs_code`, `refs_title`, `refsl_title` and `ref_order`; the foreign keys are written as provenance only and nothing reads them to display a line. That is what lets a section be renamed — or invented on the spot, which FileMaker allows — without rewriting métrés already sent to a client, and it is verified by a test. `ref_order` counts from 1 inside one `(métré, ref_code, refs_code)` group and is the third component of the printed code `MetreLine::refLineCode()` = "20.8.1" (METL::REFSL_Code_c). - **The line views are laid out like `METL_MetreComplete_List_Full`.** That layout is two leading sub-summaries over the body plus a trailing grand summary, and the web views reproduce it: a heading row per section (sub-summary by `REF_Code`) and per sub-section (by `REFS_Title`), each carrying the three money subtotals, then the lines, then the métré's total. Headings exist only for groups that have a line, because a sub-summary prints only when a record triggers it. Subtotals are computed in the page, not on the server: the cells already recompute as you type, so a server-side subtotal would be the one stale figure on screen.
- **`PriceTotal*All_c` per line, `zsm_SumTotal*_noOptions` per group.** The source's body column has no option guard - an option line shows what it would cost - and only the subtotals and the métré's stored totals exclude it. Showing 0,00 € on an option line, which this application used to do, hides the number the option exists to state. Both are in the payload (`price_total_*_all` and `price_total_*_no_options`); the option line's own total is greyed and italic so the difference is visible.
- **A line can be filed under a sub-section that exists in no catalogue.** METL_NewFromREF's second branch: a code and a title typed in the section heading (`zg_REF_SelectedNewCode` / `zg_REF_SelectedNewTitle` there, the same two fields in the same place here). Which is the other half of why a line's section is a copy and not a link — there is nothing to link to. Those four columns are writable at creation (`StoreMetreLineRequest`) and never afterwards: re-filing a line would change the printed code of something already on a document, and the source does not offer it either.
- Lines are listed in section order — METL_Sort as the working screens call it: `ref_code, refs_code, refs_title, ref_order`, ascending with no special treatment of empties, so a sectionless line leads (an empty number sorts before 0 in FileMaker). `sort_order` only breaks ties. The source's two other sort flavours are deliberately not reproduced: `isOption_b` first belongs to the client offer, the accounting and the printouts, and the TAG_Choice1/2 variant depends on `MET::Sort_OrderTags`, part of the unresolved Lots/Tags switch. Inserting from the catalogue (METL_New_Multi) copies the item's title, its unit and its price — into **`price_buy`**: the catalogue is a purchase-price book and nothing in it feeds the client price. Titles are copied in the **métré's** language (the source used the interface's) with a fallback (the source blanks the title when the translation is missing, and `Title_NL` is empty on all 644 catalogue rows).
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

- **No test may touch a real FileMaker server.** `Http::fake()` always. Note `Http::fake()` stubs *stack* — the first match keeps winning; use `Http::sequence()` for successive responses.
- Money and formula work gets **numeric** tests with hand-computed expected values, not just shape assertions.
- Watch out for helper names that collide with Laravel's `TestCase`: `session()` and `component()` are taken. This has cost time twice.
- Verify UI work **in a real browser**, not only through the suite. Several real bugs (the modal backdrop, the stale working copy, a blanked column, the readonly account) passed every PHP test and were only visible when driven. Install Playwright + Chromium in a scratch directory, point `SHAKEDESIGN_HOST` at a small fake Data API server if the flow reads ShakeDesign, drive it, **look at the screenshot**, and check `console --errors`. Restore `.env` and delete any seeded demo rows afterwards.
- When a browser check disagrees with the suite, suspect the check first — several "bugs" were faulty selectors (input `value` is not in `textContent`; `:has-text("Livré")` also matches "Non livré").

## Workflow

- **Never guess a business formula.** If the export is unclear or silent, list what you found and ask. There is money behind these.
- Commit in small, reversible steps, split by concern — bug fixes to shared infrastructure separate from feature work. Verify each intermediate state (`git stash --include-untracked --keep-index`, run the suite) rather than reconstructing plausible history.
- Commit messages carry the *reasoning*, especially for anything counter-intuitive or deliberately divergent from the source.
- Only commit when asked.

## Critical test

Any migration/seeder touching `MET_Metre`, `METL_MetreLines`, `LOT_Lot`, `REF_Reference` or `METC_MetreLineComponent` must have a test asserting a given source `zkp` round-trips unchanged. See `tests/Feature/ZkpPreservationTest.php`.

## Open items

- **SOR → FileMaker link.** The Achats/Ventes/Commandes view shows the linked supplier order's title but cannot open it. Needs three things confirmed: the target file, the script name, and the parameter format. `SOR_GoTo` exists in the export but only as a name, and it is in the ShakeMetre file while the SOR lives in ShakeDesign.
- **The Tags/Lots switch.** Lots is fully wired. Tags shows `METL::tag1` read-only, because METL carries `tag1`/`tag2` *and* there is a separate `TAG` table keyed to the métré, and nothing says which the switch should drive.
- **Documents:** all seven are inert rows tagged `Bientôt`, same reasoning as the views.
- **`Nouvelle offre client`** on the métré page is a disabled button tagged `Bientôt`; `ShakeDesignClient::createOffer()` exists and is tested but nothing calls it yet.
- **Supplier company filtering** in the picker is limited to `isSupplier_b` + `isActive_b`. No company in ShakeDesign currently has `isActive_b = 0`, so that half of the filter has never been exercised against real data.
- **The profile and password screens are still in Breeze's English** ("Profile Information", "Save", "Delete Account"). They now carry the DA but not the language of the rest of the application. `Welcome.vue`, Breeze's landing page on `/`, is untouched beyond the ramp remap.
- **Four things the FileMaker line list does and this one does not yet.** Drag-and-drop reordering inside a sub-section (`zg_DRAG_N_DROP` + `Order`, which `METL_Reorder` renumbers 1..n); selection of a whole section or sub-section (`METL_Select_REF` / `_REFS`, which keep tri-state group flags in `MET::zkm_REF_Selection_g`); pushing a line's unit price back into the catalogue (`METL_Update_REFSL_Price`, confirmation dialog included — it writes `REFSL::Price`, the direction one would not guess); and the two tag levels of the sort, which wait on the Lots/Tags switch above.
- **`REF_Reference.Code` is text in the source and an integer here.** The live catalogue's first section is the string `"00"`, which this project's column flattens to `0` — the line codes it feeds are numeric anyway (`0.0.1`), but the catalogue screen shows `0` where FileMaker shows `00`.
- **The 57 809 métré lines are not imported yet.** The mapping is settled now, which was what it waited on: `REFSL_Title` → `refsl_title`, `Description` → `description`, the four section columns copied as they stand, `Order` → `ref_order`. The catalogue itself (19/118/507) can be re-read from the server whenever needed.
- **The breadcrumb on the Achats/Ventes/Commandes view stops at the métré.** The project's *name* lives in ShakeDesign, and fetching it over the Data API for a single label would put a remote call on the heaviest screen of the application. `metre.project_id` is in the payload if that trade-off is ever revisited.
