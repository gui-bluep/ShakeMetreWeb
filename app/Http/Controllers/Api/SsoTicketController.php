<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ShakeDesign\ShakeDesignApiException;
use App\Services\ShakeDesign\ShakeDesignClient;
use App\Services\Sso\SsoTicket;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Mints a one-time login URL for a user already authenticated inside ShakeDesign.
 *
 * ShakeDesign calls this with a machine token, gets back a URL, and sends its user there.
 * Authorisation is checked against ShakeDesign itself on every call - the account must exist
 * and be active there - so revoking someone in FileMaker denies them here immediately,
 * without a synchronisation step that could lag.
 */
class SsoTicketController extends Controller
{
    /**
     * Its own ability, deliberately not metres:read. Issuing a login is a far stronger power
     * than reading portal rows, so the token that can do it is a separate token: leaking the
     * portal one must not let anyone log in as an arbitrary user.
     */
    public const ABILITY = 'sso:issue';

    public function store(Request $request, ShakeDesignClient $client, SsoTicket $tickets): JsonResponse
    {
        $validated = $request->validate([
            'shakedesign_user_id' => ['required', 'string', 'max:255'],
        ]);

        $zkp = $validated['shakedesign_user_id'];

        try {
            $account = $client->findUserByZkp($zkp);
        } catch (ShakeDesignApiException $e) {
            // Cannot establish who this is, so issue nothing. 502 rather than 403: the caller
            // did nothing wrong and a retry may well succeed.
            abort(502, 'ShakeDesign est injoignable, aucun ticket émis.');
        }

        abort_if($account === null || ! $this->isActive($account), 403, 'Compte ShakeDesign inactif ou inconnu.');

        $user = $this->resolveUser($zkp, $account);

        return response()->json([
            'login_url' => url('/sso/consume/'.$tickets->issueFor($user->getKey())),
            'expires_in' => SsoTicket::TTL_SECONDS,
        ]);
    }

    /**
     * Both flags must be true. They are separate in ShakeDesign - one disables the person,
     * the other the login - and either being false means no access, so this reads them as an
     * AND rather than trusting whichever happens to be present.
     *
     * A missing flag counts as inactive: an API layout that forgot to expose them must fail
     * closed, not hand out logins.
     *
     * @param  array<string, mixed>  $account
     */
    private function isActive(array $account): bool
    {
        foreach (['isActiveAccount_b', 'isActiveUser_b'] as $flag) {
            if (! array_key_exists($flag, $account) || ! filter_var($account[$flag], FILTER_VALIDATE_BOOLEAN)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The address to store, synthesised when ShakeDesign holds none.
     *
     * An empty Mail_1 used to refuse the login outright, which was wrong: access is governed
     * by isActiveAccount_b and isActiveUser_b, and by nothing else. The address is a
     * convenience here - display today, mail notifications later - so a legitimate active
     * account without one must still get in.
     *
     * Derived from the zkp so it is stable across logins and unique per ShakeDesign account,
     * which matters because users.email carries a unique index: two addressless accounts must
     * not collide on a shared placeholder. If Mail_1 is filled in later, the next login
     * overwrites the synthetic address with the real one, and vice versa.
     *
     * Mail_2 is deliberately ignored - it is a secondary address in ShakeDesign, not a
     * fallback for the primary one, and silently promoting it could mail the wrong person.
     *
     * @param  array<string, mixed>  $account
     */
    private function email(string $zkp, array $account): string
    {
        $mail = trim((string) ($account['Mail_1'] ?? ''));

        return $mail !== '' ? $mail : $zkp.'@shakedesign.local';
    }

    /**
     * Upserts the Laravel user for this ShakeDesign account: created on first arrival, and
     * re-synchronised from ShakeDesign on every single call thereafter.
     *
     * ShakeDesign stays the live source of truth for identity and privilege - a rename or a
     * demotion there takes effect at the next login rather than being frozen at first sight.
     * The only attributes that survive untouched are the ones ShakeDesign knows nothing about.
     *
     * Matched on shakedesign_user_id, never on email: an email can change in ShakeDesign, and
     * matching on it would either lose the link or let one account claim another's row.
     *
     * Written as firstOrNew + forceFill rather than User::updateOrCreate() on purpose:
     * updateOrCreate mass-assigns, and `role` and `shakedesign_user_id` are deliberately
     * outside $fillable so no request payload can reach them - so the idiomatic call would
     * silently discard exactly the two attributes that matter most here.
     *
     * @param  array<string, mixed>  $account
     */
    private function resolveUser(string $zkp, array $account): User
    {
        $name = trim(trim((string) ($account['NameFirst'] ?? '')).' '.trim((string) ($account['NameLast'] ?? '')));
        $email = $this->email($zkp, $account);

        $user = User::firstOrNew(['shakedesign_user_id' => $zkp]);

        // Overwritten on every call - this is the resynchronisation.
        $user->forceFill([
            'shakedesign_user_id' => $zkp,
            'email' => $email,
            'name' => $name !== '' ? $name : $email,
            'role' => UserRole::fromPrivilegeSet($account['PrivilegeSet'] ?? null),
        ]);

        // Set once, at creation only. Re-running these on every login would reset the
        // password of a user who also signs in through Breeze, and re-stamp a verification
        // date that is already true.
        if (! $user->exists) {
            // Password login is Breeze's business; an SSO-created account gets an unusable one
            // rather than a guessable placeholder.
            $user->password = Hash::make(Str::random(64));
            $user->email_verified_at = now();
        }

        try {
            $user->save();
        } catch (UniqueConstraintViolationException) {
            // Another row already holds this email - a Breeze registration, or a second
            // ShakeDesign account sharing an address. Refused rather than adopted: adopting
            // would hand this identity a row whose password someone else may already know.
            // 409, and no SQL in the response.
            abort(409, "L'adresse {$email} est déjà utilisée par un autre compte ShakeMetre.");
        }

        return $user;
    }
}
