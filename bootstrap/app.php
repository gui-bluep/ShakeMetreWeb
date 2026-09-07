<?php

use App\Http\Middleware\EnsureUserCanWrite;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RedirectRootToDashboard;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Global, et `prepend` : il doit s'exécuter avant le routage, qui est justement ce qui
        // échoue sur la racine d'une installation en sous-chemin. Voir la classe.
        $middleware->prepend(RedirectRootToDashboard::class);

        $middleware->alias([
            // Sanctum ships these but registers no alias of its own.
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
            // Refuses the readonly role; applied per write route, see routes/web.php.
            'role.write' => EnsureUserCanWrite::class,
        ]);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
