<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\ProjectMetreController;
use App\Http\Controllers\Api\SsoTicketController;
use App\Models\Metre;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectMetreApiTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT = 'PRJ-1A2B3C';

    private const OTHER_PROJECT = 'PRJ-999999';

    private function url(string $zkp = self::PROJECT): string
    {
        return "/api/projects/{$zkp}/metres";
    }

    /** A token carrying exactly the ability the route requires. */
    private function actingAsMachine(array $abilities = [ProjectMetreController::ABILITY]): User
    {
        $account = User::factory()->create();
        Sanctum::actingAs($account, $abilities);

        return $account;
    }

    private function metre(array $attributes = []): Metre
    {
        return Metre::forceCreate($attributes + [
            'project_id' => self::PROJECT,
            'name' => 'Métré '.Str::random(4),
        ]);
    }

    // --- protection -------------------------------------------------------------------

    public function test_it_rejects_an_unauthenticated_request(): void
    {
        $this->getJson($this->url())->assertUnauthorized();
    }

    public function test_it_rejects_a_token_without_the_required_ability(): void
    {
        $this->actingAsMachine(['something:else']);

        $this->getJson($this->url())->assertForbidden();
    }

    public function test_it_rejects_a_token_with_no_abilities_at_all(): void
    {
        $this->actingAsMachine([]);

        $this->getJson($this->url())->assertForbidden();
    }

    public function test_it_accepts_a_token_carrying_the_read_ability(): void
    {
        $this->actingAsMachine();

        $this->getJson($this->url())->assertOk();
    }

    public function test_the_endpoint_is_read_only(): void
    {
        $this->actingAsMachine();

        foreach (['postJson', 'putJson', 'patchJson', 'deleteJson'] as $method) {
            $this->{$method}($this->url())->assertStatus(405);
        }
    }

    public function test_the_issue_token_command_creates_a_machine_account_and_a_scoped_token(): void
    {
        $this->artisan('shakedesign:issue-token')->assertSuccessful();

        $account = User::where('email', 'shakedesign-sync@invalid.local')->sole();
        $this->assertCount(1, $account->tokens);
        $this->assertSame([ProjectMetreController::ABILITY], $account->tokens->first()->abilities);
    }

    public function test_the_command_can_revoke_previously_issued_tokens(): void
    {
        $this->artisan('shakedesign:issue-token')->assertSuccessful();
        $this->artisan('shakedesign:issue-token')->assertSuccessful();
        $this->assertCount(2, User::where('email', 'shakedesign-sync@invalid.local')->sole()->tokens);

        $this->artisan('shakedesign:issue-token --revoke-existing')->assertSuccessful();

        $account = User::where('email', 'shakedesign-sync@invalid.local')->sole();
        $this->assertCount(1, $account->fresh()->tokens);
    }

    public function test_each_ability_gets_its_own_machine_account(): void
    {
        $this->artisan('shakedesign:issue-token --ability=metres:read')->assertSuccessful();
        $this->artisan('shakedesign:issue-token --ability=sso:issue')->assertSuccessful();

        $sync = User::where('email', 'shakedesign-sync@invalid.local')->sole();
        $sso = User::where('email', 'shakedesign-sso@invalid.local')->sole();

        $this->assertNotSame($sync->getKey(), $sso->getKey());
        $this->assertSame([ProjectMetreController::ABILITY], $sync->tokens->first()->abilities);
        $this->assertSame([SsoTicketController::ABILITY], $sso->tokens->first()->abilities);
    }

    public function test_revoking_one_integration_leaves_the_other_alone(): void
    {
        // The reason the accounts are separate: --revoke-existing wipes every token on the
        // account it targets, so a shared account meant rotating the SSO token silently
        // killed the portal token with it.
        $this->artisan('shakedesign:issue-token --ability=metres:read')->assertSuccessful();
        $this->artisan('shakedesign:issue-token --ability=sso:issue')->assertSuccessful();

        $this->artisan('shakedesign:issue-token --ability=sso:issue --revoke-existing')
            ->assertSuccessful();

        $this->assertCount(1, User::where('email', 'shakedesign-sync@invalid.local')->sole()->tokens);
        $this->assertCount(1, User::where('email', 'shakedesign-sso@invalid.local')->sole()->tokens);
    }

    public function test_a_token_can_never_carry_both_abilities(): void
    {
        // Splitting the abilities is pointless if one token can hold both, so the option takes
        // a single value: two integrations mean two tokens.
        $this->artisan('shakedesign:issue-token --ability=sso:issue')->assertSuccessful();

        $token = User::where('email', 'shakedesign-sso@invalid.local')->sole()->tokens->first();

        $this->assertCount(1, $token->abilities);
        $this->assertNotContains(ProjectMetreController::ABILITY, $token->abilities);
    }

    public function test_it_refuses_an_unknown_ability(): void
    {
        $this->artisan('shakedesign:issue-token --ability=metres:write')
            ->assertFailed();

        $this->assertSame(0, User::query()->count());
    }

    public function test_the_machine_account_cannot_be_logged_into(): void
    {
        $this->artisan('shakedesign:issue-token --ability=sso:issue')->assertSuccessful();

        $account = User::where('email', 'shakedesign-sso@invalid.local')->sole();

        // A random 64-character password nobody holds, and an address in an unroutable TLD.
        $this->post('/login', ['email' => $account->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    /*
     * The tests above lean on Sanctum::actingAs, which bypasses the guard. These drive the
     * real thing: a minted token sent as a bearer header, through auth:sanctum and the
     * ability middleware.
     */

    public function test_a_genuinely_minted_token_authenticates_over_the_wire(): void
    {
        $account = User::factory()->create();
        $token = $account->createToken('portal', [ProjectMetreController::ABILITY]);
        $this->metre();

        $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_a_minted_token_lacking_the_ability_is_forbidden_over_the_wire(): void
    {
        $account = User::factory()->create();
        $token = $account->createToken('portal', ['metres:write']);

        $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->getJson($this->url())
            ->assertForbidden();
    }

    public function test_a_bogus_bearer_token_is_rejected(): void
    {
        $this->withHeader('Authorization', 'Bearer 999|not-a-real-token')
            ->getJson($this->url())
            ->assertUnauthorized();
    }

    public function test_an_expired_token_is_rejected(): void
    {
        $account = User::factory()->create();
        $token = $account->createToken('portal', [ProjectMetreController::ABILITY], now()->subMinute());

        $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->getJson($this->url())
            ->assertUnauthorized();
    }

    public function test_a_revoked_token_stops_working(): void
    {
        $account = User::factory()->create();
        $token = $account->createToken('portal', [ProjectMetreController::ABILITY]);
        $header = 'Bearer '.$token->plainTextToken;

        $this->withHeader('Authorization', $header)->getJson($this->url())->assertOk();

        $account->tokens()->delete();

        // Within one test the container is reused and RequestGuard memoises the resolved
        // user, so the token would not be re-checked. Production boots per request.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', $header)->getJson($this->url())->assertUnauthorized();
    }

    // --- payload ----------------------------------------------------------------------

    public function test_it_returns_only_the_portal_columns(): void
    {
        $this->actingAsMachine();

        $this->metre([
            'name' => 'Métré A',
            'is_accepted_b' => true,
            'is_status_site_b' => false,
            'is_archived_b' => false,
            'tot_sum_total_sales_stored' => 1234.5,
            'tot_sum_total_ordered_stored' => 1000,
            'total_sales_metl_stored' => 1500,
            'total_purchase_metl_stored' => 1200,
            // Must not leak: not a portal column.
            'comment_internal' => 'ne doit pas sortir',
        ]);

        $response = $this->getJson($this->url())->assertOk();

        $response->assertJsonCount(1, 'data');
        $this->assertSame([
            'id',
            'name',
            'ratio',
            'tot_sum_total_sales_stored',
            'tot_sum_total_ordered_stored',
            'is_accepted_b',
            'is_status_site_b',
            'is_archived_b',
        ], array_keys($response->json('data.0')));

        $response->assertJsonMissing(['comment_internal' => 'ne doit pas sortir']);
    }

    public function test_it_exposes_the_filemaker_zkp_as_the_id(): void
    {
        $this->actingAsMachine();
        $zkp = '1A2B3C4D-5E6F-7890-ABCD-EF1234567890';
        $this->metre(['id' => $zkp]);

        $this->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.0.id', $zkp);
    }

    public function test_booleans_are_returned_as_json_booleans(): void
    {
        $this->actingAsMachine();
        $this->metre(['is_accepted_b' => true, 'is_status_site_b' => false, 'is_archived_b' => true]);

        $row = $this->getJson($this->url())->assertOk()->json('data.0');

        $this->assertTrue($row['is_accepted_b']);
        $this->assertFalse($row['is_status_site_b']);
        $this->assertTrue($row['is_archived_b']);
    }

    public function test_stored_totals_are_returned_as_numbers_not_strings(): void
    {
        $this->actingAsMachine();
        $this->metre(['tot_sum_total_sales_stored' => 1234.5, 'tot_sum_total_ordered_stored' => 1000]);

        $row = $this->getJson($this->url())->assertOk()->json('data.0');

        // The DB driver hands decimals back as strings ("1234.5000"); the portal needs
        // numbers. A whole float re-decodes as int, which is the same JSON number, so the
        // assertion is "numeric, not a string" rather than a strict type match.
        $this->assertIsNotString($row['tot_sum_total_sales_stored']);
        $this->assertIsNotString($row['tot_sum_total_ordered_stored']);
        $this->assertSame(1234.5, $row['tot_sum_total_sales_stored']);
        $this->assertEquals(1000, $row['tot_sum_total_ordered_stored']);
    }

    // --- ratio (MET_Metre::Ratio_c) ----------------------------------------------------

    public function test_ratio_is_computed_from_the_metl_stored_totals_rounded_to_two_decimals(): void
    {
        $this->actingAsMachine();
        // Round ( 1500 / 1200 ; 2 ) = 1.25
        $this->metre(['total_sales_metl_stored' => 1500, 'total_purchase_metl_stored' => 1200]);

        $this->getJson($this->url())->assertOk()->assertJsonPath('data.0.ratio', 1.25);
    }

    public function test_ratio_rounds_the_way_filemaker_does(): void
    {
        $this->actingAsMachine();
        // Round ( 1000 / 3 ; 2 ) = 333.33
        $this->metre(['total_sales_metl_stored' => 1000, 'total_purchase_metl_stored' => 3]);

        $this->getJson($this->url())->assertOk()->assertJsonPath('data.0.ratio', 333.33);
    }

    public function test_ratio_is_null_when_either_operand_is_empty(): void
    {
        $this->actingAsMachine();
        $this->metre(['name' => 'no purchase', 'total_sales_metl_stored' => 1500]);
        $this->metre(['name' => 'no sales', 'total_purchase_metl_stored' => 1200]);
        $this->metre(['name' => 'neither']);

        $rows = $this->getJson($this->url())->assertOk()->json('data');

        foreach ($rows as $row) {
            $this->assertNull($row['ratio'], "ratio should be null for {$row['name']}");
        }
    }

    public function test_ratio_is_null_rather_than_dividing_by_zero(): void
    {
        $this->actingAsMachine();
        $this->metre(['total_sales_metl_stored' => 1500, 'total_purchase_metl_stored' => 0]);

        $this->getJson($this->url())->assertOk()->assertJsonPath('data.0.ratio', null);
    }

    // --- scoping ----------------------------------------------------------------------

    public function test_it_returns_only_the_metres_of_the_requested_project(): void
    {
        $this->actingAsMachine();
        $this->metre(['name' => 'mine']);
        $this->metre(['name' => 'theirs', 'project_id' => self::OTHER_PROJECT]);
        Metre::forceCreate(['name' => 'orphan']); // no project at all

        $response = $this->getJson($this->url())->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', 'mine');
    }

    public function test_an_unknown_project_returns_an_empty_list_not_a_404(): void
    {
        $this->actingAsMachine();
        $this->metre();

        // Projects live in ShakeDesign, so "no such project" is indistinguishable from
        // "project with no metres" - both are an empty list.
        $this->getJson($this->url('PRJ-DOES-NOT-EXIST'))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_archived_metres_are_included_since_the_portal_shows_them(): void
    {
        $this->actingAsMachine();
        $this->metre(['name' => 'archived', 'is_archived_b' => true]);

        $this->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.is_archived_b', true);
    }

    // --- pagination -------------------------------------------------------------------

    public function test_fifty_metres_are_returned_unpaginated(): void
    {
        $this->actingAsMachine();
        for ($i = 0; $i < 50; $i++) {
            $this->metre(['ind_project' => $i]);
        }

        $response = $this->getJson($this->url())->assertOk();

        $response->assertJsonCount(50, 'data');
        $response->assertJsonMissingPath('meta');
        $response->assertJsonMissingPath('links');
    }

    public function test_more_than_fifty_metres_are_paginated(): void
    {
        $this->actingAsMachine();
        for ($i = 0; $i < 51; $i++) {
            $this->metre(['ind_project' => $i]);
        }

        $response = $this->getJson($this->url())->assertOk();

        $response->assertJsonCount(50, 'data');
        $response->assertJsonPath('meta.total', 51);
        $response->assertJsonPath('meta.per_page', 50);
        $response->assertJsonPath('meta.current_page', 1);
    }

    public function test_the_second_page_returns_the_remainder(): void
    {
        $this->actingAsMachine();
        for ($i = 0; $i < 51; $i++) {
            $this->metre(['ind_project' => $i]);
        }

        $this->getJson($this->url().'?page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 2);
    }

    public function test_pagination_counts_only_the_requested_project(): void
    {
        $this->actingAsMachine();
        for ($i = 0; $i < 40; $i++) {
            $this->metre(['ind_project' => $i]);
        }
        for ($i = 0; $i < 40; $i++) {
            $this->metre(['ind_project' => $i, 'project_id' => self::OTHER_PROJECT]);
        }

        // 80 metres exist but only 40 belong to this project, so no pagination.
        $response = $this->getJson($this->url())->assertOk();

        $response->assertJsonCount(40, 'data');
        $response->assertJsonMissingPath('meta');
    }
}
