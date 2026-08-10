<?php

namespace App\Console\Commands\LegacyImport;

/**
 * TEMPORAIRE — À SUPPRIMER AVEC L'IMPORT.
 *
 * Champ FileMaker → colonne locale, une entrée par colonne à remplir, plus la conversion de
 * valeur. Déclaratif exprès : la correspondance est la partie qu'on relit, et une table se
 * relit contre `docs/filemaker-reference/API_MIGRATION_layouts.md` là où du code impératif se
 * relit ligne à ligne.
 *
 * Les types, et ce qu'ils règlent :
 *
 *  - `uuid` — une clé. `''` devient null ; **ce qui n'a pas la forme d'un UUID devient null
 *    aussi, et est signalé** plutôt qu'écrit : `zkf_OFF` et `zkf_TENDER_Supp1..5` sont typés
 *    Number dans FileMaker alors qu'ils portent des UUID (relevé sur le serveur), donc le type
 *    déclaré ne prouve rien et la colonne locale est un `uuid`.
 *  - `int` — les codes de section. `'02'` vaut 2 et `'00'` vaut 0 : la source les stocke en
 *    texte à zéros de tête, la colonne locale est un entier. Perte connue et documentée
 *    (CLAUDE.md, « REF_Reference.Code est du texte dans la source »).
 *  - `dec` — de l'argent et des quantités. `''` devient null et non 0 : « pas de prix » n'est
 *    pas « prix nul », et les formules de la source distinguent les deux (IsEmpty).
 *  - `bool` — FileMaker rend 1 / 0 / `''`. Les colonnes locales sont NOT NULL avec un défaut
 *    false, donc `''` vaut false.
 *  - `date` / `datetime` — **le Data API rend les dates au format du fichier, ici
 *    `MM/DD/YYYY`.** Carbon::parse() sur `01/21/2025` marcherait par chance et sur
 *    `04/10/2025` donnerait le 10 avril là où la source dit le 10 avril — mais rien ne
 *    garantit lequel des deux composants Carbon prendra pour le mois. Le format est donc
 *    explicite, et un composant > 12 en première position fait échouer l'import bruyamment
 *    plutôt que de décaler douze mois de dates d'accord commercial.
 */
class LegacyFieldMap
{
    /**
     * Anomalies de conversion rencontrées, pour le rapport final. Une clé mal formée ou une
     * date illisible est mise à null et comptée — jamais écrite de travers en silence.
     *
     * @var array<string, int>
     */
    private array $anomalies = [];

    /**
     * Les valeurs distinctes de `zlg_creaUserName` / `zlg_modifUserName` croisées.
     *
     * L'auteur ne survit pas : FileMaker donne un NOM DE COMPTE, `created_by` / `updated_by`
     * attendent l'UUID d'un utilisateur, et écrire « Sophie » dans une colonne d'UUID serait un
     * mensonge que rien ne rattraperait. Les colonnes restent nulles et les noms sont
     * rassemblés ici, pour qu'une correspondance reste possible plus tard — c'est la raison
     * pour laquelle les champs sont sur les mises en page.
     *
     * @var array<string, int>
     */
    private array $accountNames = [];

    /**
     * REF_Reference → metre_references.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function reference(): array
    {
        return [
            'id' => ['zkp', 'uuid'],
            'code' => ['Code', 'int'],
            'title_en' => ['Title_EN', 'str'],
            'title_fr' => ['Title_FR', 'str'],
            'title_nl' => ['Title_NL', 'str'],
        ];
    }

    /**
     * REFS_SubReference → sub_references.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function subReference(): array
    {
        return [
            'id' => ['zkp', 'uuid'],
            'reference_id' => ['zkf_REF', 'uuid'],
            'code' => ['Code', 'int'],
            'title_en' => ['Title_EN', 'str'],
            'title_fr' => ['Title_FR', 'str'],
            'title_nl' => ['Title_NL', 'str'],
        ];
    }

    /**
     * REFSL_SubReferenceLines → sub_reference_lines.
     *
     * Porte zkf_REFS et zkf_REF, les deux clés étrangères stockées de la source. Vérifié sur
     * les 507 lignes : zkf_REF est toujours celui du REFS parent, aucune incohérence.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function subReferenceLine(): array
    {
        return [
            'id' => ['zkp', 'uuid'],
            'sub_reference_id' => ['zkf_REFS', 'uuid'],
            'reference_id' => ['zkf_REF', 'uuid'],
            'code' => ['Code', 'int'],
            'title_en' => ['Title_EN', 'str'],
            'title_fr' => ['Title_FR', 'str'],
            'title_nl' => ['Title_NL', 'str'],
            'price' => ['Price', 'dec'],
            'unit' => ['Unit', 'str'],
            'description_en' => ['Description_EN', 'text'],
            'description_fr' => ['Description_FR', 'text'],
            'description_nl' => ['Description_NL', 'text'],
        ];
    }

    /**
     * MET_Metre → metres.
     *
     * Les totaux `_Stored` sont repris VERBATIM, pas recalculés : ce sont les chiffres que
     * FileMaker montrait, et un import qui les recalcule ne migre pas des données, il en
     * fabrique. `--recalculate` existe pour les refaire ensuite, séparément et sciemment.
     *
     * Sans source, et à raison : `sequence_number`, `lot_names_cache`, `lot_ids_cache` — les
     * `zg_LotNames_Cache` / `zg_LotZKP_Cache` de la source sont des champs GLOBAUX, donc de
     * l'état de session partagé par tous les enregistrements, pas une donnée du métré.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function metre(): array
    {
        return [
            'id' => ['zkp', 'uuid'],
            'project_id' => ['zkf_PRJ', 'uuid'],
            'offer_id' => ['zkf_OFF', 'uuid'],

            'name' => ['Name', 'str'],
            'ind_project' => ['IndProject', 'int'],
            'language' => ['Language', 'str'],
            'ratio_markup' => ['Ratio_Markup', 'dec'],
            'sort_order_tags' => ['Sort_OrderTags', 'int'],

            'is_accepted_b' => ['isAccepted_b', 'bool'],
            'is_status_site_b' => ['IsStatus_Site_b', 'bool'],
            'is_imported_b' => ['isImported_b', 'bool'],
            'is_locked_b' => ['isLocked_b', 'bool'],
            'is_archived_b' => ['isArchived_b', 'bool'],

            'date_creation' => ['Date_Creation', 'date'],
            'date_agreement' => ['Date_Agreement', 'date'],
            'prog_progress_update_date' => ['PROG_ProgressUpdateDate', 'date'],
            'date_time_update_calcs_stored' => ['DateTime_UpdateCalcsStored', 'datetime'],

            'comment_client' => ['Comment_Client', 'text'],
            'comment_internal' => ['Comment_Internal', 'text'],
            'comment_supplier' => ['Comment_Supplier', 'text'],

            'prog_progress_client_total_amount_stored' => ['PROG_ProgressClientTotal_Amount_Stored', 'dec'],
            'prog_progress_client_total_percent_stored' => ['PROG_ProgressClientTotal_Percent_Stored', 'dec'],
            'prog_progress_supp_total_amount_stored' => ['PROG_ProgressSuppTotal_Amount_Stored', 'dec'],
            'prog_progress_supp_total_percent_stored' => ['PROG_ProgressSuppTotal_Percent_Stored', 'dec'],
            'prog_progress_client_total_amount_valid_stored_c' => ['PROG_ProgressClientTotal_Amount_Valid_Stored_c', 'dec'],
            'prog_progress_client_total_percent_valid_stored_c' => ['PROG_ProgressClientTotal_Percent_Valid_Stored_c', 'dec'],
            'prog_progress_supp_total_amount_valid_stored_c' => ['PROG_ProgressSuppTotal_Amount_Valid_Stored_c', 'dec'],
            'prog_progress_supp_total_percent_valid_stored_c' => ['PROG_ProgressSuppTotal_Percent_Valid_Stored_c', 'dec'],

            'tot_sum_total_ordered_stored' => ['Tot_Sum_TotalOrdered_Stored', 'dec'],
            'tot_sum_total_sales_stored' => ['Tot_Sum_TotalSales_Stored', 'dec'],
            'tot_sum_total_sales_offer_stored' => ['Tot_Sum_TotalSalesOffer_Stored', 'dec'],
            'tot_sum_total_buy_stored' => ['Tot_Sum_TotalBuy_Stored', 'dec'],
            'tot_sum_total_gain_stored' => ['Tot_Sum_TotalGain_Stored', 'dec'],
            'tot_lot_assigned_gain_on_purchase_stored' => ['Tot_LotAssigned_GainOnPurchase_Stored', 'dec'],

            /*
             * DÉLIBÉRÉMENT ABSENTS : tot_sum_total_fees_stored, tot_percentage_total_fees_stored,
             * tot_ratio_total_fees_stored.
             *
             * Ces trois-là ne sont pas des données par enregistrement, malgré leur nom en
             * `_Stored`. Le dictionnaire donne leurs formules :
             *
             *     Tot_Sum_TotalFees_Stored         zsm_SumTotalSales_Stored - zsm_SumTotalOrdered_Stored
             *     Tot_Percentage_TotalFees_Stored  (Tot_Sum_TotalFees_Stored / zsm_SumTotalSales_Stored) * 100
             *     Tot_Ratio_TotalFees_Stored       zsm_SumTotalSales_Stored / zsm_SumTotalOrdered_Stored
             *
             * — des champs Calculated qui consomment des champs SUMMARY (`zsm_`), donc des totaux
             * du FOUND SET, pas du métré. Le même argument que celui qui a déjà écarté les `zsm_*`
             * des colonnes de `metres` (voir la migration create_metres_table) s'applique aux
             * calculs qui les lisent.
             *
             * Constaté et non déduit : sur une lecture réelle, `Tot_Sum_TotalFees_Stored` valait
             * 6 494 969,76 et `Tot_Ratio_TotalFees_Stored` 1,5450183058739 — À L'IDENTIQUE sur
             * chaque métré lu. Les importer écrirait le même chiffre dénué de sens sur les 877, et
             * l'un d'eux revenait à 3,5275847787813E+17, ce qui débordait `decimal(15,4)` et faisait
             * échouer l'insertion : le déni de service était l'avertissement, pas le problème.
             *
             * Les colonnes restent donc nulles, ce qui se lit « pas encore calculé » là où un
             * agrégat de found set se lirait « faux ». `RecalculateMetreTotals` les remplit par
             * enregistrement — c'est ce que `--recalculate` déclenche.
             *
             * Les quatre `PROG_*_Valid_Stored_c` sont Calculated eux aussi mais restent importés,
             * et la différence est nette : leurs formules sont `PROG_..._Stored * isAccepted_b` /
             * `* IsStatus_Site_b`, donc du par-enregistrement de bout en bout.
             */

            'total_ordered_metl_stored' => ['Total_Ordered_METL_Stored', 'dec'],
            'total_purchase_metl_stored' => ['Total_Purchase_METL_Stored', 'dec'],
            'total_sales_metl_stored' => ['Total_Sales_METL_Stored', 'dec'],
            'total_gain_metl_stored' => ['Total_Gain_METL_Stored', 'dec'],
        ];
    }

    /**
     * LOT_Lot → lots.
     *
     * `zkf_TENDER_Supp1..5` sont typés Number dans la source et portent des UUID de sociétés
     * ShakeDesign (vérifié : 118 lots renseignés, tous en UUID). Les colonnes locales sont des
     * `uuid`, ce qui était une inférence du plan de migration et se trouve confirmé.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function lot(): array
    {
        $map = [
            'id' => ['zkp', 'uuid'],
            'project_id' => ['zkf_PRJ', 'uuid'],
            'company_id' => ['zkf_CPY', 'uuid'],
            'contact_id' => ['zkf_CTC', 'uuid'],

            'code' => ['Code', 'int'],
            'title_en' => ['Title_EN', 'str'],
            'title_fr' => ['Title_FR', 'str'],
            'title_nl' => ['Title_NL', 'str'],
            'title_custom' => ['Title_Custom', 'str'],

            'cpy_name_ae' => ['CPY_Name_ae', 'str'],
            'cpy_adr_ae' => ['CPY_ADR_ae', 'str'],
            'ctc_name_ae' => ['CTC_Name_ae', 'str'],

            'tender_weighting_price' => ['TENDER_Weighting_Price', 'dec'],
        ];

        for ($i = 1; $i <= 5; $i++) {
            $map["tender_supplier_{$i}_id"] = ["zkf_TENDER_Supp{$i}", 'uuid'];
            $map["tender_supp{$i}_comment"] = ["TENDER_Supp{$i}_Comment", 'text'];
        }

        for ($crit = 1; $crit <= 5; $crit++) {
            $map["tender_weighting_crit{$crit}"] = ["TENDER_Weighting_Crit{$crit}", 'int'];
            $map["tender_weighting_crit{$crit}_description"] = ["TENDER_Weighting_Crit{$crit}_Description", 'text'];

            for ($supp = 1; $supp <= 5; $supp++) {
                $map["tender_weighting_crit{$crit}_supp{$supp}"] = ["TENDER_Weighting_Crit{$crit}_Supp{$supp}", 'int'];
            }
        }

        return $map;
    }

    /**
     * METL_MetreLines → metre_lines.
     *
     * Deux points qui se lisent mal sans explication :
     *
     *  - **`Order` va dans `ref_order`, pas dans `sort_order`.** Ce sont deux rangs différents
     *    et les deux existent : `Order` compte à partir de 1 DANS une section et forme le
     *    troisième terme du code imprimé « 20.8.1 », là où `sort_order` est un rang libre dans
     *    tout le métré, propre au web et sans source.
     *  - **`Image_500x500` n'est pas repris.** C'est un conteneur : le Data API n'en rend
     *    qu'une URL temporaire à télécharger fichier par fichier, et ce projet n'a pas de
     *    stockage. Hors périmètre de la première passe, décidé ; conséquence, le document
     *    « budget client — complet » perd les vignettes que la source imprimait.
     *
     * `zkf_REF` / `zkf_REFS` / `zkf_REFSL` sont là et arriveront nuls : ils sont vides sur les
     * 57 816 lignes (recompté sur le serveur). Une ligne ne pointe pas le catalogue, elle en
     * porte une copie — c'est ce qui permet de renommer une section sans réécrire les métrés
     * déjà envoyés. Les poser documente le fait.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function metreLine(): array
    {
        $map = [
            'id' => ['zkp', 'uuid'],
            'metre_id' => ['zkf_MET', 'uuid'],

            'reference_id' => ['zkf_REF', 'uuid'],
            'sub_reference_id' => ['zkf_REFS', 'uuid'],
            'sub_reference_line_id' => ['zkf_REFSL', 'uuid'],
            'material_id' => ['zkf_MAT', 'uuid'],
            'cart_material_id' => ['zkf_JCARTMAT', 'uuid'],
            'lot_id' => ['zkf_LOT', 'uuid'],

            'supplier_order_id' => ['zkf_SOR', 'uuid'],
            'company_id' => ['zkf_CPY', 'uuid'],
            'vat_value_id' => ['zkf_VAT_ae', 'uuid'],
            'accounting_code_id' => ['zkf_AccountingCode_ae', 'uuid'],

            'ref_code' => ['REF_Code', 'int'],
            'ref_title' => ['REF_Title', 'str'],
            'refs_code' => ['REFS_Code', 'int'],
            'refs_title' => ['REFS_Title', 'str'],
            'refsl_title' => ['REFSL_Title', 'str'],
            'ref_order' => ['Order', 'int'],

            'language' => ['Language', 'str'],
            'unit' => ['Unit', 'str'],
            'description' => ['Description', 'text'],
            'description_cch' => ['Description_CCH', 'text'],
            'comment_client' => ['Comment_Client', 'text'],
            'comment_supplier' => ['Comment_Supplier', 'text'],
            'localisation' => ['Localisation', 'text'],
            'tag1' => ['TAG1', 'str'],
            'tag2' => ['TAG2', 'str'],
            'lot_name_stored' => ['LOT_Name_Stored', 'str'],
            'sor_title_ref' => ['SOR_TitleRef', 'str'],
            'procurement_method_code_ae' => ['ProcurementMethodCode_ae', 'str'],

            'price_buy' => ['PriceBuy', 'dec'],
            'price_ordered' => ['PriceOrdered', 'dec'],
            'price_sales' => ['PriceSales', 'dec'],
            'quantity' => ['Quantity', 'dec'],
            'quantity_ordered' => ['QuantityOrdered', 'dec'],
            'ratio' => ['Ratio', 'dec'],
            // Deux noms de champ contiennent une espace. Ce n'est pas une coquille.
            'sum_total_work_fee' => ['SumTotal WorkFee', 'dec'],
            'sum_total_work_fee_ordered' => ['SumTotal WorkFeeOrdered', 'dec'],
            'sum_total_company1_price' => ['SumTotalCompany1Price', 'dec'],
            'prog_progress_client' => ['PROG_ProgressClient', 'dec'],
            'prog_progress_supp' => ['PROG_ProgressSupp', 'dec'],
            'vat_ae' => ['VAT_ae', 'dec'],

            'is_option_b' => ['isOption_b', 'bool'],
            'is_locked_bae' => ['isLocked_bae', 'bool'],
            'is_imported_b' => ['isImported_b', 'bool'],
            'is_tender_line_b' => ['isTenderLine_b', 'bool'],
            'is_estimated_price_b' => ['isEstimatedPrice_b', 'bool'],
            'is_delivered_b' => ['isDelivered_b', 'bool'],
            'metc_is_present_b' => ['METC_isPresent_b', 'bool'],

            'tender_id' => ['TENDER_Id', 'int'],
        ];

        for ($i = 1; $i <= 5; $i++) {
            $map["tender_supp{$i}_price"] = ["TENDER_Supp{$i}_Price", 'dec'];
            $map["tender_supp{$i}_quantity"] = ["TENDER_Supp{$i}_Quantity", 'dec'];
            $map["tender_supp{$i}_omit_b"] = ["TENDER_Supp{$i}_Omit_b", 'bool'];
        }

        return $map;
    }

    /**
     * METC_MetreLineComponent → metre_line_components.
     *
     * `z_Order` → `sort_order`, contre la liste de champs remise, qui donnait `sort_order` comme
     * sans source. Ce n'est pas une supposition, c'est lu dans l'export : `Field id="182"
     * name="z_Order" fieldtype="Normal" datatype="Number"`, dont l'auto-enter est
     * `metc_METC__Ordering_s::z_Order + 1` — le rang suivant, pris par auto-jointure triée
     * décroissant — que les variables `$$DRAG.ORDER` / `$$DROP.ORDER` lisent pour le
     * glisser-déposer et sur lequel les portails trient croissant. C'est donc bien le rang d'un
     * composant dans sa ligne, et le laisser tomber perdrait l'ordre d'affichage.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function metreLineComponent(): array
    {
        return [
            'id' => ['zkp', 'uuid'],
            'metre_line_id' => ['zkf_METL', 'uuid'],
            'sort_order' => ['z_Order', 'int'],
            'description' => ['Description', 'text'],
            'length' => ['Length', 'dec'],
            'width' => ['Width', 'dec'],
            'height' => ['Height', 'dec'],
            'quantity_sales' => ['QuantitySales', 'dec'],
            'quantity_ordered' => ['QuantityOrdered', 'dec'],
        ];
    }

    /**
     * Un enregistrement FileMaker → une ligne prête pour un insert, horodatages compris.
     *
     * @param  array<string, array{0: string, 1: string}>  $map
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    public function row(array $map, array $record, string $table): array
    {
        $row = [];

        foreach ($map as $column => [$field, $type]) {
            $row[$column] = $this->coerce($record[$field] ?? null, $type, $table, $field);
        }

        // Les horodatages de création/modification passent tels quels — eux, contrairement à
        // l'auteur, ont un équivalent exact. Un métré sans horodatage garde des colonnes nulles
        // plutôt que de se voir daté du jour de l'import, qui serait une date inventée.
        $row['created_at'] = $this->coerce($record['zlg_creaTimeStamp'] ?? null, 'datetime', $table, 'zlg_creaTimeStamp');
        $row['updated_at'] = $this->coerce($record['zlg_modifTimeStamp'] ?? null, 'datetime', $table, 'zlg_modifTimeStamp');

        // created_by / updated_by restent nuls — voir $accountNames.
        $row['created_by'] = null;
        $row['updated_by'] = null;

        foreach (['zlg_creaUserName', 'zlg_modifUserName'] as $field) {
            $name = trim((string) ($record[$field] ?? ''));

            if ($name !== '') {
                $this->accountNames[$name] = ($this->accountNames[$name] ?? 0) + 1;
            }
        }

        return $row;
    }

    private function coerce(mixed $value, string $type, string $table, string $field): mixed
    {
        if ($type === 'bool') {
            // Un booléen FileMaker est un nombre : 1, 0, ou vide. Les colonnes locales sont
            // NOT NULL avec un défaut false, donc vide vaut false.
            return (bool) (int) ($value === '' || $value === null ? 0 : $value);
        }

        if (is_string($value)) {
            $value = trim($value);
        }

        if ($value === '' || $value === null) {
            return null;
        }

        // « ? » est la façon dont FileMaker rend une erreur de calcul dans un champ stocké,
        // typiquement une division par zéro. Relevé sur les quatre pourcentages d'avancement :
        // `PROG_ProgressSuppTotal_Percent_Stored` vaut « ? » sur 519 des 877 métrés, ceux dont le
        // total est nul. null est donc la lecture JUSTE, pas un échec de conversion — d'où un
        // libellé à part dans le rapport plutôt qu'un « nombre illisible », qui se lirait comme
        // un bug et apprendrait à ignorer la liste des anomalies.
        if ($value === '?' && in_array($type, ['dec', 'int'], true)) {
            return $this->flag(null, $table, $field, '« ? », erreur de calcul FileMaker → null');
        }

        return match ($type) {
            'uuid' => $this->uuid($value, $table, $field),
            'int' => $this->number($value, $table, $field, asInt: true),
            'dec' => $this->number($value, $table, $field),
            'str', 'text' => (string) $value,
            'date' => $this->temporal((string) $value, withTime: false, table: $table, field: $field),
            'datetime' => $this->temporal((string) $value, withTime: true, table: $table, field: $field),
            default => $value,
        };
    }

    /**
     * Un nombre, en reproduisant la lecture que FileMaker fait lui-même du champ.
     *
     * Un champ Nombre de FileMaker accepte du texte, et le lit quand même comme un nombre : il en
     * extrait la partie numérique, comme `GetAsNumber`. Trouvé sur les données réelles, et
     * seulement parce que l'import complet l'a signalé — `TENDER_Supp3_Quantity` vaut **« 59² »**
     * sur 6 lignes, un « ² » tapé à la main dans une quantité. FileMaker le tient pour 59 :
     * vérifié en interrogeant le serveur avec une comparaison numérique, qui ramène bien ces
     * lignes. Les mettre à null perdrait donc une quantité d'appel d'offres que la source, elle,
     * utilise dans ses calculs.
     *
     * D'où l'extraction plutôt que le rejet — et le signalement dans tous les cas : une valeur
     * réinterprétée doit se voir, même quand la réinterprétation est la bonne. Ce qui ne contient
     * aucun nombre reste null et est signalé autrement.
     */
    private function number(mixed $value, string $table, string $field, bool $asInt = false): int|float|null
    {
        if (is_numeric($value)) {
            return $this->withinRange($asInt ? (int) $value : (float) $value, $table, $field, $asInt);
        }

        if (preg_match('/-?\d+(?:[.,]\d+)?/', (string) $value, $matches) === 1) {
            $number = (float) str_replace(',', '.', $matches[0]);

            $this->flag(null, $table, $field, sprintf(
                '« %s » lu comme %s, comme le fait le champ Nombre de FileMaker',
                $value,
                rtrim(rtrim(number_format($number, 4, '.', ''), '0'), '.'),
            ));

            return $this->withinRange($asInt ? (int) $number : $number, $table, $field, $asInt);
        }

        return $this->flag(null, $table, $field, sprintf('nombre illisible (« %s ») → null', $value));
    }

    /**
     * Ce que la colonne peut contenir, ou null.
     *
     * FileMaker n'a pas de bornes : un champ Nombre accepte n'importe quelle magnitude. Les
     * colonnes d'ici en ont — `decimal(15,4)` partout, soit 11 chiffres avant la virgule, et des
     * `integer` MySQL. Sans ce contrôle, une seule valeur hors bornes fait échouer l'`upsert` du
     * paquet entier avec un « Numeric value out of range », **à 1 métré sur 877**, et emporte la
     * phase avec elle. Vu pour de vrai sur `Tot_Percentage_TotalFees_Stored` à 3,5e17 — un champ
     * qui, lui, n'aurait jamais dû être importé (voir metre()), mais rien ne garantit qu'il soit
     * le dernier.
     *
     * null et un signalement, jamais un écrêtage : ramener 3,5e17 à 99 999 999 999,9999
     * inventerait un chiffre, et sur un montant c'est pire que reconnaître qu'on n'en a pas.
     */
    private function withinRange(int|float $number, string $table, string $field, bool $asInt): int|float|null
    {
        // decimal(15,4) : 15 chiffres dont 4 après la virgule.
        $limit = $asInt ? 2147483647 : 10 ** 11;

        if (abs($number) >= $limit || is_nan((float) $number) || is_infinite((float) $number)) {
            return $this->flag(null, $table, $field, sprintf(
                '%s dépasse ce que la colonne peut contenir (%s) → null',
                is_float($number) ? sprintf('%.4G', $number) : (string) $number,
                $asInt ? 'entier' : 'decimal(15,4)',
            ));
        }

        return $number;
    }

    /**
     * Une clé, ou null.
     *
     * Le contrôle n'est pas de la méfiance envers la source : `zkf_OFF` et les cinq
     * `zkf_TENDER_Supp` sont typés Number côté FileMaker, donc rien n'y empêche un nombre, et
     * la colonne locale est un `uuid`. Écrire « 12 » dans un `char(36)` d'UUID passerait la
     * base et casserait la première jointure. Mieux vaut null et un signalement.
     */
    private function uuid(mixed $value, string $table, string $field): ?string
    {
        $value = (string) $value;

        if (preg_match('/^[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}$/', $value) !== 1) {
            return $this->flag(null, $table, $field, 'clé qui n\'est pas un UUID');
        }

        return $value;
    }

    /**
     * `MM/DD/YYYY [HH:MM[:SS]]` → `Y-m-d [H:i:s]`, ou l'ISO si le fichier s'y met un jour.
     *
     * Décomposé à la main plutôt que confié à `Carbon::createFromFormat`, et ce n'est pas de la
     * défiance : createFromFormat REPORTE les composants hors bornes au lieu d'échouer, donc
     * `13/05/2025` lu en `m/d/Y` devient janvier 2026 sans un mot. Sur un fichier réglé en
     * jour/mois, ça décalerait silencieusement des dates d'accord commercial — exactement le
     * genre d'erreur qu'on ne voit qu'une fois qu'elle est en base.
     *
     * `checkdate()` tranche, et un premier composant > 12 avec un second qui, lui, passerait
     * pour un mois est signalé nommément : c'est la signature d'un fichier en jour/mois, un
     * problème de configuration et non une donnée abîmée.
     */
    private function temporal(string $value, bool $withTime, string $table, string $field): ?string
    {
        // ISO d'abord : sans ambiguïté, donc rien à vérifier au-delà de la validité du jour.
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{1,2}):(\d{2})(?::(\d{2}))?)?$/', $value, $m) === 1) {
            [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})(?:\s+(\d{1,2}):(\d{2})(?::(\d{2}))?)?$#', $value, $m) === 1) {
            [$month, $day, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];

            if ($month > 12 && $day <= 12) {
                return $this->flag(null, $table, $field, sprintf(
                    'date en jour/mois (« %s ») alors que le Data API est lu en mois/jour — '.
                    'le fichier FileMaker n\'a pas le format attendu, corrigez avant d\'importer',
                    $value,
                ));
            }
        } else {
            return $this->flag(null, $table, $field, sprintf('date illisible (« %s »)', $value));
        }

        if (! checkdate($month, $day, $year)) {
            return $this->flag(null, $table, $field, sprintf('date inexistante (« %s »)', $value));
        }

        $date = sprintf('%04d-%02d-%02d', $year, $month, $day);

        if (! $withTime) {
            return $date;
        }

        return $date.sprintf(' %02d:%02d:%02d', (int) ($m[4] ?? 0), (int) ($m[5] ?? 0), (int) ($m[6] ?? 0));
    }

    private function flag(mixed $fallback, string $table, string $field, string $reason): mixed
    {
        $key = "{$table}.{$field} : {$reason}";
        $this->anomalies[$key] = ($this->anomalies[$key] ?? 0) + 1;

        return $fallback;
    }

    /** @return array<string, int> */
    public function anomalies(): array
    {
        return $this->anomalies;
    }

    /** @return array<string, int> */
    public function accountNames(): array
    {
        arsort($this->accountNames);

        return $this->accountNames;
    }
}
