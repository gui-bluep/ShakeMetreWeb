<?php

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
});

require __DIR__.'/auth.php';
