<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * La racine de l'application mène au tableau de bord, jamais à la page d'accueil de Breeze.
 *
 * Décidé avec l'utilisateur : ShakeMetre est un outil interne, et le Welcome de Laravel n'y sert
 * personne. La protection reste entière - on est renvoyé vers `/dashboard`, donc vers `/login`
 * pour qui n'est pas connecté, exactement comme si on avait demandé le tableau de bord.
 *
 * Pourquoi un middleware GLOBAL et pas seulement une route de redirection : sous un sous-chemin,
 * la racine n'est pas routable du tout. `CompiledRouteCollection::requestWithoutTrailingSlash()`
 * retire la barre finale de REQUEST_URI sans tenir compte du chemin de base, donc `/metre/`
 * devient `/metre`, dont le chemin applicatif est vide ; le matcher compilé ne trouve rien, la
 * reprise ne couvre que les routes non compilées, et Laravel finit par répondre « 405 Method Not
 * Allowed, Supported methods: HEAD » - le verbe demandé étant exclu de sa recherche des autres
 * verbes. Un middleware global s'exécute AVANT le routage : c'est le seul endroit d'où l'on
 * puisse traiter une URL que le routeur n'atteint pas.
 *
 * Trois conditions sont nécessaires à ce bug, ce qui explique qu'il ne se soit vu qu'en
 * production : un sous-chemin, une barre finale, et le cache de routes - que `artisan optimize`
 * met en place au déploiement et qu'on n'a pas en développement. Seule la racine est touchée :
 * `/metre/references/` se réduit correctement en `/references`.
 */
class RedirectRootToDashboard
{
    public function handle(Request $request, Closure $next): Response
    {
        // Le chemin applicatif, pas REQUEST_URI : celui-ci porte la chaîne de requête, et
        // `/metre/?x=1` tombe dans le même trou que `/metre/`.
        if ($request->getPathInfo() === '/') {
            return redirect()->route('dashboard');
        }

        return $next($request);
    }
}
