<?php

namespace Tests\Feature;

use App\Models\Lot;
use App\Models\User;
use App\Services\ShakeDesign\ShakeDesignClient;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The company / contact pickers and the name snapshots they leave on a lot. Every ShakeDesign
 * response is faked - no test touches a real FileMaker server.
 */
class ShakeDesignLookupTest extends TestCase
{
    use RefreshDatabase;

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

    private function ok(array $response): array
    {
        return ['response' => $response, 'messages' => [['code' => '0', 'message' => 'OK']]];
    }

    private function fmError(string $code, string $message, int $status = 404): PromiseInterface
    {
        return Http::response(
            ['response' => [], 'messages' => [['code' => $code, 'message' => $message]]],
            $status,
        );
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function found(array $rows): array
    {
        return $this->ok([
            'data' => array_map(fn (array $fieldData) => ['fieldData' => $fieldData, 'recordId' => '1'], $rows),
        ]);
    }

    private function sessionBody(): array
    {
        return $this->ok(['token' => 'tok-1']);
    }

    // --- companies ---------------------------------------------------------------------------

    public function test_it_lists_companies_with_no_search_term(): void
    {
        $this->actAsWriter();

        Http::fake([
            '*/sessions' => Http::response($this->sessionBody()),
            '*/layouts/API_CPY/_find' => Http::response($this->found([
                ['zkp' => 'CPY-1', 'Name' => 'Alpha', 'VAT' => 'BE123', 'AddressBill_City' => 'Lasne'],
            ])),
        ]);

        $this->getJson('/api/shakedesign/companies')
            ->assertOk()
            ->assertJson(['data' => [
                ['zkp' => 'CPY-1', 'name' => 'Alpha', 'vat' => 'BE123', 'city' => 'Lasne'],
            ]]);
    }

    /**
     * The picker offers suppliers, not every company. Filtering on isSupplier_b is also what
     * lets a find work with no search term: the Data API has no "match everything" query, but
     * "every active supplier" is a real criterion.
     */
    public function test_it_only_offers_active_suppliers(): void
    {
        $this->actAsWriter();

        Http::fake([
            '*/sessions' => Http::response($this->sessionBody()),
            '*/layouts/API_CPY/_find' => Http::response($this->found([])),
        ]);

        $this->getJson('/api/shakedesign/companies')->assertOk();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/layouts/API_CPY/_find')
            && $r['query'] === [['isSupplier_b' => '==1', 'isActive_b' => '==1']]);
    }

    /** Criteria inside one query element are AND-ed, so the term narrows rather than widens. */
    public function test_a_search_term_is_anded_with_the_supplier_filter(): void
    {
        $this->actAsWriter();

        Http::fake([
            '*/sessions' => Http::response($this->sessionBody()),
            '*/layouts/API_CPY/_find' => Http::response($this->found([['zkp' => 'CPY-1', 'Name' => 'Alpha']])),
        ]);

        $this->getJson('/api/shakedesign/companies?q=alph')->assertOk();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/layouts/API_CPY/_find')
            && $r['query'] === [['isSupplier_b' => '==1', 'isActive_b' => '==1', 'Name' => '*alph*']]);
    }

    /**
     * The live data holds 292 active suppliers, which the previous limit of 200 cut off in
     * silence - a capped list is indistinguishable from a complete one, so it has to say so.
     */
    public function test_a_capped_result_says_so_rather_than_looking_complete(): void
    {
        $this->actAsWriter();

        $atTheCap = array_map(
            fn (int $i) => ['zkp' => "CPY-{$i}", 'Name' => "Société {$i}"],
            range(1, ShakeDesignClient::COMPANY_LIST_LIMIT),
        );

        Http::fake([
            '*/sessions' => Http::response($this->sessionBody()),
            '*/layouts/API_CPY/_find' => Http::response($this->found($atTheCap)),
        ]);

        $this->getJson('/api/shakedesign/companies')
            ->assertOk()
            ->assertJsonPath('truncated', true)
            ->assertJsonPath('limit', ShakeDesignClient::COMPANY_LIST_LIMIT);
    }

    public function test_a_result_below_the_cap_is_not_flagged_as_truncated(): void
    {
        $this->actAsWriter();

        Http::fake([
            '*/sessions' => Http::response($this->sessionBody()),
            '*/layouts/API_CPY/_find' => Http::response($this->found([['zkp' => 'CPY-1', 'Name' => 'Alpha']])),
        ]);

        $this->getJson('/api/shakedesign/companies')
            ->assertOk()
            ->assertJsonPath('truncated', false);
    }

    public function test_a_company_without_a_name_still_gets_a_label(): void
    {
        $this->actAsWriter();

        Http::fake([
            '*/sessions' => Http::response($this->sessionBody()),
            '*/layouts/API_CPY/_find' => Http::response($this->found([['zkp' => 'CPY-1', 'Name' => '']])),
        ]);

        $this->getJson('/api/shakedesign/companies')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Société sans nom');
    }

    public function test_matching_no_company_returns_an_empty_list(): void
    {
        $this->actAsWriter();

        Http::fake([
            '*/sessions' => Http::response($this->sessionBody()),
            '*/layouts/API_CPY/_find' => $this->fmError('401', 'No records match the request'),
        ]);

        $this->getJson('/api/shakedesign/companies?q=zzz')
            ->assertOk()
            ->assertJson(['data' => []]);
    }

    // --- contacts of a company ---------------------------------------------------------------

    public function test_it_resolves_a_companys_contacts_through_the_join_table(): void
    {
        $this->actAsWriter();

        Http::fake([
            '*/sessions' => Http::response($this->sessionBody()),
            '*/layouts/API_JCPYCTC/_find' => Http::response($this->found([
                ['zkf_CPY' => 'CPY-1', 'zkf_CTC' => 'CTC-2', 'Role' => 'Chantier'],
                ['zkf_CPY' => 'CPY-1', 'zkf_CTC' => 'CTC-1', 'Role' => ''],
            ])),
            '*/layouts/API_CTC/_find' => Http::response($this->found([
                ['zkp' => 'CTC-1', 'NameFirst' => 'Anne', 'NameLast' => 'Bertrand'],
                ['zkp' => 'CTC-2', 'NameFirst' => 'Zoé', 'NameLast' => 'Colin'],
            ])),
        ]);

        $this->getJson('/api/shakedesign/companies/CPY-1/contacts')
            ->assertOk()
            // Name-ordered, and the join's Role travels with the contact.
            ->assertJson(['data' => [
                ['zkp' => 'CTC-1', 'name' => 'Anne Bertrand', 'role' => null],
                ['zkp' => 'CTC-2', 'name' => 'Zoé Colin', 'role' => 'Chantier'],
            ]]);
    }

    /** Two calls whatever the number of contacts: the keys are OR'd into one find. */
    public function test_it_fetches_every_contact_in_a_single_request(): void
    {
        $this->actAsWriter();

        Http::fake([
            '*/sessions' => Http::response($this->sessionBody()),
            '*/layouts/API_JCPYCTC/_find' => Http::response($this->found([
                ['zkf_CTC' => 'CTC-1'], ['zkf_CTC' => 'CTC-2'], ['zkf_CTC' => 'CTC-3'],
            ])),
            '*/layouts/API_CTC/_find' => Http::response($this->found([
                ['zkp' => 'CTC-1', 'NameFirst' => 'A', 'NameLast' => 'A'],
            ])),
        ]);

        $this->getJson('/api/shakedesign/companies/CPY-1/contacts')->assertOk();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/layouts/API_CTC/_find')
            && $r['query'] === [['zkp' => '==CTC-1'], ['zkp' => '==CTC-2'], ['zkp' => '==CTC-3']]);

        $contactFinds = collect(Http::recorded())
            ->filter(fn (array $pair) => str_contains($pair[0]->url(), '/layouts/API_CTC/_find'))
            ->count();

        $this->assertSame(1, $contactFinds, 'contacts should be fetched in one request, not one per contact');
    }

    public function test_a_company_with_no_linked_contact_returns_an_empty_list(): void
    {
        $this->actAsWriter();

        Http::fake([
            '*/sessions' => Http::response($this->sessionBody()),
            '*/layouts/API_JCPYCTC/_find' => $this->fmError('401', 'No records match the request'),
        ]);

        $this->getJson('/api/shakedesign/companies/CPY-1/contacts')
            ->assertOk()
            ->assertJson(['data' => []]);
    }

    /**
     * The layout exists today; this covers it going missing. "No contacts" and "the join
     * layout is gone" must not look the same - the first quietly loses data.
     */
    public function test_a_missing_join_layout_is_reported_as_actionable_not_as_empty(): void
    {
        $this->actAsWriter();

        Http::fake([
            '*/sessions' => Http::response($this->sessionBody()),
            '*/layouts/API_JCPYCTC/_find' => $this->fmError('105', 'Layout is missing', 500),
        ]);

        $this->getJson('/api/shakedesign/companies/CPY-1/contacts')
            ->assertStatus(503)
            ->assertJsonFragment(['message' => 'Les contacts sont momentanément indisponibles : le layout API_JCPYCTC '
                .'est introuvable dans ShakeDesign (table JCPYCTC_JoinCompaniesContacts, '
                .'champs zkf_CPY et zkf_CTC).']);
    }

    public function test_a_contact_without_a_name_still_gets_a_label(): void
    {
        $this->actAsWriter();

        Http::fake([
            '*/sessions' => Http::response($this->sessionBody()),
            '*/layouts/API_JCPYCTC/_find' => Http::response($this->found([['zkf_CTC' => 'CTC-1']])),
            '*/layouts/API_CTC/_find' => Http::response($this->found([
                ['zkp' => 'CTC-1', 'NameFirst' => '', 'NameLast' => ''],
            ])),
        ]);

        $this->getJson('/api/shakedesign/companies/CPY-1/contacts')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Contact sans nom');
    }

    public function test_a_guest_cannot_use_the_lookups(): void
    {
        Http::fake();

        $this->getJson('/api/shakedesign/companies')->assertUnauthorized();
        $this->getJson('/api/shakedesign/companies/CPY-1/contacts')->assertUnauthorized();

        Http::assertNothingSent();
    }

    public function test_a_readonly_account_may_use_the_lookups(): void
    {
        $this->actingAs(User::factory()->readOnly()->create());

        Http::fake([
            '*/sessions' => Http::response($this->sessionBody()),
            '*/layouts/API_CPY/_find' => Http::response($this->found([])),
        ]);

        $this->getJson('/api/shakedesign/companies')->assertOk();
    }

    // --- the name snapshots a picked value leaves on the lot ---------------------------------

    private function lot(array $attributes = []): Lot
    {
        return Lot::forceCreate($attributes + ['project_id' => 'PRJ-1', 'code' => 1]);
    }

    public function test_choosing_a_company_stores_the_name_resolved_from_the_id(): void
    {
        $this->actAsWriter();
        $lot = $this->lot();

        Http::fake([
            '*/sessions' => Http::response($this->sessionBody()),
            '*/layouts/API_CPY/_find' => Http::response($this->found([
                ['zkp' => 'CPY-9', 'Name' => 'Entreprise Durand'],
            ])),
        ]);

        $this->patchJson("/api/lots/{$lot->id}", ['company_id' => 'CPY-9'])->assertOk();

        $fresh = $lot->fresh();
        $this->assertSame('CPY-9', $fresh->company_id);
        $this->assertSame('Entreprise Durand', $fresh->cpy_name_ae);
    }

    public function test_choosing_a_contact_stores_its_assembled_name(): void
    {
        $this->actAsWriter();
        $lot = $this->lot(['company_id' => 'CPY-9', 'cpy_name_ae' => 'Entreprise Durand']);

        Http::fake([
            '*/sessions' => Http::response($this->sessionBody()),
            '*/layouts/API_CTC/_find' => Http::response($this->found([
                ['zkp' => 'CTC-3', 'NameFirst' => 'Marie', 'NameLast' => 'Dupont'],
            ])),
        ]);

        $this->patchJson("/api/lots/{$lot->id}", ['contact_id' => 'CTC-3'])->assertOk();

        $this->assertSame('Marie Dupont', $lot->fresh()->ctc_name_ae);
    }

    /**
     * Contacts are company-scoped, so a contact chosen for the previous supplier cannot be
     * assumed to belong to the new one.
     */
    public function test_changing_the_company_clears_the_contact(): void
    {
        $this->actAsWriter();
        $lot = $this->lot([
            'company_id' => 'CPY-1', 'cpy_name_ae' => 'Ancienne',
            'contact_id' => 'CTC-1', 'ctc_name_ae' => 'Ancien contact',
        ]);

        Http::fake([
            '*/sessions' => Http::response($this->sessionBody()),
            '*/layouts/API_CPY/_find' => Http::response($this->found([['zkp' => 'CPY-2', 'Name' => 'Nouvelle']])),
        ]);

        $this->patchJson("/api/lots/{$lot->id}", ['company_id' => 'CPY-2'])->assertOk();

        $fresh = $lot->fresh();
        $this->assertSame('Nouvelle', $fresh->cpy_name_ae);
        $this->assertNull($fresh->contact_id);
        $this->assertNull($fresh->ctc_name_ae);
    }

    public function test_replacing_both_at_once_keeps_the_contact_that_was_named(): void
    {
        $this->actAsWriter();
        $lot = $this->lot(['company_id' => 'CPY-1', 'contact_id' => 'CTC-1']);

        Http::fake([
            '*/sessions' => Http::response($this->sessionBody()),
            '*/layouts/API_CPY/_find' => Http::response($this->found([['zkp' => 'CPY-2', 'Name' => 'Nouvelle']])),
            '*/layouts/API_CTC/_find' => Http::response($this->found([
                ['zkp' => 'CTC-2', 'NameFirst' => 'Jean', 'NameLast' => 'Neuf'],
            ])),
        ]);

        $this->patchJson("/api/lots/{$lot->id}", ['company_id' => 'CPY-2', 'contact_id' => 'CTC-2'])->assertOk();

        $fresh = $lot->fresh();
        $this->assertSame('CTC-2', $fresh->contact_id);
        $this->assertSame('Jean Neuf', $fresh->ctc_name_ae);
    }

    public function test_clearing_the_company_clears_its_name_and_the_contact(): void
    {
        $this->actAsWriter();
        $lot = $this->lot([
            'company_id' => 'CPY-1', 'cpy_name_ae' => 'Ancienne',
            'contact_id' => 'CTC-1', 'ctc_name_ae' => 'Ancien contact',
        ]);

        Http::fake();

        $this->patchJson("/api/lots/{$lot->id}", ['company_id' => null])->assertOk();

        $fresh = $lot->fresh();
        $this->assertNull($fresh->company_id);
        $this->assertNull($fresh->cpy_name_ae);
        $this->assertNull($fresh->contact_id);
        // Clearing needs no lookup - there is no id left to resolve a name from.
        Http::assertNothingSent();
    }

    /** Editing the weighting must not reach ShakeDesign at all. */
    public function test_editing_something_else_never_calls_shakedesign(): void
    {
        $this->actAsWriter();
        $lot = $this->lot(['company_id' => 'CPY-1', 'cpy_name_ae' => 'Inchangée']);

        Http::fake();

        $this->patchJson("/api/lots/{$lot->id}", ['tender_weighting_price' => 60])->assertOk();

        Http::assertNothingSent();
        $this->assertSame('Inchangée', $lot->fresh()->cpy_name_ae);
    }

    /**
     * The id is what integrity depends on; the name is for display. A lookup failure must not
     * lose the user's choice.
     */
    public function test_a_failed_name_lookup_still_stores_the_id(): void
    {
        $this->actAsWriter();
        $lot = $this->lot();

        Http::fake([
            '*/sessions' => Http::response($this->sessionBody()),
            '*/layouts/API_CPY/_find' => $this->fmError('952', 'Invalid token', 401),
        ]);

        $this->patchJson("/api/lots/{$lot->id}", ['company_id' => 'CPY-9'])->assertOk();

        $fresh = $lot->fresh();
        $this->assertSame('CPY-9', $fresh->company_id);
        $this->assertNull($fresh->cpy_name_ae);
    }

    /** The snapshot columns are server-owned - a client cannot set a name of its choosing. */
    public function test_the_name_snapshot_columns_are_not_writable(): void
    {
        $this->actAsWriter();
        $lot = $this->lot();

        $this->patchJson("/api/lots/{$lot->id}", ['cpy_name_ae' => 'Inventé'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cpy_name_ae']);

        $this->assertNull($lot->fresh()->cpy_name_ae);
    }

    public function test_the_project_page_exposes_the_snapshot_names(): void
    {
        $this->actAsWriter();
        $this->lot([
            'company_id' => 'CPY-1', 'cpy_name_ae' => 'Entreprise Durand',
            'contact_id' => 'CTC-1', 'ctc_name_ae' => 'Marie Dupont',
        ]);

        Http::fake([
            '*/sessions' => Http::response($this->sessionBody()),
            '*/layouts/API_PRJ/_find' => Http::response($this->found([['zkp' => 'PRJ-1', 'Name' => 'Chantier']])),
        ]);

        $this->get('/projects/PRJ-1')->assertInertia(fn ($page) => $page
            ->where('lots.0.company_name', 'Entreprise Durand')
            ->where('lots.0.contact_name', 'Marie Dupont'));
    }
}
