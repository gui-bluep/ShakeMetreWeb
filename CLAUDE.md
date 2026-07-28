# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project state

This is a fresh Laravel 13 skeleton (PHP ^8.3) at the very start of a migration: **ShakeMetre**, a quantity-survey/metré module currently built in FileMaker (`ShakeMetre.fmp12`), is being rebuilt here as a Laravel application. It is being coupled to a separate existing app, **ShakeDesign**, which stays in FileMaker. Almost no application code exists yet beyond the default Laravel install (`User` model, default `Controller`, default `AppServiceProvider`, default `welcome` view/route). Expect to be building models, migrations, and controllers from scratch guided by the FileMaker schema reference below — don't assume undocumented conventions exist yet.

## FileMaker migration reference

`docs/filemaker-reference/` is the authoritative source-schema documentation for this migration. Read it before generating migrations, models, or business logic for any ShakeMetre domain:

- `ShakeMetre_Analyse_et_Plan_Migration_Laravel.md` — full architecture analysis and phased migration plan (in French): the 17 source FileMaker tables and their roles, FileMaker naming conventions (`zkp`, `zkf_`, `zg_`, `_Stored`, `_ae`, etc.), the target Eloquent table mapping, the UUID primary-key strategy, and the `_Stored`-field → Observer/queued-job pattern.
- `ShakeMetre_data_dictionary.json` — complete data dictionary: all 17 tables, every field (including real calculation formulas, not summaries), 91 relationships, table occurrences, 274 scripts, 128 layouts, 92 custom functions, value lists.
- `ShakeDesign_boundary_tables.json` — the 7 ShakeDesign tables coupled to ShakeMetre (Projects, Offers, Companies, Contacts, JoinCompaniesContacts, SupplierOrders, Values), with all their fields.

Critical constraint documented there: `OFF_Offers.zkf_MET` and `SOR_SupplierOrders.zkf_MET` in ShakeDesign store **hard references** to `MET_Metre.zkp`. Tables already using text/UUID-style primary keys (`MET_Metre`, `METL_MetreLines`, `LOT_Lot`, `REF_Reference`, `METC_MetreLineComponent`) must have their existing UUID values preserved verbatim during data migration, or those stored foreign keys in ShakeDesign break silently. Tables still on numeric legacy keys (`MAT`, `CAT`, `CATS`, `CART`, `JCARTMAT`, `TAG`, `REFS`, `REFSL`) have no such constraint and are free to be redesigned.

Do not port these FileMaker artifacts: `ZZZ_Template`, `ZSET_Settings`/`ZVAR_Variables`/`ZSTRI_Strings` (replace with Laravel config + a `translations` table), the `BrowserNav` module (replaced by native Laravel/JS routing), anything under `OLD/`/`TEMP/`/`__SAVE_AR_*`/`__OLD` script or layout naming, and the `DEV/Raw`/`DEV/Blank` debug layouts. Most of the 92 custom functions need no equivalent (Laravel/Collections/`Str`/Eloquent/Carbon already cover them) — only the FR/date and privilege business logic needs re-implementing, not translating line-by-line.

When starting a new functional domain (MetreLines, then Lots, then Tender Process, etc.), re-read the relevant sections of the JSON dictionary rather than relying on conversation memory — the plan document explicitly recommends one conversation per domain to avoid context dilution.

## Commands

```bash
composer install                 # PHP dependencies
npm install                      # JS dependencies

php artisan key:generate         # generate APP_KEY (.env)
php artisan migrate              # run database migrations

composer run dev                 # run server + queue listener + pail logs + vite, concurrently
php artisan serve                # Laravel dev server only
npm run dev                      # Vite dev server only
npm run build                    # production frontend build

composer test                    # clears config cache, then runs php artisan test
php artisan test                 # run full test suite
php artisan test --filter=NameOfTest   # run a single test (method or class name)
php artisan test tests/Feature/ExampleTest.php   # run a single test file

vendor/bin/pint                  # format PHP code (Laravel Pint)
```

Default local DB is SQLite (`DB_CONNECTION=sqlite`, file at `database/database.sqlite`). Tests run against an in-memory SQLite DB (`phpunit.xml`), regardless of local `.env` settings.

## Architecture

Standard Laravel 13 structure — no custom architectural layers yet:

- `app/Models`, `app/Http/Controllers`, `app/Providers` — currently only the framework defaults.
- `database/migrations`, `database/factories`, `database/seeders` — currently only the default `users`/`cache`/`jobs` tables.
- `routes/web.php` — single default `/` route; `routes/console.php` for Artisan commands.
- Frontend: Tailwind CSS v4 (via `@tailwindcss/vite`) + Vite (`vite.config.js`), assets under `resources/`.
- Tests: PHPUnit via `tests/Unit` and `tests/Feature`, `Tests\TestCase` base class, Pest is not installed.

As the migration progresses, expect the target architecture (per the migration plan) to include: Eloquent models per FileMaker table mapped under `app/Models`, a `MetreLineObserver` recalculating `_Stored` aggregate fields via a queued job (`RecalculateMetreTotals`) rather than at request time, and a `ShakeDesignClient` service (`app/Services/ShakeDesign`) wrapping the FileMaker Data API for cross-system reads/writes (projects, companies, contacts, VAT values, offer/supplier-order creation) — since ShakeDesign remains a separate FileMaker application throughout.
