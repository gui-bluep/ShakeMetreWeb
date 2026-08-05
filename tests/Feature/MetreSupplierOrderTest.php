<?php

namespace Tests\Feature;

use App\Models\Lot;
use App\Models\Metre;
use App\Models\MetreLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * La commande fournisseur d'un lot depuis un métré - MET_SOR_CreateCSupplierOrder.
 *
 * Ce qui est vérifié d'abord est ce qui coûte cher si c'est faux : quelles lignes partent, quel
 * montant, et les quatre refus qui doivent empêcher l'écriture. Aucun test ne touche un vrai
 * serveur FileMaker.
 */
class MetreSupplierOrderTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT = 'PRJ-1A2B3C';

    private Metre $metre;

    private Lot $lot;

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
            'is_accepted_b' => true,
        ]);

        $this->lot = Lot::forceCreate([
            'project_id' => self::PROJECT,
            'code' => 12,
            'title_fr' => 'Toiture',
            'company_id' => 'CPY-77',
            'contact_id' => 'CTC-88',
            'cpy_name_ae' => 'Toitures Collignon',
        ]);
    }

    private function line(array $attributes = []): MetreLine
    {
        return MetreLine::forceCreate($attributes + [
            'metre_id' => $this->metre->id,
            'lot_id' => $this->lot->id,
            'ref_code' => 10, 'refs_code' => 1, 'refs_title' => 'Gros-oeuvre',
        ]);
    }

    private function url(): string
    {
        return "/api/metres/{$this->metre->id}/lots/{$this->lot->id}/supplier-order";
    }

    /** La création de l'en-tête, celle de la ligne, la relecture, puis la numérotation. */
    private function fakeShakeDesign(?string $number = 'SOR-2026-0042'): void
    {
        Http::fake([
            '*/sessions' => Http::response([
                'response' => ['token' => 'tok-1'],
                'messages' => [['code' => '0', 'message' => 'OK']],
            ]),
            '*/layouts/API_SOR/records/900' => Http::response([
                'response' => ['data' => [['fieldData' => ['zkp' => 'SOR-NEW'], 'recordId' => '900']]],
                'messages' => [['code' => '0', 'message' => 'OK']],
            ]),
            '*/layouts/API_SOR/records' => Http::response([
                'response' => ['recordId' => '900', 'modId' => '0'],
                'messages' => [['code' => '0', 'message' => 'OK']],
            ]),
            '*/layouts/API_SOL/records' => Http::response([
                'response' => ['recordId' => '901', 'modId' => '0'],
                'messages' => [['code' => '0', 'message' => 'OK']],
            ]),
            '*/script/ZSET_Numbering*' => $number === null
                ? Http::response(['messages' => [['code' => '104', 'message' => "script:'ZSET_Numbering' is missing"]]], 500)
                : Http::response([
                    'response' => ['scriptResult' => $number, 'scriptError' => '0'],
                    'messages' => [['code' => '0', 'message' => 'OK']],
                ]),
        ]);
    }

    /** @return list<array<string, mixed>> les fieldData envoyés, par couche */
    private function sentTo(string $needle): array
    {
        $sent = [];

        foreach (Http::recorded() as [$request]) {
            if (str_contains($request->url(), $needle) && $request->method() === 'POST') {
                $sent[] = (array) ($request->data()['fieldData'] ?? []);
            }
        }

        return $sent;
    }

    // --- ce qui part -------------------------------------------------------------------------

    /**
     * Le jeu trouvé de la source : les lignes de CE lot dans CE métré, hors lignes d'appel
     * d'offres. Une ligne d'un autre lot, d'un autre métré, ou marquée « appel d'offres » reste
     * dehors.
     */
    public function test_only_this_metres_lines_for_this_lot_are_ordered(): void
    {
        $this->actAsWriter();
        $this->fakeShakeDesign();

        $this->line(['quantity_ordered' => 2, 'price_ordered' => 100]);          // 200, retenue
        $this->line(['quantity_ordered' => 1, 'price_ordered' => 50, 'is_tender_line_b' => true]);
        $this->line(['lot_id' => null, 'quantity_ordered' => 1, 'price_ordered' => 999]);

        $other = Metre::forceCreate(['project_id' => self::PROJECT, 'name' => 'Autre', 'ind_project' => 7]);
        MetreLine::forceCreate([
            'metre_id' => $other->id, 'lot_id' => $this->lot->id,
            'quantity_ordered' => 1, 'price_ordered' => 777,
        ]);

        $response = $this->getJson($this->url())->assertOk();

        $this->assertCount(1, $response->json('data.lines'));
        $this->assertEquals(200, $response->json('data.total'));
    }

    /**
     * Une seule ligne part chez ShakeDesign, portant le total : `SOR_NewFromMetre` appelle
     * `SOL_New` avec `Qty = 1`, `priceUnit = $SOLPrice`, `vatRate = 0` et le nom du métré. Le
     * détail des postes reste dans le métré.
     */
    public function test_shakedesign_receives_one_line_carrying_the_total(): void
    {
        $this->actAsWriter();
        $this->fakeShakeDesign();

        $this->line(['quantity_ordered' => 2, 'price_ordered' => 100]);
        $this->line(['quantity_ordered' => 3, 'price_ordered' => 70]);

        $this->postJson($this->url())->assertCreated();

        $header = $this->sentTo('layouts/API_SOR/records')[0];
        $this->assertSame($this->metre->id, $header['zkf_MET'], 'La référence dure vers le métré.');
        $this->assertSame(self::PROJECT, $header['zkf_PRJ']);
        $this->assertSame('CPY-77', $header['zkf_CPY'], 'La société vient du LOT, pas du projet.');
        $this->assertSame('CTC-88', $header['zkf_CTC']);
        $this->assertSame('6 - Chantier Nord', $header['Title']);

        $lines = $this->sentTo('layouts/API_SOL/records');
        $this->assertCount(1, $lines);
        $this->assertSame(1, $lines[0]['Quantity']);
        $this->assertEquals(410, $lines[0]['PriceUnit']);
        $this->assertEquals(0, $lines[0]['VATRate']);
        $this->assertSame('6 - Chantier Nord', $lines[0]['Title']);
    }

    /** La référence de la commande se pose sur chaque ligne du lot - le Replace Field Contents. */
    public function test_the_order_is_written_back_on_every_line(): void
    {
        $this->actAsWriter();
        $this->fakeShakeDesign();

        $a = $this->line(['quantity_ordered' => 1, 'price_ordered' => 10]);
        $b = $this->line(['quantity_ordered' => 1, 'price_ordered' => 20]);

        $this->postJson($this->url())->assertCreated()
            ->assertJsonPath('data.supplier_order.number', 'SOR-2026-0042')
            ->assertJsonPath('data.lines_marked', 2);

        foreach ([$a, $b] as $line) {
            $line->refresh();
            $this->assertSame('SOR-NEW', $line->supplier_order_id);
            $this->assertSame('SOR-2026-0042', $line->sor_title_ref);
        }
    }

    /**
     * La numérotation est un service de ShakeDesign qui peut être fermé au compte API. Fermée,
     * la commande se crée quand même : elle porte sa clé, donc le lien fonctionne, il lui manque
     * seulement sa référence lisible. Refuser de créer serait pire.
     */
    public function test_an_unavailable_numbering_does_not_prevent_the_order(): void
    {
        $this->actAsWriter();
        $this->fakeShakeDesign(number: null);

        $line = $this->line(['quantity_ordered' => 1, 'price_ordered' => 10]);

        $this->postJson($this->url())->assertCreated()
            ->assertJsonPath('data.supplier_order.number', null);

        $this->assertSame('SOR-NEW', $line->refresh()->supplier_order_id);
        $this->assertNull($line->sor_title_ref);
    }

    // --- les quatre refus --------------------------------------------------------------------

    public function test_an_unaccepted_metre_cannot_be_ordered(): void
    {
        $this->actAsWriter();
        $this->fakeShakeDesign();
        $this->metre->forceFill(['is_accepted_b' => false])->save();
        $this->line(['quantity_ordered' => 1, 'price_ordered' => 10]);

        $this->postJson($this->url())
            ->assertStatus(422)
            ->assertJsonPath('message', 'Le métré doit être accepté.');

        Http::assertNothingSent();
    }

    public function test_a_lot_without_a_supplier_cannot_be_ordered(): void
    {
        $this->actAsWriter();
        $this->fakeShakeDesign();
        $this->lot->forceFill(['company_id' => null])->save();
        $this->line(['quantity_ordered' => 1, 'price_ordered' => 10]);

        $this->postJson($this->url())
            ->assertStatus(422)
            ->assertJsonPath('message', 'Un fournisseur doit être assigné au lot sélectionné.');

        Http::assertNothingSent();
    }

    public function test_a_lot_without_a_line_cannot_be_ordered(): void
    {
        $this->actAsWriter();
        $this->fakeShakeDesign();

        $this->postJson($this->url())->assertStatus(422);

        Http::assertNothingSent();
    }

    /** Une ligne déjà commandée bloque tout le lot : la source refuse le jeu trouvé entier. */
    public function test_lines_already_on_an_order_block_the_whole_lot(): void
    {
        $this->actAsWriter();
        $this->fakeShakeDesign();

        $this->line(['quantity_ordered' => 1, 'price_ordered' => 10]);
        $this->line(['quantity_ordered' => 1, 'price_ordered' => 20, 'supplier_order_id' => 'SOR-OLD']);

        $this->postJson($this->url())
            ->assertStatus(422)
            ->assertJsonPath('blockers.0.code', 'lines_already_ordered');

        Http::assertNothingSent();
    }

    /** L'écran de contrôle montre les quatre refus ensemble, là où la source les enchaîne. */
    public function test_the_preview_reports_every_blocker_at_once(): void
    {
        $this->actAsWriter();
        $this->metre->forceFill(['is_accepted_b' => false])->save();
        $this->lot->forceFill(['company_id' => null])->save();

        $codes = $this->getJson($this->url())->assertOk()->json('data.blockers.*.code');

        $this->assertEqualsCanonicalizing(
            ['metre_not_accepted', 'lot_without_supplier', 'no_line'],
            $codes,
        );
    }

    // --- accès ---------------------------------------------------------------------------------

    /** Regarder ce qu'on s'apprête à commander n'est pas commander. */
    public function test_a_readonly_account_may_preview_but_not_create(): void
    {
        $this->actingAs(User::factory()->readOnly()->create());
        $this->fakeShakeDesign();
        $this->line(['quantity_ordered' => 1, 'price_ordered' => 10]);

        $this->getJson($this->url())->assertOk();
        $this->postJson($this->url())->assertForbidden();

        Http::assertNothingSent();
    }

    /** Le lot d'un autre chantier n'est pas commandable depuis ce métré, et l'URL le dit mal. */
    public function test_a_lot_of_another_project_is_not_found(): void
    {
        $this->actAsWriter();
        $foreign = Lot::forceCreate(['project_id' => 'PRJ-OTHER', 'code' => 1, 'company_id' => 'CPY-9']);

        $this->getJson("/api/metres/{$this->metre->id}/lots/{$foreign->id}/supplier-order")
            ->assertNotFound();
    }

    private function actAsWriter(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }
}
