<?php

use App\Http\Controllers\Auth\SsoConsumeController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LotController;
use App\Http\Controllers\MetreController;
use App\Http\Controllers\MetreLineComponentController;
use App\Http\Controllers\MetreLineController;
use App\Http\Controllers\MetreLineDetailController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectSearchController;
use App\Http\Controllers\ShakeDesignLookupController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
    ]);
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Backs the dashboard's search box - a plain read against ShakeDesign, open to a
    // readonly account like any other lookup.
    Route::get('/api/projects/search', ProjectSearchController::class)->name('projects.search');
});

/*
| ShakeDesign lookups behind the company / contact pickers. Reads only, so no role.write -
| choosing a value is a write, but that goes through PATCH /api/lots/{lot}, which is gated.
*/
Route::middleware('auth')->group(function () {
    Route::get('/api/shakedesign/companies', [ShakeDesignLookupController::class, 'companies'])
        ->name('shakedesign.companies');

    Route::get('/api/shakedesign/companies/{company}/contacts', [ShakeDesignLookupController::class, 'contacts'])
        ->name('shakedesign.company-contacts');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');

    // A readonly account may look at its profile but not rewrite or delete it.
    Route::middleware('role.write')->group(function () {
        Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    });
});

/*
| One métré's own page: header fields, totals, and the actions on the métré as a whole.
| Duplicating and deleting are redirects (Inertia visits), the field edits are JSON under
| /api like every other debounced editor here.
|
| Declared before /metres/{metre}/lines only for readability; the two cannot collide, since
| `lines` is a literal segment and this route has none.
*/
Route::middleware('auth')->group(function () {
    Route::get('/metres/{metre}', [MetreController::class, 'show'])->name('metres.show');

    Route::middleware('role.write')->group(function () {
        Route::patch('/api/metres/{metre}', [MetreController::class, 'update'])->name('metres.update');
        Route::post('/metres/{metre}/duplicate', [MetreController::class, 'duplicate'])->name('metres.duplicate');
        Route::delete('/metres/{metre}', [MetreController::class, 'destroy'])->name('metres.destroy');
    });
});

/*
| The "Achats — Ventes — Commandes" view of a métré's lines. Cell edits go through the existing
| PATCH /api/metre-lines/{id} below; only create/duplicate/delete are new here.
*/
Route::middleware('auth')->group(function () {
    Route::get('/metres/{metre}/lines/achats-ventes-commandes', [MetreLineDetailController::class, 'show'])
        ->name('metres.lines.detail');

    Route::middleware('role.write')->group(function () {
        Route::post('/api/metres/{metre}/lines', [MetreLineDetailController::class, 'store'])
            ->name('metre-lines.store');

        Route::post('/api/metre-lines/{metreLine}/duplicate', [MetreLineDetailController::class, 'duplicate'])
            ->name('metre-lines.duplicate');

        Route::delete('/api/metre-lines/{metreLine}', [MetreLineDetailController::class, 'destroy'])
            ->name('metre-lines.destroy');
    });
});

/*
| The metre grid and its cell-update endpoint.
|
| The PATCH deliberately lives here rather than in routes/api.php: it is called by the grid
| on behalf of a logged-in human, so it needs the session and CSRF protection of the `web`
| group. routes/api.php is stateless and reserved for the machine-to-machine ShakeDesign
| integration. The URI keeps the /api prefix because it returns JSON rather than an Inertia
| response, but the middleware stack is the web one.
|
| `role.write` refuses the readonly role. Enforced here, not only hidden in the UI.
*/
Route::middleware('auth')->group(function () {
    Route::get('/metres/{metre}/lines', [MetreLineController::class, 'index'])
        ->name('metres.lines.index');

    Route::patch('/api/metre-lines/{metreLine}', [MetreLineController::class, 'update'])
        ->middleware('role.write')
        ->name('metre-lines.update');

    // Components of a metre line - the METC portal. Reading is open to a readonly account;
    // every write goes through role.write like the line endpoint, because changing a component
    // rewrites the parent line's quantities.
    Route::get('/api/metre-lines/{metreLine}/components', [MetreLineComponentController::class, 'index'])
        ->name('metre-line-components.index');

    Route::middleware('role.write')->group(function () {
        Route::post('/api/metre-lines/{metreLine}/components', [MetreLineComponentController::class, 'store'])
            ->name('metre-line-components.store');

        Route::patch('/api/metre-line-components/{metreLineComponent}', [MetreLineComponentController::class, 'update'])
            ->name('metre-line-components.update');

        Route::delete('/api/metre-line-components/{metreLineComponent}', [MetreLineComponentController::class, 'destroy'])
            ->name('metre-line-components.destroy');
    });
});

/*
| A project's own métrés and lots. `project` is a bare ShakeDesign zkp, not a local model -
| PRJ_Projects has no local table - so there is no route-model binding to reject an unknown
| one with; see ProjectController for why that is fine here.
|
| storeMetre redirects back to the page (an Inertia form post, validation errors flash to
| the session) while storeLot answers JSON (the "manage lots" panel appends the row itself
| without a page visit) - which is why the latter lives under /api: this app renders
| validation failures as JSON only for that prefix (see bootstrap/app.php,
| shouldRenderJsonWhen), the same rule every other JSON endpoint here follows.
*/
Route::middleware('auth')->group(function () {
    Route::get('/projects/{project}', [ProjectController::class, 'show'])
        ->name('projects.show');

    Route::middleware('role.write')->group(function () {
        Route::post('/projects/{project}/metres', [ProjectController::class, 'storeMetre'])
            ->name('projects.metres.store');

        Route::post('/api/projects/{project}/lots', [ProjectController::class, 'storeLot'])
            ->name('projects.lots.store');
    });
});

/*
| Supplier tender comparison for one lot. Reading (the page itself and the scoring refresh)
| is open to a readonly account like the rest of the app; the weighting patch and the award
| both go through role.write, same guarantee as the grid.
*/
Route::middleware('auth')->group(function () {
    Route::get('/lots/{lot}/tender-comparison', [LotController::class, 'show'])
        ->name('lots.tender-comparison');

    Route::get('/api/lots/{lot}/tender-scoring', [LotController::class, 'scoring'])
        ->name('lots.tender-scoring');

    Route::middleware('role.write')->group(function () {
        Route::patch('/api/lots/{lot}', [LotController::class, 'update'])
            ->name('lots.update');

        Route::delete('/api/lots/{lot}', [LotController::class, 'destroy'])
            ->name('lots.destroy');

        Route::post('/api/lots/{lot}/tender-award', [LotController::class, 'award'])
            ->name('lots.tender-award');
    });
});

/*
| Consumes a one-time SSO ticket minted by POST /api/sso/tickets, so a user already
| authenticated in ShakeDesign lands here signed in. Deliberately outside the `auth` group -
| it is what creates the session - and throttled, since the token is the only secret.
*/
Route::get('/sso/consume/{token}', [SsoConsumeController::class, '__invoke'])
    ->middleware('throttle:sso-consume')
    ->name('sso.consume');

require __DIR__.'/auth.php';
