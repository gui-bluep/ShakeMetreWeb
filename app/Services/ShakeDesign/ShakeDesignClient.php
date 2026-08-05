<?php

namespace App\Services\ShakeDesign;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

    /**
     * JCPYCTC_JoinCompaniesContacts - which contacts belong to which company.
     *
     * Confirmed against the live layout, which exposes zkp, zkf_CPY and zkf_CTC. Only the two
     * foreign keys are needed: contact names are resolved from API_CTC in a second call, so no
     * related fields have to be added here. Note the layout does NOT expose `Role`, which the
     * source table carries - contactsForCompany() therefore reports a null role rather than
     * inventing one, and adding the field to the layout is all it would take to populate it.
     */
    private const LAYOUT_COMPANY_CONTACT = 'API_JCPYCTC';

    private const LAYOUT_VALUE = 'API_ZVAL';

    private const LAYOUT_USER = 'API_ZUSR';

    /** Un métré n'a pas cinquante offres ; la borne garde contre une réponse démesurée. */
    private const OFFER_LIST_LIMIT = 50;

    private const LAYOUT_OFFER = 'API_OFF';

    private const LAYOUT_OFFER_LINE = 'API_OFL';

    private const LAYOUT_SUPPLIER_ORDER = 'API_SOR';

    private const LAYOUT_SUPPLIER_ORDER_LINE = 'API_SOL';

    /** Every ShakeDesign table keys on a text `zkp`. */
    private const KEY_FIELD = 'zkp';

    /**
     * Comfortably above the 292 active suppliers the live data holds, so the picker is not
     * quietly missing entries - the previous 200 was below it and truncated in silence.
     * Reaching this cap is reported rather than hidden; see ShakeDesignLookupController.
     */
    public const COMPANY_LIST_LIMIT = 500;

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
        // Posé après création, avec le numéro rendu par ZSET_Numbering - voir nextNumber().
        'Number',
        'Language', 'Title', 'Description', 'Comments', 'Date', 'Category',
    ];

    private const FIELDS_OFFER_LINE = [
        'zkf_OFF', 'Title', 'Quantity', 'PriceUnit',
        'Discount', 'DiscountType', 'VATRate', 'Comments', 'Unit',
    ];

    private const FIELDS_SUPPLIER_ORDER = [
        'zkf_PRJ', 'zkf_CPY', 'zkf_CTC', 'zkf_MET',
        // Posé après coup, avec le numéro rendu par ZSET_Numbering - voir nextNumber().
        'Number',
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
     * The ShakeDesign account behind an SSO ticket (ZUSR_Users).
     *
     * @return array<string, mixed>|null null when no account carries that zkp
     *
     * @throws ShakeDesignApiException on a genuine failure (auth, transport, bad request)
     */
    public function findUserByZkp(string $zkp): ?array
    {
        return $this->findOneByKey(self::LAYOUT_USER, $zkp, 'user');
    }

    /**
     * Projects whose Name or Number contains $term - the dashboard's project search.
     *
     * Name and Number are an inference, not a proven field list: API_PRJ is a
     * to-be-built API layout (per the migration plan, "layouts dédiés à l'API...
     * exposant uniquement les champs nécessaires"), and nothing in the export pins down
     * which fields it exposes beyond zkp, which findProject() already relies on. Name and
     * Number are PRJ_Projects' own plain identifying fields (ShakeDesign_boundary_tables.json),
     * the two a person would actually type into a search box - confirm against the real
     * layout before depending on this for anything beyond display.
     *
     * The two fields are OR'd: the Data API treats each element of `query` as a
     * whole-record alternative, so this matches a project whose Name contains the term OR
     * whose Number does, not one requiring both.
     *
     * @return list<array<string, mixed>> each element the matching project's fieldData,
     *                                    empty when nothing matches
     *
     * @throws ShakeDesignApiException on a genuine failure (auth, transport, bad request)
     */
    public function searchProjects(string $term, int $limit = 25): array
    {
        $needle = $this->escapeFindValue(trim($term));

        try {
            $body = $this->send(
                'post',
                'layouts/'.self::LAYOUT_PROJECT.'/_find',
                [
                    'query' => [
                        ['Name' => "*{$needle}*"],
                        ['Number' => "*{$needle}*"],
                    ],
                    'limit' => $limit,
                    'sort' => [['fieldName' => 'Name', 'sortOrder' => 'ascend']],
                ],
                'project search',
            );
        } catch (ShakeDesignApiException $e) {
            if ($e->isNotFound()) {
                return [];
            }

            throw $e;
        }

        return array_column($body['response']['data'] ?? [], 'fieldData');
    }

    /**
     * Suppliers for the supplier picker, name-ordered.
     *
     * Narrowed to isSupplier_b = 1 and isActive_b = 1, both now exposed on API_CPY. Criteria
     * within a single `query` element are AND-ed by the Data API, so the search term joins them
     * rather than widening the result.
     *
     * Filtering on isSupplier_b is also what makes a find work with no search term: the Data
     * API has no "match everything" query, but "every active supplier" is a real criterion, so
     * there is no longer any need to fall back to listing records - which could not have been
     * filtered at all.
     *
     * On the live data isActive_b currently excludes nothing: every one of the 292 suppliers is
     * active, and no company anywhere has isActive_b = 0. It is applied because it is the right
     * criterion for a picker offering a new choice, not because it has been seen to matter -
     * its effect is covered by a test rather than by real data.
     *
     * @return list<array<string, mixed>> each the company's fieldData; empty when nothing matches
     *
     * @throws ShakeDesignApiException on a genuine failure (auth, transport, bad request)
     */
    public function listCompanies(?string $term = null, int $limit = self::COMPANY_LIST_LIMIT): array
    {
        $criteria = ['isSupplier_b' => '==1', 'isActive_b' => '==1'];
        $needle = trim((string) $term);

        if ($needle !== '') {
            $criteria['Name'] = '*'.$this->escapeFindValue($needle).'*';
        }

        return $this->rows(
            'post',
            'layouts/'.self::LAYOUT_COMPANY.'/_find',
            [
                'query' => [$criteria],
                'limit' => $limit,
                'sort' => [['fieldName' => 'Name', 'sortOrder' => 'ascend']],
            ],
            'supplier list',
        );
    }

    /**
     * The contacts linked to one company, through JCPYCTC_JoinCompaniesContacts.
     *
     * Two calls, whatever the number of contacts: one to read the join rows for this company,
     * one to fetch every referenced contact at once - the Data API ORs the elements of
     * `query`, so N contact keys become a single request rather than N lookups.
     *
     * `role` comes from the join's Role field, now exposed on API_JCPYCTC and carrying real
     * values ("Développeur", "Architect - Co-Founder"). An empty Role becomes null rather than
     * an empty string, so a contact with no stated role reads as unknown instead of as having
     * one that happens to be blank.
     *
     * @return list<array{zkp: string, name: string, role: ?string}> name-ordered
     *
     * @throws ShakeDesignApiException on a genuine failure, including the layout going missing
     */
    public function contactsForCompany(string $companyZkp, int $limit = 200): array
    {
        $joins = $this->rows(
            'post',
            'layouts/'.self::LAYOUT_COMPANY_CONTACT.'/_find',
            [
                'query' => [['zkf_CPY' => '=='.$this->escapeFindValue($companyZkp)]],
                'limit' => $limit,
            ],
            'company-contact link lookup',
        );

        // zkf_CTC -> Role, keyed so a contact appearing twice cannot produce a duplicate row.
        $roles = [];

        foreach ($joins as $join) {
            $contactZkp = trim((string) ($join['zkf_CTC'] ?? ''));

            if ($contactZkp !== '') {
                $roles[$contactZkp] ??= is_scalar($join['Role'] ?? null) && (string) $join['Role'] !== ''
                    ? (string) $join['Role']
                    : null;
            }
        }

        if ($roles === []) {
            return [];
        }

        $contacts = $this->rows(
            'post',
            'layouts/'.self::LAYOUT_CONTACT.'/_find',
            [
                'query' => array_values(array_map(
                    fn (string $zkp) => [self::KEY_FIELD => '=='.$this->escapeFindValue($zkp)],
                    array_keys($roles),
                )),
                'limit' => $limit,
            ],
            'contact lookup',
        );

        $resolved = [];

        foreach ($contacts as $contact) {
            $zkp = trim((string) ($contact[self::KEY_FIELD] ?? ''));

            if ($zkp === '') {
                continue;
            }

            $resolved[] = [
                'zkp' => $zkp,
                'name' => self::contactName($contact),
                'role' => $roles[$zkp] ?? null,
            ];
        }

        usort($resolved, fn (array $a, array $b) => strcasecmp($a['name'], $b['name']));

        return $resolved;
    }

    /**
     * API_CTC exposes NameFirst and NameLast separately - there is no combined field on it -
     * so the display name is assembled here rather than read.
     *
     * @param  array<string, mixed>  $contact
     */
    public static function contactName(array $contact): string
    {
        $name = trim(implode(' ', array_filter([
            trim((string) ($contact['NameFirst'] ?? '')),
            trim((string) ($contact['NameLast'] ?? '')),
        ], fn (string $part) => $part !== '')));

        return $name === '' ? 'Contact sans nom' : $name;
    }

    /**
     * A list read where matching nothing is a normal outcome, not a failure - the same
     * FileMaker error 401 that findOneByKey() swallows, surfaced as an empty list.
     *
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function rows(string $method, string $path, array $payload, string $context): array
    {
        try {
            $body = $this->send($method, $path, $payload, $context);
        } catch (ShakeDesignApiException $e) {
            if ($e->isNotFound()) {
                return [];
            }

            throw $e;
        }

        return array_values(array_column($body['response']['data'] ?? [], 'fieldData'));
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
     * Repose des champs sur une commande fournisseur déjà créée, par son `recordId`.
     *
     * Sert au numéro, qui ne peut être obtenu qu'après coup : `ZSET_Numbering` s'exécute à part,
     * et la source elle-même crée d'abord puis numérote. La même liste blanche que la création
     * s'applique - un champ hors `FIELDS_SUPPLIER_ORDER` est refusé avant tout appel réseau.
     *
     * @param  array<string, mixed>  $fields
     *
     * @throws InvalidArgumentException sur un champ inconnu
     * @throws ShakeDesignApiException
     */
    public function updateSupplierOrder(string $recordId, array $fields): void
    {
        $this->assertKnownFields($fields, self::FIELDS_SUPPLIER_ORDER, 'supplier order');

        $this->send(
            'patch',
            'layouts/'.self::LAYOUT_SUPPLIER_ORDER."/records/{$recordId}",
            ['fieldData' => (object) $fields],
            'supplier order update',
        );
    }

    /**
     * Le pendant pour une offre client. Même raison : le numéro n'existe qu'après création.
     *
     * @param  array<string, mixed>  $fields
     *
     * @throws InvalidArgumentException sur un champ inconnu
     * @throws ShakeDesignApiException
     */
    public function updateOffer(string $recordId, array $fields): void
    {
        $this->assertKnownFields($fields, self::FIELDS_OFFER, 'offer');

        $this->send(
            'patch',
            'layouts/'.self::LAYOUT_OFFER."/records/{$recordId}",
            ['fieldData' => (object) $fields],
            'offer update',
        );
    }

    /**
     * Le prochain numéro de document de ShakeDesign - « SOR-2026-0625 » -, ou `null` s'il n'a pas
     * pu être obtenu.
     *
     * **Le seul endroit de ce projet qui exécute un script FileMaker**, et c'est délibéré. La
     * numérotation vit dans `ZSET_Settings`, elle est partagée par les deux applications, et
     * `ZSET_Numbering` ouvre le compteur par un `Open Record/Request` avant de l'incrémenter :
     * ce verrou est précisément ce qui interdit qu'une commande créée du web et une créée dans
     * FileMaker tombent sur le même numéro. Refaire le calcul en PHP, c'est perdre le verrou et
     * gagner des doublons - sur des numéros de commande, ce qui se paie cher.
     *
     * Le Data API sait exécuter un script (`/layouts/{layout}/script/{nom}`) ; ce que ce projet
     * évitait jusqu'ici était de faire écrire ShakeDesign PAR un script dont le corps nous
     * échappait. Ici le corps est lu, le script porte lui-même « exécuter avec privilèges
     * d'accès complets », et le compte API n'a besoin que du droit de l'exécuter.
     *
     * **Dégradé, jamais fatal.** Sans le droit d'exécution, FileMaker répond code 104 « script is
     * missing » - le même code que pour un script absent, on ne peut pas distinguer les deux. Un
     * document sans numéro reste un document ; refuser de le créer serait pire. L'échec est
     * journalisé en nommant le type demandé, parce qu'un numéro manquant se remarque tard.
     *
     * @param  string  $type  le type de ZSET_Numbering : « SOR », « OFF », « INV », …
     */
    public function nextNumber(string $type): ?string
    {
        try {
            $body = $this->send(
                'get',
                'layouts/'.self::LAYOUT_SUPPLIER_ORDER.'/script/ZSET_Numbering',
                ['script.param' => "<Type>{$type}</Type>"],
                'document numbering',
            );
        } catch (ShakeDesignApiException $e) {
            Log::warning("ShakeDesign numbering unavailable for {$type}: {$e->getMessage()}");

            return null;
        }

        // Le script signale ses propres échecs à part du transport : `scriptError` non nul, ou un
        // résultat négatif, qui est la convention de sortie de ces scripts.
        $error = (string) ($body['response']['scriptError'] ?? '0');
        $result = trim((string) ($body['response']['scriptResult'] ?? ''));

        if ($error !== '0' || $result === '' || str_starts_with($result, '-')) {
            Log::warning("ShakeDesign numbering failed for {$type}: error={$error} result={$result}");

            return null;
        }

        return $result;
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
     * Les offres client d'un métré - `OFF_Offers.zkf_MET`, la référence dure que ce projet doit
     * préserver (voir la contrainte critique de CLAUDE.md).
     *
     * `OFL_Total_PriceNoTax_cU` plutôt que son jumeau `_Stored` : un calcul non stocké est évalué
     * par FileMaker à la lecture, donc toujours à jour, tandis que la version stockée dépend d'un
     * recalcul dont nous ne savons rien depuis ici. Pour afficher les données d'un autre système,
     * la valeur vivante est la seule honnête. C'est aussi le montant HORS TVA, celui qui se compare
     * au total des ventes du métré - lui non plus ne porte pas de TVA.
     *
     * Un métré sans offre est un résultat normal : FileMaker le signale par l'erreur 401, avalée
     * ici en liste vide.
     *
     * @return list<array{zkp: string, title: ?string, date: ?string, category: ?string, language: ?string, total_no_tax: ?float}>
     *
     * @throws ShakeDesignApiException on a genuine failure
     */
    /** Un champ FileMaker vide arrive en chaîne vide ; nul veut dire nul. */
    private static function nullIfBlank(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }

    public function listOffersForMetre(string $metreZkp): array
    {
        $rows = $this->rows(
            'post',
            'layouts/'.self::LAYOUT_OFFER.'/_find',
            [
                'query' => [['zkf_MET' => '=='.$this->escapeFindValue($metreZkp)]],
                'limit' => self::OFFER_LIST_LIMIT,
                'sort' => [['fieldName' => 'Date', 'sortOrder' => 'descend']],
            ],
            'client offer list',
        );

        return array_values(array_map(fn (array $row) => [
            'zkp' => (string) ($row['zkp'] ?? ''),
            'title' => $this->nullIfBlank($row['Title'] ?? null),
            'date' => $this->nullIfBlank($row['Date'] ?? null),
            'category' => $this->nullIfBlank($row['Category'] ?? null),
            'language' => $this->nullIfBlank($row['Language'] ?? null),
            'total_no_tax' => ($row['OFL_Total_PriceNoTax_cU'] ?? null) === null || $row['OFL_Total_PriceNoTax_cU'] === ''
                ? null
                : (float) $row['OFL_Total_PriceNoTax_cU'],
        ], $rows));
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

        // Un échec de transport (hôte injoignable, délai dépassé) remonte en
        // ShakeDesignApiException comme les autres : c'est ce que le contrat de ce client annonce,
        // et c'est ce qui permet à un écran de dégrader au lieu de rendre une erreur 500.
        try {
            return $payload === null
                ? $request->{$method}($this->url($path))
                : $request->{$method}($this->url($path), $payload);
        } catch (ConnectionException $e) {
            throw ShakeDesignApiException::fromTransport($path, $e);
        }
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
        // Même règle que `dispatch()` : l'ouverture de session est le premier appel, donc le
        // premier endroit où un hôte injoignable se manifeste.
        try {
            $response = $this->http()
                ->withBasicAuth($this->config('username'), $this->config('password'))
                // FileMaker rejects a session request without a JSON body.
                ->post($this->url('sessions'), (object) []);
        } catch (ConnectionException $e) {
            throw ShakeDesignApiException::fromTransport('authentication', $e);
        }

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
