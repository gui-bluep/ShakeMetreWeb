<?php

use App\Http\Controllers\Api\ProjectMetreController;
use App\Http\Controllers\Api\SsoTicketController;
use Illuminate\Support\Facades\Route;

/*
| Machine-to-machine only. Tokens are minted by `php artisan shakedesign:issue-token`
| and carry a single ability, so a leaked token can do exactly one thing - it cannot write,
| and it cannot reach any route added later unless that route explicitly grants its ability.
|
| The two abilities are deliberately separate. Reading portal rows and minting a login are not
| comparable powers, so `metres:read` must never open the SSO endpoint: whoever holds the
| portal token must not be able to sign in as an arbitrary user.
|
| The default `GET /api/user` route from `install:api` was removed: it echoes the
| authenticated model, which is needless surface for a machine integration.
*/
Route::middleware(['auth:sanctum', 'abilities:'.ProjectMetreController::ABILITY])
    ->get('/projects/{zkp}/metres', [ProjectMetreController::class, 'index'])
    ->name('api.projects.metres.index');

Route::middleware([
    'auth:sanctum',
    'abilities:'.SsoTicketController::ABILITY,
    'throttle:sso-issue',
])->post('/sso/tickets', [SsoTicketController::class, 'store'])
    ->name('api.sso.tickets.store');
