<?php

namespace Tests\Feature;

use App\Models\Lot;
use App\Models\Metre;
use App\Models\MetreLine;
use App\Models\MetreLineComponent;
use App\Models\Reference;
use App\Models\SubReference;
use App\Models\SubReferenceLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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

    /**
     * Le code du lot voyage à part de son nom : la colonne les affiche tous les deux, et un lot
     * sans nom doit rester lisible en tant que lot plutôt que de ressembler à une ligne sans lot.
     */
    public function test_the_page_carries_the_lot_code_beside_its_name(): void
    {
        $this->actAsWriter();

        $named = Lot::forceCreate(['project_id' => self::PROJECT, 'code' => 12, 'title_fr' => 'Toiture']);
        $unnamed = Lot::forceCreate(['project_id' => self::PROJECT, 'code' => 30]);

        $this->line(['lot_id' => $named->id, 'ref_code' => 1]);
        $this->line(['lot_id' => $unnamed->id, 'ref_code' => 2]);
        $this->line(['ref_code' => 3]);

        $this->get($this->url())->assertInertia(fn ($page) => $page
            ->where('lines.0.lot_code', 12)
            ->where('lines.0.lot_name', 'Toiture')
            // Le nom manque, le code est là : c'est ce qui distingue les deux cas à l'écran.
            ->where('lines.1.lot_code', 30)
            ->where('lines.1.lot_name', null)
            ->where('lines.2.lot_code', null)
            ->where('lines.2.lot_name', null));
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

    // --- filing a line by hand -----------------------------------------------------------

    /**
     * METL_NewFromREF's second branch: a sub-section defined on the spot, code and title typed in
     * the section header, existing in no catalogue. The source's dialog says as much -
     * « Choisissez une section ou définissez en une nouvelle (code et titre) » - and it is why a
     * line's section is a copy rather than a link: here there is nothing to link to.
     */
    public function test_a_line_can_be_filed_under_a_section_defined_on_the_spot(): void
    {
        $this->actAsWriter();

        $this->postJson("/api/metres/{$this->metre->id}/lines", [
            'ref_code' => 20,
            'ref_title' => 'SOLS',
            'refs_code' => 99,
            'refs_title' => 'Reprises diverses',
        ])->assertCreated()
            ->assertJsonPath('data.ref_title', 'SOLS')
            ->assertJsonPath('data.refs_title', 'Reprises diverses')
            ->assertJsonPath('data.computed.ref_line_code', '20.99.1');

        $line = MetreLine::sole();
        $this->assertNull($line->reference_id, 'aucun catalogue derrière cette section');
        $this->assertNull($line->sub_reference_id);
        $this->assertSame(0, SubReference::count());
    }

    /** L'autre branche : une ligne de plus dans un groupe qui existe déjà, donc le rang suivant. */
    public function test_a_line_added_to_an_existing_group_takes_the_next_rank(): void
    {
        $this->actAsWriter();
        $section = ['ref_code' => 20, 'ref_title' => 'SOLS', 'refs_code' => 8, 'refs_title' => 'Carrelage'];

        $this->postJson("/api/metres/{$this->metre->id}/lines", $section)
            ->assertCreated()->assertJsonPath('data.computed.ref_line_code', '20.8.1');
        $this->postJson("/api/metres/{$this->metre->id}/lines", $section)
            ->assertCreated()->assertJsonPath('data.computed.ref_line_code', '20.8.2');
    }

    public function test_an_empty_line_still_takes_no_section(): void
    {
        $this->actAsWriter();

        $this->postJson("/api/metres/{$this->metre->id}/lines", [])
            ->assertCreated()
            ->assertJsonPath('data.ref_code', null)
            ->assertJsonPath('data.computed.ref_line_code', null);
    }

    /**
     * Une sous-section sans titre s'imprimerait comme un intitulé illisible, et la section d'une
     * ligne ne se modifie plus après coup : ce serait changer le code imprimé d'une ligne déjà
     * portée sur un document.
     */
    public function test_a_new_sub_section_needs_a_code_and_a_title(): void
    {
        $this->actAsWriter();

        $this->postJson("/api/metres/{$this->metre->id}/lines", ['refs_code' => 99])
            ->assertStatus(422)->assertJsonValidationErrors('refs_title');

        $this->postJson("/api/metres/{$this->metre->id}/lines", ['refs_code' => null, 'refs_title' => 'Divers'])
            ->assertStatus(422)->assertJsonValidationErrors('refs_code');

        $this->postJson("/api/metres/{$this->metre->id}/lines", ['quantity' => 5])
            ->assertStatus(422)->assertJsonValidationErrors('quantity');

        $line = $this->line(['ref_code' => 20, 'refs_code' => 8]);
        $this->patchJson("/api/metre-lines/{$line->id}", ['ref_code' => 30])
            ->assertStatus(422)->assertJsonValidationErrors('ref_code');
    }

    /**
     * PriceTotal*All_c : le montant d'une ligne, options comprises. La liste FileMaker affiche
     * celui-là par ligne et n'écarte les options que dans ses sous-totaux - une option montre ce
     * qu'elle coûterait sans peser sur le total.
     */
    public function test_a_line_carries_both_its_own_total_and_the_gated_one(): void
    {
        $this->actAsWriter();
        $this->line([
            'is_option_b' => true,
            'quantity' => 2, 'price_buy' => 50, 'price_sales' => 100,
            'quantity_ordered' => 2, 'price_ordered' => 80,
        ]);

        $this->get($this->url())->assertInertia(fn ($page) => $page
            ->where('lines.0.computed.price_total_buy_all', 100)
            ->where('lines.0.computed.price_total_sales_all', 200)
            ->where('lines.0.computed.price_total_ordered_all', 160)
            // Les mêmes, écartés parce que la ligne est en option : ce sont eux qu'additionnent
            // les sous-totaux et les totaux du métré.
            ->where('lines.0.computed.price_total_buy_no_options', 0)
            ->where('lines.0.computed.price_total_sales_no_options', 0)
            ->where('lines.0.computed.price_total_ordered_no_options', 0)
            ->etc());
    }

    // --- the reference catalogue -----------------------------------------------------------

    /**
     * How a métré is actually filled in the FileMaker application (METL_New_Multi): one line per
     * chosen catalogue item, carrying its localised title, its unit and its price - and the price
     * lands on PriceBuy, because the catalogue is a purchase-price book.
     *
     * The figures below are the shape of the real data: reference "SOLS" code 20, sub-reference
     * "Carrelage" code 8, an item priced per m2.
     */
    public function test_it_creates_one_line_per_catalogue_item(): void
    {
        $this->actAsWriter();
        [$reference, $subReference, $item] = $this->catalogue();

        $response = $this->postJson("/api/metres/{$this->metre->id}/lines/from-catalogue", [
            'sub_reference_line_ids' => [$item->id],
        ])->assertCreated();

        $response->assertJsonPath('data.0.refsl_title', 'Carrelage 30x30')
            // `description` reste vide : c'est la note libre du source, pas le titre.
            ->assertJsonPath('data.0.description', null)
            ->assertJsonPath('data.0.unit', 'm2')
            ->assertJsonPath('data.0.price_buy', 48.5)
            // La section est recopiée sur la ligne, pas jointe.
            ->assertJsonPath('data.0.ref_code', 20)
            ->assertJsonPath('data.0.ref_title', 'SOLS')
            ->assertJsonPath('data.0.refs_code', 8)
            ->assertJsonPath('data.0.refs_title', 'Carrelage')
            // METL::REFSL_Code_c
            ->assertJsonPath('data.0.computed.ref_line_code', '20.8.1');

        $line = MetreLine::sole();
        $this->assertSame($reference->id, $line->reference_id, 'la provenance est conservée');
        $this->assertSame($subReference->id, $line->sub_reference_id);
        $this->assertSame($item->id, $line->sub_reference_line_id);
        $this->assertSame(1, (int) $line->ref_order);
    }

    /**
     * The rank counts inside one section, from 1, and is the third component of the code. Two
     * items of the same sub-section give 1 and 2; an item of another sub-section starts again
     * at 1, which is what makes "20.8.1" and "20.2.1" two different lines.
     */
    public function test_the_rank_counts_within_the_section(): void
    {
        $this->actAsWriter();
        [$reference, $subReference, $item] = $this->catalogue();

        $other = SubReference::forceCreate([
            'reference_id' => $reference->id, 'code' => 2, 'title_fr' => 'Sols souples',
        ]);
        $otherItem = SubReferenceLine::forceCreate([
            'sub_reference_id' => $other->id, 'reference_id' => $reference->id,
            'code' => 1, 'title_fr' => 'Linoléum', 'unit' => 'm2', 'price' => 30,
        ]);

        $this->postJson("/api/metres/{$this->metre->id}/lines/from-catalogue", [
            'sub_reference_line_ids' => [$item->id, $item->id, $otherItem->id],
        ])->assertCreated()
            ->assertJsonPath('data.0.computed.ref_line_code', '20.8.1')
            // Le même article deux fois : deux lignes, deux rangs.
            ->assertJsonPath('data.1.computed.ref_line_code', '20.8.2')
            // Une autre sous-section repart à 1.
            ->assertJsonPath('data.2.computed.ref_line_code', '20.2.1');
    }

    /**
     * The title is copied in the MÉTRÉ's language, not the interface's - a métré is a document
     * with a language of its own. The source read a UI global instead, so the same item entered
     * the same day landed in French or in English depending on who was looking.
     */
    public function test_the_title_is_copied_in_the_metres_language(): void
    {
        $this->actAsWriter();
        [, , $item] = $this->catalogue();
        $this->metre->forceFill(['language' => 'EN'])->save();

        $this->postJson("/api/metres/{$this->metre->id}/lines/from-catalogue", [
            'sub_reference_line_ids' => [$item->id],
        ])->assertCreated()
            ->assertJsonPath('data.0.refsl_title', 'Tiles 30x30')
            ->assertJsonPath('data.0.ref_title', 'FLOOR')
            ->assertJsonPath('data.0.refs_title', 'Tiles');
    }

    /**
     * Title_NL is empty on all 19 references, all 118 sub-references and all 507 items of the
     * live file, so the source formula yields an empty section title for a Dutch métré. It falls
     * back here: an empty heading on a client document is worse than a French one.
     */
    public function test_a_missing_translation_falls_back_rather_than_blanking_the_title(): void
    {
        $this->actAsWriter();
        [, , $item] = $this->catalogue();
        $this->metre->forceFill(['language' => 'NL'])->save();

        $this->postJson("/api/metres/{$this->metre->id}/lines/from-catalogue", [
            'sub_reference_line_ids' => [$item->id],
        ])->assertCreated()->assertJsonPath('data.0.ref_title', 'SOLS');
    }

    /** The catalogue never becomes a way around the two guards on writing lines. */
    public function test_a_locked_metre_and_a_readonly_account_refuse_catalogue_insertion(): void
    {
        [, , $item] = $this->catalogue();
        $payload = ['sub_reference_line_ids' => [$item->id]];

        $this->actAsWriter();
        $this->metre->forceFill(['is_locked_b' => true])->save();
        $this->postJson("/api/metres/{$this->metre->id}/lines/from-catalogue", $payload)->assertStatus(423);
        $this->metre->forceFill(['is_locked_b' => false])->save();

        $this->actingAs(User::factory()->readOnly()->create());
        $this->postJson("/api/metres/{$this->metre->id}/lines/from-catalogue", $payload)->assertStatus(403);

        $this->assertSame(0, MetreLine::count());
    }

    public function test_an_unknown_catalogue_item_is_rejected(): void
    {
        $this->actAsWriter();

        $this->postJson("/api/metres/{$this->metre->id}/lines/from-catalogue", [
            'sub_reference_line_ids' => [(string) Str::uuid()],
        ])->assertStatus(422)->assertJsonValidationErrors('sub_reference_line_ids.0');

        $this->postJson("/api/metres/{$this->metre->id}/lines/from-catalogue", [
            'sub_reference_line_ids' => [],
        ])->assertStatus(422)->assertJsonValidationErrors('sub_reference_line_ids');
    }

    /**
     * A line's section, its title and its rank are a snapshot: renaming the catalogue entry
     * afterwards must not rewrite a métré that may already have been sent to a client. That is
     * the whole reason the source copies instead of linking.
     */
    public function test_renaming_a_catalogue_entry_leaves_existing_lines_alone(): void
    {
        $this->actAsWriter();
        [$reference, , $item] = $this->catalogue();

        $this->postJson("/api/metres/{$this->metre->id}/lines/from-catalogue", [
            'sub_reference_line_ids' => [$item->id],
        ])->assertCreated();

        $reference->forceFill(['title_fr' => 'REVÊTEMENTS DE SOL', 'code' => 21])->save();
        $item->forceFill(['title_fr' => 'Carrelage 60x60', 'price' => 99])->save();

        $line = MetreLine::sole();
        $this->assertSame('SOLS', $line->ref_title);
        $this->assertSame(20, (int) $line->ref_code);
        $this->assertSame('Carrelage 30x30', $line->refsl_title);
        $this->assertEquals(48.5, $line->price_buy);
        $this->assertSame('20.8.1', $line->refLineCode());
    }

    /** The catalogue is readable by anyone who may see a métré, including a readonly account. */
    public function test_the_catalogue_is_served_as_a_three_level_tree(): void
    {
        $this->actingAs(User::factory()->readOnly()->create());
        $this->catalogue();

        $this->getJson('/api/references/catalogue')
            ->assertOk()
            ->assertJsonPath('data.0.code', 20)
            ->assertJsonPath('data.0.title', 'SOLS')
            ->assertJsonPath('data.0.sub_references.0.code', 8)
            ->assertJsonPath('data.0.sub_references.0.title', 'Carrelage')
            ->assertJsonPath('data.0.sub_references.0.lines.0.title', 'Carrelage 30x30')
            ->assertJsonPath('data.0.sub_references.0.lines.0.unit', 'm2')
            ->assertJsonPath('data.0.sub_references.0.lines.0.price', 48.5);
    }

    /**
     * The order a métré's lines are listed in is METL_Sort's: section, sub-section, then rank.
     * Not the order they were created in - a line added to section 20 belongs under 20, above
     * everything in section 60, however late it was typed. Ascending with no special treatment
     * of empties, so a sectionless line leads: that is what FileMaker does, an empty number
     * sorting before 0.
     */
    public function test_the_lines_are_listed_in_section_order(): void
    {
        $this->actAsWriter();
        $this->line(['description' => 'sans section', 'sort_order' => 1]);
        $this->line(['description' => 'électricité', 'ref_code' => 60, 'refs_code' => 4, 'ref_order' => 1, 'sort_order' => 2]);
        $this->line(['description' => 'sols, rang 2', 'ref_code' => 20, 'refs_code' => 8, 'ref_order' => 2, 'sort_order' => 3]);
        $this->line(['description' => 'sols, rang 1', 'ref_code' => 20, 'refs_code' => 8, 'ref_order' => 1, 'sort_order' => 4]);

        $this->get($this->url())->assertInertia(fn ($page) => $page
            // Une ligne sans section passe en tête, comme dans FileMaker où un nombre vide se
            // trie avant 0.
            ->where('lines.0.description', 'sans section')
            ->where('lines.1.description', 'sols, rang 1')
            ->where('lines.2.description', 'sols, rang 2')
            ->where('lines.3.description', 'électricité')
            ->etc());
    }

    /**
     * @return array{0: Reference, 1: SubReference, 2: SubReferenceLine}
     */
    private function catalogue(): array
    {
        $reference = Reference::forceCreate(['code' => 20, 'title_fr' => 'SOLS', 'title_en' => 'FLOOR']);
        $subReference = SubReference::forceCreate([
            'reference_id' => $reference->id, 'code' => 8, 'title_fr' => 'Carrelage', 'title_en' => 'Tiles',
        ]);
        $item = SubReferenceLine::forceCreate([
            'sub_reference_id' => $subReference->id, 'reference_id' => $reference->id,
            'code' => 1, 'title_fr' => 'Carrelage 30x30', 'title_en' => 'Tiles 30x30',
            'unit' => 'm2', 'price' => 48.5,
        ]);

        return [$reference, $subReference, $item];
    }

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
