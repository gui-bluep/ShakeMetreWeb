<?php

use App\Http\Controllers\Api\ProjectMetreController;
use Illuminate\Support\Facades\Route;

/*
| Machine-to-machine only. Tokens are minted by `php artisan shakedesign:issue-token`
| and carry a single ability, so a leaked token can read metre portal rows and nothing
| else - it cannot write, and it cannot reach any route added later unless that route
| explicitly grants its ability.
|
| The default `GET /api/user` route from `install:api` was removed: it echoes the
| authenticated model, which is needless surface for a machine integration.
*/
Route::middleware(['auth:sanctum', 'abilities:'.ProjectMetreController::ABILITY])
    ->get('/projects/{zkp}/metres', [ProjectMetreController::class, 'index'])
    ->name('api.projects.metres.index');
