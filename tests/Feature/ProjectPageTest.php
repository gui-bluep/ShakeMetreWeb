<?php

namespace Tests\Feature;

use App\Models\Lot;
use App\Models\Metre;
use App\Models\User;
use Illuminate\Database\QueryException;
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
                // Commandes / Travaux = 900 / 700, not the _metl_ pair below (which is 1.25).
                ->where('metres.0.ratio', 1.29)
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
     * The Ratio column of THIS page is Commandes / Travaux - the row's own two columns - and
     * not Metre::ratio() (vendu / acheté), which is the "Ratio réel" of the métré's own page.
     * Confirmed by the user. Pinned with numbers that tell the two formulas apart: the
     * _metl_ inputs below would give 1.25, Commandes / Travaux gives 1.29.
     */
    public function test_the_metre_ratio_column_is_commandes_over_travaux(): void
    {
        $this->actAsWriter();
        $this->fakeProjectFound();

        $this->metre([
            'tot_sum_total_sales_stored' => 900,     // Commandes
            'tot_sum_total_ordered_stored' => 700,   // Travaux
            'total_sales_metl_stored' => 1000,       // Ratio_c's inputs: 1.25, not what is shown
            'total_purchase_metl_stored' => 800,
        ]);

        // 900 / 700 = 1.2857… -> 1.29
        $this->get('/projects/'.self::PROJECT)
            ->assertInertia(fn ($page) => $page->where('metres.0.ratio', 1.29));
    }

    /**
     * And the total row divides its own two figures, so the column keeps meaning the same
     * thing on the last line as on every line above it.
     */
    public function test_the_page_total_ratio_is_the_summed_commandes_over_the_summed_travaux(): void
    {
        $this->actAsWriter();
        $this->fakeProjectFound();

        $this->metre(['tot_sum_total_sales_stored' => 900, 'tot_sum_total_ordered_stored' => 700]);
        $this->metre(['tot_sum_total_sales_stored' => 600, 'tot_sum_total_ordered_stored' => 500]);

        // (900 + 600) / (700 + 500) = 1.25
        $this->get('/projects/'.self::PROJECT)
            ->assertInertia(fn ($page) => $page
                ->where('totals.total_ordered', 1500)
                ->where('totals.total_works', 1200)
                ->where('totals.total_ratio', 1.25));
    }

    /**
     * A métré not yet on site has both columns empty, so there is nothing to divide - an em
     * dash, not a 0 and not an error. Same guard as Ratio_c.
     */
    public function test_the_ratio_is_absent_when_there_is_nothing_to_divide(): void
    {
        $this->actAsWriter();
        $this->fakeProjectFound();

        $this->metre();

        $this->get('/projects/'.self::PROJECT)
            ->assertInertia(fn ($page) => $page
                ->where('metres.0.ratio', null)
                ->where('totals.total_ratio', null));
    }

    // --- the métré's ID within its project ---------------------------------------------------

    public function test_a_metre_row_carries_its_id_within_the_project(): void
    {
        $this->actAsWriter();
        $this->fakeProjectFound();

        $this->metre(['ind_project' => 7, 'name' => 'Métré A']);

        $this->get('/projects/'.self::PROJECT)
            ->assertInertia(fn ($page) => $page->where('metres.0.ind_project', 7));
    }

    public function test_created_metres_are_numbered_from_one_within_the_project(): void
    {
        $this->actAsWriter();

        $this->post('/projects/'.self::PROJECT.'/metres', ['name' => 'Premier']);
        $this->post('/projects/'.self::PROJECT.'/metres', ['name' => 'Deuxième']);
        $this->post('/projects/'.self::PROJECT.'/metres', ['name' => 'Troisième']);

        $this->assertDatabaseHas('metres', ['name' => 'Premier', 'ind_project' => 1]);
        $this->assertDatabaseHas('metres', ['name' => 'Deuxième', 'ind_project' => 2]);
        $this->assertDatabaseHas('metres', ['name' => 'Troisième', 'ind_project' => 3]);
    }

    /**
     * The number is scoped to the project: the second project starts again at 1. That is the
     * whole point of it rather than a global counter.
     */
    public function test_the_numbering_restarts_at_one_for_another_project(): void
    {
        $this->actAsWriter();

        $this->post('/projects/'.self::PROJECT.'/metres', ['name' => 'A1']);
        $this->post('/projects/'.self::PROJECT.'/metres', ['name' => 'A2']);
        $this->post('/projects/PRJ-OTHER/metres', ['name' => 'B1']);

        $this->assertDatabaseHas('metres', ['name' => 'A2', 'project_id' => self::PROJECT, 'ind_project' => 2]);
        $this->assertDatabaseHas('metres', ['name' => 'B1', 'project_id' => 'PRJ-OTHER', 'ind_project' => 1]);
    }

    /**
     * Not count + 1: deleting the middle métré of three must not hand the next one a number
     * that is already taken.
     */
    public function test_a_gap_in_the_numbering_is_not_filled_in(): void
    {
        $this->actAsWriter();

        $this->metre(['ind_project' => 1]);
        $this->metre(['ind_project' => 3]);

        $this->post('/projects/'.self::PROJECT.'/metres', ['name' => 'Suivant']);

        $this->assertDatabaseHas('metres', ['name' => 'Suivant', 'ind_project' => 4]);
    }

    /**
     * The case the maximum of the surviving métrés cannot answer: delete the highest one and it
     * drops, so "max + 1" would hand its number out a second time. A spent number stays spent -
     * people refer to a métré by it.
     */
    public function test_deleting_the_last_metre_does_not_free_its_number(): void
    {
        $this->actAsWriter();

        $this->post('/projects/'.self::PROJECT.'/metres', ['name' => 'Un']);
        $this->post('/projects/'.self::PROJECT.'/metres', ['name' => 'Deux']);
        $this->post('/projects/'.self::PROJECT.'/metres', ['name' => 'Trois']);

        $three = Metre::where('name', 'Trois')->sole();
        $this->delete("/metres/{$three->id}")->assertRedirect();

        $this->post('/projects/'.self::PROJECT.'/metres', ['name' => 'Quatre']);

        $this->assertDatabaseHas('metres', ['name' => 'Quatre', 'ind_project' => 4]);
        $this->assertDatabaseMissing('metres', ['ind_project' => 3]);
    }

    /**
     * And the same once every métré of the project is gone: the numbering does not restart,
     * because the high-water mark is kept per project rather than derived from the rows.
     */
    public function test_emptying_a_project_does_not_restart_the_numbering(): void
    {
        $this->actAsWriter();

        $this->post('/projects/'.self::PROJECT.'/metres', ['name' => 'Un']);
        $this->post('/projects/'.self::PROJECT.'/metres', ['name' => 'Deux']);

        foreach (Metre::all() as $metre) {
            $this->delete("/metres/{$metre->id}");
        }

        $this->post('/projects/'.self::PROJECT.'/metres', ['name' => 'Trois']);

        $this->assertDatabaseHas('metres', ['name' => 'Trois', 'ind_project' => 3]);
    }

    /**
     * The mark alone is not enough: a métré inserted with an explicit number - an import, or
     * the test suite - never went through the allocator, so the highest live number is
     * consulted too and the higher of the two wins.
     */
    public function test_a_number_inserted_outside_the_allocator_is_not_handed_out_again(): void
    {
        $this->actAsWriter();

        $this->post('/projects/'.self::PROJECT.'/metres', ['name' => 'Un']);
        $this->metre(['ind_project' => 9]);

        $this->post('/projects/'.self::PROJECT.'/metres', ['name' => 'Suivant']);

        $this->assertDatabaseHas('metres', ['name' => 'Suivant', 'ind_project' => 10]);
    }

    /**
     * The uniqueness is a database constraint, not just a convention the allocator follows -
     * that is what makes two simultaneous creations fail loudly instead of both reading the
     * same maximum and handing out the same ID.
     */
    public function test_two_metres_of_one_project_cannot_share_an_id(): void
    {
        $this->metre(['ind_project' => 1]);

        $this->expectException(QueryException::class);

        $this->metre(['ind_project' => 1]);
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

    /**
     * The code is a number, so 2 comes before 10 - the trap a text sort falls into. Lots with
     * no code go last rather than first, where MySQL's default NULL ordering would put them and
     * where they would read as the head of the list.
     */
    public function test_the_lots_are_ordered_by_code_as_numbers(): void
    {
        $this->actAsWriter();
        $this->fakeProjectFound();

        Lot::forceCreate(['project_id' => self::PROJECT, 'code' => 10, 'title_fr' => 'Dix']);
        Lot::forceCreate(['project_id' => self::PROJECT, 'code' => null, 'title_fr' => 'Sans code']);
        Lot::forceCreate(['project_id' => self::PROJECT, 'code' => 2, 'title_fr' => 'Deux']);

        $this->get('/projects/'.self::PROJECT)
            ->assertInertia(fn ($page) => $page
                ->where('lots.0.title', 'Deux')
                ->where('lots.1.title', 'Dix')
                ->where('lots.2.title', 'Sans code'));
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
