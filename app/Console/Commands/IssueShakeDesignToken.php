<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\ProjectMetreController;
use App\Http\Controllers\Api\SsoTicketController;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Mints one machine-to-machine token for ShakeDesign.
 *
 * There is no login flow: Sanctum tokens hang off a model, so this creates (once) a
 * dedicated non-human account with an unusable random password and issues a token against
 * it. Nothing can authenticate as those accounts interactively.
 *
 *     php artisan shakedesign:issue-token --ability=metres:read
 *     php artisan shakedesign:issue-token --ability=sso:issue --expires=365
 */
class IssueShakeDesignToken extends Command
{
    protected $signature = 'shakedesign:issue-token
                            {--ability=metres:read : The single ability to grant (metres:read or sso:issue)}
                            {--name= : Label stored on the token; defaults to the account name}
                            {--expires= : Optional lifetime in days; omit for a non-expiring token}
                            {--revoke-existing : Delete this ability\'s other tokens first}';

    protected $description = 'Issue a scoped Sanctum token for a ShakeDesign integration';

    /**
     * One account per ability, never a shared one.
     *
     * Two reasons, and the second is the sharp one. Auditing: `last_used_at` and the token
     * list read per integration instead of being interleaved. And revocation:
     * --revoke-existing wipes every token on the account it targets, so a shared account
     * meant that rotating the SSO token silently killed the portal token with it.
     *
     * @var array<string, array{account: string, label: string}>
     */
    private const INTEGRATIONS = [
        ProjectMetreController::ABILITY => [
            'account' => 'shakedesign-sync',
            'label' => 'ShakeDesign metre portal (read-only)',
        ],
        SsoTicketController::ABILITY => [
            'account' => 'shakedesign-sso',
            'label' => 'ShakeDesign single sign-on',
        ],
    ];

    public function handle(): int
    {
        $ability = trim((string) $this->option('ability'));

        // A mistyped option is user error, not a bug: report it and exit non-zero rather than
        // printing a stack trace. Nothing is created on the way out.
        if (! array_key_exists($ability, self::INTEGRATIONS)) {
            $this->error(sprintf(
                'Unknown ability [%s]. Known: %s.',
                $ability,
                implode(', ', array_keys(self::INTEGRATIONS)),
            ));

            return self::FAILURE;
        }

        $integration = self::INTEGRATIONS[$ability];

        $account = $this->machineAccount($integration);

        if ($this->option('revoke-existing')) {
            $revoked = $account->tokens()->delete();
            $this->line("Revoked <info>{$revoked}</info> existing token(s) for <info>{$integration['account']}</info>.");
        }

        $expiresAt = $this->option('expires') !== null
            ? now()->addDays((int) $this->option('expires'))
            : null;

        // A single ability, deliberately not an array: a token carrying both would be exactly
        // the superset that splitting the abilities exists to prevent - reading portal rows
        // and minting logins are not comparable powers. Two integrations mean two tokens.
        $token = $account->createToken(
            (string) ($this->option('name') ?: $integration['account']),
            [$ability],
            $expiresAt,
        );

        $this->newLine();
        $this->line('Token issued. It is shown once and not recoverable:');
        $this->newLine();
        $this->line("  <comment>{$token->plainTextToken}</comment>");
        $this->newLine();
        $this->line('  ability : '.$ability);
        $this->line('  account : '.$account->email);
        $this->line('  expires : '.($expiresAt?->toDateTimeString() ?? 'never'));
        $this->newLine();
        $this->line('Use it as a bearer token:');
        $this->line('  <comment>Authorization: Bearer '.$token->plainTextToken.'</comment>');

        return self::SUCCESS;
    }

    /**
     * @param  array{account: string, label: string}  $integration
     */
    private function machineAccount(array $integration): User
    {
        $email = $integration['account'].'@invalid.local';

        $account = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $integration['label'],
                // Unusable by design: no one is meant to log in as this account.
                'password' => Hash::make(Str::random(64)),
            ],
        );

        if ($account->wasRecentlyCreated) {
            $this->line("Created machine account <info>{$email}</info>.");
        }

        return $account;
    }
}
