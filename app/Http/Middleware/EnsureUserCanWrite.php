<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses the `readonly` role any request that could change something.
 *
 * Registered on the routes that write rather than inferred from the HTTP verb, so adding a
 * write route is a deliberate act: a new POST that forgets this middleware is visible in the
 * route list, whereas a verb-sniffing global middleware would quietly cover it and give a
 * false sense of completeness.
 *
 * The grid also hides its inputs from a readonly user, but that is presentation. This is the
 * guarantee.
 */
class EnsureUserCanWrite
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->canWrite()) {
            abort(403, 'Votre compte est en lecture seule.');
        }

        return $next($request);
    }
}
