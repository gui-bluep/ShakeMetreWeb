<?php

namespace Tests\Feature;

use App\Models\Lot;
use App\Models\Metre;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A project's own page: its métrés and lots. `project` is a bare ShakeDesign zkp - no local
 * table - so every test here either fakes ShakeDesign's find or exercises the case where it
 * answers "not found", which is indistinguishable from "temporarily unreachable" from here.
 */
class ProjectPageTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT = 'PRJ-1A2B3C';

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

    private function actAsWriter(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    private function actAsReadOnly(): User
    {
        $user = User::factory()->readOnly()->create();
        $this->actingAs($user);

        return $user;
    }

    private function ok(array $response): array
    {
        return ['response' => $response, 'messages' => [['code' => '0', 'message' => 'OK']]];
    }

    private function fakeProjectFound(string $name = 'Chantier Nord'): void
    {
        Http::fake([
            '*/sessions' => Http::response($this->ok(['token' => 'tok-1'])),
            '*/layouts/API_PRJ/_find' => Http::response($this->ok([
                'data' => [['fieldData' => ['zkp' => self::PROJECT, 'Name' => $name], 'recordId' => '1']],
            ])),
        ]);
    }

    private function fakeProjectNotFound(): void
    {
        Http::fake([
            '*/sessions' => Http::response($this->ok(['token' => 'tok-1'])),
            '*/layouts/API_PRJ/_find' => Http::response(
                ['response' => [], 'messages' => [['code' => '401', 'message' => 'No records match the request']]],
                404,
            ),
        ]);
    }

    private function metre(array $attributes = []): Metre
    {
        return Metre::forceCreate($attributes + ['project_id' => self::PROJECT]);
    }

    public function test_the_page_shows_the_project_name_resolved_from_shakedesign(): void
    {
        $this->actAsWriter();
        $this->fakeProjectFound('Chantier Nord');

        $this->get('/projects/'.self::PROJECT)
            ->assertInertia(fn ($page) => $page
                ->component('Projects/Show')
                ->where('project.id', self::PROJECT)
                ->where('project.name', 'Chantier Nord'));
    }

    public function test_the_page_still_renders_when_shakedesign_has_no_such_project(): void
    {
        $this->actAsWriter();
        $this->fakeProjectNotFound();
        $this->metre(['name' => 'Métré local']);

        $this->get('/projects/'.self::PROJECT)
            ->assertInertia(fn ($page) => $page
                ->component('Projects/Show')
                ->where('project.name', null)
                ->has('metres', 1));
    }

    public function test_a_metre_row_carries_every_requested_column(): void
    {
        $this->actAsWriter();
        $this->fakeProjectFound();

        $this->metre([
            'name' => 'Métré A',
            'date_creation' => '2026-01-15',
            'date_agreement' => '2026-02-01',
            'is_accepted_b' => true,
            'is_status_site_b' => false,
            'tot_sum_total_sales_offer_stored' => 1000,
            // "commandes" reads Tot_Sum_TotalSales_Stored, "travaux" reads
            // Tot_Sum_TotalOrdered_Stored - confirmed against the source, the reverse of
            // the field names' surface reading.
            'tot_sum_total_sales_stored' => 900,
            'tot_sum_total_ordered_stored' => 700,
            'tot_sum_total_gain_stored' => 200,
            'total_sales_metl_stored' => 1000,
            'total_purchase_metl_stored' => 800,
        ]);

        $this->get('/projects/'.self::PROJECT)
            ->assertInertia(fn ($page) => $page
                ->where('metres.0.name', 'Métré A')
                ->where('metres.0.ratio', 1.25)
                ->where('metres.0.date_creation', '2026-01-15')
                ->where('metres.0.date_agreement', '2026-02-01')
                ->where('metres.0.is_accepted_b', true)
                ->where('metres.0.is_status_site_b', false)
                ->where('metres.0.total_offers', 1000)
                ->where('metres.0.total_ordered', 900)
                ->where('metres.0.total_works', 700)
                ->where('metres.0.total_gain', 200));
    }

    public function test_the_page_totals_sum_the_commandes_travaux_gains_columns_across_metres(): void
    {
        $this->actAsWriter();
        $this->fakeProjectFound();

        $this->metre(['tot_sum_total_sales_stored' => 900, 'tot_sum_total_ordered_stored' => 700, 'tot_sum_total_gain_stored' => 200]);
        // A métré not yet on site has null totals - contributes 0, not excluded from the sum.
        $this->metre(['tot_sum_total_sales_stored' => null, 'tot_sum_total_ordered_stored' => null, 'tot_sum_total_gain_stored' => null]);
        $this->metre(['tot_sum_total_sales_stored' => 100, 'tot_sum_total_ordered_stored' => 50, 'tot_sum_total_gain_stored' => 50]);

        $this->get('/projects/'.self::PROJECT)
            ->assertInertia(fn ($page) => $page
                ->where('totals.total_ordered', 1000)
                ->where('totals.total_works', 750)
                ->where('totals.total_gain', 250));
    }

    /**
     * total_ratio is Σ Total_Sales_METL_Stored / Σ Total_Purchase_METL_Stored across the
     * project's métrés - the same Ratio_c formula as a single métré, applied to summed
     * inputs rather than transcribed from a source field (there isn't one at this level).
     */
    public function test_the_page_total_ratio_sums_the_metl_inputs_across_metres(): void
    {
        $this->actAsWriter();
        $this->fakeProjectFound();

        $this->metre(['total_sales_metl_stored' => 1000, 'total_purchase_metl_stored' => 800]);
        $this->metre(['total_sales_metl_stored' => 500, 'total_purchase_metl_stored' => 400]);

        // (1000 + 500) / (800 + 400) = 1.25
        $this->get('/projects/'.self::PROJECT)
            ->assertInertia(fn ($page) => $page->where('totals.total_ratio', 1.25));
    }

    public function test_the_page_total_ratio_is_null_when_nothing_feeds_it(): void
    {
        $this->actAsWriter();
        $this->fakeProjectFound();

        $this->metre();

        $this->get('/projects/'.self::PROJECT)
            ->assertInertia(fn ($page) => $page->where('totals.total_ratio', null));
    }

    public function test_the_page_lists_the_project_lots(): void
    {
        $this->actAsWriter();
        $this->fakeProjectFound();

        Lot::forceCreate(['project_id' => self::PROJECT, 'code' => 3, 'title_fr' => 'Gros oeuvre']);
        Lot::forceCreate(['project_id' => 'PRJ-OTHER', 'code' => 1, 'title_fr' => 'Autre projet']);

        $this->get('/projects/'.self::PROJECT)
            ->assertInertia(fn ($page) => $page
                ->has('lots', 1)
                ->where('lots.0.title', 'Gros oeuvre'));
    }

    public function test_a_readonly_account_may_view_the_project_page(): void
    {
        $this->actAsReadOnly();
        $this->fakeProjectFound();

        $this->get('/projects/'.self::PROJECT)->assertOk();
    }

    public function test_a_guest_is_redirected_away(): void
    {
        $this->get('/projects/'.self::PROJECT)->assertRedirect('/login');
    }

    // --- creating a métré -------------------------------------------------------------------

    public function test_creating_a_metre_attaches_it_to_the_project(): void
    {
        $this->actAsWriter();

        $response = $this->post('/projects/'.self::PROJECT.'/metres', ['name' => 'Nouveau métré']);

        $response->assertRedirect(route('projects.show', self::PROJECT));
        $this->assertDatabaseHas('metres', ['project_id' => self::PROJECT, 'name' => 'Nouveau métré']);
    }

    public function test_creating_a_metre_requires_a_name(): void
    {
        $this->actAsWriter();

        $this->post('/projects/'.self::PROJECT.'/metres', ['name' => ''])
            ->assertSessionHasErrors('name');
        $this->assertDatabaseCount('metres', 0);
    }

    public function test_a_readonly_account_cannot_create_a_metre(): void
    {
        $this->actAsReadOnly();

        $this->post('/projects/'.self::PROJECT.'/metres', ['name' => 'X'])->assertStatus(403);
        $this->assertDatabaseCount('metres', 0);
    }

    // --- creating a lot ----------------------------------------------------------------------

    public function test_creating_a_lot_attaches_it_to_the_project(): void
    {
        $this->actAsWriter();

        $response = $this->postJson('/api/projects/'.self::PROJECT.'/lots', [
            'code' => 5,
            'title_fr' => 'Toiture',
            'title_en' => 'Roofing',
            'title_nl' => 'Dak',
            'company_id' => 'CPY-1',
            'contact_id' => 'CTC-1',
        ]);

        $response->assertCreated()->assertJson(['data' => [
            'code' => 5, 'title' => 'Toiture',
            'title_fr' => 'Toiture', 'title_en' => 'Roofing', 'title_nl' => 'Dak',
            'company_id' => 'CPY-1', 'contact_id' => 'CTC-1',
        ]]);
        $this->assertDatabaseHas('lots', [
            'project_id' => self::PROJECT,
            'code' => 5,
            'title_fr' => 'Toiture',
            'title_en' => 'Roofing',
            'title_nl' => 'Dak',
            'company_id' => 'CPY-1',
            'contact_id' => 'CTC-1',
        ]);
    }

    /**
     * Every field is independently optional, matching the "manage lots" panel's own blank
     * new-row: a lot may be created with just a code, filled in gradually afterwards.
     */
    public function test_creating_a_lot_with_no_fields_is_allowed(): void
    {
        $this->actAsWriter();

        $this->postJson('/api/projects/'.self::PROJECT.'/lots', [])
            ->assertCreated()
            ->assertJson(['data' => ['code' => null, 'title' => null]]);

        $this->assertDatabaseHas('lots', ['project_id' => self::PROJECT, 'code' => null, 'title_fr' => null]);
    }

    public function test_a_readonly_account_cannot_create_a_lot(): void
    {
        $this->actAsReadOnly();

        $this->post('/api/projects/'.self::PROJECT.'/lots', ['title_fr' => 'X'])->assertStatus(403);
        $this->assertDatabaseCount('lots', 0);
    }
}
