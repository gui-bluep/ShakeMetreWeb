# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project state

**ShakeMetre**, a quantity-survey/metré module originally built in FileMaker (`ShakeMetre.fmp12`), rebuilt here as a Laravel 13 / Inertia / Vue 3 application. It is coupled to **ShakeDesign**, which stays in FileMaker for the whole migration and is reached over the FileMaker Data API.

The migration is well underway — this is **not** a skeleton. Already built and covered by ~420 PHP tests and 25 Vitest tests:

- All 13 domain tables migrated, with UUID primary keys preserved from FileMaker.
- Eloquent models per source table; `MetreLineObserver` / `MetreLineComponentObserver` driving `RecalculateMetreTotals` and `RecalculateMetreLineQuantitiesFromComponents`.
- `ShakeDesignClient` over the Data API: project/company/contact/VAT/user lookups, project search, company list, a company's contacts through JCPYCTC, and offer / supplier-order creation.
- Auth: Breeze password login **plus** a ShakeDesign SSO ticket flow, and a `readonly` role enforced server-side.
- Screens: dashboard project search → project page (métrés + lots) → métré page → line views; plus the metré-line grid, the METC components panel, and the supplier tender comparison.

Run `git log --oneline` first — the commit messages carry the reasoning behind the non-obvious decisions and are the fastest way to understand why something is the way it is.

## FileMaker migration reference

`docs/filemaker-reference/` is the authoritative source-schema documentation. Read it before generating migrations, models, or business logic for any ShakeMetre domain:

- `ShakeMetre_Analyse_et_Plan_Migration_Laravel.md` — architecture analysis and phased plan (French): the 17 source tables, FileMaker naming conventions (`zkp`, `zkf_`, `zg_`, `_Stored`, `_ae`, …), the target Eloquent mapping, the UUID strategy, the `_Stored` → Observer/queued-job pattern.
- `ShakeMetre_data_dictionary.json` — all 17 tables, every field with its real calculation formula, 91 relationships, table occurrences, 274 scripts, 128 layouts, 92 custom functions, value lists.
- `ShakeDesign_boundary_tables.json` — the 7 ShakeDesign tables ShakeMetre is coupled to.

**Critical constraint:** `OFF_Offers.zkf_MET` and `SOR_SupplierOrders.zkf_MET` in ShakeDesign store **hard references** to `MET_Metre.zkp`. `MET_Metre`, `METL_MetreLines`, `LOT_Lot`, `REF_Reference` and `METC_MetreLineComponent` must keep their existing UUIDs verbatim, or those foreign keys break silently. `MAT`, `CAT`, `CATS`, `CART`, `JCARTMAT`, `TAG`, `REFS`, `REFSL` are on numeric legacy keys and are free to be redesigned.

Do not port: `ZZZ_Template`, `ZSET_Settings`/`ZVAR_Variables`/`ZSTRI_Strings` (use Laravel config + a `translations` table), the `BrowserNav` module, anything under `OLD/`/`TEMP/`/`__SAVE_AR_*`/`__OLD`, the `DEV/Raw`/`DEV/Blank` layouts. Most of the 92 custom functions need no equivalent — only the FR/date and privilege logic.

### What the export does and does not tell you

Hard-won and worth knowing before trusting it:

- **Script bodies are absent** (`Has_DDR_INFO="False"`). You get script *names* only. 274 of them.
- **Summary fields carry `calc: null`** — no aggregation operator, no source field. Their meaning is unverified unless separately confirmed.
- The 250-character calc truncation **has been fixed** by a re-export (commit `3fb3a66`). Older comments referring to truncated formulas are historical.
- `isTenderLine_b`, `Omit_b` and `TENDER_Id` are referenced by no formula at all.
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

## Settled decisions — do not "fix" these

Each of these looks like an inconsistency and is not. Ask before changing any of them.

- **"Commandes" means a different column on different screens.** Project page: `Tot_Sum_TotalSales_Stored`. Métré page: `Tot_Sum_TotalOrdered_Stored`. Both confirmed by the user, both commented in place.
- **Achats and Vendu client share one quantity.** `PriceTotalBuy` and `PriceTotalSales` both multiply by `METL::Quantity` in the source; only the order total has its own (`QuantityOrdered`). The UI binds both inputs to `quantity` on purpose.
- **The line ratio is computed, not read.** `price_sales / price_buy`, rounded to 2, null when either price is absent or the purchase price is 0. It deliberately replaces the stored `METL::Ratio` column, which nothing in the export claims to maintain. The column is left untouched in the database.
- **Active/inactive was dropped from the dashboard.** `PRJ_Projects.isActive_b` is not on `API_PRJ`; coercing its absence to `false` made every project claim to be inactive.
- **`bestPriceAmongSuppliers()` ignores `is_tender_line_b`.** The source guard reads `Case ( isTenderLine_b ; 0 ; _min )`, which literally zeroes the benchmark for exactly the lines it exists to compare. Filtering happens at the query that selects the lines, never inside the price calculation.
- **Only the METL final-score formula is implemented.** `LOT_Lot::TENDER_Supp1_Total_cU` has its weighted price term commented out and scores supplier 1 on a different scale from the other four. Supp2–5 agree with the METL formula.
- **A duplicated métré keeps `isStatus_Site_b` but not `isAccepted_b`.** Site is an internal status; accepted is a claim about a client having agreed. Consequence, pinned by a test: "achats" on a fresh duplicate reads empty until it is accepted, because `Tot_Sum_TotalBuy` is gated on acceptance.
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
- **Métré views not yet built:** Achats — Ventes, Achats — Commandes, Ventes. The three render as disabled buttons on the métré page.
- **Documents:** all seven buttons are disabled placeholders.
- **`Nouvelle offre client`** on the métré page is a disabled placeholder; `ShakeDesignClient::createOffer()` exists and is tested but nothing calls it yet.
- **Supplier company filtering** in the picker is limited to `isSupplier_b` + `isActive_b`. No company in ShakeDesign currently has `isActive_b = 0`, so that half of the filter has never been exercised against real data.
