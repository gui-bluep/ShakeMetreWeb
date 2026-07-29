<?php

use App\Http\Controllers\Auth\SsoConsumeController;
use App\Http\Controllers\MetreLineComponentController;
use App\Http\Controllers\MetreLineController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
    ]);
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', fn () => Inertia::render('Dashboard'))->name('dashboard');
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
| Consumes a one-time SSO ticket minted by POST /api/sso/tickets, so a user already
| authenticated in ShakeDesign lands here signed in. Deliberately outside the `auth` group -
| it is what creates the session - and throttled, since the token is the only secret.
*/
Route::get('/sso/consume/{token}', [SsoConsumeController::class, '__invoke'])
    ->middleware('throttle:sso-consume')
    ->name('sso.consume');

require __DIR__.'/auth.php';
