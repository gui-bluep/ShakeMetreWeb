<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\ProjectMetreController;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Mints the machine-to-machine token ShakeDesign uses to read the metre portal.
 *
 * There is no login flow: Sanctum tokens hang off a model, so this creates (once) a
 * dedicated non-human account with an unusable random password and issues a token
 * carrying a single ability against it. Nothing can authenticate as that account
 * interactively.
 */
class IssueShakeDesignToken extends Command
{
    protected $signature = 'shakedesign:issue-token
                            {--name=shakedesign-portal : Label stored on the token}
                            {--email=shakedesign-machine@invalid.local : Identifier of the machine account}
                            {--expires= : Optional lifetime in days; omit for a non-expiring token}
                            {--revoke-existing : Delete this account\'s other tokens first}';

    protected $description = 'Issue a read-only Sanctum token for the ShakeDesign metre portal';

    public function handle(): int
    {
        $account = User::firstOrCreate(
            ['email' => $this->option('email')],
            [
                'name' => 'ShakeDesign machine account',
                // Unusable by design: no one is meant to log in as this account.
                'password' => Hash::make(Str::random(64)),
            ],
        );

        if ($account->wasRecentlyCreated) {
            $this->line("Created machine account <info>{$account->email}</info>.");
        }

        if ($this->option('revoke-existing')) {
            $revoked = $account->tokens()->delete();
            $this->line("Revoked <info>{$revoked}</info> existing token(s).");
        }

        $expiresAt = $this->option('expires') !== null
            ? now()->addDays((int) $this->option('expires'))
            : null;

        $token = $account->createToken(
            (string) $this->option('name'),
            [ProjectMetreController::ABILITY],
            $expiresAt,
        );

        $this->newLine();
        $this->line('Token issued. It is shown once and not recoverable:');
        $this->newLine();
        $this->line("  <comment>{$token->plainTextToken}</comment>");
        $this->newLine();
        $this->line('  ability : '.ProjectMetreController::ABILITY.' (read-only)');
        $this->line('  expires : '.($expiresAt?->toDateTimeString() ?? 'never'));
        $this->newLine();
        $this->line('Use it as a bearer token:');
        $this->line('  <comment>Authorization: Bearer '.$token->plainTextToken.'</comment>');

        return self::SUCCESS;
    }
}
