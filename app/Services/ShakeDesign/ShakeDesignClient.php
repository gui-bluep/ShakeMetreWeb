<?php

namespace App\Services\ShakeDesign;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Talks to ShakeDesign's FileMaker Data API.
 *
 * ShakeDesign remains a FileMaker application for the whole migration, so this is the
 * only way ShakeMetre resolves a project/company/contact/VAT value, and the only way it
 * creates the client offers and supplier orders that ShakeDesign stores hard references
 * to (OFF_Offers.zkf_MET and SOR_SupplierOrders.zkf_MET both point at MET_Metre.zkp).
 *
 * Two Data API traits shape the implementation:
 *
 *  - Records are addressed by an internal `recordId`, not by a field value. Looking a
 *    record up by its `zkp` therefore needs POST `_find`, not GET `records/{id}` - and a
 *    create returns only a recordId, so the generated `zkp` has to be read back.
 *  - Failure is reported in the body (`messages[].code`), not reliably by HTTP status.
 *    Everything here branches on the FileMaker code; see ShakeDesignApiException.
 */
class ShakeDesignClient
{
    private const LAYOUT_PROJECT = 'API_PRJ';

    private const LAYOUT_COMPANY = 'API_CPY';

    private const LAYOUT_CONTACT = 'API_CTC';

    private const LAYOUT_VALUE = 'API_ZVAL';

    private const LAYOUT_OFFER = 'API_OFF';

    private const LAYOUT_OFFER_LINE = 'API_OFL';

    private const LAYOUT_SUPPLIER_ORDER = 'API_SOR';

    private const LAYOUT_SUPPLIER_ORDER_LINE = 'API_SOL';

    /** Every ShakeDesign table keys on a text `zkp`. */
    private const KEY_FIELD = 'zkp';

    /**
     * Enforced here rather than by FileMaker: both fields carry notEmpty="False" in the
     * source schema, so the only thing that ever guaranteed them was the offer-creation
     * script we are replacing. zkf_MET in particular is the stored reference from
     * ShakeDesign back to MET_Metre.zkp - an offer or supplier order written without it is
     * silently detached from its metre, which is the exact breakage the migration analysis
     * warns about.
     */
    private const REQUIRED_HEADER_FIELDS = ['zkf_PRJ', 'zkf_MET'];

    private const FIELDS_OFFER = [
        'zkf_PRJ', 'zkf_CPY', 'zkf_CTC', 'zkf_MET',
        'Language', 'Title', 'Description', 'Comments', 'Date', 'Category',
    ];

    private const FIELDS_OFFER_LINE = [
        'zkf_OFF', 'Title', 'Quantity', 'PriceUnit',
        'Discount', 'DiscountType', 'VATRate', 'Comments', 'Unit',
    ];

    private const FIELDS_SUPPLIER_ORDER = [
        'zkf_PRJ', 'zkf_CPY', 'zkf_CTC', 'zkf_MET',
        'Language', 'Title', 'Description', 'Comments', 'Date', 'Category',
        'Delivery_Address', 'Delivery_AddressCity',
        'Delivery_AddressPostalCode', 'Delivery_AddressCountry',
    ];

    private const FIELDS_SUPPLIER_ORDER_LINE = [
        'zkf_SOR', 'Title', 'Quantity', 'PriceUnit',
        'Discount', 'DiscountType', 'VATRate', 'Comments',
    ];

    /**
     * @return array<string, mixed>|null null when no record carries that zkp
     *
     * @throws ShakeDesignApiException on a genuine failure (auth, transport, bad request)
     */
    public function findProject(string $zkp): ?array
    {
        return $this->findOneByKey(self::LAYOUT_PROJECT, $zkp, 'project');
    }

    /**
     * @return array<string, mixed>|null null when no record carries that zkp
     *
     * @throws ShakeDesignApiException on a genuine failure (auth, transport, bad request)
     */
    public function findCompany(string $zkp): ?array
    {
        return $this->findOneByKey(self::LAYOUT_COMPANY, $zkp, 'company');
    }

    /**
     * @return array<string, mixed>|null null when no record carries that zkp
     *
     * @throws ShakeDesignApiException on a genuine failure (auth, transport, bad request)
     */
    public function findContact(string $zkp): ?array
    {
        return $this->findOneByKey(self::LAYOUT_CONTACT, $zkp, 'contact');
    }

    /**
     * @return array<string, mixed>|null null when no record carries that zkp
     *
     * @throws ShakeDesignApiException on a genuine failure (auth, transport, bad request)
     */
    public function findVatValue(string $zkp): ?array
    {
        return $this->findOneByKey(self::LAYOUT_VALUE, $zkp, 'VAT value');
    }

    /**
     * Creates a client offer: header on API_OFF, then one record per line on API_OFL,
     * each carrying zkf_OFF = the header's generated zkp. Sequential creates rather than
     * portalData, so a line failure is attributable to that line.
     *
     * @param  array<string, mixed>  $header  subset of FIELDS_OFFER; zkf_PRJ and zkf_MET required
     * @param  list<array<string, mixed>>  $lines  each a subset of FIELDS_OFFER_LINE
     * @return array{zkp: string, recordId: string, fieldData: array<string, mixed>, lines: list<array{recordId: string}>}
     *
     * @throws InvalidArgumentException on an unknown or missing required field, before any
     *                                  network call is made
     * @throws ShakeDesignApiException on any Data API failure. If the header was written
     *                                 and a line then failed, the header is left in place
     *                                 and its zkp is in the message.
     */
    public function createOffer(array $header, array $lines): array
    {
        return $this->createHeaderWithLines(
            entity: 'offer',
            headerLayout: self::LAYOUT_OFFER,
            headerFields: self::FIELDS_OFFER,
            header: $header,
            lineLayout: self::LAYOUT_OFFER_LINE,
            lineFields: self::FIELDS_OFFER_LINE,
            lineParentField: 'zkf_OFF',
            lines: $lines,
        );
    }

    /**
     * Creates a supplier order: header on API_SOR, then one record per line on API_SOL,
     * each carrying zkf_SOR = the header's generated zkp.
     *
     * @param  array<string, mixed>  $header  subset of FIELDS_SUPPLIER_ORDER; zkf_PRJ and zkf_MET required
     * @param  list<array<string, mixed>>  $lines  each a subset of FIELDS_SUPPLIER_ORDER_LINE
     * @return array{zkp: string, recordId: string, fieldData: array<string, mixed>, lines: list<array{recordId: string}>}
     *
     * @throws InvalidArgumentException on an unknown or missing required field, before any
     *                                  network call is made
     * @throws ShakeDesignApiException
     */
    public function createSupplierOrder(array $header, array $lines): array
    {
        return $this->createHeaderWithLines(
            entity: 'supplier order',
            headerLayout: self::LAYOUT_SUPPLIER_ORDER,
            headerFields: self::FIELDS_SUPPLIER_ORDER,
            header: $header,
            lineLayout: self::LAYOUT_SUPPLIER_ORDER_LINE,
            lineFields: self::FIELDS_SUPPLIER_ORDER_LINE,
            lineParentField: 'zkf_SOR',
            lines: $lines,
        );
    }

    /**
     * @param  list<string>  $headerFields
     * @param  array<string, mixed>  $header
     * @param  list<string>  $lineFields
     * @param  list<array<string, mixed>>  $lines
     */
    private function createHeaderWithLines(
        string $entity,
        string $headerLayout,
        array $headerFields,
        array $header,
        string $lineLayout,
        array $lineFields,
        string $lineParentField,
        array $lines,
    ): array {
        // Validate the whole payload up front, before any network call: once the header is
        // written there is no rollback, so nothing malformed may be discovered halfway.
        $this->assertKnownFields($header, $headerFields, $headerLayout);
        foreach ($lines as $line) {
            $this->assertKnownFields($line, $lineFields, $lineLayout);
        }
        $this->assertRequiredHeaderFields($header, $entity);

        $headerRecordId = $this->createRecord($headerLayout, $header, "{$entity} header");

        // A create returns only the internal recordId, so read the record back to get the
        // zkp FileMaker generated - that is what the lines must reference.
        try {
            $headerFieldData = $this->readRecord($headerLayout, $headerRecordId, "{$entity} header");
        } catch (ShakeDesignApiException $e) {
            throw ShakeDesignApiException::orphanedHeaderWithoutKey(
                $entity, $headerRecordId, $headerLayout, $e,
            );
        }

        $headerZkp = $headerFieldData[self::KEY_FIELD] ?? null;

        if (! is_string($headerZkp) || $headerZkp === '') {
            throw new ShakeDesignApiException(sprintf(
                'ShakeDesign %s header was created (recordId %s) but layout %s returned no %s, '.
                'so its lines cannot be linked. The header is orphaned in ShakeDesign and was '.
                'NOT deleted - investigate manually.',
                $entity, $headerRecordId, $headerLayout, self::KEY_FIELD,
            ));
        }

        $createdLines = [];

        foreach ($lines as $index => $line) {
            $line[$lineParentField] = $headerZkp;

            try {
                $createdLines[] = [
                    'recordId' => $this->createRecord($lineLayout, $line, "{$entity} line"),
                ];
            } catch (ShakeDesignApiException $e) {
                throw ShakeDesignApiException::orphanedHeader($entity, $headerZkp, $index, $e);
            }
        }

        return [
            'zkp' => $headerZkp,
            'recordId' => $headerRecordId,
            'fieldData' => $headerFieldData,
            'lines' => $createdLines,
        ];
    }

    /**
     * A search matching nothing is a normal outcome, not a failure: FileMaker reports it as
     * error 401, which is swallowed here and surfaced as null. Every other FileMaker error
     * still propagates.
     *
     * @return array<string, mixed>|null the record's fieldData, or null when absent
     */
    private function findOneByKey(string $layout, string $zkp, string $context): ?array
    {
        try {
            $body = $this->send(
                'post',
                "layouts/{$layout}/_find",
                [
                    // `==` is an exact whole-field match; a bare `=` would match on word
                    // boundaries and could resolve the wrong record.
                    'query' => [[self::KEY_FIELD => '=='.$this->escapeFindValue($zkp)]],
                    'limit' => 1,
                ],
                "{$context} lookup",
            );
        } catch (ShakeDesignApiException $e) {
            if ($e->isNotFound()) {
                return null;
            }

            throw $e;
        }

        return $body['response']['data'][0]['fieldData'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $fieldData
     * @return string the internal recordId FileMaker assigned
     */
    private function createRecord(string $layout, array $fieldData, string $context): string
    {
        $body = $this->send(
            'post',
            "layouts/{$layout}/records",
            ['fieldData' => (object) $fieldData],
            "{$context} create",
        );

        $recordId = $body['response']['recordId'] ?? null;

        if (! is_scalar($recordId) || (string) $recordId === '') {
            throw new ShakeDesignApiException(
                "ShakeDesign {$context} create returned no recordId."
            );
        }

        return (string) $recordId;
    }

    /**
     * @return array<string, mixed>
     */
    private function readRecord(string $layout, string $recordId, string $context): array
    {
        $body = $this->send('get', "layouts/{$layout}/records/{$recordId}", null, "{$context} read-back");

        return $body['response']['data'][0]['fieldData'] ?? [];
    }

    /**
     * Performs an authenticated Data API call, refreshing the session and replaying the
     * request exactly once if the server rejects the token before the cache TTL lapsed.
     *
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed> the decoded response body
     */
    private function send(string $method, string $path, ?array $payload, string $context): array
    {
        $response = $this->dispatch($method, $path, $payload, $this->token());

        if ($this->indicatesExpiredSession($response)) {
            Cache::forget($this->config('token_cache_key'));

            $response = $this->dispatch($method, $path, $payload, $this->token());
        }

        return $this->decode($response, $context);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function dispatch(string $method, string $path, ?array $payload, string $token): Response
    {
        $request = $this->http()->withToken($token);

        return $payload === null
            ? $request->{$method}($this->url($path))
            : $request->{$method}($this->url($path), $payload);
    }

    /**
     * A rejected token is reported either as HTTP 401 or as FileMaker code 952. FileMaker
     * code 401 must NOT land here: it means "no records match", which is a data condition,
     * not an authentication one.
     */
    private function indicatesExpiredSession(Response $response): bool
    {
        return $response->status() === 401
            || $this->fileMakerCode($response) === ShakeDesignApiException::CODE_INVALID_TOKEN;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response, string $context): array
    {
        $code = $this->fileMakerCode($response);

        // "0" is FileMaker's success code. Anything else - including a 200 response whose
        // body carries an error - is a failure.
        if ($code !== '0') {
            throw ShakeDesignApiException::fromResponse(
                $context,
                $code,
                $this->fileMakerMessage($response),
                $response->status(),
            );
        }

        return (array) $response->json();
    }

    private function token(): string
    {
        $cached = Cache::get($this->config('token_cache_key'));

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $token = $this->authenticate();

        Cache::put($this->config('token_cache_key'), $token, (int) $this->config('token_ttl'));

        return $token;
    }

    private function authenticate(): string
    {
        $response = $this->http()
            ->withBasicAuth($this->config('username'), $this->config('password'))
            // FileMaker rejects a session request without a JSON body.
            ->post($this->url('sessions'), (object) []);

        $body = $this->decode($response, 'authentication');
        $token = $body['response']['token'] ?? null;

        if (! is_string($token) || $token === '') {
            throw new ShakeDesignApiException('ShakeDesign authentication returned no session token.');
        }

        return $token;
    }

    private function http(): PendingRequest
    {
        return Http::asJson()
            ->acceptJson()
            ->timeout((int) $this->config('timeout', 15))
            ->connectTimeout((int) $this->config('connect_timeout', 5))
            ->withOptions(['verify' => (bool) $this->config('verify', true)]);
    }

    private function url(string $path): string
    {
        $host = rtrim((string) $this->config('host'), '/');

        if (! str_starts_with($host, 'http://') && ! str_starts_with($host, 'https://')) {
            $host = "https://{$host}";
        }

        return sprintf(
            '%s/fmi/data/%s/databases/%s/%s',
            $host,
            $this->config('version', 'vLatest'),
            rawurlencode((string) $this->config('database')),
            $path,
        );
    }

    /**
     * Fails loudly on a field the target layout does not expose, rather than letting
     * FileMaker answer with an opaque "field is missing" error - or worse, silently
     * dropping caller data.
     *
     * @param  array<string, mixed>  $given
     * @param  list<string>  $allowed
     */
    private function assertKnownFields(array $given, array $allowed, string $layout): void
    {
        $unknown = array_diff(array_keys($given), $allowed);

        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                'Unknown field(s) [%s] for ShakeDesign layout %s. Allowed: [%s].',
                implode(', ', $unknown),
                $layout,
                implode(', ', $allowed),
            ));
        }
    }

    /**
     * An empty value counts as absent: a blank zkf_MET detaches the record from its metre
     * just as surely as a missing key would.
     *
     * @param  array<string, mixed>  $header
     */
    private function assertRequiredHeaderFields(array $header, string $entity): void
    {
        $missing = array_values(array_filter(
            self::REQUIRED_HEADER_FIELDS,
            fn (string $field) => ! isset($header[$field])
                || (is_string($header[$field]) && trim($header[$field]) === ''),
        ));

        if ($missing !== []) {
            throw new InvalidArgumentException(sprintf(
                'ShakeDesign %s header is missing required field(s) [%s]. FileMaker never '.
                'enforced these at field level (notEmpty="False"); they are required here '.
                'because zkf_MET is the stored reference back to MET_Metre.zkp and zkf_PRJ '.
                'ties the record to its project.',
                $entity,
                implode(', ', $missing),
            ));
        }
    }

    /**
     * FileMaker treats these as find operators, so they are escaped to keep a literal
     * value literal. A zkp never contains them, but a caller-supplied value might.
     */
    private function escapeFindValue(string $value): string
    {
        return str_replace(
            ['\\', '@', '*', '#', '?', '!', '=', '<', '>', '"'],
            ['\\\\', '\@', '\*', '\#', '\?', '\!', '\=', '\<', '\>', '\"'],
            $value,
        );
    }

    private function fileMakerCode(Response $response): ?string
    {
        $code = $response->json('messages.0.code');

        return is_scalar($code) ? (string) $code : null;
    }

    private function fileMakerMessage(Response $response): ?string
    {
        $message = $response->json('messages.0.message');

        return is_scalar($message) ? (string) $message : null;
    }

    private function config(string $key, mixed $default = null): mixed
    {
        $value = config("services.shakedesign.{$key}", $default);

        if (in_array($key, ['host', 'database', 'username', 'password'], true)
            && (! is_string($value) || $value === '')) {
            throw ShakeDesignApiException::notConfigured($key);
        }

        return $value;
    }
}
