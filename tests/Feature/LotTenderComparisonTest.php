<?php

namespace Tests\Feature;

use App\Models\Lot;
use App\Models\Metre;
use App\Models\MetreLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The supplier tender comparison screen: the page itself, the weighting/notes patch, the
 * scoring refresh, and the award action - all thin wiring around App\Models\Lot and
 * App\Actions\SelectTenderSupplier, which TenderScoringTest and the action's own tests already
 * cover formula by formula.
 */
class LotTenderComparisonTest extends TestCase
{
    use RefreshDatabase;

    private Metre $metre;

    private Lot $lot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->metre = Metre::forceCreate(['name' => 'Métré', 'project_id' => 'PRJ-1']);

        $this->lot = Lot::forceCreate([
            'code' => 1,
            'project_id' => 'PRJ-1',
            'tender_supplier_1_id' => 'CPY-1',
            'tender_supplier_2_id' => 'CPY-2',
            'tender_weighting_price' => 60,
            'tender_weighting_crit1' => 25,
            'tender_weighting_crit1_description' => 'Technique',
            'tender_weighting_crit2' => 15,
            'tender_weighting_crit1_supp1' => 80,
            'tender_weighting_crit1_supp2' => 60,
            'tender_weighting_crit2_supp1' => 50,
            'tender_weighting_crit2_supp2' => 90,
        ]);
    }

    private function line(array $attributes = []): MetreLine
    {
        return MetreLine::forceCreate($attributes + [
            'metre_id' => $this->metre->id,
            'lot_id' => $this->lot->id,
            'is_tender_line_b' => true,
        ]);
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

    // --- the page -------------------------------------------------------------------------

    public function test_the_page_only_lists_slots_that_are_actually_assigned(): void
    {
        $this->actAsWriter();

        $response = $this->get("/lots/{$this->lot->id}/tender-comparison");

        $response->assertInertia(fn ($page) => $page
            ->component('Lots/TenderComparison')
            ->where('suppliers', [
                ['slot' => 1, 'company_id' => 'CPY-1'],
                ['slot' => 2, 'company_id' => 'CPY-2'],
            ])
        );
    }

    public function test_the_page_lists_only_tender_lines_of_that_lot(): void
    {
        $this->actAsWriter();

        $tenderLine = $this->line(['description' => 'En appel d\'offres']);
        $this->line(['description' => 'Hors appel d\'offres', 'is_tender_line_b' => false]);

        $otherLot = Lot::forceCreate(['code' => 2, 'project_id' => 'PRJ-1']);
        $this->line(['description' => 'Autre lot', 'lot_id' => $otherLot->id]);

        $response = $this->get("/lots/{$this->lot->id}/tender-comparison");

        $response->assertInertia(fn ($page) => $page
            ->has('lines', 1)
            ->where('lines.0.id', $tenderLine->id)
        );
    }

    public function test_a_readonly_account_may_view_the_comparison(): void
    {
        $this->actAsReadOnly();

        $this->get("/lots/{$this->lot->id}/tender-comparison")->assertOk();
    }

    // --- scoring ----------------------------------------------------------------------------

    public function test_scoring_ranks_only_assigned_suppliers(): void
    {
        $this->actAsWriter();

        $this->line([
            'tender_supp1_price' => 10, 'tender_supp1_quantity' => 10,
            'tender_supp2_price' => 12, 'tender_supp2_quantity' => 10,
            // Supplier 3 has no tender_supplier_3_id on the lot, so it never held a slot,
            // but nothing stops a stray quote column from having a value anyway.
            'tender_supp3_price' => 1, 'tender_supp3_quantity' => 1,
        ]);

        $response = $this->getJson("/api/lots/{$this->lot->id}/tender-scoring");

        $response->assertOk();
        $suppliers = $response->json('data.scoring.suppliers');

        $this->assertSame([1, 2], array_keys($suppliers));
        $this->assertSame(1, $suppliers[1]['rank']);
        $this->assertSame(2, $suppliers[2]['rank']);
    }

    // --- weighting patch --------------------------------------------------------------------

    public function test_writing_the_weighting_and_notes_updates_the_score(): void
    {
        $this->actAsWriter();

        $this->line([
            'tender_supp1_price' => 10, 'tender_supp1_quantity' => 10,
            'tender_supp2_price' => 12, 'tender_supp2_quantity' => 10,
        ]);

        $response = $this->patchJson("/api/lots/{$this->lot->id}", [
            'tender_weighting_crit1_supp1' => 100,
        ]);

        $response->assertOk();
        $this->assertSame(100, $this->lot->fresh()->tender_weighting_crit1_supp1);
        $this->assertNotNull($response->json('data.scoring.suppliers.1.final_score'));
    }

    public function test_a_key_outside_the_whitelist_is_rejected(): void
    {
        $this->actAsWriter();

        $this->patchJson("/api/lots/{$this->lot->id}", ['project_id' => 'PRJ-9'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['project_id']);

        $this->assertSame('PRJ-1', $this->lot->fresh()->project_id);
    }

    /**
     * title_custom, the language titles, code, and the assigned supplier/contact are the
     * project page's "manage lots" panel writing through this same endpoint - one whitelist
     * for the whole Lot, not a second one that could disagree with this one about what is
     * editable.
     */
    public function test_the_lot_identity_fields_are_editable_through_the_same_endpoint(): void
    {
        $this->actAsWriter();

        $this->patchJson("/api/lots/{$this->lot->id}", [
            'title_custom' => 'Gros oeuvre',
            'title_fr' => 'Gros oeuvre FR',
            'title_en' => 'Shell and core',
            'title_nl' => 'Ruwbouw',
            'code' => 7,
            'company_id' => 'CPY-9',
            'contact_id' => 'CTC-3',
        ])->assertOk();

        $fresh = $this->lot->fresh();
        $this->assertSame('Gros oeuvre FR', $fresh->title_fr);
        $this->assertSame('Shell and core', $fresh->title_en);
        $this->assertSame('Ruwbouw', $fresh->title_nl);
        $this->assertSame('CPY-9', $fresh->company_id);
        $this->assertSame('CTC-3', $fresh->contact_id);
        $this->assertSame(7, $fresh->code);
    }

    public function test_a_note_outside_zero_to_a_hundred_is_rejected(): void
    {
        $this->actAsWriter();

        $this->patchJson("/api/lots/{$this->lot->id}", ['tender_weighting_crit1_supp1' => 150])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['tender_weighting_crit1_supp1']);
    }

    public function test_a_readonly_account_cannot_write_the_weighting(): void
    {
        $this->actAsReadOnly();

        $this->patchJson("/api/lots/{$this->lot->id}", ['tender_weighting_price' => 50])
            ->assertStatus(403);
    }

    // --- quote fields go through the metre-line endpoint ------------------------------------

    public function test_a_quote_field_is_editable_through_the_metre_line_endpoint(): void
    {
        $this->actAsWriter();

        $line = $this->line();

        $this->patchJson("/api/metre-lines/{$line->id}", ['tender_supp1_price' => 42])
            ->assertOk();

        $this->assertSame(42.0, (float) $line->fresh()->tender_supp1_price);
    }

    // --- award --------------------------------------------------------------------------

    public function test_awarding_the_lot_to_the_slot_company_succeeds(): void
    {
        $this->actAsWriter();

        $response = $this->postJson("/api/lots/{$this->lot->id}/tender-award", [
            'supplier_number' => 1,
            'company_id' => 'CPY-1',
        ]);

        $response->assertOk();
        $this->assertSame('CPY-1', $this->lot->fresh()->company_id);
        $this->assertSame('CPY-1', $response->json('data.lot.company_id'));
    }

    public function test_awarding_the_lot_to_a_company_outside_the_slot_is_refused(): void
    {
        $this->actAsWriter();

        $response = $this->postJson("/api/lots/{$this->lot->id}/tender-award", [
            'supplier_number' => 1,
            'company_id' => 'CPY-2',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('holds company CPY-1', $response->json('message'));
        $this->assertNull($this->lot->fresh()->company_id);
    }

    public function test_a_readonly_account_cannot_award_the_lot(): void
    {
        $this->actAsReadOnly();

        $this->postJson("/api/lots/{$this->lot->id}/tender-award", [
            'supplier_number' => 1,
            'company_id' => 'CPY-1',
        ])->assertStatus(403);
    }

    // --- delete -----------------------------------------------------------------------------

    public function test_deleting_an_empty_lot_succeeds(): void
    {
        $this->actAsWriter();

        $this->deleteJson("/api/lots/{$this->lot->id}")->assertNoContent();
        $this->assertDatabaseMissing('lots', ['id' => $this->lot->id]);
    }

    /**
     * A tender line's lot_id is data - which lot it was compared under - so deleting the lot
     * out from under it is refused rather than silently orphaning or cascading.
     */
    public function test_deleting_a_lot_with_metre_lines_is_refused(): void
    {
        $this->actAsWriter();
        $this->line();

        $this->deleteJson("/api/lots/{$this->lot->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Ce lot contient des lignes de métré et ne peut pas être supprimé.');

        $this->assertDatabaseHas('lots', ['id' => $this->lot->id]);
    }

    public function test_a_readonly_account_cannot_delete_a_lot(): void
    {
        $this->actAsReadOnly();

        $this->deleteJson("/api/lots/{$this->lot->id}")->assertStatus(403);
        $this->assertDatabaseHas('lots', ['id' => $this->lot->id]);
    }

    // --- restriction à un métré ---------------------------------------------------------------

    /**
     * `?metre=` est la voie du bouton « Appel d'offres » du cadre Fournisseurs :
     * `METT_LOT_ShowLOT` cherche `zkf_LOT = lot AND zkf_MET = métré`.
     *
     * La restriction porte AUSSI sur les scores. Dans la source, les synthèses de METT_LOT_Form
     * totalisent le jeu trouvé ; ne filtrer que la liste donnerait un écran où les lignes disent
     * une chose et les totaux une autre.
     */
    public function test_a_metre_scope_narrows_the_lines_and_the_scores(): void
    {
        $this->actAsWriter();

        $this->line(['tender_supp1_price' => 100, 'tender_supp1_quantity' => 1]);

        $other = Metre::forceCreate(['name' => 'Autre métré', 'project_id' => 'PRJ-1']);
        MetreLine::forceCreate([
            'metre_id' => $other->id, 'lot_id' => $this->lot->id, 'is_tender_line_b' => true,
            'tender_supp1_price' => 900, 'tender_supp1_quantity' => 1,
        ]);

        // Sans portée : les deux métrés.
        $this->get("/lots/{$this->lot->id}/tender-comparison")
            ->assertInertia(fn ($page) => $page->has('lines', 2)->where('metre', null));

        // Avec : celui-ci seulement, scores compris.
        $this->get("/lots/{$this->lot->id}/tender-comparison?metre={$this->metre->id}")
            ->assertInertia(fn ($page) => $page
                ->has('lines', 1)
                ->where('metre.id', $this->metre->id)
                // Le score du fournisseur 1 ne compte plus que la ligne de ce métré.
                // En valeur et non à l'identique : un décimal revient en float sur SQLite et en
                // chaîne zéro-remplie sur MySQL - le piège consigné dans le CLAUDE.md.
                ->where('scoring.suppliers.1.sum', fn ($sum) => (float) $sum === 100.0));

        $this->getJson("/api/lots/{$this->lot->id}/tender-scoring?metre={$this->metre->id}")
            ->assertOk()
            ->assertJsonPath('data.scoring.suppliers.1.sum', fn ($sum) => (float) $sum === 100.0);
    }

    /** Un métré d'un autre chantier n'est pas une portée vide, c'est un mauvais lien. */
    public function test_a_metre_from_another_project_is_not_found(): void
    {
        $this->actAsWriter();
        $foreign = Metre::forceCreate(['name' => 'Ailleurs', 'project_id' => 'PRJ-2']);

        $this->get("/lots/{$this->lot->id}/tender-comparison?metre={$foreign->id}")
            ->assertNotFound();
    }
}
