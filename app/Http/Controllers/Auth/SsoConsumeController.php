<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Sso\SsoTicket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Redeems a one-time SSO ticket and signs the user in.
 *
 * Every failure - unknown token, expired, already consumed, user since deleted - takes the
 * same silent path to /login with no message. Saying "this link has expired" would confirm
 * that the token once existed, which turns a guess into information; and there is nothing the
 * visitor could usefully do differently anyway, since they cannot mint a ticket themselves.
 */
class SsoConsumeController extends Controller
{
    public function __invoke(Request $request, string $token, SsoTicket $tickets): RedirectResponse
    {
        $userId = $tickets->consume($token);

        if ($userId === null) {
            return redirect()->route('login');
        }

        $user = User::find($userId);

        if ($user === null) {
            return redirect()->route('login');
        }

        // The web guard by name, not the default one: this route exists to open a browser
        // session, and Auth::login() would follow whatever guard happens to be default.
        Auth::guard('web')->login($user);

        // The session id changes on login, so a stolen pre-login session cannot be reused.
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }
}
