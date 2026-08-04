<?php

namespace Tests\Feature;

use App\Jobs\RecalculateMetreTotals;
use App\Models\Lot;
use App\Models\Metre;
use App\Models\MetreLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Le lot posé sur une sélection de lignes - METL_Lot_AssignToSelection.
 *
 * Le geste porte sur des centaines de lignes d'argent d'un coup, donc ce qui est vérifié ici n'est
 * pas seulement « le lot est écrit » : c'est que RIEN D'AUTRE ne l'est, que la sélection ne peut
 * pas emporter une ligne d'un autre métré, et qu'un lot d'un autre projet est refusé - la même
 * règle que sur une ligne seule, dont l'erreur se ferait ici au centuple.
 */
class MetreLineBulkLotTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT = 'PRJ-1A2B3C';

    private Metre $metre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->metre = Metre::forceCreate(['project_id' => self::PROJECT, 'name' => 'Métré A']);
    }

    private function actAsWriter(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    private function line(array $attributes = []): MetreLine
    {
        return MetreLine::forceCreate($attributes + ['metre_id' => $this->metre->id]);
    }

    private function lot(array $attributes = []): Lot
    {
        return Lot::forceCreate($attributes + ['project_id' => self::PROJECT, 'code' => 1]);
    }

    private function url(?Metre $metre = null): string
    {
        return '/api/metres/'.($metre ?? $this->metre)->id.'/lines/assign-lot';
    }

    // --- ce que le geste fait ----------------------------------------------------------------

    public function test_it_assigns_one_lot_to_every_selected_line(): void
    {
        $this->actAsWriter();

        $lot = $this->lot(['code' => 2, 'title_fr' => 'Toiture']);
        $first = $this->line(['refsl_title' => 'A']);
        $second = $this->line(['refsl_title' => 'B']);
        $untouched = $this->line(['refsl_title' => 'C']);

        $this->postJson($this->url(), [
            'line_ids' => [$first->id, $second->id],
            'lot_id' => $lot->id,
        ])->assertOk();

        $this->assertSame($lot->id, $first->fresh()->lot_id);
        $this->assertSame($lot->id, $second->fresh()->lot_id);
        $this->assertNull($untouched->fresh()->lot_id, 'Une ligne non cochée ne doit pas bouger.');
    }

    /** La réponse sert à remettre les lignes à jour à l'écran : elle porte le nom du lot. */
    public function test_it_returns_the_updated_lines_with_the_lot_name(): void
    {
        $this->actAsWriter();

        $lot = $this->lot(['code' => 3, 'title_fr' => 'Électricité']);
        $line = $this->line();

        $response = $this->postJson($this->url(), [
            'line_ids' => [$line->id],
            'lot_id' => $lot->id,
        ])->assertOk();

        $response->assertJsonPath('data.0.id', $line->id)
            ->assertJsonPath('data.0.lot_id', $lot->id)
            ->assertJsonPath('data.0.lot_name', 'Électricité');
    }

    /** Détacher est un geste légitime, et c'est ce que fait le script avec un `$LOT` vide. */
    public function test_a_null_lot_detaches_the_selection(): void
    {
        $this->actAsWriter();

        $lot = $this->lot();
        $line = $this->line(['lot_id' => $lot->id]);

        $this->postJson($this->url(), ['line_ids' => [$line->id], 'lot_id' => null])->assertOk();

        $this->assertNull($line->fresh()->lot_id);
    }

    public function test_the_lot_key_must_be_sent_even_to_detach(): void
    {
        $this->actAsWriter();

        // Sans `present`, un champ oublié par un appelant vaudrait « détache tout ».
        $this->postJson($this->url(), ['line_ids' => [$this->line()->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('lot_id');
    }

    /**
     * Le point le plus important du lot : l'écriture ne touche QUE le lot.
     *
     * Une seule instruction plutôt qu'une boucle de `save()`, donc l'observateur ne tourne pas -
     * sa règle « pm » viderait les quantités d'une ligne pour mémoire qui en porte encore, et
     * c'est exactement ce qu'un changement de lot n'a pas à faire.
     */
    public function test_it_writes_nothing_but_the_lot(): void
    {
        $this->actAsWriter();

        $lot = $this->lot();
        $line = $this->line([
            'refsl_title' => 'Poste pour mémoire',
            'unit' => 'pm',
            'price_buy' => 100,
            'price_sales' => 150,
            'ref_code' => 20,
            'ref_title' => 'SOLS',
            'refs_code' => 8,
            'refs_title' => 'Chape',
            'ref_order' => 1,
        ]);

        // Une ligne « pm » qui porte encore des quantités ne peut pas être créée par les modèles -
        // l'observateur les vide à l'enregistrement. C'est en revanche à quoi ressemble une ligne
        // importée, et c'est précisément ce qu'un changement de lot ne doit pas « corriger ».
        DB::table('metre_lines')->where('id', $line->id)->update(['quantity' => 4, 'quantity_ordered' => 4]);

        $before = $line->fresh()->getAttributes();
        $this->assertEquals(4, $before['quantity'], 'Le montage du test doit bien poser la quantité.');

        $this->postJson($this->url(), ['line_ids' => [$line->id], 'lot_id' => $lot->id])->assertOk();

        $after = $line->fresh()->getAttributes();

        foreach ($before as $column => $value) {
            if (in_array($column, ['lot_id', 'updated_at'], true)) {
                continue;
            }

            $this->assertEquals($value, $after[$column], "La colonne {$column} a changé.");
        }

        // Nommément, puisque c'est la règle de l'observateur qu'on écarte.
        $this->assertEquals(4, $after['quantity']);
        $this->assertEquals(4, $after['quantity_ordered']);
        $this->assertEquals(1, $after['ref_order'], 'Le rang imprimé ne doit pas être réattribué.');
    }

    /**
     * Le recalcul est demandé une fois, et il est nécessaire : GainOnPurchases_c ne compte une
     * ligne que si son lot porte une société fournisseur, donc changer de lot déplace ce total.
     */
    public function test_it_queues_one_recalculation_for_the_metre(): void
    {
        Queue::fake();
        $this->actAsWriter();

        $lot = $this->lot();
        $lines = [$this->line()->id, $this->line()->id, $this->line()->id];

        $this->postJson($this->url(), ['line_ids' => $lines, 'lot_id' => $lot->id])->assertOk();

        Queue::assertPushed(RecalculateMetreTotals::class, 1);
    }

    /** Le total concerné bouge réellement, sans qu'aucun prix n'ait été touché. */
    public function test_assigning_a_supplier_lot_moves_the_purchase_gain(): void
    {
        $this->actAsWriter();

        // `tot_lot_assigned_gain_on_purchase_stored` n'est renseigné que pour un métré « sur site » :
        // sans cela le total reste nul et le test ne prouverait rien.
        $this->metre->forceFill(['is_status_site_b' => true])->save();

        $lot = $this->lot(['code' => 4, 'company_id' => 'CPY-9']);
        $line = $this->line([
            'price_buy' => 100,
            'quantity' => 2,
            'price_ordered' => 80,
            'quantity_ordered' => 2,
        ]);

        (new RecalculateMetreTotals($this->metre))->handle();
        $this->assertEquals(0, $this->metre->fresh()->tot_lot_assigned_gain_on_purchase_stored);

        $this->postJson($this->url(), ['line_ids' => [$line->id], 'lot_id' => $lot->id])->assertOk();
        (new RecalculateMetreTotals($this->metre->fresh()))->handle();

        // (100 × 2) − (80 × 2) = 40, compté seulement parce que le lot porte une société.
        $this->assertEquals(40, $this->metre->fresh()->tot_lot_assigned_gain_on_purchase_stored);
    }

    // --- ce que le geste refuse --------------------------------------------------------------

    /**
     * `exists` ne dit que « cette ligne existe ». Sans le contrôle de portée, une requête forgée
     * poserait un lot sur les lignes d'un autre métré en passant par un métré qu'on a le droit
     * d'écrire.
     */
    public function test_it_refuses_a_selection_holding_a_line_of_another_metre(): void
    {
        $this->actAsWriter();

        $other = Metre::forceCreate(['project_id' => self::PROJECT, 'name' => 'Métré B']);
        $stranger = MetreLine::forceCreate(['metre_id' => $other->id]);
        $mine = $this->line();
        $lot = $this->lot();

        $this->postJson($this->url(), [
            'line_ids' => [$mine->id, $stranger->id],
            'lot_id' => $lot->id,
        ])->assertStatus(422)->assertJsonValidationErrors('line_ids');

        // Refusé en bloc : la ligne légitime ne doit pas être écrite non plus.
        $this->assertNull($mine->fresh()->lot_id);
        $this->assertNull($stranger->fresh()->lot_id);
    }

    public function test_it_refuses_a_lot_from_another_project(): void
    {
        $this->actAsWriter();

        $foreign = Lot::forceCreate(['project_id' => 'PRJ-OTHER', 'code' => 9]);
        $line = $this->line();

        $this->postJson($this->url(), ['line_ids' => [$line->id], 'lot_id' => $foreign->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('lot_id');

        $this->assertNull($line->fresh()->lot_id);
    }

    public function test_it_refuses_an_empty_selection(): void
    {
        $this->actAsWriter();

        $this->postJson($this->url(), ['line_ids' => [], 'lot_id' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors('line_ids');
    }

    public function test_it_refuses_an_unknown_line(): void
    {
        $this->actAsWriter();

        $this->postJson($this->url(), ['line_ids' => [Str::uuid()->toString()], 'lot_id' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors('line_ids.0');
    }

    /** `role.write`, posé sur la route et non déduit du verbe. */
    public function test_a_readonly_account_may_not_assign_a_lot(): void
    {
        $this->actingAs(User::factory()->readOnly()->create());

        $lot = $this->lot();
        $line = $this->line();

        $this->postJson($this->url(), ['line_ids' => [$line->id], 'lot_id' => $lot->id])
            ->assertForbidden();

        $this->assertNull($line->fresh()->lot_id);
    }

    public function test_a_locked_metre_refuses_the_assignment(): void
    {
        $this->actAsWriter();

        $this->metre->forceFill(['is_locked_b' => true])->save();
        $lot = $this->lot();
        $line = $this->line();

        $this->postJson($this->url(), ['line_ids' => [$line->id], 'lot_id' => $lot->id])
            ->assertStatus(423);

        $this->assertNull($line->fresh()->lot_id);
    }
}
