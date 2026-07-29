<?php

use App\Http\Controllers\MetreLineController;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
| The metre grid and its cell-update endpoint.
|
| The PATCH deliberately lives here rather than in routes/api.php: it is called by the
| grid on behalf of a logged-in human, so it needs the session and CSRF protection of the
| `web` group. routes/api.php is stateless and reserved for the machine-to-machine
| ShakeDesign integration. The URI keeps the /api prefix because it returns JSON rather
| than an Inertia response, but the middleware stack is the web one.
*/
/*
| Local-only stand-in for the login screen this application does not have yet.
|
| It signs in a dedicated dev account (created on first use) and returns you to the page you
| were trying to reach. Registered only when APP_ENV=local, so it cannot exist in staging or
| production - but delete it the moment real authentication lands, because it grants a
| session with no credentials at all.
*/
if (app()->environment('local')) {
    Route::get('/dev-login', function () {
        $user = User::firstOrCreate(
            ['email' => 'dev@shakemetre.local'],
            ['name' => 'Dev', 'password' => Hash::make('password')],
        );

        Auth::login($user);

        // The auth middleware stored where you were headed, so go back there.
        return redirect()->intended('/');
    })->name('dev-login');
}

Route::middleware('auth')->group(function () {
    Route::get('/metres/{metre}/lines', [MetreLineController::class, 'index'])
        ->name('metres.lines.index');

    Route::patch('/api/metre-lines/{metreLine}', [MetreLineController::class, 'update'])
        ->name('metre-lines.update');
});
