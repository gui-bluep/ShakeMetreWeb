<?php

namespace Tests\Feature;

use App\Services\ShakeDesign\ShakeDesignApiException;
use App\Services\ShakeDesign\ShakeDesignClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every response here is faked - no test touches a real FileMaker server.
 */
class ShakeDesignClientTest extends TestCase
{
    private const HOST = 'fms.example.test';

    private const TOKEN = 'tok-first';

    private const TOKEN_2 = 'tok-second';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.shakedesign', [
            'host' => self::HOST,
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

    private function client(): ShakeDesignClient
    {
        return new ShakeDesignClient;
    }

    // --- helpers building Data API response bodies -------------------------------------

    private function ok(array $response): array
    {
        return ['response' => $response, 'messages' => [['code' => '0', 'message' => 'OK']]];
    }

    private function fmError(string $code, string $message): array
    {
        return ['response' => [], 'messages' => [['code' => $code, 'message' => $message]]];
    }

    private function sessionBody(string $token = self::TOKEN): array
    {
        return $this->ok(['token' => $token]);
    }

    private function foundBody(array $fieldData): array
    {
        return $this->ok(['data' => [['fieldData' => $fieldData, 'recordId' => '7', 'modId' => '0']]]);
    }

    /**
     * Reads fieldData off the wire. Decoded from the raw body rather than via $request[...]
     * because the client sends fieldData as a JSON object, not an array.
     *
     * @return array<string, mixed>
     */
    private function sentFieldData(Request $request): array
    {
        return json_decode($request->body(), true)['fieldData'] ?? [];
    }

    /** A header carrying the two fields the client requires, plus whatever the test needs. */
    private function header(array $extra = []): array
    {
        return $extra + ['zkf_PRJ' => 'PRJ-1', 'zkf_MET' => 'MET-1'];
    }

    // --- reads ------------------------------------------------------------------------

    public function test_find_project_returns_the_field_data(): void
    {
        Http::fake([
            '*/sessions' => Http::response($this->sessionBody()),
            '*/layouts/API_PRJ/_find' => Http::response($this->foundBody([
                'zkp' => 'PRJ-1', 'Name' => 'Chantier Nord',
            ])),
        ]);

        $project = $this->client()->findProject('PRJ-1');

        $this->assertSame(['zkp' => 'PRJ-1', 'Name' => 'Chantier Nord'], $project);
    }

    public function test_it_looks_records_up_with_an_exact_match_query_on_zkp(): void
    {
        Http::fake([
            '*/sessions' => Http::response($this->sessionBody()),
            '*/layouts/API_CPY/_find' => Http::response($this->foundBody(['zkp' => 'CPY-9'])),
        ]);

        $this->client()->findCompany('CPY-9');

        Http::assertSent(function (Request $request) {
            if (! str_contains($request->url(), '/layouts/API_CPY/_find')) {
                return false;
            }

            // `==` not `=`: an exact whole-field match, so a zkp cannot resolve a
            // different record by word-boundary matching.
            return $request['query'] === [['zkp' => '==CPY-9']] && $request['limit'] === 1;
        });
    }

    public function test_each_read_targets_its_own_api_layout(): void
    {
        foreach ([
            'findProject' => 'API_PRJ',
            'findCompany' => 'API_CPY',
            'findContact' => 'API_CTC',
            'findVatValue' => 'API_ZVAL',
        ] as $method => $layout) {
            Cache::flush();
            Http::fake([
                '*/sessions' => Http::response($this->sessionBody()),
                '*/_find' => Http::response($this->foundBody(['zkp' => 'X'])),
            ]);

            $this->client()->{$method}('X');

            Http::assertSent(fn (Request $r) => str_contains($r->url(), "/layouts/{$layout}/_find"));
        }
    }

    public function test_a_search_matching_nothing_returns_null_rather_than_throwing(): void
    {
        Http::fake([
            '*/sessions' => Http::response($this->sessionBody()),
            // FileMaker reports "no records match" as code 401 in the body - unrelated to
            // HTTP 401 - and answers 404 while doing so. An empty search is a normal
            // outcome, so it must not surface as an error.
            '*/layouts/API_PRJ/_find' => Http::response(
                $this->fmError('401', 'No records match the request'), 404
            ),
        ]);

        $this->assertNull($this->client()->findProject('missing'));
    }

    public function test_every_read_returns_null_when_nothing_matches(): void
    {
        foreach (['findProject', 'findCompany', 'findContact', 'findVatValue'] as $method) {
            Cache::flush();
            Http::fake([
                '*/sessions' => Http::response($this->sessionBody()),
                '*/_find' => Http::response($this->fmError('401', 'No records match the request'), 404),
            ]);

            $this->assertNull($this->client()->{$method}('missing'), "{$method} should return null");
        }
    }

    public function test_a_not_found_is_distinguished_from_an_expired_session(): void
    {
        // Guards the trap this client is built around: FileMaker code 401 ("no records") and
        // HTTP 401 / code 952 (dead token) must never be conflated, or a simple miss would
        // trigger an authentication retry loop.
        $notFound = ShakeDesignApiException::fromResponse('lookup', '401', 'No records match the request', 404);
        $expired = ShakeDesignApiException::fromResponse('lookup', '952', 'Invalid token', 401);

        $this->assertTrue($notFound->isNotFound());
        $this->assertFalse($notFound->isExpiredSession());
        $this->assertTrue($expired->isExpiredSession());
        $this->assertFalse($expired->isNotFound());
    }

    public function test_a_filemaker_error_is_reported_with_its_own_code_and_message(): void
    {
        Http::fake([
            '*/sessions' => Http::response($this->sessionBody()),
            '*/layouts/API_PRJ/_find' => Http::response($this->fmError('102', 'Field is missing'), 500),
        ]);

        try {
            $this->client()->findProject('PRJ-1');
            $this->fail('Expected ShakeDesignApiException.');
        } catch (ShakeDesignApiException $e) {
            $this->assertSame('102', $e->fileMakerCode);
            $this->assertSame('Field is missing', $e->fileMakerMessage);
            $this->assertSame(500, $e->httpStatus);
        }
    }

    public function test_an_error_carried_in_a_200_response_body_still_throws(): void
    {
        Http::fake([
            '*/sessions' => Http::response($this->sessionBody()),
            '*/layouts/API_PRJ/_find' => Http::response($this->fmError('105', 'Layout is missing'), 200),
        ]);

        $this->expectException(ShakeDesignApiException::class);
        $this->client()->findProject('PRJ-1');
    }

    // --- session handling -------------------------------------------------------------

    public function test_the_session_token_is_cached_and_reused(): void
    {
        Http::fake([
            '*/sessions' => Http::response($this->sessionBody()),
            '*/_find' => Http::response($this->foundBody(['zkp' => 'X'])),
        ]);

        $client = $this->client();
        $client->findProject('X');
        $client->findCompany('X');
        $client->findContact('X');

        Http::assertSentCount(4); // one authentication, three lookups
        $this->assertSame(self::TOKEN, Cache::get('shakedesign:data-api:token'));
    }

    public function test_the_token_is_sent_as_a_bearer_token(): void
    {
        Http::fake([
            '*/sessions' => Http::response($this->sessionBody()),
            '*/_find' => Http::response($this->foundBody(['zkp' => 'X'])),
        ]);

        $this->client()->findProject('X');

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '_find')
            && $r->hasHeader('Authorization', 'Bearer '.self::TOKEN));
    }

    public function test_an_expired_session_is_renewed_and_the_request_replayed_once(): void
    {
        Cache::put('shakedesign:data-api:token', 'stale-token', 840);

        Http::fake([
            '*/sessions' => Http::response($this->sessionBody(self::TOKEN_2)),
            '*/layouts/API_PRJ/_find' => Http::sequence()
                ->push($this->fmError('952', 'Invalid FileMaker Data API token'), 401)
                ->push($this->foundBody(['zkp' => 'PRJ-1'])),
        ]);

        $project = $this->client()->findProject('PRJ-1');

        $this->assertSame(['zkp' => 'PRJ-1'], $project);
        $this->assertSame(self::TOKEN_2, Cache::get('shakedesign:data-api:token'));
        Http::assertSentCount(3); // failed lookup, re-authentication, replayed lookup
    }

    public function test_it_retries_only_once_before_giving_up(): void
    {
        Cache::put('shakedesign:data-api:token', 'stale-token', 840);

        Http::fake([
            '*/sessions' => Http::response($this->sessionBody(self::TOKEN_2)),
            '*/layouts/API_PRJ/_find' => Http::response(
                $this->fmError('952', 'Invalid FileMaker Data API token'), 401
            ),
        ]);

        try {
            $this->client()->findProject('PRJ-1');
            $this->fail('Expected ShakeDesignApiException.');
        } catch (ShakeDesignApiException $e) {
            $this->assertSame('952', $e->fileMakerCode);
        }

        Http::assertSentCount(3); // failed lookup, re-authentication, failed replay - no more
    }

    public function test_authentication_failure_is_reported_as_a_filemaker_error(): void
    {
        Http::fake([
            '*/sessions' => Http::response($this->fmError('212', 'Invalid account or password'), 401),
        ]);

        try {
            $this->client()->findProject('PRJ-1');
            $this->fail('Expected ShakeDesignApiException.');
        } catch (ShakeDesignApiException $e) {
            $this->assertSame('212', $e->fileMakerCode);
            $this->assertStringContainsString('authentication', $e->getMessage());
        }
    }

    public function test_missing_configuration_is_reported_before_any_request(): void
    {
        config()->set('services.shakedesign.host', null);
        Http::fake();

        $this->expectException(ShakeDesignApiException::class);
        $this->expectExceptionMessage('services.shakedesign.host is empty');

        $this->client()->findProject('PRJ-1');
    }

    // --- createOffer ------------------------------------------------------------------

    public function test_create_offer_writes_the_header_then_one_record_per_line(): void
    {
        Http::fake([
            '*/sessions' => Http::response($this->sessionBody()),
            '*/layouts/API_OFF/records' => Http::response($this->ok(['recordId' => '55', 'modId' => '0'])),
            '*/layouts/API_OFF/records/55' => Http::response($this->foundBody([
                'zkp' => 'OFF-NEW', 'Title' => 'Offre',
            ])),
            '*/layouts/API_OFL/records' => Http::sequence()
                ->push($this->ok(['recordId' => '101', 'modId' => '0']))
                ->push($this->ok(['recordId' => '102', 'modId' => '0'])),
        ]);

        $result = $this->client()->createOffer(
            ['zkf_PRJ' => 'PRJ-1', 'zkf_MET' => 'MET-1', 'Title' => 'Offre', 'Language' => 'FR'],
            [
                ['Title' => 'Ligne A', 'Quantity' => 2, 'PriceUnit' => 100, 'Unit' => 'm2'],
                ['Title' => 'Ligne B', 'Quantity' => 1, 'PriceUnit' => 50],
            ],
        );

        $this->assertSame('OFF-NEW', $result['zkp']);
        $this->assertSame('55', $result['recordId']);
        $this->assertSame([['recordId' => '101'], ['recordId' => '102']], $result['lines']);
        $this->assertSame('Offre', $result['fieldData']['Title']);
    }

    public function test_create_offer_links_every_line_to_the_generated_header_zkp(): void
    {
        Http::fake([
            '*/sessions' => Http::response($this->sessionBody()),
            '*/layouts/API_OFF/records' => Http::response($this->ok(['recordId' => '55'])),
            '*/layouts/API_OFF/records/55' => Http::response($this->foundBody(['zkp' => 'OFF-NEW'])),
            '*/layouts/API_OFL/records' => Http::response($this->ok(['recordId' => '101'])),
        ]);

        // The caller does not supply zkf_OFF; the client fills it from the created header.
        $this->client()->createOffer($this->header(['Title' => 'Offre']), [['Title' => 'Ligne A']]);

        Http::assertSent(function (Request $r) {
            if (! str_contains($r->url(), '/layouts/API_OFL/records')) {
                return false;
            }

            // fieldData must serialize as a JSON object; FileMaker rejects an array.
            $this->assertStringContainsString('"fieldData":{', $r->body());

            return $this->sentFieldData($r)['zkf_OFF'] === 'OFF-NEW';
        });
    }

    public function test_create_offer_leaves_the_header_in_place_when_a_line_fails(): void
    {
        Http::fake([
            '*/sessions' => Http::response($this->sessionBody()),
            '*/layouts/API_OFF/records' => Http::response($this->ok(['recordId' => '55'])),
            '*/layouts/API_OFF/records/55' => Http::response($this->foundBody(['zkp' => 'OFF-ORPHAN'])),
            '*/layouts/API_OFL/records' => Http::sequence()
                ->push($this->ok(['recordId' => '101']))
                ->push($this->fmError('507', 'Value in field failed validation'), 500),
        ]);

        try {
            $this->client()->createOffer(
                $this->header(['Title' => 'Offre']),
                [['Title' => 'Ligne A'], ['Title' => 'Ligne B', 'Quantity' => -1]],
            );
            $this->fail('Expected ShakeDesignApiException.');
        } catch (ShakeDesignApiException $e) {
            $this->assertStringContainsString('OFF-ORPHAN', $e->getMessage());
            $this->assertStringContainsString('NOT deleted', $e->getMessage());
            $this->assertStringContainsString('line index 1', $e->getMessage());
            $this->assertSame('507', $e->fileMakerCode);
        }

        // No rollback attempt: the header must survive for manual investigation.
        Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE');
    }

    public function test_create_offer_rejects_a_field_the_layout_does_not_expose(): void
    {
        Http::fake();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown field(s) [Nonsense] for ShakeDesign layout API_OFF');

        $this->client()->createOffer(['Title' => 'Offre', 'Nonsense' => 1], []);
    }

    public function test_create_offer_validates_lines_before_writing_the_header(): void
    {
        Http::fake();

        try {
            $this->client()->createOffer(['Title' => 'Offre'], [['Title' => 'A'], ['Bogus' => 2]]);
            $this->fail('Expected InvalidArgumentException.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('API_OFL', $e->getMessage());
        }

        // Nothing may have been written if the payload was never valid.
        Http::assertNothingSent();
    }

    // --- the zkf_PRJ / zkf_MET guarantee ----------------------------------------------

    /**
     * Both fields carry notEmpty="False" in the FileMaker schema, so nothing but the old
     * creation script ever guaranteed them. The client is now that guarantee.
     */
    public static function missingRequiredHeaderFieldProvider(): array
    {
        return [
            'no zkf_PRJ' => [['zkf_MET' => 'MET-1'], 'zkf_PRJ'],
            'no zkf_MET' => [['zkf_PRJ' => 'PRJ-1'], 'zkf_MET'],
            'neither' => [[], 'zkf_PRJ, zkf_MET'],
            'empty zkf_MET' => [['zkf_PRJ' => 'PRJ-1', 'zkf_MET' => ''], 'zkf_MET'],
            'blank zkf_PRJ' => [['zkf_PRJ' => '   ', 'zkf_MET' => 'MET-1'], 'zkf_PRJ'],
            'null zkf_MET' => [['zkf_PRJ' => 'PRJ-1', 'zkf_MET' => null], 'zkf_MET'],
        ];
    }

    #[DataProvider('missingRequiredHeaderFieldProvider')]
    public function test_create_offer_requires_the_project_and_metre_keys(array $header, string $expected): void
    {
        Http::fake();

        try {
            $this->client()->createOffer($header + ['Title' => 'Offre'], [['Title' => 'Ligne A']]);
            $this->fail('Expected InvalidArgumentException.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString("[{$expected}]", $e->getMessage());
        }

        // The guarantee is worthless if the header has already been written.
        Http::assertNothingSent();
    }

    #[DataProvider('missingRequiredHeaderFieldProvider')]
    public function test_create_supplier_order_requires_the_project_and_metre_keys(array $header, string $expected): void
    {
        Http::fake();

        try {
            $this->client()->createSupplierOrder($header + ['Title' => 'Commande'], [['Title' => 'Ligne A']]);
            $this->fail('Expected InvalidArgumentException.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString("[{$expected}]", $e->getMessage());
        }

        Http::assertNothingSent();
    }

    // --- createSupplierOrder ----------------------------------------------------------

    public function test_create_supplier_order_writes_header_and_lines_on_its_own_layouts(): void
    {
        Http::fake([
            '*/sessions' => Http::response($this->sessionBody()),
            '*/layouts/API_SOR/records' => Http::response($this->ok(['recordId' => '77'])),
            '*/layouts/API_SOR/records/77' => Http::response($this->foundBody(['zkp' => 'SOR-NEW'])),
            '*/layouts/API_SOL/records' => Http::response($this->ok(['recordId' => '201'])),
        ]);

        $result = $this->client()->createSupplierOrder(
            [
                'zkf_PRJ' => 'PRJ-1',
                'zkf_MET' => 'MET-1',
                'Title' => 'Commande',
                'Delivery_Address' => '1 rue du Chantier',
                'Delivery_AddressCity' => 'Bruxelles',
                'Delivery_AddressPostalCode' => '1000',
                'Delivery_AddressCountry' => 'BE',
            ],
            [['Title' => 'Ligne A', 'Quantity' => 3, 'PriceUnit' => 20]],
        );

        $this->assertSame('SOR-NEW', $result['zkp']);
        $this->assertSame([['recordId' => '201']], $result['lines']);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/layouts/API_SOL/records')
            && $this->sentFieldData($r)['zkf_SOR'] === 'SOR-NEW');
    }

    public function test_create_supplier_order_leaves_the_header_in_place_when_a_line_fails(): void
    {
        Http::fake([
            '*/sessions' => Http::response($this->sessionBody()),
            '*/layouts/API_SOR/records' => Http::response($this->ok(['recordId' => '77'])),
            '*/layouts/API_SOR/records/77' => Http::response($this->foundBody(['zkp' => 'SOR-ORPHAN'])),
            '*/layouts/API_SOL/records' => Http::response(
                $this->fmError('507', 'Value in field failed validation'), 500
            ),
        ]);

        try {
            $this->client()->createSupplierOrder($this->header(['Title' => 'Commande']), [['Title' => 'Ligne A']]);
            $this->fail('Expected ShakeDesignApiException.');
        } catch (ShakeDesignApiException $e) {
            $this->assertStringContainsString('SOR-ORPHAN', $e->getMessage());
            $this->assertStringContainsString('NOT deleted', $e->getMessage());
            $this->assertStringContainsString('line index 0', $e->getMessage());
        }

        Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE');
    }

    public function test_create_supplier_order_rejects_the_offer_only_unit_field(): void
    {
        Http::fake();

        // API_SOL exposes no Unit field, unlike API_OFL.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown field(s) [Unit] for ShakeDesign layout API_SOL');

        $this->client()->createSupplierOrder(['Title' => 'Commande'], [['Title' => 'A', 'Unit' => 'm2']]);
    }

    public function test_a_failed_header_read_back_still_names_the_orphan_by_record_id(): void
    {
        Http::fake([
            '*/sessions' => Http::response($this->sessionBody()),
            '*/layouts/API_OFF/records' => Http::response($this->ok(['recordId' => '55'])),
            // The header exists, but we cannot learn its zkp - so we cannot name it in the
            // message; the internal recordId is all the investigator gets.
            '*/layouts/API_OFF/records/55' => Http::response($this->fmError('105', 'Layout is missing'), 500),
        ]);

        try {
            $this->client()->createOffer($this->header(['Title' => 'Offre']), [['Title' => 'Ligne A']]);
            $this->fail('Expected ShakeDesignApiException.');
        } catch (ShakeDesignApiException $e) {
            $this->assertStringContainsString('recordId 55', $e->getMessage());
            $this->assertStringContainsString('NOT deleted', $e->getMessage());
            $this->assertSame('105', $e->fileMakerCode);
        }

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/layouts/API_OFL/records'));
        Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE');
    }

    public function test_a_header_created_without_a_readable_zkp_is_reported_not_silently_linked(): void
    {
        Http::fake([
            '*/sessions' => Http::response($this->sessionBody()),
            '*/layouts/API_OFF/records' => Http::response($this->ok(['recordId' => '55'])),
            // Layout does not expose zkp -> the lines could not be linked.
            '*/layouts/API_OFF/records/55' => Http::response($this->foundBody(['Title' => 'Offre'])),
        ]);

        try {
            $this->client()->createOffer($this->header(['Title' => 'Offre']), [['Title' => 'Ligne A']]);
            $this->fail('Expected ShakeDesignApiException.');
        } catch (ShakeDesignApiException $e) {
            $this->assertStringContainsString('recordId 55', $e->getMessage());
            $this->assertStringContainsString('returned no zkp', $e->getMessage());
        }

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/layouts/API_OFL/records'));
    }
}
