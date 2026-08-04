<?php

namespace Tests\Feature;

use App\Jobs\RecalculateMetreTotals;
use App\Models\Cart;
use App\Models\Lot;
use App\Models\Metre;
use App\Models\MetreLine;
use App\Models\MetreLineComponent;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The métré's own page: its header fields, the actions on it, and the two cascades those
 * actions depend on. ShakeDesign is faked throughout - the page resolves the project name.
 */
class MetrePageTest extends TestCase
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
        $this->fakeProject();
    }

    private function fakeProject(): void
    {
        Http::fake([
            '*/sessions' => Http::response([
                'response' => ['token' => 'tok-1'],
                'messages' => [['code' => '0', 'message' => 'OK']],
            ]),
            '*/layouts/API_PRJ/_find' => Http::response([
                'response' => ['data' => [['fieldData' => ['zkp' => self::PROJECT, 'Name' => 'Chantier Nord'], 'recordId' => '1']]],
                'messages' => [['code' => '0', 'message' => 'OK']],
            ]),
            // La page lit aussi les offres client du métré. Un motif non couvert par Http::fake()
            // n'est PAS bloqué : il part pour de vrai, et le test finit par appeler un serveur
            // FileMaker. D'où ce faux-ci, qui répond « aucun enregistrement » (code 401).
            '*/layouts/API_OFF/_find' => Http::response([
                'messages' => [['code' => '401', 'message' => 'No records match the request']],
                'response' => [],
            ]),
        ]);
    }

    private function actAsWriter(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    private function metre(array $attributes = []): Metre
    {
        return Metre::forceCreate($attributes + ['project_id' => self::PROJECT, 'name' => 'Métré A']);
    }

    private function line(Metre $metre, array $attributes = []): MetreLine
    {
        return MetreLine::forceCreate($attributes + ['metre_id' => $metre->id]);
    }

    // --- carte Fournisseur (le portail Prj_LOT__ de MET_Form) -------------------------------

    /**
     * Les trois totaux et la répartition par lot, à la main.
     *
     * Le garde est « la ligne a un lot », pas « son lot a une société » : le second lot n'a aucun
     * fournisseur et compte pourtant comme assigné - c'est ce qui distingue ces totaux de
     * GainOnPurchases_c.
     */
    public function test_the_supplier_card_splits_the_amounts_by_lot(): void
    {
        $this->actAsWriter();
        $metre = $this->metre(['language' => 'FR']);

        $withCompany = Lot::forceCreate([
            'project_id' => self::PROJECT, 'code' => 2,
            'title_fr' => 'Toiture', 'title_en' => 'Roof', 'cpy_name_ae' => 'Toitures Dupont',
        ]);
        $withoutCompany = Lot::forceCreate([
            'project_id' => self::PROJECT, 'code' => 1, 'title_fr' => 'Gros oeuvre',
        ]);

        // 10 × 3 = 30 achats, 10 × 2 = 20 commandé
        $this->line($metre, ['lot_id' => $withCompany->id, 'price_buy' => 3, 'quantity' => 10, 'price_ordered' => 2, 'quantity_ordered' => 10]);
        // 4 × 1,5 = 6 achats, rien de commandé
        $this->line($metre, ['lot_id' => $withCompany->id, 'price_buy' => 1.5, 'quantity' => 4]);
        // 5 × 8 = 40 achats sur le lot sans fournisseur
        $this->line($metre, ['lot_id' => $withoutCompany->id, 'price_buy' => 8, 'quantity' => 5]);
        // 7 × 2 = 14 achats sans lot du tout
        $this->line($metre, ['price_buy' => 2, 'quantity' => 7]);
        // une option : comptée nulle part
        $this->line($metre, ['lot_id' => $withCompany->id, 'price_buy' => 1000, 'quantity' => 1, 'is_option_b' => true]);

        $breakdown = $metre->lotBreakdown();

        $this->assertEquals(76, $breakdown['assigned_buy'], '30 + 6 + 40, options exclues');
        $this->assertEquals(14, $breakdown['unassigned_buy']);
        $this->assertEquals(20, $breakdown['assigned_ordered']);

        // Triés par code : le lot 1 avant le lot 2.
        $this->assertSame([1, 2], array_column($breakdown['lots'], 'code'));

        [$first, $second] = $breakdown['lots'];
        $this->assertEquals(40, $first['buy']);
        $this->assertNull($first['company'], 'Un lot sans société reste dans la liste.');
        $this->assertSame('Gros oeuvre', $first['name']);

        $this->assertEquals(36, $second['buy'], '30 + 6, l\'option écartée');
        $this->assertEquals(20, $second['ordered']);
        $this->assertSame('Toitures Dupont', $second['company']);
        // Un compte de LIGNES, pas de montants : l'option en fait partie même si son montant
        // n'entre dans aucun total. Deux lignes ordinaires plus l'option.
        $this->assertSame(3, $second['lines_count']);
    }

    /** Le nom du lot suit la langue du métré, comme partout ailleurs. */
    public function test_the_card_names_a_lot_in_the_metres_language(): void
    {
        $this->actAsWriter();
        $metre = $this->metre(['language' => 'EN']);
        $lot = Lot::forceCreate([
            'project_id' => self::PROJECT, 'code' => 3,
            'title_fr' => 'Toiture', 'title_en' => 'Roof', 'title_nl' => 'Dak',
        ]);
        $this->line($metre, ['lot_id' => $lot->id, 'price_buy' => 1, 'quantity' => 1]);

        $this->assertSame('Roof', $metre->lotBreakdown()['lots'][0]['name']);
    }

    /** Un lot du projet sans aucune ligne dans ce métré n'encombre pas la carte. */
    public function test_a_lot_with_no_line_in_this_metre_is_absent(): void
    {
        $this->actAsWriter();
        $metre = $this->metre();
        Lot::forceCreate(['project_id' => self::PROJECT, 'code' => 9, 'title_fr' => 'Jamais utilisé']);
        $this->line($metre, ['price_buy' => 1, 'quantity' => 1]);

        $this->assertSame([], $metre->lotBreakdown()['lots']);
        $this->assertEquals(1, $metre->lotBreakdown()['unassigned_buy']);
    }

    public function test_the_page_carries_the_breakdown(): void
    {
        $this->actAsWriter();
        $metre = $this->metre(['language' => 'FR']);
        $lot = Lot::forceCreate(['project_id' => self::PROJECT, 'code' => 4, 'title_fr' => 'Sols', 'cpy_name_ae' => 'Sols SA']);
        $this->line($metre, ['lot_id' => $lot->id, 'price_buy' => 10, 'quantity' => 2]);

        $this->get("/metres/{$metre->id}")->assertInertia(fn ($page) => $page
            ->where('lotBreakdown.assigned_buy', 20)
            ->where('lotBreakdown.lots.0.company', 'Sols SA')
            ->where('lotBreakdown.lots.0.name', 'Sols'));
    }

    // --- verrou (MET_LockUnlock) ------------------------------------------------------------

    public function test_it_locks_a_metre(): void
    {
        $this->actAsWriter();
        $metre = $this->metre();

        $this->postJson("/api/metres/{$metre->id}/lock", ['locked' => true])
            ->assertOk()
            ->assertJsonPath('data.is_locked_b', true);

        $this->assertTrue((bool) $metre->fresh()->is_locked_b);
    }

    /**
     * Le déverrouillage doit passer alors que le métré EST verrouillé - sinon le verrou se
     * refermerait sur sa propre clé. C'est la raison du point d'entrée séparé.
     */
    public function test_it_unlocks_a_locked_metre(): void
    {
        $this->actAsWriter();
        $metre = $this->metre(['is_locked_b' => true]);

        $this->postJson("/api/metres/{$metre->id}/lock", ['locked' => false])
            ->assertOk()
            ->assertJsonPath('data.is_locked_b', false);

        $this->assertFalse((bool) $metre->fresh()->is_locked_b);
    }

    /** L'état visé est envoyé : deux appels de suite ne font pas l'aller-retour d'une bascule. */
    public function test_locking_twice_leaves_it_locked(): void
    {
        $this->actAsWriter();
        $metre = $this->metre();

        $this->postJson("/api/metres/{$metre->id}/lock", ['locked' => true])->assertOk();
        $this->postJson("/api/metres/{$metre->id}/lock", ['locked' => true])->assertOk();

        $this->assertTrue((bool) $metre->fresh()->is_locked_b);
    }

    public function test_the_target_state_is_required(): void
    {
        $this->actAsWriter();

        $this->postJson("/api/metres/{$this->metre()->id}/lock", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('locked');
    }

    /** Ce que le verrou empêche : écrire les lignes. */
    public function test_a_locked_metre_refuses_line_writes(): void
    {
        $this->actAsWriter();
        $metre = $this->metre();
        $line = $this->line($metre, ['refsl_title' => 'Avant']);

        $this->postJson("/api/metres/{$metre->id}/lock", ['locked' => true])->assertOk();

        $this->postJson("/api/metres/{$metre->id}/lines", [])->assertStatus(423);
        $this->assertSame('Avant', $line->fresh()->refsl_title);

        // Et l'inverse : déverrouillé, la même écriture passe.
        $this->postJson("/api/metres/{$metre->id}/lock", ['locked' => false])->assertOk();
        $this->postJson("/api/metres/{$metre->id}/lines", [])->assertStatus(201);
    }

    public function test_a_readonly_account_may_not_lock(): void
    {
        $this->actingAs(User::factory()->readOnly()->create());
        $metre = $this->metre();

        $this->postJson("/api/metres/{$metre->id}/lock", ['locked' => true])->assertForbidden();

        $this->assertFalse((bool) $metre->fresh()->is_locked_b);
    }

    /** `is_locked_b` reste hors du whitelist du métré : il ne s'écrit que par son point d'entrée. */
    public function test_the_metre_patch_still_refuses_the_lock_column(): void
    {
        $this->actAsWriter();
        $metre = $this->metre();

        $this->patchJson("/api/metres/{$metre->id}", ['is_locked_b' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors('is_locked_b');

        $this->assertFalse((bool) $metre->fresh()->is_locked_b);
    }

    // --- the page --------------------------------------------------------------------------

    public function test_the_page_shows_the_project_name_and_the_metre_fields(): void
    {
        $this->actAsWriter();

        $metre = $this->metre([
            'ratio_markup' => 1.15,
            'language' => 'FR',
            'date_agreement' => '2026-03-01',
            'is_accepted_b' => true,
            'is_status_site_b' => false,
            'comment_client' => 'Bonjour',
            'total_sales_metl_stored' => 1000,
            'total_purchase_metl_stored' => 800,
        ]);

        $this->get("/metres/{$metre->id}")
            ->assertInertia(fn ($page) => $page
                ->component('Metres/Show')
                ->where('project.name', 'Chantier Nord')
                ->where('project.id', self::PROJECT)
                ->where('metre.name', 'Métré A')
                ->where('metre.ratio_markup', 1.15)
                ->where('metre.language', 'FR')
                ->where('metre.date_agreement', '2026-03-01')
                ->where('metre.is_accepted_b', true)
                ->where('metre.is_status_site_b', false)
                ->where('metre.comment_client', 'Bonjour')
                // MET_Metre::Ratio_c, computed from the METL stored totals.
                ->where('metre.ratio', 1.25)
                ->where('languages', ['FR', 'EN', 'NL']));
    }

    /**
     * The four totals on this screen map by source field name: achats = Buy, ventes = Sales,
     * commandes = Ordered, gains = Gain. Note "commandes" is a different column here than on
     * the project page - intentional, confirmed, and pinned so nobody aligns them by accident.
     *
     * They come from the UNGATED Total_*_METL_Stored columns, which is why the gated Tot_Sum_*
     * values below - deliberately different numbers - are not what shows.
     */
    public function test_the_totals_map_by_source_field_name(): void
    {
        $this->actAsWriter();

        $metre = $this->metre([
            'total_purchase_metl_stored' => 700,
            'total_sales_metl_stored' => 1000,
            'total_ordered_metl_stored' => 900,
            'total_gain_metl_stored' => 100,

            'tot_sum_total_buy_stored' => 1,
            'tot_sum_total_sales_stored' => 2,
            'tot_sum_total_ordered_stored' => 3,
            'tot_sum_total_gain_stored' => 4,
        ]);

        $this->get("/metres/{$metre->id}")
            ->assertInertia(fn ($page) => $page
                ->where('metre.totals.purchases', 700)
                ->where('metre.totals.sales', 1000)
                ->where('metre.totals.ordered', 900)
                ->where('metre.totals.gain', 100));
    }

    /**
     * The screen shows what the lines add up to whether or not the two flags are set. It used to
     * read the gated columns, so a métré that was neither accepted nor on site displayed four
     * empty tiles - which reads as a broken page, not as "nothing is committed yet".
     */
    public function test_the_totals_are_shown_even_when_neither_flag_is_set(): void
    {
        $this->actAsWriter();

        $metre = $this->metre(['is_accepted_b' => false, 'is_status_site_b' => false]);
        $this->line($metre, [
            'quantity' => 2, 'price_buy' => 50, 'price_sales' => 100,
            'quantity_ordered' => 2, 'price_ordered' => 80,
        ]);

        (new RecalculateMetreTotals($metre))->handle();

        // Every gated column is empty, as its own formula requires...
        $metre->refresh();
        $this->assertNull($metre->tot_sum_total_buy_stored);
        $this->assertNull($metre->tot_sum_total_sales_stored);

        // ...and the page shows the figures anyway.
        $this->get("/metres/{$metre->id}")
            ->assertInertia(fn ($page) => $page
                ->where('metre.totals.purchases', 100)
                ->where('metre.totals.sales', 200)
                ->where('metre.totals.ordered', 160)
                ->where('metre.totals.gain', 40));
    }

    public function test_the_page_reports_how_many_lines_would_be_destroyed(): void
    {
        $this->actAsWriter();
        $metre = $this->metre();
        $this->line($metre);
        $this->line($metre);

        $this->get("/metres/{$metre->id}")
            ->assertInertia(fn ($page) => $page->where('lineCount', 2));
    }

    public function test_a_readonly_account_may_view_the_page(): void
    {
        $this->actingAs(User::factory()->readOnly()->create());

        $this->get("/metres/{$this->metre()->id}")->assertOk();
    }

    // --- editing ---------------------------------------------------------------------------

    public function test_it_writes_the_editable_header_fields(): void
    {
        $this->actAsWriter();
        $metre = $this->metre();

        $this->patchJson("/api/metres/{$metre->id}", [
            'name' => 'Métré révisé',
            'ratio_markup' => 1.2,
            'language' => 'NL',
            'date_agreement' => '2026-04-15',
            'comment_internal' => 'note interne',
        ])->assertOk();

        $fresh = $metre->fresh();
        $this->assertSame('Métré révisé', $fresh->name);
        // Compared numerically: decimal columns come back as a padded string on MySQL and as
        // a float on the SQLite the suite runs against, and the value is what matters here.
        $this->assertEquals(1.2, $fresh->ratio_markup);
        $this->assertSame('NL', $fresh->language);
        $this->assertSame('2026-04-15', $fresh->date_agreement->toDateString());
        $this->assertSame('note interne', $fresh->comment_internal);
    }

    // --- the agreement date follows acceptance ---------------------------------------------

    /**
     * The normal path: the page sends the day the person ticking the box is living in, read from
     * their browser, and it is stored as-is. Only their machine knows that date - the server may
     * be in another timezone, and it is not the one anybody would put on an agreement.
     */
    public function test_the_date_the_page_sends_with_the_flag_is_the_one_stored(): void
    {
        $this->actAsWriter();
        $metre = $this->metre(['is_accepted_b' => false]);

        $this->patchJson("/api/metres/{$metre->id}", [
            'is_accepted_b' => true,
            'date_agreement' => '2026-01-20',
        ])->assertOk()->assertJsonPath('data.date_agreement', '2026-01-20');

        $this->assertSame('2026-01-20', $metre->fresh()->date_agreement->toDateString());
    }

    /**
     * The fallback, for a caller that ticks the flag without saying which day it is: the server's
     * own clock, config('app.timezone').
     */
    public function test_accepting_without_a_date_stamps_the_servers_today(): void
    {
        $this->actAsWriter();
        $metre = $this->metre(['is_accepted_b' => false, 'date_agreement' => null]);

        $today = now()->toDateString();

        $this->patchJson("/api/metres/{$metre->id}", ['is_accepted_b' => true])
            ->assertOk()
            ->assertJsonPath('data.date_agreement', $today);

        $this->assertSame($today, $metre->fresh()->date_agreement->toDateString());
    }

    public function test_unaccepting_a_metre_clears_the_agreement_date(): void
    {
        $this->actAsWriter();
        $metre = $this->metre(['is_accepted_b' => true, 'date_agreement' => '2026-03-01']);

        $this->patchJson("/api/metres/{$metre->id}", ['is_accepted_b' => false])
            ->assertOk()
            ->assertJsonPath('data.date_agreement', null);

        $this->assertNull($metre->fresh()->date_agreement);
    }

    /**
     * No memory of the cleared date: re-ticking stamps today rather than restoring what was
     * there. The old date described an agreement that was taken back, and bringing it back would
     * assert a date nobody chose.
     */
    public function test_re_accepting_stamps_today_and_does_not_restore_the_old_date(): void
    {
        $this->actAsWriter();
        $metre = $this->metre(['is_accepted_b' => true, 'date_agreement' => '2020-01-01']);

        $this->patchJson("/api/metres/{$metre->id}", ['is_accepted_b' => false])->assertOk();
        $this->patchJson("/api/metres/{$metre->id}", ['is_accepted_b' => true])->assertOk();

        $this->assertSame(now()->toDateString(), $metre->fresh()->date_agreement->toDateString());
    }

    /**
     * The date stays a field: a métré accepted at a meeting last Tuesday and recorded today has
     * to be correctable, and a later edit must not be undone by the stamp.
     */
    public function test_the_agreement_date_remains_editable_after_it_is_stamped(): void
    {
        $this->actAsWriter();
        $metre = $this->metre(['is_accepted_b' => false]);

        $this->patchJson("/api/metres/{$metre->id}", ['is_accepted_b' => true])->assertOk();
        $this->patchJson("/api/metres/{$metre->id}", ['date_agreement' => '2026-02-10'])->assertOk();

        $this->assertSame('2026-02-10', $metre->fresh()->date_agreement->toDateString());
    }

    /**
     * Only on the transition: a PATCH that touches something else on an already-accepted métré
     * must not silently move its agreement date to today.
     */
    public function test_editing_another_field_leaves_the_agreement_date_alone(): void
    {
        $this->actAsWriter();
        $metre = $this->metre(['is_accepted_b' => true, 'date_agreement' => '2026-03-01']);

        $this->patchJson("/api/metres/{$metre->id}", ['name' => 'Autre nom'])->assertOk();
        // Re-sending the same value is not a transition either.
        $this->patchJson("/api/metres/{$metre->id}", ['is_accepted_b' => true])->assertOk();

        $this->assertSame('2026-03-01', $metre->fresh()->date_agreement->toDateString());
    }

    public function test_an_unknown_language_is_rejected(): void
    {
        $this->actAsWriter();

        $this->patchJson("/api/metres/{$this->metre()->id}", ['language' => 'DE'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('language');
    }

    public function test_a_materialized_total_cannot_be_written(): void
    {
        $this->actAsWriter();
        $metre = $this->metre(['tot_sum_total_gain_stored' => 100]);

        $this->patchJson("/api/metres/{$metre->id}", ['tot_sum_total_gain_stored' => 999])
            ->assertStatus(422)
            ->assertJsonValidationErrors('tot_sum_total_gain_stored');

        $this->assertEquals(100, $metre->fresh()->tot_sum_total_gain_stored);
    }

    public function test_the_computed_ratio_cannot_be_written(): void
    {
        $this->actAsWriter();

        $this->patchJson("/api/metres/{$this->metre()->id}", ['ratio' => 9])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ratio');
    }

    /**
     * isAccepted_b gates Tot_Sum_TotalBuy and isStatus_Site_b gates Sales/Ordered/Gain, so the
     * flags are inputs to those sums - flipping one without recomputing leaves every total wrong.
     * Asserted on the columns rather than on the response, since what this page displays comes
     * from the ungated set and does not move when a flag does.
     */
    public function test_flipping_the_site_flag_recomputes_the_totals(): void
    {
        $this->actAsWriter();
        $metre = $this->metre(['is_status_site_b' => false]);
        $this->line($metre, ['quantity' => 2, 'price_sales' => 100, 'price_ordered' => 80]);

        // Gated off: the site totals are empty while the flag is false.
        (new RecalculateMetreTotals($metre))->handle();
        $this->assertNull($metre->fresh()->tot_sum_total_sales_stored);

        $response = $this->patchJson("/api/metres/{$metre->id}", ['is_status_site_b' => true]);

        $response->assertOk();
        $this->assertEquals(200, $metre->fresh()->tot_sum_total_sales_stored);
    }

    public function test_flipping_the_accepted_flag_recomputes_the_purchase_total(): void
    {
        $this->actAsWriter();
        $metre = $this->metre(['is_accepted_b' => false]);
        $this->line($metre, ['quantity' => 2, 'price_buy' => 50]);

        $this->patchJson("/api/metres/{$metre->id}", ['is_accepted_b' => true])->assertOk();

        $this->assertEquals(100, $metre->fresh()->tot_sum_total_buy_stored);
    }

    public function test_a_readonly_account_cannot_edit(): void
    {
        $this->actingAs(User::factory()->readOnly()->create());

        $this->patchJson("/api/metres/{$this->metre()->id}", ['name' => 'X'])->assertStatus(403);
    }

    // --- duplicate -------------------------------------------------------------------------

    public function test_duplicating_copies_the_lines_and_their_components(): void
    {
        $this->actAsWriter();
        $metre = $this->metre(['language' => 'FR', 'ratio_markup' => 1.1]);
        $line = $this->line($metre, ['description' => 'Terrassement', 'unit' => 'm2', 'price_sales' => 10]);
        MetreLineComponent::forceCreate([
            'metre_line_id' => $line->id, 'description' => 'Zone A',
            'quantity_sales' => 2, 'length' => 3, 'width' => 4,
        ]);

        $this->post("/metres/{$metre->id}/duplicate")->assertRedirect();

        $copy = Metre::where('id', '!=', $metre->id)->sole();
        $this->assertSame('Métré A (copie)', $copy->name);
        $this->assertSame(self::PROJECT, $copy->project_id);
        $this->assertSame('FR', $copy->language);
        $this->assertSame(1, $copy->metreLines()->count());

        $copiedLine = $copy->metreLines()->sole();
        $this->assertSame('Terrassement', $copiedLine->description);
        $this->assertNotSame($line->id, $copiedLine->id, 'the copy must not reuse the original zkp');
        $this->assertSame(1, $copiedLine->metreLineComponents()->count());
        $this->assertSame('Zone A', $copiedLine->metreLineComponents()->sole()->description);
    }

    /**
     * A duplicate has not been agreed to by anyone, and is not the métré an existing offer was
     * raised against - carrying either over would assert something untrue.
     */
    public function test_duplicating_does_not_carry_over_acceptance_or_the_offer(): void
    {
        $this->actAsWriter();
        $metre = $this->metre([
            'is_accepted_b' => true,
            'date_agreement' => '2026-01-01',
            'offer_id' => 'OFF-1',
            'is_locked_b' => true,
            'is_archived_b' => true,
        ]);

        $this->post("/metres/{$metre->id}/duplicate");

        $copy = Metre::where('id', '!=', $metre->id)->sole();
        $this->assertFalse($copy->is_accepted_b);
        $this->assertNull($copy->date_agreement);
        $this->assertNull($copy->offer_id);
        $this->assertFalse($copy->is_locked_b, 'a copy must be workable');
        $this->assertFalse($copy->is_archived_b);
    }

    /**
     * The copy takes the next ID in the project rather than the original's. Copying it verbatim
     * made a métré and its duplicate both read as the same ID on the project page, which is
     * exactly what a per-project number exists to prevent.
     */
    public function test_duplicating_gives_the_copy_the_next_id_in_the_project(): void
    {
        $this->actAsWriter();
        $metre = $this->metre(['ind_project' => 2]);

        $this->post("/metres/{$metre->id}/duplicate");

        $copy = Metre::where('id', '!=', $metre->id)->sole();
        $this->assertSame(2, (int) $metre->fresh()->ind_project);
        $this->assertSame(3, (int) $copy->ind_project);
    }

    /**
     * The site flag travels with the copy - it is an internal status, not a claim about a
     * client - which is also what keeps the copied totals legible, since Sales/Ordered/Gain are
     * gated on it.
     */
    public function test_duplicating_recomputes_the_copys_totals_from_the_copied_lines(): void
    {
        $this->actAsWriter();
        $metre = $this->metre(['is_status_site_b' => true]);
        // quantity_ordered is set explicitly: PriceTotalOrdered multiplies price_ordered by it,
        // not by `quantity`, and nothing syncs the two - that sync rule was never found in the
        // export, so it was deliberately not implemented.
        $this->line($metre, [
            'quantity' => 3, 'price_sales' => 100,
            'quantity_ordered' => 3, 'price_ordered' => 60,
        ]);

        $this->post("/metres/{$metre->id}/duplicate");

        $copy = Metre::where('id', '!=', $metre->id)->sole();
        $this->assertTrue($copy->is_status_site_b);
        $this->assertEquals(300, $copy->tot_sum_total_sales_stored);
        $this->assertEquals(180, $copy->tot_sum_total_ordered_stored);
    }

    /**
     * The consequence of not copying acceptance: Tot_Sum_TotalBuy is gated on isAccepted_b, so
     * a fresh duplicate has no purchase total until somebody accepts it. Pinned so it is not
     * mistaken for a broken recalculation later.
     *
     * That empty column now shows on the project page's roll-up and in the portal replica only -
     * the métré page reads the ungated Total_Purchase_METL_Stored and shows the figure.
     */
    public function test_a_duplicate_has_no_purchase_total_until_it_is_accepted(): void
    {
        $this->actAsWriter();
        $metre = $this->metre(['is_accepted_b' => true]);
        $this->line($metre, ['quantity' => 2, 'price_buy' => 50]);

        $this->post("/metres/{$metre->id}/duplicate");

        $copy = Metre::where('id', '!=', $metre->id)->sole();
        $this->assertFalse($copy->is_accepted_b);
        $this->assertNull($copy->tot_sum_total_buy_stored);
    }

    public function test_a_readonly_account_cannot_duplicate(): void
    {
        $this->actingAs(User::factory()->readOnly()->create());

        $this->post("/metres/{$this->metre()->id}/duplicate")->assertStatus(403);
        $this->assertSame(1, Metre::count());
    }

    // --- delete ----------------------------------------------------------------------------

    /**
     * Only metre_line_components cascades in the schema; metre_lines, carts, cart_materials and
     * tags are all ON DELETE NO ACTION, so the delete has to clear them in order or the foreign
     * keys refuse it.
     */
    public function test_deleting_removes_the_lines_components_carts_and_tags(): void
    {
        $this->actAsWriter();
        $metre = $this->metre();
        $line = $this->line($metre);
        MetreLineComponent::forceCreate(['metre_line_id' => $line->id, 'quantity_sales' => 1]);
        Cart::forceCreate(['metre_id' => $metre->id, 'name' => 'Panier']);
        Tag::forceCreate(['metre_id' => $metre->id, 'tag_text' => 'Étiquette']);

        $this->delete("/metres/{$metre->id}")
            ->assertRedirect(route('projects.show', self::PROJECT));

        $this->assertDatabaseMissing('metres', ['id' => $metre->id]);
        $this->assertDatabaseCount('metre_lines', 0);
        $this->assertDatabaseCount('metre_line_components', 0);
        $this->assertDatabaseCount('carts', 0);
        $this->assertDatabaseCount('tags', 0);
    }

    public function test_deleting_leaves_other_metres_alone(): void
    {
        $this->actAsWriter();
        $target = $this->metre();
        $keep = $this->metre(['name' => 'À garder']);
        $this->line($keep);

        $this->delete("/metres/{$target->id}");

        $this->assertDatabaseHas('metres', ['id' => $keep->id]);
        $this->assertSame(1, $keep->metreLines()->count());
    }

    public function test_a_readonly_account_cannot_delete(): void
    {
        $this->actingAs(User::factory()->readOnly()->create());
        $metre = $this->metre();

        $this->delete("/metres/{$metre->id}")->assertStatus(403);
        $this->assertDatabaseHas('metres', ['id' => $metre->id]);
    }

    public function test_a_guest_is_redirected_away(): void
    {
        $this->get("/metres/{$this->metre()->id}")->assertRedirect('/login');
    }
}
