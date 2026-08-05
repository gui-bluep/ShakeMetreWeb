<?php

namespace Tests\Feature;

use App\Documents\MetreDocument;
use App\Documents\MetreDocumentBuilder;
use App\Models\Metre;
use App\Models\MetreLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Les sept documents du cadre « Documents » - METL_GoTo_Print.
 *
 * Ce qui est vérifié en premier est ce qu'on ne devinerait pas : quelles lignes entrent dans quel
 * document, où tombe le total, et le fait qu'un budget client montre ses options sans les
 * compter. Le PDF lui-même n'est rendu que pour prouver que chaque variante du gabarit tient
 * debout - sur un métré de trois lignes, pour que sept rendus ne coûtent pas sept secondes.
 */
class MetreDocumentTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT = 'PRJ-1A2B3C';

    private Metre $metre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->metre = Metre::forceCreate([
            'project_id' => self::PROJECT,
            'name' => 'Métré A',
            'ind_project' => 3,
            'language' => 'FR',
        ]);

        $this->fakeProject();
    }

    /**
     * Le nom du projet, lu chez ShakeDesign par l'en-tête du document.
     *
     * Appelé par chaque test plutôt que posé dans setUp() : les stubs de Http::fake() s'empilent
     * et le premier motif qui correspond gagne, donc un fake de setUp() rendrait intestable la
     * panne de ShakeDesign. Un motif non couvert, lui, partirait pour de vrai.
     */
    private function fakeProject(): void
    {
        Http::fake([
            '*/sessions' => Http::response([
                'response' => ['token' => 'tok-1'],
                'messages' => [['code' => '0', 'message' => 'OK']],
            ]),
            '*/layouts/API_PRJ/_find' => Http::response([
                'response' => ['data' => [['fieldData' => [
                    'zkp' => self::PROJECT, 'Name' => 'Chantier Nord', 'Number' => '2024-045',
                ], 'recordId' => '1']]],
                'messages' => [['code' => '0', 'message' => 'OK']],
            ]),
        ]);
    }

    private function line(array $attributes = []): MetreLine
    {
        return MetreLine::forceCreate($attributes + [
            'metre_id' => $this->metre->id,
            'ref_code' => 10, 'ref_title' => 'DEMOLITION',
            'refs_code' => 1, 'refs_title' => 'Gros-oeuvre',
        ]);
    }

    private function url(string $document): string
    {
        return "/metres/{$this->metre->id}/documents/{$document}";
    }

    /** @return array<string, array{string}> */
    public static function documentProvider(): array
    {
        return collect(MetreDocument::cases())
            ->mapWithKeys(fn (MetreDocument $d) => [$d->value => [$d->value]])
            ->all();
    }

    // --- ce que chaque document contient -----------------------------------------------------

    /**
     * Le total imprimé exclut les options dans les sept documents, et c'est le même chiffre que
     * celui de la page du métré : les montants sont calculés par la base, comme les totaux
     * stockés, et non par `round()` de PHP, qui ne tranche pas un demi-centime pareil.
     */
    public function test_the_printed_total_excludes_options(): void
    {
        $this->line(['quantity' => 10, 'price_sales' => 100]);          // 1 000
        $this->line(['quantity' => 2, 'price_sales' => 50]);            //   100
        $this->line(['quantity' => 1, 'price_sales' => 9999, 'is_option_b' => true]);

        $built = (new MetreDocumentBuilder($this->metre, MetreDocument::ClientBudget))->build();

        $this->assertEquals(1100, $built['total']);
    }

    /**
     * Un budget client imprime ses options - dans leur propre bloc, après le total. C'est tout
     * l'objet d'une option : dire ce qu'elle coûterait sans l'ajouter à ce qui est dû.
     */
    public function test_a_client_budget_prints_its_options_after_the_total(): void
    {
        $this->line(['quantity' => 1, 'price_sales' => 100]);
        $this->line(['quantity' => 1, 'price_sales' => 40, 'is_option_b' => true]);

        $rows = (new MetreDocumentBuilder($this->metre, MetreDocument::ClientBudget))->build()['rows'];
        $kinds = array_column($rows, 'kind');

        $total = array_search('total', $kinds, true);
        $optionBlock = collect($rows)->search(
            fn ($r) => $r['kind'] === 'option-break' && $r['isOption']
        );

        $this->assertNotFalse($optionBlock, 'Le bloc des options doit exister.');
        $this->assertLessThan($optionBlock, $total, 'Le total précède le bloc des options.');

        // Et il n'y a pas de second total après lui : la source masque les deux objets de la
        // sous-totalisation de queue quand isOption_b est vrai.
        $this->assertSame(1, count(array_keys($kinds, 'total', true)));
    }

    /** Le budget fournisseur ne charge même pas les options : `$Option = 0` resserre le jeu trouvé. */
    public function test_the_supplier_budget_leaves_options_out_entirely(): void
    {
        $this->line(['quantity_ordered' => 2, 'price_ordered' => 100]);
        $this->line(['quantity_ordered' => 1, 'price_ordered' => 500, 'is_option_b' => true]);

        $built = (new MetreDocumentBuilder($this->metre, MetreDocument::SupplierBudgetOrdered))->build();

        $this->assertSame(1, $built['lineCount']);
        $this->assertEquals(200, $built['total']);
        $this->assertNotContains('option-break', array_column($built['rows'], 'kind'));
    }

    /** Le budget fournisseur compte le commandé, qui a sa propre quantité - la seule des trois. */
    public function test_the_supplier_budget_counts_the_ordered_columns(): void
    {
        $this->line([
            'quantity' => 10, 'price_sales' => 100,      // ignorés ici
            'quantity_ordered' => 3, 'price_ordered' => 70,
        ]);

        $built = (new MetreDocumentBuilder($this->metre, MetreDocument::SupplierBudgetOrdered))->build();

        $this->assertEquals(210, $built['total']);
    }

    /** Les deux récapitulatifs n'ont pas de part Body : seuls les totaux de groupe s'impriment. */
    public function test_the_summaries_print_no_line(): void
    {
        $this->line(['quantity' => 1, 'price_sales' => 100]);

        foreach ([MetreDocument::ClientBudgetSummary, MetreDocument::ClientBudgetSummaryBasic] as $d) {
            $kinds = array_column((new MetreDocumentBuilder($this->metre, $d))->build()['rows'], 'kind');

            $this->assertNotContains('line', $kinds, $d->value);
            $this->assertContains('group', $kinds, $d->value);
        }
    }

    /** « catégories » s'arrête à REF ; « sous-catégories » descend à REFS. Toute leur différence. */
    public function test_categories_stops_one_level_above_sub_categories(): void
    {
        $this->line(['quantity' => 1, 'price_sales' => 100]);

        $levels = fn (MetreDocument $d) => array_column(
            array_filter(
                (new MetreDocumentBuilder($this->metre, $d))->build()['rows'],
                fn ($r) => $r['kind'] === 'group',
            ),
            'level',
        );

        $this->assertSame(['ref', 'refs'], array_values($levels(MetreDocument::ClientBudgetSummary)));
        $this->assertSame(['ref'], array_values($levels(MetreDocument::ClientBudgetSummaryBasic)));
    }

    /**
     * Les deux niveaux de tag n'existent que si `MET::Sort_OrderTags` vaut 1 ou 2 - sans quoi
     * `TAG_Choice1_cU` rend la chaîne vide et ne produit aucun groupe. C'est l'état par défaut,
     * et il est délibéré : la bascule Lots/Tags n'est pas tranchée.
     */
    public function test_tags_group_only_when_the_metre_declares_an_order(): void
    {
        $this->line(['quantity' => 1, 'price_sales' => 100, 'tag1' => 'Lot 1', 'tag2' => 'Phase A']);

        $levels = fn () => array_column(
            array_filter(
                (new MetreDocumentBuilder($this->metre->fresh(), MetreDocument::ClientBudget))->build()['rows'],
                fn ($r) => $r['kind'] === 'group',
            ),
            'level',
        );

        $this->assertSame(['ref', 'refs'], array_values($levels()));

        $this->metre->forceFill(['sort_order_tags' => 1])->save();
        $this->assertSame(['tag1', 'tag2', 'ref', 'refs'], array_values($levels()));
    }

    /** `Sort_OrderTags = 2` intervertit les deux : TAG2 devient le niveau du dessus. */
    public function test_the_tag_order_swaps_the_two_levels(): void
    {
        $this->line(['quantity' => 1, 'price_sales' => 100, 'tag1' => 'Un', 'tag2' => 'Deux']);
        $this->metre->forceFill(['sort_order_tags' => 2])->save();

        $rows = (new MetreDocumentBuilder($this->metre->fresh(), MetreDocument::ClientBudget))->build()['rows'];
        $tags = array_values(array_filter($rows, fn ($r) => str_starts_with($r['kind'] === 'group' ? $r['level'] : '', 'tag')));

        $this->assertSame('Deux', $tags[0]['title']);
        $this->assertSame('Un', $tags[1]['title']);
    }

    /** Une nouvelle section réimprime sa première sous-section, comme toute sous-totalisation. */
    public function test_a_new_section_reprints_its_first_sub_section(): void
    {
        $this->line(['ref_code' => 10, 'refs_code' => 1, 'refs_title' => 'A', 'quantity' => 1, 'price_sales' => 1]);
        $this->line(['ref_code' => 20, 'refs_code' => 1, 'refs_title' => 'A', 'quantity' => 1, 'price_sales' => 1]);

        $rows = (new MetreDocumentBuilder($this->metre, MetreDocument::ClientBudget))->build()['rows'];
        $levels = array_column(array_filter($rows, fn ($r) => $r['kind'] === 'group'), 'level');

        $this->assertSame(['ref', 'refs', 'ref', 'refs'], array_values($levels));
    }

    // --- la réponse HTTP ---------------------------------------------------------------------

    #[DataProvider('documentProvider')]
    public function test_every_document_renders_a_pdf(string $document): void
    {
        $this->actingAs(User::factory()->create());
        $this->line(['unit' => 'm2', 'quantity' => 2, 'price_sales' => 100, 'quantity_ordered' => 2, 'price_ordered' => 80]);
        $this->line(['quantity' => 1, 'price_sales' => 40, 'is_option_b' => true]);

        $response = $this->get($this->url($document));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_an_unknown_document_is_not_found(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get($this->url('budget-client-inexistant'))->assertNotFound();
    }

    /**
     * L'aperçu et le téléchargement sont la même URL : seul l'en-tête de disposition change, donc
     * ce qu'on regarde et ce qu'on enregistre ne peuvent pas diverger.
     */
    public function test_download_only_changes_the_disposition(): void
    {
        $this->actingAs(User::factory()->create());
        $this->line(['quantity' => 1, 'price_sales' => 100]);

        $inline = $this->get($this->url('budget-client-categories'));
        $attached = $this->get($this->url('budget-client-categories').'?download=1');

        $this->assertStringContainsString('inline', $inline->headers->get('content-disposition'));
        $this->assertStringContainsString('attachment', $attached->headers->get('content-disposition'));
        $this->assertStringContainsString('.pdf', $attached->headers->get('content-disposition'));
    }

    /** Imprimer est une lecture : un compte en lecture seule y a droit. */
    public function test_a_readonly_account_can_print(): void
    {
        $this->actingAs(User::factory()->readOnly()->create());
        $this->line(['quantity' => 1, 'price_sales' => 100]);

        $this->get($this->url('budget-client-categories'))->assertOk();
    }

    /** Le verrou interdit d'écrire, pas de regarder. */
    public function test_a_locked_metre_still_prints(): void
    {
        $this->actingAs(User::factory()->create());
        $this->metre->forceFill(['is_locked_b' => true])->save();
        $this->line(['quantity' => 1, 'price_sales' => 100]);

        $this->get($this->url('budget-client-categories'))->assertOk();
    }

    /**
     * Un métré sans ligne rend quand même son document : la source imprimerait une page vide
     * plutôt que rien, et une erreur ici se lirait comme une panne.
     */
    public function test_a_metre_without_a_line_still_renders(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get($this->url('budget-client-complet'))->assertOk();
    }

    /**
     * ShakeDesign muet coûte la ligne de projet, pas le document : les montants sont ici, pas
     * là-bas. Même dégradation que la carte des offres.
     */
    public function test_an_unreachable_shakedesign_costs_the_project_line_only(): void
    {
        $this->actingAs(User::factory()->create());
        $this->line(['quantity' => 1, 'price_sales' => 100]);

        Http::fake(['*' => Http::response(['messages' => [['code' => '500']]], 500)]);

        $this->get($this->url('budget-client-categories'))->assertOk();
        Http::assertSentCount(2);   // la session, puis la recherche qui échoue
    }

    public function test_a_guest_is_redirected_away(): void
    {
        $this->get($this->url('budget-client-complet'))->assertRedirect('/login');
    }
}
