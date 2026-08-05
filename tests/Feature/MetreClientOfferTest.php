<?php

namespace Tests\Feature;

use App\Models\Metre;
use App\Models\MetreLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * L'offre client d'un métré - MET_OFF_CreateClientOffer, et la liste des offres déjà rattachées.
 *
 * Aucun test ne touche un vrai serveur FileMaker : tout passe par Http::fake(). Ce qui est vérifié
 * en premier est la règle qu'on ne devinerait pas - une ligne d'offre PAR TAUX DE TVA, portant la
 * somme des ventes de son taux, options exclues - et non le simple fait qu'un appel part.
 */
class MetreClientOfferTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT = 'PRJ-1A2B3C';

    private const COMPANY = 'CPY-77';

    private const CONTACT = 'CTC-88';

    private Metre $metre;

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

        $this->metre = Metre::forceCreate([
            'project_id' => self::PROJECT,
            'name' => 'Chantier Nord',
            'ind_project' => 6,
            'language' => 'FR',
        ]);
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

    /** Le projet, la création de l'en-tête, celle des lignes, puis la relecture de la liste. */
    private function fakeShakeDesign(array $offers = []): void
    {
        Http::fake([
            '*/sessions' => Http::response([
                'response' => ['token' => 'tok-1'],
                'messages' => [['code' => '0', 'message' => 'OK']],
            ]),
            '*/layouts/API_PRJ/_find' => Http::response([
                'response' => ['data' => [['fieldData' => [
                    'zkp' => self::PROJECT, 'Name' => 'Chantier Nord',
                    'zkf_CPY' => self::COMPANY, 'zkf_CTC' => self::CONTACT,
                ], 'recordId' => '1']]],
                'messages' => [['code' => '0', 'message' => 'OK']],
            ]),
            '*/layouts/API_OFF/records' => Http::response([
                'response' => ['recordId' => '501', 'modId' => '0'],
                'messages' => [['code' => '0', 'message' => 'OK']],
            ]),
            '*/layouts/API_OFF/records/501' => Http::response([
                'response' => ['data' => [['fieldData' => ['zkp' => 'OFF-NEW'], 'recordId' => '501']]],
                'messages' => [['code' => '0', 'message' => 'OK']],
            ]),
            '*/layouts/API_OFL/records' => Http::response([
                'response' => ['recordId' => '900', 'modId' => '0'],
                'messages' => [['code' => '0', 'message' => 'OK']],
            ]),
            // La numérotation, appelée après création. Un motif non couvert ne serait pas bloqué :
            // il partirait pour de vrai (voir la note du CLAUDE.md sur Http::fake()).
            '*/script/ZSET_Numbering*' => Http::response([
                'response' => ['scriptResult' => 'OFF-2026-0007', 'scriptError' => '0'],
                'messages' => [['code' => '0', 'message' => 'OK']],
            ]),
            '*/layouts/API_OFF/records/501' => Http::response([
                'response' => ['data' => [['fieldData' => ['zkp' => 'OFF-NEW'], 'recordId' => '501']], 'modId' => '1'],
                'messages' => [['code' => '0', 'message' => 'OK']],
            ]),
            '*/layouts/API_OFF/_find' => Http::response([
                'response' => ['data' => array_map(fn ($o) => ['fieldData' => $o, 'recordId' => '1'], $offers)],
                'messages' => [['code' => '0', 'message' => 'OK']],
            ]),
        ]);
    }

    /** @return list<array<string, mixed>> les fieldData envoyés à API_OFL, dans l'ordre */
    private function sentOfferLines(): array
    {
        $lines = [];

        foreach (Http::recorded() as [$request]) {
            if (str_contains($request->url(), 'layouts/API_OFL/records')) {
                // Le client envoie fieldData en objet, pour que {} sorte au lieu de [].
                $lines[] = (array) $request->data()['fieldData'];
            }
        }

        return $lines;
    }

    // --- la règle : une ligne par taux de TVA ------------------------------------------------

    public function test_it_sends_one_offer_line_per_vat_rate(): void
    {
        $this->actAsWriter();
        $this->fakeShakeDesign();

        // 21 % : 10 × 100 = 1000, et 2 × 50 = 100 → 1100
        $this->line(['vat_value_id' => 'VAT-21', 'vat_ae' => 21, 'price_sales' => 100, 'quantity' => 10]);
        $this->line(['vat_value_id' => 'VAT-21', 'vat_ae' => 21, 'price_sales' => 50, 'quantity' => 2]);
        // 6 % : 4 × 25 = 100
        $this->line(['vat_value_id' => 'VAT-6', 'vat_ae' => 6, 'price_sales' => 25, 'quantity' => 4]);
        // une option à 21 % : comptée nulle part
        $this->line(['vat_value_id' => 'VAT-21', 'vat_ae' => 21, 'price_sales' => 9999, 'quantity' => 1, 'is_option_b' => true]);

        $this->postJson("/api/metres/{$this->metre->id}/offer")->assertCreated();

        $lines = $this->sentOfferLines();

        $this->assertCount(2, $lines, 'Deux taux de TVA, deux lignes d\'offre.');

        // Triées par taux : 6 avant 21.
        $this->assertEquals(6, $lines[0]['VATRate']);
        $this->assertEquals(100, $lines[0]['PriceUnit']);
        $this->assertEquals(21, $lines[1]['VATRate']);
        $this->assertEquals(1100, $lines[1]['PriceUnit'], 'L\'option est exclue.');

        foreach ($lines as $line) {
            $this->assertSame(1, $line['Quantity'], 'Quantity vaut toujours 1 : le prix EST le total.');
            $this->assertSame('6 - Chantier Nord', $line['Title'], 'IndProject & " - " & Name.');
        }
    }

    /** Les lignes sans TVA forment leur propre groupe, sans taux inventé, et passent en dernier. */
    public function test_lines_without_vat_form_their_own_untaxed_group(): void
    {
        $this->actAsWriter();
        $this->fakeShakeDesign();

        $this->line(['vat_value_id' => 'VAT-21', 'vat_ae' => 21, 'price_sales' => 10, 'quantity' => 1]);
        $this->line(['price_sales' => 7, 'quantity' => 1]);

        $this->postJson("/api/metres/{$this->metre->id}/offer")->assertCreated();

        $lines = $this->sentOfferLines();
        $this->assertCount(2, $lines);
        $this->assertEquals(21, $lines[0]['VATRate']);
        $this->assertArrayNotHasKey('VATRate', $lines[1], 'Aucun taux inventé pour une ligne sans TVA.');
        $this->assertEquals(7, $lines[1]['PriceUnit']);
    }

    /** Sans aucune TVA, une seule ligne : le total des ventes du métré. */
    public function test_a_metre_without_any_vat_produces_one_line(): void
    {
        $this->actAsWriter();
        $this->fakeShakeDesign();

        $this->line(['price_sales' => 100, 'quantity' => 3]);
        $this->line(['price_sales' => 50, 'quantity' => 1]);

        $this->postJson("/api/metres/{$this->metre->id}/offer")->assertCreated();

        $lines = $this->sentOfferLines();
        $this->assertCount(1, $lines);
        $this->assertEquals(350, $lines[0]['PriceUnit']);
    }

    // --- l'en-tête ---------------------------------------------------------------------------

    /**
     * `zkf_MET` est la référence dure que ce projet doit préserver : c'est par elle que ShakeDesign
     * relie une offre à son métré. La société et le contact viennent DU PROJET, pas du métré.
     */
    public function test_the_header_carries_the_hard_metre_reference_and_the_projects_client(): void
    {
        $this->actAsWriter();
        $this->fakeShakeDesign();
        $this->line(['price_sales' => 1, 'quantity' => 1]);

        $this->postJson("/api/metres/{$this->metre->id}/offer")->assertCreated();

        $header = null;

        foreach (Http::recorded() as [$request]) {
            if (str_contains($request->url(), 'layouts/API_OFF/records') && $request->method() === 'POST') {
                $header = (array) $request->data()['fieldData'];
                break;
            }
        }

        $this->assertSame($this->metre->id, $header['zkf_MET']);
        $this->assertSame(self::PROJECT, $header['zkf_PRJ']);
        $this->assertSame(self::COMPANY, $header['zkf_CPY']);
        $this->assertSame(self::CONTACT, $header['zkf_CTC']);
        $this->assertSame('FR', $header['Language']);
        $this->assertSame('Chantier Nord', $header['Title']);
    }

    /** Les totaux sont rafraîchis avant l'envoi : le montant offert est celui de l'écran. */
    public function test_it_recalculates_before_sending(): void
    {
        $this->actAsWriter();
        $this->fakeShakeDesign();

        $this->line(['price_sales' => 100, 'quantity' => 2]);

        // Le total est faussé SANS passer par les modèles, donc sans déclencher l'observateur :
        // c'est l'état d'un métré dont les totaux stockés ont pris du retard.
        DB::table('metres')
            ->where('id', $this->metre->id)
            ->update(['total_sales_metl_stored' => 1]);

        $this->postJson("/api/metres/{$this->metre->id}/offer")->assertCreated();

        $this->assertEquals(200, $this->metre->fresh()->total_sales_metl_stored, 'Recalculé par le point d\'entrée.');

        // Et c'est bien 200 qui part, pas le 1 périmé.
        $this->assertEquals(200, $this->sentOfferLines()[0]['PriceUnit']);
    }

    // --- les refus ---------------------------------------------------------------------------

    /** Le source refuse un métré sans ligne : « Aucune ligne de métré ». */
    public function test_a_metre_without_lines_is_refused_before_any_call(): void
    {
        $this->actAsWriter();
        $this->fakeShakeDesign();

        $this->postJson("/api/metres/{$this->metre->id}/offer")->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_a_readonly_account_may_not_create_an_offer(): void
    {
        $this->actingAs(User::factory()->readOnly()->create());
        $this->fakeShakeDesign();
        $this->line(['price_sales' => 1, 'quantity' => 1]);

        $this->postJson("/api/metres/{$this->metre->id}/offer")->assertForbidden();

        Http::assertNothingSent();
    }

    // --- la liste ----------------------------------------------------------------------------

    public function test_the_page_lists_the_offers_of_this_metre(): void
    {
        $this->actAsWriter();
        $this->fakeShakeDesign([
            [
                'zkp' => 'OFF-1', 'Title' => 'Offre initiale', 'Date' => '05/11/2025',
                'Category' => 'ST / CO', 'Language' => 'FR', 'OFL_Total_PriceNoTax_cU' => 5000,
            ],
            [
                'zkp' => 'OFF-2', 'Title' => '', 'Date' => '', 'Category' => '',
                'Language' => 'FR', 'OFL_Total_PriceNoTax_cU' => '',
            ],
        ]);

        $this->get("/metres/{$this->metre->id}")->assertInertia(fn ($page) => $page
            ->where('offers.0.zkp', 'OFF-1')
            ->where('offers.0.title', 'Offre initiale')
            ->where('offers.0.total_no_tax', 5000)
            // Un champ FileMaker vide devient null, pas une chaîne vide ni 0 €.
            ->where('offers.1.title', null)
            ->where('offers.1.total_no_tax', null));
    }

    /**
     * De quoi ouvrir une offre dans le client FileMaker : la cible `fmp://` arrive une fois pour
     * la page, le zkp de l'offre vient de sa ligne. La page les assemble en
     * `fmp://…/ShakeDesign?script=OFF_GoTo&param=<OFF>{zkp}</OFF>` - `ShakeDesign :: OFF_GoTo`
     * (id 89) étant `SOR_GoTo` à la balise près. L'assemblage lui-même est testé côté Vitest.
     *
     * Le setUp remplace tout le bloc `services.shakedesign`, donc la cible y est absente : elle
     * est posée ici, ce qui est aussi la preuve qu'elle vient bien de la configuration.
     */
    public function test_the_page_carries_what_it_takes_to_open_an_offer_in_filemaker(): void
    {
        $this->actAsWriter();
        config([
            'services.shakedesign.fmp_host' => 'https://fms23.mycloud.fm',
            'services.shakedesign.fmp_database' => 'ShakeDesign',
        ]);
        $this->fakeShakeDesign([
            ['zkp' => 'OFF-1', 'Title' => 'Offre initiale', 'OFL_Total_PriceNoTax_cU' => 5000],
        ]);

        $this->get("/metres/{$this->metre->id}")->assertInertia(fn ($page) => $page
            // Le schéma du host Data API est retiré : `fmp://https://…` ne mène nulle part.
            ->where('filemakerLink.host', 'fms23.mycloud.fm')
            ->where('filemakerLink.database', 'ShakeDesign')
            ->where('offers.0.zkp', 'OFF-1'));
    }

    /** Sans cible configurée, la page rend le titre de l'offre sans lien plutôt qu'un lien mort. */
    public function test_an_unconfigured_filemaker_target_reaches_the_page_empty(): void
    {
        $this->actAsWriter();
        $this->fakeShakeDesign([['zkp' => 'OFF-1', 'Title' => 'Offre initiale']]);

        $this->get("/metres/{$this->metre->id}")->assertInertia(fn ($page) => $page
            ->where('filemakerLink.host', null)
            ->where('filemakerLink.database', null));
    }

    /**
     * L'offre reçoit son numéro de `ZSET_Numbering`, comme la commande fournisseur.
     *
     * Le compteur vit dans ShakeDesign et son `Open Record/Request` est ce qui interdit qu'une
     * offre créée du web et une créée dans FileMaker portent le même numéro. Le refuser au web
     * laisserait des offres sans référence, ce qui a été le cas jusqu'ici.
     */
    public function test_a_created_offer_is_numbered(): void
    {
        $this->actAsWriter();
        $this->fakeShakeDesign();
        $this->line(['price_sales' => 100, 'quantity' => 1]);

        $this->postJson("/api/metres/{$this->metre->id}/offer")
            ->assertCreated()
            ->assertJsonPath('data.offer.number', 'OFF-2026-0007');

        $patched = collect(Http::recorded())
            ->map(fn ($pair) => $pair[0])
            ->first(fn ($r) => $r->method() === 'PATCH' && str_contains($r->url(), 'API_OFF/records/'));

        $this->assertNotNull($patched, 'Le numéro doit être reposé sur l\'offre créée.');
        $this->assertSame('OFF-2026-0007', ((array) $patched->data()['fieldData'])['Number']);
    }

    /** Numérotation fermée au compte API : l'offre existe quand même, sans référence. */
    public function test_an_unavailable_numbering_still_creates_the_offer(): void
    {
        $this->actAsWriter();
        Http::fake([
            '*/sessions' => Http::response(['response' => ['token' => 't'], 'messages' => [['code' => '0']]]),
            '*/layouts/API_PRJ/_find' => Http::response([
                'response' => ['data' => [['fieldData' => ['zkp' => self::PROJECT], 'recordId' => '1']]],
                'messages' => [['code' => '0']],
            ]),
            '*/script/ZSET_Numbering*' => Http::response(['messages' => [['code' => '104', 'message' => 'missing']]], 500),
            '*/layouts/API_OFF/records/501' => Http::response([
                'response' => ['data' => [['fieldData' => ['zkp' => 'OFF-NEW'], 'recordId' => '501']]],
                'messages' => [['code' => '0']],
            ]),
            '*/layouts/API_OFF/records' => Http::response([
                'response' => ['recordId' => '501', 'modId' => '0'], 'messages' => [['code' => '0']],
            ]),
            '*/layouts/API_OFL/records' => Http::response([
                'response' => ['recordId' => '900', 'modId' => '0'], 'messages' => [['code' => '0']],
            ]),
            '*/layouts/API_OFF/_find' => Http::response(['response' => ['data' => []], 'messages' => [['code' => '0']]]),
        ]);
        $this->line(['price_sales' => 100, 'quantity' => 1]);

        $this->postJson("/api/metres/{$this->metre->id}/offer")
            ->assertCreated()
            ->assertJsonPath('data.offer.number', null);
    }

    /** La recherche est bornée au métré : c'est zkf_MET qui filtre, pas le projet. */
    public function test_the_list_is_filtered_on_the_metres_own_key(): void
    {
        $this->actAsWriter();
        $this->fakeShakeDesign();

        $this->get("/metres/{$this->metre->id}")->assertOk();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'layouts/API_OFF/_find')) {
                return false;
            }

            return $request->data()['query'][0]['zkf_MET'] === '=='.$this->metre->id;
        });
    }

    /**
     * ShakeDesign injoignable - pas « répond mal », mais « ne répond pas ».
     *
     * Ce cas traversait tout : une ConnectionException de Laravel n'est pas une
     * ShakeDesignApiException, alors que le contrat du client annonce l'inverse depuis le début.
     * Six tests de la page métré l'ont révélé en tentant un vrai appel réseau.
     */
    public function test_an_unreachable_host_degrades_instead_of_breaking_the_page(): void
    {
        $this->actAsWriter();

        Http::fake([
            '*/sessions' => Http::response([
                'response' => ['token' => 'tok-1'],
                'messages' => [['code' => '0', 'message' => 'OK']],
            ]),
            '*/layouts/API_PRJ/_find' => Http::response([
                'response' => ['data' => [['fieldData' => ['zkp' => self::PROJECT, 'Name' => 'X'], 'recordId' => '1']]],
                'messages' => [['code' => '0', 'message' => 'OK']],
            ]),
            '*/layouts/API_OFF/_find' => fn () => throw new ConnectionException('Could not resolve host'),
        ]);

        $this->get("/metres/{$this->metre->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('offers', null));
    }

    /** ShakeDesign indisponible : la page s'affiche quand même, et le dit. */
    public function test_the_page_degrades_when_shakedesign_cannot_be_reached(): void
    {
        $this->actAsWriter();

        Http::fake([
            '*/sessions' => Http::response([
                'response' => ['token' => 'tok-1'],
                'messages' => [['code' => '0', 'message' => 'OK']],
            ]),
            '*/layouts/API_PRJ/_find' => Http::response([
                'response' => ['data' => [['fieldData' => ['zkp' => self::PROJECT, 'Name' => 'X'], 'recordId' => '1']]],
                'messages' => [['code' => '0', 'message' => 'OK']],
            ]),
            '*/layouts/API_OFF/_find' => Http::response(['messages' => [['code' => '500', 'message' => 'Boom']]], 500),
        ]);

        $this->get("/metres/{$this->metre->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('offers', null));
    }
}
