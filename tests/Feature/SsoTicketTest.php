<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Http\Controllers\Api\ProjectMetreController;
use App\Http\Controllers\Api\SsoTicketController;
use App\Models\User;
use App\Services\Sso\SsoTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The SSO handshake: ShakeDesign asks for a login URL, its user follows it once.
 *
 * Every ShakeDesign response is faked - no test touches a real FileMaker server.
 */
class SsoTicketTest extends TestCase
{
    use RefreshDatabase;

    private const ZKP = 'ZUSR-1A2B3C4D-5E6F';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.shakedesign', [
            'host' => 'fms.example.test',
            'database' => 'ShakeDesign',
            'username' => 'api_user',
            'password' => 'api_secret',
            'version' => 'vLatest',
            'timeout' => 15,
            'connect_timeout' => 5,
            'verify' => true,
            'token_cache_key' => 'shakedesign:data-api:token',
            'token_ttl' => 840,
        ]);

        Cache::flush();
    }

    /** A machine token carrying the SSO ability, which is not the portal one. */
    private function actingAsSsoMachine(array $abilities = [SsoTicketController::ABILITY]): void
    {
        Sanctum::actingAs(User::factory()->create(), $abilities);
    }

    /** @param array<string, mixed> $overrides */
    private function fakeShakeDesignAccount(array $overrides = []): void
    {
        $fieldData = $overrides + [
            'zkp' => self::ZKP,
            'Mail_1' => 'anne.dupont@example.test',
            'NameFirst' => 'Anne',
            'NameLast' => 'Dupont',
            'PrivilegeSet' => 'User',
            'isActiveAccount_b' => 1,
            'isActiveUser_b' => 1,
        ];

        Http::fake([
            '*/sessions' => Http::response([
                'response' => ['token' => 'tok'],
                'messages' => [['code' => '0', 'message' => 'OK']],
            ]),
            '*/layouts/API_ZUSR/_find' => Http::response([
                'response' => ['data' => [['fieldData' => $fieldData, 'recordId' => '1', 'modId' => '0']]],
                'messages' => [['code' => '0', 'message' => 'OK']],
            ]),
        ]);
    }

    private function fakeNoSuchAccount(): void
    {
        Http::fake([
            '*/sessions' => Http::response([
                'response' => ['token' => 'tok'],
                'messages' => [['code' => '0', 'message' => 'OK']],
            ]),
            // FileMaker reports "no records match" as code 401 in the body.
            '*/layouts/API_ZUSR/_find' => Http::response([
                'response' => [],
                'messages' => [['code' => '401', 'message' => 'No records match the request']],
            ], 404),
        ]);
    }

    private function issue(): TestResponse
    {
        return $this->postJson('/api/sso/tickets', ['shakedesign_user_id' => self::ZKP]);
    }

    // --- who may ask for a ticket ------------------------------------------------------

    public function test_it_rejects_an_unauthenticated_caller(): void
    {
        $this->issue()->assertUnauthorized();
    }

    public function test_the_portal_ability_cannot_mint_a_login(): void
    {
        // The whole point of a separate ability: a leaked metres:read token must not be able
        // to sign in as an arbitrary user.
        $this->actingAsSsoMachine([ProjectMetreController::ABILITY]);
        $this->fakeShakeDesignAccount();

        $this->issue()->assertForbidden();
        $this->assertDatabaseMissing('users', ['shakedesign_user_id' => self::ZKP]);
    }

    public function test_the_sso_ability_cannot_read_the_metre_portal(): void
    {
        // And symmetrically, so neither token is a superset of the other.
        Sanctum::actingAs(User::factory()->create(), [SsoTicketController::ABILITY]);

        $this->getJson('/api/projects/PRJ-1/metres')->assertForbidden();
    }

    public function test_it_requires_a_shakedesign_user_id(): void
    {
        $this->actingAsSsoMachine();

        $this->postJson('/api/sso/tickets', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('shakedesign_user_id');
    }

    // --- refusing inactive or unknown accounts ----------------------------------------

    public function test_an_unknown_shakedesign_account_gets_no_ticket(): void
    {
        $this->actingAsSsoMachine();
        $this->fakeNoSuchAccount();

        $this->issue()->assertForbidden();
        $this->assertDatabaseMissing('users', ['shakedesign_user_id' => self::ZKP]);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function inactiveAccountProvider(): array
    {
        return [
            'account disabled' => [['isActiveAccount_b' => 0]],
            'user disabled' => [['isActiveUser_b' => 0]],
            'both disabled' => [['isActiveAccount_b' => 0, 'isActiveUser_b' => 0]],
            // An API layout that forgot to expose a flag must fail closed.
            'account flag absent' => [['isActiveAccount_b' => null]],
            'user flag absent' => [['isActiveUser_b' => null]],
        ];
    }

    #[DataProvider('inactiveAccountProvider')]
    public function test_an_inactive_shakedesign_account_gets_no_ticket(array $flags): void
    {
        $this->actingAsSsoMachine();

        // A null flag stands for "not on the layout at all".
        $account = array_filter($flags, fn ($value) => $value !== null);
        $absent = array_keys(array_filter($flags, fn ($value) => $value === null));

        $fieldData = $account + [
            'zkp' => self::ZKP,
            'Mail_1' => 'anne.dupont@example.test',
            'NameFirst' => 'Anne',
            'NameLast' => 'Dupont',
            'PrivilegeSet' => 'Admin',
            'isActiveAccount_b' => 1,
            'isActiveUser_b' => 1,
        ];
        foreach ($absent as $flag) {
            unset($fieldData[$flag]);
        }

        Http::fake([
            '*/sessions' => Http::response([
                'response' => ['token' => 'tok'],
                'messages' => [['code' => '0', 'message' => 'OK']],
            ]),
            '*/layouts/API_ZUSR/_find' => Http::response([
                'response' => ['data' => [['fieldData' => $fieldData, 'recordId' => '1', 'modId' => '0']]],
                'messages' => [['code' => '0', 'message' => 'OK']],
            ]),
        ]);

        $this->issue()->assertForbidden();
        $this->assertDatabaseMissing('users', ['shakedesign_user_id' => self::ZKP]);
    }

    public function test_a_shakedesign_outage_issues_nothing_and_is_not_reported_as_a_refusal(): void
    {
        $this->actingAsSsoMachine();
        Http::fake([
            '*/sessions' => Http::response([
                'response' => [],
                'messages' => [['code' => '212', 'message' => 'Invalid account or password']],
            ], 401),
        ]);

        // 502, not 403: the caller did nothing wrong and a retry may succeed.
        $this->issue()->assertStatus(502);
        $this->assertDatabaseMissing('users', ['shakedesign_user_id' => self::ZKP]);
    }

    // --- role mapping ------------------------------------------------------------------

    /** @return array<string, array{string, UserRole}> */
    public static function privilegeSetProvider(): array
    {
        return [
            'Admin' => ['Admin', UserRole::Admin],
            'User' => ['User', UserRole::User],
            'Read-Only Access' => ['[Read-Only Access]', UserRole::ReadOnly],
            // Anything unrecognised must land on the least privilege, never admin.
            'unknown privilege set' => ['Superviseur', UserRole::ReadOnly],
            'empty' => ['', UserRole::ReadOnly],
        ];
    }

    #[DataProvider('privilegeSetProvider')]
    public function test_it_maps_the_filemaker_privilege_set_to_a_role(string $privilegeSet, UserRole $expected): void
    {
        $this->actingAsSsoMachine();
        $this->fakeShakeDesignAccount(['PrivilegeSet' => $privilegeSet]);

        $this->issue()->assertOk();

        $user = User::where('shakedesign_user_id', self::ZKP)->sole();
        $this->assertSame($expected, $user->role);
    }

    public function test_an_unknown_privilege_set_never_grants_write_access(): void
    {
        $this->actingAsSsoMachine();
        $this->fakeShakeDesignAccount(['PrivilegeSet' => 'Quelque chose de nouveau']);

        $this->issue()->assertOk();

        $this->assertFalse(User::where('shakedesign_user_id', self::ZKP)->sole()->canWrite());
    }

    // --- provisioning the Laravel user -------------------------------------------------

    public function test_it_creates_the_laravel_user_on_first_arrival(): void
    {
        $this->actingAsSsoMachine();
        $this->fakeShakeDesignAccount();

        $this->issue()->assertOk()->assertJsonStructure(['login_url', 'expires_in']);

        $user = User::where('shakedesign_user_id', self::ZKP)->sole();
        $this->assertSame('anne.dupont@example.test', $user->email);
        $this->assertSame('Anne Dupont', $user->name);
    }

    public function test_it_reuses_the_same_user_on_later_arrivals(): void
    {
        $this->actingAsSsoMachine();
        $this->fakeShakeDesignAccount();

        $this->issue()->assertOk();
        $this->issue()->assertOk();

        $this->assertSame(1, User::where('shakedesign_user_id', self::ZKP)->count());
    }

    public function test_it_refreshes_the_role_and_identity_from_shakedesign_on_every_login(): void
    {
        $existing = User::factory()->admin()->fromShakeDesign(self::ZKP)->create([
            'email' => 'ancienne.adresse@example.test',
            'name' => 'Ancien Nom',
        ]);

        $this->actingAsSsoMachine();
        // Demoted and renamed in ShakeDesign since last time.
        $this->fakeShakeDesignAccount(['PrivilegeSet' => '[Read-Only Access]']);

        $this->issue()->assertOk();

        $existing->refresh();
        $this->assertSame(UserRole::ReadOnly, $existing->role);
        $this->assertSame('anne.dupont@example.test', $existing->email);
        $this->assertSame('Anne Dupont', $existing->name);
    }

    public function test_it_matches_on_the_shakedesign_id_not_the_email(): void
    {
        // A different ShakeDesign account that happens to share the email must not be hijacked.
        $other = User::factory()->fromShakeDesign('ZUSR-OTHER')->create([
            'email' => 'anne.dupont@example.test',
        ]);

        $this->actingAsSsoMachine();
        $this->fakeShakeDesignAccount();

        $this->issue()->assertStatus(409);

        $this->assertSame('ZUSR-OTHER', $other->refresh()->shakedesign_user_id);
        $this->assertDatabaseMissing('users', ['shakedesign_user_id' => self::ZKP]);
    }

    public function test_an_email_already_taken_is_refused_without_leaking_sql(): void
    {
        // A Breeze registration holding the same address, with no ShakeDesign link.
        User::factory()->create(['email' => 'anne.dupont@example.test']);

        $this->actingAsSsoMachine();
        $this->fakeShakeDesignAccount();

        $response = $this->issue()->assertStatus(409);

        // Adopting the row would hand this identity an account whose password someone else
        // may already know, so it is refused - and the reply says so without exposing the
        // query or the schema.
        $this->assertStringContainsString('anne.dupont@example.test', $response->json('message'));
        $this->assertStringNotContainsString('SQLSTATE', (string) $response->getContent());
        $this->assertStringNotContainsString('insert into', (string) $response->getContent());
    }

    public function test_a_resync_that_frees_the_email_still_works(): void
    {
        // The clash only matters while the address is genuinely taken; once the other account
        // moves on, the same ticket request succeeds.
        $other = User::factory()->fromShakeDesign('ZUSR-OTHER')->create([
            'email' => 'anne.dupont@example.test',
        ]);

        $this->actingAsSsoMachine();
        $this->fakeShakeDesignAccount();
        $this->issue()->assertStatus(409);

        $other->forceFill(['email' => 'autre.adresse@example.test'])->save();

        $this->issue()->assertOk();
        $this->assertDatabaseHas('users', ['shakedesign_user_id' => self::ZKP]);
    }

    public function test_a_resync_does_not_reset_the_password_of_an_existing_user(): void
    {
        $existing = User::factory()->fromShakeDesign(self::ZKP)->create();
        $passwordBefore = $existing->password;

        $this->actingAsSsoMachine();
        $this->fakeShakeDesignAccount();

        $this->issue()->assertOk();

        // Password and verification date are create-only: a user who also signs in through
        // Breeze must not be locked out by an SSO login.
        $this->assertSame($passwordBefore, $existing->refresh()->password);
    }

    public function test_an_account_without_an_email_is_rejected(): void
    {
        $this->actingAsSsoMachine();
        $this->fakeShakeDesignAccount(['Mail_1' => '']);

        $this->issue()->assertStatus(422);
        $this->assertDatabaseMissing('users', ['shakedesign_user_id' => self::ZKP]);
    }

    // --- consuming the ticket ----------------------------------------------------------

    public function test_a_valid_ticket_signs_the_user_in(): void
    {
        $this->actingAsSsoMachine();
        $this->fakeShakeDesignAccount();
        $loginUrl = $this->issue()->assertOk()->json('login_url');

        $this->app['auth']->forgetGuards();

        $this->get($loginUrl)->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs(User::where('shakedesign_user_id', self::ZKP)->sole());
    }

    public function test_a_ticket_works_exactly_once(): void
    {
        $this->actingAsSsoMachine();
        $this->fakeShakeDesignAccount();
        $loginUrl = $this->issue()->assertOk()->json('login_url');

        $this->app['auth']->forgetGuards();
        $this->get($loginUrl)->assertRedirect(route('dashboard'));

        // Replaying the URL finds nothing: the entry was pulled, not read.
        $this->post('/logout');
        $this->app['auth']->forgetGuards();

        $this->get($loginUrl)->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_an_expired_ticket_is_refused(): void
    {
        $this->actingAsSsoMachine();
        $this->fakeShakeDesignAccount();
        $loginUrl = $this->issue()->assertOk()->json('login_url');

        $this->travel(SsoTicket::TTL_SECONDS + 1)->seconds();

        // Drop the machine token's guard state, otherwise assertGuest() would be looking at
        // the Sanctum caller rather than at whether a session was opened.
        $this->app['auth']->forgetGuards();

        $this->get($loginUrl)->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_a_ticket_still_works_just_before_it_expires(): void
    {
        $this->actingAsSsoMachine();
        $this->fakeShakeDesignAccount();
        $loginUrl = $this->issue()->assertOk()->json('login_url');

        $this->travel(SsoTicket::TTL_SECONDS - 5)->seconds();
        $this->app['auth']->forgetGuards();

        $this->get($loginUrl)->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
    }

    public function test_an_unknown_token_is_refused_silently(): void
    {
        $response = $this->get('/sso/consume/'.str_repeat('a', 64));

        // Same destination and no message as an expired one: nothing reveals whether the
        // token ever existed.
        $response->assertRedirect(route('login'));
        $response->assertSessionHasNoErrors();
        $this->assertGuest();
    }

    public function test_a_ticket_for_a_since_deleted_user_is_refused(): void
    {
        $this->actingAsSsoMachine();
        $this->fakeShakeDesignAccount();
        $loginUrl = $this->issue()->assertOk()->json('login_url');

        User::where('shakedesign_user_id', self::ZKP)->delete();
        $this->app['auth']->forgetGuards();

        $this->get($loginUrl)->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_the_ticket_is_random_rather_than_derived_from_the_user(): void
    {
        $this->actingAsSsoMachine();
        $this->fakeShakeDesignAccount();

        $first = str($this->issue()->assertOk()->json('login_url'))->afterLast('/')->value();
        $second = str($this->issue()->assertOk()->json('login_url'))->afterLast('/')->value();

        $user = User::where('shakedesign_user_id', self::ZKP)->sole();

        // Two tickets for the same user must differ: the token stands for a cache entry, not
        // for the user, so it cannot be guessed from an id or reused across logins.
        $this->assertNotSame($first, $second);
        $this->assertNotSame((string) $user->getKey(), $first);
        $this->assertGreaterThanOrEqual(32, strlen($first));
    }
}
