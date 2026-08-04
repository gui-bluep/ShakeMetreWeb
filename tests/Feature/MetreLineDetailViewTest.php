<?php

namespace Tests\Feature;

use App\Models\Lot;
use App\Models\Metre;
use App\Models\MetreLine;
use App\Models\MetreLineComponent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The four money views of a métré's lines - "Achats — Ventes — Commandes" and its three narrower
 * cuts, all rendered by one page: what the page carries, the line create/duplicate/
 * delete actions, and the fields it adds to the shared metre-line whitelist.
 */
class MetreLineDetailViewTest extends TestCase
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

    private function url(string $view = 'achats-ventes-commandes'): string
    {
        return "/metres/{$this->metre->id}/lines/{$view}";
    }

    // --- the four views --------------------------------------------------------------------

    /**
     * One page, four cuts of it: the slug says which money blocks are on screen and nothing
     * else. Pinned here rather than in the template because it is what a view *is* - the page
     * only decides how a block looks.
     */
    public static function viewProvider(): array
    {
        return [
            'all three' => ['achats-ventes-commandes', ['achats', 'ventes', 'commandes']],
            'achats — ventes' => ['achats-ventes', ['achats', 'ventes']],
            'achats — commandes' => ['achats-commandes', ['achats', 'commandes']],
            'ventes' => ['ventes', ['ventes']],
        ];
    }

    #[DataProvider('viewProvider')]
    public function test_each_view_renders_with_its_own_blocks(string $view, array $blocks): void
    {
        $this->actAsWriter();
        $this->line(['description' => 'Terrassement', 'quantity' => 2, 'price_buy' => 50]);

        $this->get($this->url($view))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Metres/LinesDetail')
                ->where('view', $view)
                ->where('blocks', $blocks)
                ->has('lines', 1));
    }

    /**
     * The payload does not change with the view: a line carries the same fields and the same
     * computed values everywhere, so switching cut cannot change what a row means - only which
     * columns are drawn. It is also what lets the narrow views reuse the wide one's resource.
     */
    #[DataProvider('viewProvider')]
    public function test_every_view_carries_the_same_line_payload(string $view): void
    {
        $this->actAsWriter();
        $this->line([
            'description' => 'Terrassement',
            'quantity' => 10, 'price_buy' => 70, 'price_sales' => 100,
            'quantity_ordered' => 8, 'price_ordered' => 80,
        ]);

        $this->get($this->url($view))
            ->assertInertia(fn ($page) => $page
                ->where('lines.0.computed.price_total_buy_no_options', 700)
                ->where('lines.0.computed.price_total_sales_no_options', 1000)
                ->where('lines.0.computed.price_total_ordered_no_options', 640)
                // Computed for every view, displayed only where both prices are on screen.
                ->where('lines.0.computed.price_ratio', 1.43)
                ->etc());
    }

    public function test_an_unknown_view_is_not_a_page(): void
    {
        $this->actAsWriter();

        $this->get($this->url('achats-gains'))->assertNotFound();
        $this->get($this->url('commandes'))->assertNotFound();
    }

    // --- the page --------------------------------------------------------------------------

    public function test_the_page_lists_the_lines_with_the_three_blocks(): void
    {
        $this->actAsWriter();

        $this->line([
            'description' => 'Terrassement',
            'unit' => 'm2',
            'quantity' => 10,
            'price_buy' => 70,
            'price_sales' => 100,
            'quantity_ordered' => 8,
            'price_ordered' => 80,
        ]);

        $this->get($this->url())->assertInertia(fn ($page) => $page
            ->component('Metres/LinesDetail')
            ->where('metre.name', 'Métré A')
            ->where('lines.0.description', 'Terrassement')
            ->where('lines.0.unit', 'm2')
            // Achats and Vendu client share `quantity`; only the order block has its own.
            ->where('lines.0.quantity', 10)
            ->where('lines.0.quantity_ordered', 8)
            ->where('lines.0.computed.price_total_buy_no_options', 700)
            ->where('lines.0.computed.price_total_sales_no_options', 1000)
            ->where('lines.0.computed.price_total_ordered_no_options', 640)
            ->where('units', ["m'", 'm2', 'm3', 'Ff', 'Pce', 'Pm']));
    }

    /** An option line contributes nothing to any of the three totals. */
    public function test_an_option_line_totals_zero_in_all_three_blocks(): void
    {
        $this->actAsWriter();

        $this->line([
            'is_option_b' => true,
            'quantity' => 10, 'price_buy' => 70, 'price_sales' => 100,
            'quantity_ordered' => 10, 'price_ordered' => 80,
        ]);

        $this->get($this->url())->assertInertia(fn ($page) => $page
            ->where('lines.0.computed.price_total_buy_no_options', 0)
            ->where('lines.0.computed.price_total_sales_no_options', 0)
            ->where('lines.0.computed.price_total_ordered_no_options', 0));
    }

    /**
     * The ratio is derived from the two unit prices, not read from the stored METL::Ratio column
     * - which nothing in the export claims to maintain, so a stored copy could disagree with the
     * prices printed beside it.
     */
    public function test_the_ratio_is_computed_from_the_two_unit_prices(): void
    {
        $this->actAsWriter();

        // 150 / 100 = 1.5, and the stale stored column says something else entirely.
        $this->line(['price_sales' => 150, 'price_buy' => 100, 'ratio' => 9.99]);

        $this->get($this->url())->assertInertia(fn ($page) => $page
            ->where('lines.0.computed.price_ratio', 1.5));
    }

    public function test_the_ratio_rounds_to_two_decimals(): void
    {
        $this->actAsWriter();

        // 100 / 3 = 33.333... -> 33.33
        $this->line(['price_sales' => 100, 'price_buy' => 3]);

        $this->get($this->url())->assertInertia(fn ($page) => $page
            ->where('lines.0.computed.price_ratio', 33.33));
    }

    /** A zero purchase price yields no ratio rather than a division error. */
    public function test_the_ratio_is_null_when_the_purchase_price_is_zero(): void
    {
        $this->actAsWriter();
        $this->line(['price_sales' => 150, 'price_buy' => 0]);

        $this->get($this->url())->assertInertia(fn ($page) => $page
            ->where('lines.0.computed.price_ratio', null));
    }

    public function test_the_ratio_is_null_when_either_price_is_absent(): void
    {
        $this->actAsWriter();
        $this->line(['price_sales' => 150, 'price_buy' => null]);
        $this->line(['price_sales' => null, 'price_buy' => 100]);

        $this->get($this->url())->assertInertia(fn ($page) => $page
            ->where('lines.0.computed.price_ratio', null)
            ->where('lines.1.computed.price_ratio', null));
    }

    /** A sales price of exactly zero is a real answer, not an absent one. */
    public function test_a_zero_sales_price_gives_a_ratio_of_zero(): void
    {
        $this->actAsWriter();
        $this->line(['price_sales' => 0, 'price_buy' => 100]);

        $this->get($this->url())->assertInertia(fn ($page) => $page
            ->where('lines.0.computed.price_ratio', 0));
    }

    public function test_the_ratio_cannot_be_written(): void
    {
        $this->actAsWriter();

        $this->patchJson("/api/metre-lines/{$this->line()->id}", ['price_ratio' => 5])
            ->assertStatus(422)
            ->assertJsonValidationErrors('price_ratio');
    }

    /** The shared PATCH response carries it, so the view stays in step after a save. */
    public function test_the_patch_response_carries_the_recomputed_ratio(): void
    {
        $this->actAsWriter();
        $line = $this->line(['price_sales' => 150, 'price_buy' => 100]);

        $this->patchJson("/api/metre-lines/{$line->id}", ['price_buy' => 50])
            ->assertOk()
            ->assertJsonPath('data.computed.price_ratio', 3);
    }

    public function test_the_page_offers_only_this_projects_lots(): void
    {
        $this->actAsWriter();

        $mine = Lot::forceCreate(['project_id' => self::PROJECT, 'code' => 1, 'title_fr' => 'Gros oeuvre']);
        Lot::forceCreate(['project_id' => 'PRJ-OTHER', 'code' => 9, 'title_fr' => 'Autre projet']);

        $this->get($this->url())->assertInertia(fn ($page) => $page
            ->has('lots', 1)
            ->where('lots.0.id', $mine->id));
    }

    public function test_the_page_carries_the_assigned_lot_name(): void
    {
        $this->actAsWriter();

        $lot = Lot::forceCreate(['project_id' => self::PROJECT, 'code' => 2, 'title_fr' => 'Toiture']);
        $this->line(['lot_id' => $lot->id]);

        $this->get($this->url())->assertInertia(fn ($page) => $page
            ->where('lines.0.lot_id', $lot->id)
            ->where('lines.0.lot_name', 'Toiture'));
    }

    public function test_a_readonly_account_may_view_the_page(): void
    {
        $this->actingAs(User::factory()->readOnly()->create());

        $this->get($this->url())->assertOk();
    }

    // --- the fields this view adds to the whitelist -----------------------------------------

    public function test_it_writes_the_fields_this_view_adds(): void
    {
        $this->actAsWriter();
        $line = $this->line();

        $this->patchJson("/api/metre-lines/{$line->id}", [
            'unit' => 'Pce',
            'is_estimated_price_b' => true,
            'is_delivered_b' => true,
            'comment_client' => 'côté client',
            'comment_supplier' => 'côté fournisseur',
        ])->assertOk();

        $fresh = $line->fresh();
        $this->assertSame('Pce', $fresh->unit);
        $this->assertTrue($fresh->is_estimated_price_b);
        $this->assertTrue($fresh->is_delivered_b);
        $this->assertSame('côté client', $fresh->comment_client);
        $this->assertSame('côté fournisseur', $fresh->comment_supplier);
    }

    /**
     * The Achats/Ventes/Commandes view replaces its whole `computed` block from the PATCH
     * response, so the shared endpoint has to return the buy total even though the other grid
     * does not display it - otherwise that view's Achats column blanks itself on every save.
     */
    public function test_the_patch_response_carries_the_buy_total(): void
    {
        $this->actAsWriter();
        $line = $this->line(['quantity' => 10, 'price_buy' => 70]);

        $this->patchJson("/api/metre-lines/{$line->id}", ['quantity' => 20])
            ->assertOk()
            ->assertJsonPath('data.computed.price_total_buy_no_options', 1400);
    }

    public function test_a_unit_outside_the_list_is_rejected(): void
    {
        $this->actAsWriter();

        $this->patchJson("/api/metre-lines/{$this->line()->id}", ['unit' => 'tonnes'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('unit');
    }

    public function test_a_line_can_be_assigned_to_a_lot_of_its_own_project(): void
    {
        $this->actAsWriter();
        $lot = Lot::forceCreate(['project_id' => self::PROJECT, 'code' => 1]);
        $line = $this->line();

        $this->patchJson("/api/metre-lines/{$line->id}", ['lot_id' => $lot->id])->assertOk();

        $this->assertSame($lot->id, $line->fresh()->lot_id);
    }

    /**
     * lot_id is how the tender scoring finds its lines, so a cross-project assignment would put
     * this line into another project's supplier comparison and change what a supplier looks like
     * they quoted.
     */
    public function test_a_lot_from_another_project_is_refused(): void
    {
        $this->actAsWriter();
        $foreign = Lot::forceCreate(['project_id' => 'PRJ-OTHER', 'code' => 9]);
        $line = $this->line();

        $this->patchJson("/api/metre-lines/{$line->id}", ['lot_id' => $foreign->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('lot_id');

        $this->assertNull($line->fresh()->lot_id);
    }

    public function test_the_computed_buy_total_cannot_be_written(): void
    {
        $this->actAsWriter();

        $this->patchJson("/api/metre-lines/{$this->line()->id}", ['price_total_buy_no_options' => 999])
            ->assertStatus(422)
            ->assertJsonValidationErrors('price_total_buy_no_options');
    }

    // --- create / duplicate / delete ---------------------------------------------------------

    public function test_it_appends_an_empty_line(): void
    {
        $this->actAsWriter();
        $this->line(['sort_order' => 4]);

        $response = $this->postJson("/api/metres/{$this->metre->id}/lines", []);

        $response->assertCreated()->assertJsonPath('data.sort_order', 5);
        $this->assertSame(2, $this->metre->metreLines()->count());
    }

    public function test_it_duplicates_a_line_with_its_components(): void
    {
        $this->actAsWriter();
        $line = $this->line(['description' => 'Terrassement', 'quantity' => 3, 'price_buy' => 10]);
        MetreLineComponent::forceCreate([
            'metre_line_id' => $line->id, 'description' => 'Zone A', 'quantity_sales' => 2,
        ]);

        $response = $this->postJson("/api/metre-lines/{$line->id}/duplicate", []);

        $response->assertCreated()->assertJsonPath('data.description', 'Terrassement');

        $copy = MetreLine::where('id', '!=', $line->id)->sole();
        $this->assertNotSame($line->id, $copy->id);
        $this->assertSame(1, $copy->metreLineComponents()->count());
    }

    /** A copy has not been ordered through anything, nor delivered. */
    public function test_a_duplicated_line_drops_the_supplier_order_and_delivery(): void
    {
        $this->actAsWriter();
        $line = $this->line([
            'supplier_order_id' => 'SOR-1',
            'sor_title_ref' => 'Commande 42',
            'is_delivered_b' => true,
        ]);

        $this->postJson("/api/metre-lines/{$line->id}/duplicate", [])->assertCreated();

        $copy = MetreLine::where('id', '!=', $line->id)->sole();
        $this->assertNull($copy->supplier_order_id);
        $this->assertNull($copy->sor_title_ref);
        $this->assertFalse($copy->is_delivered_b);
    }

    public function test_it_deletes_a_line_and_its_components(): void
    {
        $this->actAsWriter();
        $line = $this->line();
        MetreLineComponent::forceCreate(['metre_line_id' => $line->id, 'quantity_sales' => 1]);

        $this->deleteJson("/api/metre-lines/{$line->id}")->assertNoContent();

        $this->assertDatabaseCount('metre_lines', 0);
        $this->assertDatabaseCount('metre_line_components', 0);
    }

    // --- guards ------------------------------------------------------------------------------

    public function test_a_locked_metre_refuses_line_creation_duplication_and_deletion(): void
    {
        $this->actAsWriter();
        $this->metre->forceFill(['is_locked_b' => true])->save();
        $line = $this->line();

        $this->postJson("/api/metres/{$this->metre->id}/lines", [])->assertStatus(423);
        $this->postJson("/api/metre-lines/{$line->id}/duplicate", [])->assertStatus(423);
        $this->deleteJson("/api/metre-lines/{$line->id}")->assertStatus(423);

        $this->assertDatabaseCount('metre_lines', 1);
    }

    public function test_a_readonly_account_cannot_create_duplicate_or_delete(): void
    {
        $this->actingAs(User::factory()->readOnly()->create());
        $line = $this->line();

        $this->postJson("/api/metres/{$this->metre->id}/lines", [])->assertStatus(403);
        $this->postJson("/api/metre-lines/{$line->id}/duplicate", [])->assertStatus(403);
        $this->deleteJson("/api/metre-lines/{$line->id}")->assertStatus(403);

        $this->assertDatabaseCount('metre_lines', 1);
    }

    public function test_a_guest_is_redirected_away(): void
    {
        $this->get($this->url())->assertRedirect('/login');
    }
}
