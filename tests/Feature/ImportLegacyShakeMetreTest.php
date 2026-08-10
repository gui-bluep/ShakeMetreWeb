<?php

namespace Tests\Feature;

use App\Console\Commands\LegacyImport\LegacyFieldMap;
use App\Models\Metre;
use App\Models\MetreLine;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `shakemetre:import` — la reprise des données de l'ancien ShakeMetre FileMaker.
 *
 * Ce qui est vérifié ici, et pourquoi c'est ici que ça se vérifie : la commande tourne UNE
 * fois, sur de l'argent, contre un serveur qu'on ne peut pas rejouer. Une erreur de
 * correspondance de champ ou de conversion de date ne se voit pas au moment de l'import — elle
 * se voit des semaines plus tard sur un écran de métré, et la source aura peut-être déjà été
 * mise hors service.
 *
 * **Aucun test ne touche un vrai serveur FileMaker.** `Http::fake()` monte un faux Data API
 * complet — sessions, métadonnées de mise en page, pagination, `_find` — parce qu'un stub par
 * URL ne suffirait pas : la commande enchaîne les appels et c'est justement l'enchaînement
 * (l'ordre des phases, les clés étrangères validées au vol) qui est en jeu.
 */
class ImportLegacyShakeMetreTest extends TestCase
{
    use RefreshDatabase;

    /** FileMaker Get(UUID) rend des majuscules ; la casse doit survivre aussi. */
    private const REF = '540E4B6D-6750-4909-8F02-3AFBD5395197';

    private const REFS = 'E89058CD-DEB5-437A-B9C0-140EFB321412';

    private const REFS_ORPHAN = 'D82A40C9-0296-4C09-8A76-22FB255F1226';

    private const REFSL = '59013DB0-4EB4-4339-A704-DDB2ADBAACC0';

    private const REFSL_OF_ORPHAN = '739341E1-7F9E-4B5D-B663-ABD24F271C84';

    private const MET = 'A8BCEBF8-8875-42B7-AE1C-A54A3F266D38';

    private const MET_2 = '06DC1C6F-D797-6640-9B59-2C60FBFC1F28';

    private const PRJ = '6EFAC292-A17A-4F4F-97E8-A2BE4200D11E';

    private const LOT = 'FB0BF890-BAC6-4920-B2DB-76CAE77CFB98';

    private const LOT_GONE = '11111111-2222-3333-4444-555555555555';

    private const METL = 'E1C20FE5-695E-43B4-914C-171EB5D46792';

    private const METL_DANGLING_LOT = 'CDDE8026-869D-42CA-91F1-4E70901BCEE0';

    private const METL_OTHER_METRE = 'BBBBBBBB-1111-2222-3333-444444444444';

    private const METC = '3F484DF0-B0E7-4D55-B7C6-625B2BDB235F';

    private const METC_ORPHAN = 'DD74B5B7-80A6-4AF0-B88D-CB393E667BA9';

    /**
     * Champs à retirer des métadonnées d'une mise en page, pour simuler une mise en page
     * incomplète.
     *
     * Une propriété lue par le faux serveur, et non un second `Http::fake()` : **les stubs
     * s'empilent et le premier qui correspond continue de gagner.** Le faux posé dans setUp()
     * est une fermeture qui répond à tout, donc un `Http::fake()` ajouté dans un test ne serait
     * jamais atteint — le piège est documenté dans CLAUDE.md et il a coûté ce test une fois.
     *
     * @var array<string, list<string>>
     */
    private array $hiddenFields = [];

    /**
     * Retire les champs résumés du jeu d'essai, pour éprouver la lecture en pleine table.
     *
     * La mise en page réelle en porte, donc le défaut est de les garder : un test doit éprouver le
     * chemin que la commande emprunte vraiment. Le passer à true simule une mise en page dégraissée.
     */
    private bool $withoutSummaryFields = false;

    /** @var array<int, array<string, list<array<string, mixed>>>> jeux d'essai, par variante */
    private array $records = [];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.shakemetre_filemaker', [
            'host' => 'fm.example.test',
            'database' => 'ShakeMetre',
            'username' => 'migration',
            'password' => 'secret',
            'version' => 'vLatest',
            'timeout' => 30,
            'connect_timeout' => 5,
            'verify' => false,
        ]);

        $this->fakeDataApi();
    }

    /* ------------------------------------------------------------------ les clés */

    /**
     * Le test critique de ce dépôt : ShakeDesign porte des références DURES vers les clés de
     * ShakeMetre (`OFF_Offers.zkf_MET`, `SOR_SupplierOrders.zkf_MET` → `MET_Metre.zkp`), donc un
     * enregistrement importé doit garder son zkp au caractère près, casse comprise, ou ces clés
     * étrangères cassent en silence dans l'autre application.
     */
    public function test_every_source_zkp_survives_the_import_verbatim(): void
    {
        $this->artisan('shakemetre:import')->assertSuccessful();

        $this->assertSame(self::MET, DB::table('metres')->where('id', self::MET)->value('id'));
        $this->assertSame(self::METL, DB::table('metre_lines')->where('id', self::METL)->value('id'));
        $this->assertSame(self::LOT, DB::table('lots')->where('id', self::LOT)->value('id'));
        $this->assertSame(self::REF, DB::table('metre_references')->where('id', self::REF)->value('id'));
        $this->assertSame(self::REFS, DB::table('sub_references')->where('id', self::REFS)->value('id'));
        $this->assertSame(self::REFSL, DB::table('sub_reference_lines')->where('id', self::REFSL)->value('id'));
        $this->assertSame(self::METC, DB::table('metre_line_components')->where('id', self::METC)->value('id'));
    }

    /* ------------------------------------------------------------------ la correspondance */

    public function test_a_metre_carries_its_source_values(): void
    {
        $this->artisan('shakemetre:import')->assertSuccessful();

        $metre = DB::table('metres')->where('id', self::MET)->first();

        $this->assertSame(self::PRJ, $metre->project_id);
        $this->assertSame('Rénovation Louise', $metre->name);
        $this->assertSame(4, (int) $metre->ind_project);
        $this->assertSame('FR', $metre->language);

        // Les dates arrivent en MM/DD/YYYY. « 04/10/2025 » est le 10 avril, pas le 4 octobre :
        // c'est exactement l'inversion qu'un Carbon::parse() complaisant laisserait passer.
        $this->assertSame('2025-01-21', substr((string) $metre->date_creation, 0, 10));
        $this->assertSame('2025-04-10', substr((string) $metre->date_agreement, 0, 10));
        $this->assertSame('2026-08-04 13:50:10', (string) $metre->date_time_update_calcs_stored);

        // Les horodatages FileMaker deviennent created_at / updated_at.
        $this->assertSame('2025-01-21 15:29:16', (string) $metre->created_at);

        $this->assertEquals(1, $metre->is_accepted_b);
        $this->assertEquals(0, $metre->is_locked_b);

        // Les totaux `_Stored` sont repris tels quels, pas recalculés.
        $this->assertEquals(7895.08, $metre->total_sales_metl_stored);

        // Un champ vide devient null, jamais 0 : « pas de total » n'est pas « total nul ».
        $this->assertNull($metre->tot_sum_total_sales_stored);
        $this->assertNull($metre->ratio_markup);

        // L'auteur ne survit pas : FileMaker donne un nom de compte, la colonne attend un UUID.
        $this->assertNull($metre->created_by);
    }

    public function test_a_metre_line_carries_its_source_values(): void
    {
        $this->artisan('shakemetre:import')->assertSuccessful();

        $line = DB::table('metre_lines')->where('id', self::METL)->first();

        $this->assertSame(self::MET, $line->metre_id);
        $this->assertSame(self::LOT, $line->lot_id);

        // `Order` va dans ref_order, le troisième terme du code imprimé « 10.2.3 » - et non dans
        // sort_order, qui est un rang libre propre au web et sans source.
        $this->assertSame(3, (int) $line->ref_order);
        $this->assertNull($line->sort_order);

        // Un code de section est du texte à zéros de tête dans la source, un entier ici.
        $this->assertSame(10, (int) $line->ref_code);
        $this->assertSame(2, (int) $line->refs_code);

        $this->assertSame('Démolition et évacuation plafonds', $line->refs_title);
        $this->assertSame('Cloisons à démonter', $line->refsl_title);
        $this->assertEquals(37500, $line->quantity);
        $this->assertEquals(12.5, $line->price_buy);
        $this->assertEquals(0, $line->is_option_b);
        $this->assertEquals(1, $line->is_estimated_price_b);

        // Le nom de champ porte une espace dans la source. Ce n'est pas une coquille.
        $this->assertEquals(42.75, $line->sum_total_work_fee);

        // Une ligne ne pointe pas le catalogue : elle en porte une copie. Les trois clés sont
        // vides sur les 57 816 lignes du fichier vivant.
        $this->assertNull($line->reference_id);
        $this->assertNull($line->sub_reference_id);
        $this->assertNull($line->sub_reference_line_id);

        // Clés inter-systèmes, sans contrainte locale : elles passent telles quelles.
        $this->assertSame('315AA175-F60D-4061-A6BA-9B7DA058FADE', $line->vat_value_id);
    }

    /**
     * Un champ Nombre de FileMaker accepte du texte et le lit quand même comme un nombre.
     *
     * Relevé sur les données réelles, et seulement grâce au rapport de l'import complet :
     * `TENDER_Supp3_Quantity` vaut « 59² » sur 6 lignes — un « ² » tapé dans une quantité.
     * FileMaker tient cette valeur pour 59 (vérifié en interrogeant le serveur avec une
     * comparaison numérique, qui ramène bien ces lignes), donc la mettre à null perdrait une
     * quantité que la source utilise dans ses calculs d'appel d'offres.
     */
    public function test_a_number_field_holding_text_is_read_the_way_filemaker_reads_it(): void
    {
        $this->artisan('shakemetre:import')
            ->expectsOutputToContain('lu comme 59')
            ->assertSuccessful();

        $line = DB::table('metre_lines')->where('id', self::METL)->first();

        $this->assertEquals(59, $line->tender_supp3_quantity);

        // Ce qui ne contient aucun nombre reste null — l'extraction n'est pas de la complaisance.
        $this->assertNull($line->tender_supp4_quantity);
    }

    /**
     * Les trois `Tot_*_TotalFees_Stored` ne sont pas importés, parce que ce ne sont pas des données
     * par enregistrement : ce sont des champs Calculated qui lisent des champs Summary (`zsm_`),
     * donc des totaux du found set. Le dictionnaire donne les formules, et une lecture réelle l'a
     * montré — `Tot_Sum_TotalFees_Stored` valait 6 494 969,76 à l'identique sur chaque métré.
     *
     * Les importer écrirait le même chiffre dénué de sens sur les 877, et c'est ce qui a fait
     * échouer un vrai import : l'un d'eux revenait à 3,5e17, hors bornes de `decimal(15,4)`.
     */
    public function test_the_found_set_aggregates_are_not_imported(): void
    {
        $this->artisan('shakemetre:import')->assertSuccessful();

        $metre = DB::table('metres')->where('id', self::MET)->first();

        $this->assertNull($metre->tot_sum_total_fees_stored);
        $this->assertNull($metre->tot_percentage_total_fees_stored);
        $this->assertNull($metre->tot_ratio_total_fees_stored);

        // Les sommes voisines, elles, sont des champs Normal : par enregistrement, donc importées.
        $this->assertEquals(7895.08, $metre->total_sales_metl_stored);
    }

    /**
     * FileMaker n'a pas de bornes numériques, les colonnes d'ici en ont. Une valeur trop grande
     * doit devenir null et être signalée, pas faire échouer l'`upsert` du paquet entier — ce qui,
     * sur un vrai import, a arrêté la phase des métrés au premier enregistrement.
     */
    public function test_a_number_too_large_for_its_column_becomes_null_instead_of_failing(): void
    {
        $this->artisan('shakemetre:import')
            ->expectsOutputToContain('dépasse ce que la colonne peut contenir')
            ->assertSuccessful();

        $line = DB::table('metre_lines')->where('id', self::METL)->first();

        $this->assertNull($line->tender_supp5_price);
        // La ligne elle-même est écrite : une valeur hors bornes n'emporte pas l'enregistrement.
        $this->assertEquals(12.5, $line->price_buy);
    }

    public function test_a_component_carries_its_source_values(): void
    {
        $this->artisan('shakemetre:import')->assertSuccessful();

        $component = DB::table('metre_line_components')->where('id', self::METC)->first();

        $this->assertSame(self::METL, $component->metre_line_id);
        $this->assertEquals(2.5, $component->length);
        $this->assertEquals(1.2, $component->width);
        $this->assertEquals(3, $component->quantity_sales);
    }

    public function test_a_lot_carries_its_tender_matrix_and_supplier_keys(): void
    {
        $this->artisan('shakemetre:import')->assertSuccessful();

        $lot = DB::table('lots')->where('id', self::LOT)->first();

        $this->assertSame(self::PRJ, $lot->project_id);
        $this->assertSame('INSTALLATION DE CHANTIER', $lot->title_fr);
        $this->assertSame('Blue Pineapple srl', $lot->cpy_name_ae);
        $this->assertSame(0, (int) $lot->code);
        $this->assertEquals(80, $lot->tender_weighting_price);

        // Typé Number dans FileMaker, porteur d'un UUID en vrai - le type déclaré ne prouve
        // rien, et la colonne locale est un uuid.
        $this->assertSame('BB4C07E1-55EE-441C-B3BC-4AEB90203C8E', $lot->tender_supplier_1_id);
        $this->assertSame(30, (int) $lot->tender_weighting_crit1);
        $this->assertSame('Délai', $lot->tender_weighting_crit1_description);
    }

    /* ------------------------------------------------------------------ l'intégrité */

    /**
     * Une sous-référence sans référence ne peut pas exister : la colonne est NOT NULL et
     * contrainte. Le fichier vivant en porte deux, blanches, sans code ni titre.
     */
    public function test_a_sub_reference_without_a_parent_is_skipped_and_reported(): void
    {
        $this->artisan('shakemetre:import')
            ->expectsOutputToContain('reference_id absent ou inconnu')
            ->assertSuccessful();

        $this->assertDatabaseMissing('sub_references', ['id' => self::REFS_ORPHAN]);
        $this->assertDatabaseHas('sub_references', ['id' => self::REFS]);
    }

    /** Un article dont la sous-référence a été écartée l'est aussi : sinon la clé étrangère refuse. */
    public function test_a_catalogue_item_whose_parent_was_skipped_is_skipped_too(): void
    {
        $this->artisan('shakemetre:import')->assertSuccessful();

        $this->assertDatabaseMissing('sub_reference_lines', ['id' => self::REFSL_OF_ORPHAN]);
        $this->assertDatabaseHas('sub_reference_lines', ['id' => self::REFSL]);
    }

    /**
     * La distinction qui compte : un parent OBLIGATOIRE manquant écarte la ligne, une clé
     * FACULTATIVE qui pend est mise à null et la ligne est gardée.
     *
     * Une ligne dont le lot a été supprimé reste une vraie ligne, avec son prix et sa quantité.
     * L'écarter perdrait de l'argent pour sauver une référence qui, elle, ne vaut plus rien.
     */
    public function test_a_line_pointing_at_a_deleted_lot_keeps_its_money_and_loses_its_lot(): void
    {
        $this->artisan('shakemetre:import')->assertSuccessful();

        $line = DB::table('metre_lines')->where('id', self::METL_DANGLING_LOT)->first();

        $this->assertNotNull($line, 'la ligne doit survivre à la disparition de son lot');
        $this->assertNull($line->lot_id);
        $this->assertEquals(99.99, $line->price_sales);
    }

    public function test_a_component_whose_line_does_not_exist_is_skipped(): void
    {
        $this->artisan('shakemetre:import')->assertSuccessful();

        $this->assertDatabaseMissing('metre_line_components', ['id' => self::METC_ORPHAN]);
    }

    /* ------------------------------------------------------------------ l'ordre et la reprise */

    /**
     * L'ordre des phases est imposé par les clés étrangères : un enfant inséré avant son parent
     * fait échouer la contrainte. Le vérifier par l'ordre des appels plutôt que par le résultat,
     * parce qu'un import qui réussit par chance sur un jeu d'essai peut échouer sur le vrai.
     */
    public function test_the_phases_run_parents_before_children(): void
    {
        $this->artisan('shakemetre:import')->assertSuccessful();

        $order = [];

        foreach (Http::recorded() as [$request]) {
            if (preg_match('#/layouts/API_MIGRATION_([A-Z]+)/(?:records|_find)#', $request->url(), $m) === 1) {
                $order[] = $m[1];
            }
        }

        $firstTouch = [];

        foreach ($order as $index => $key) {
            $firstTouch[$key] ??= $index;
        }

        $this->assertLessThan($firstTouch['REFS'], $firstTouch['REF']);
        $this->assertLessThan($firstTouch['REFSL'], $firstTouch['REFS']);
        $this->assertLessThan($firstTouch['METL'], $firstTouch['MET']);
        $this->assertLessThan($firstTouch['METL'], $firstTouch['LOT']);
        $this->assertLessThan($firstTouch['METC'], $firstTouch['METL']);
    }

    /**
     * Un métré plus long qu'une page de `_find` est lu en entier.
     *
     * La phase des lignes lit métré par métré, et chaque métré page par page. Les deux niveaux
     * existent pour la même raison — le champ résumé `zsm_zkf_VAT_List`, qui pèse 17 ko par
     * enregistrement sur un gros métré — et la première version, non paginée, épuisait les 128 Mo
     * de PHP à 13 % de la phase sur les données réelles. Une pagination fausse ne lève pas
     * d'erreur : elle perd des lignes ou tourne sans fin. D'où ce test.
     */
    public function test_a_metre_longer_than_one_page_is_read_whole(): void
    {
        $this->artisan('shakemetre:import')->assertSuccessful();

        // 250 lignes générées + celle du jeu d'essai, sur le second métré.
        $this->assertSame(251, DB::table('metre_lines')->where('metre_id', self::MET_2)->count());

        // Les bornes de page, là où une pagination fausse perd des enregistrements.
        foreach ([1, 100, 101, 200, 201, 250] as $i) {
            $this->assertDatabaseHas('metre_lines', [
                'id' => sprintf('C0FFEE00-0000-4000-8000-%012d', $i),
                'ref_order' => $i + 1,
            ]);
        }
    }

    /**
     * La stratégie de lecture des lignes se déduit de la mise en page, elle ne se configure pas.
     *
     * Un champ résumé (`zsm_*`) s'évalue sur le found set et revient sur chaque enregistrement, donc
     * tant qu'il y en a un, il faut lire métré par métré : `zsm_zkf_VAT_List` pèse 2,1 Mo par ligne
     * et le total mesuré est de 733 Mo. Sans eux, la table se lit d'un bout à l'autre — ~51 Mo et
     * une centaine de requêtes au lieu de quinze cents.
     *
     * Les deux chemins doivent produire le même résultat, sinon le gain se paierait en données.
     */
    public function test_the_lines_are_read_per_metre_while_a_summary_field_is_on_the_layout(): void
    {
        $this->artisan('shakemetre:import')
            ->expectsOutputToContain('Lecture métré par métré')
            ->assertSuccessful();

        $this->assertTrue(
            $this->calledFind('API_MIGRATION_METL'),
            'les lignes doivent être cherchées métré par métré, donc via _find',
        );
        $this->assertSame(253, DB::table('metre_lines')->count());
    }

    public function test_the_lines_are_read_whole_table_when_the_layout_carries_no_summary_field(): void
    {
        $this->withoutSummaryFields = true;

        $this->artisan('shakemetre:import')
            ->expectsOutputToContain('Lecture en pleine table')
            ->assertSuccessful();

        $this->assertFalse(
            $this->calledFind('API_MIGRATION_METL'),
            'sans champ résumé, plus besoin de réduire le found set : aucun _find sur les lignes',
        );

        // Le même résultat, au même nombre de lignes, par l'autre chemin.
        $this->assertSame(253, DB::table('metre_lines')->count());
        $this->assertDatabaseHas('metre_lines', ['id' => self::METL, 'ref_order' => 3, 'lot_id' => self::LOT]);
        $this->assertDatabaseHas('metre_lines', ['id' => self::METL_DANGLING_LOT, 'lot_id' => null]);
        $this->assertDatabaseHas('metre_line_components', ['id' => self::METC]);
    }

    /* ------------------------------------------------------------------ ce qui manque, et pourquoi */

    /**
     * Le trou de comptabilité qui rendait un écart inexplicable.
     *
     * `$inserted` compte les lignes ENVOYÉES à l'`upsert`, pas celles qui s'y posent. Deux
     * enregistrements de la source portant le même zkp n'en laissent qu'un en base, et le
     * rapport continuait d'annoncer le total de la source : la base comptait moins de lignes
     * que la source sans qu'aucun chiffre ne le dise. C'est le premier soupçon à lever quand
     * un import « réussi » rend moins de lignes qu'attendu.
     */
    public function test_a_zkp_the_source_carries_twice_is_counted_and_named(): void
    {
        $this->records[0] = $this->sourceRecords();
        $this->records[0]['API_MIGRATION_METL'][] = $this->lineRecord(self::METL, self::MET, ['Order' => 9]);

        // Une seule attente, et c'est une contrainte de l'outil : Mockery ne satisfait qu'une
        // attente par appel à doWrite(), donc deux `expectsOutputToContain` visant la même ligne
        // s'excluent — la seconde échoue en annonçant une sortie pourtant présente.
        $this->artisan('shakemetre:import --fresh --force')
            ->expectsOutputToContain('zkp vu(s) plus d\'une fois dans la source, dont '.self::METL)
            ->assertSuccessful();

        // Une seule ligne en base pour ce zkp — l'`upsert` a écrasé — mais le doublon est compté,
        // donc « lus = en base + écartés + doublons » se referme et rien n'est inexpliqué.
        $this->assertSame(1, DB::table('metre_lines')->where('id', self::METL)->count());
        $this->assertSame(253, DB::table('metre_lines')->count());
    }

    public function test_the_report_closes_its_arithmetic_on_a_fresh_import(): void
    {
        // Aucun avertissement d'écart inexpliqué : tout ce qui est lu est en base, écarté, ou
        // en double. Un reliquat ici voudrait dire qu'un enregistrement s'est perdu en route.
        $this->artisan('shakemetre:import --fresh --force')
            ->doesntExpectOutputToContain('ne sont ni en base, ni écartés')
            ->assertSuccessful();
    }

    /* ------------------------------------------------------------------ l'audit */

    public function test_the_audit_says_nothing_is_missing_after_a_complete_import(): void
    {
        $this->artisan('shakemetre:import --fresh --force')->assertSuccessful();

        $this->artisan('shakemetre:import --audit')
            ->expectsOutputToContain('Aucun écart')
            ->assertSuccessful();
    }

    /**
     * L'audit compare métré par métré, ce qui transforme « il en manque 414 » en une liste
     * d'identifiants à relire. Un total contre un total ne dirait pas quoi faire.
     */
    public function test_the_audit_names_the_metres_whose_lines_are_missing(): void
    {
        $this->artisan('shakemetre:import --fresh --force')->assertSuccessful();

        // Une coupure de session en plein milieu d'un métré ressemble à ça : le métré est là,
        // une partie de ses lignes n'y est pas.
        $this->loseLines(self::MET_2, 40);

        $this->artisan('shakemetre:import --audit')
            ->expectsOutputToContain('1 métré(s) en écart — 40 ligne(s) manquante(s)')
            ->expectsOutputToContain(self::MET_2)
            ->assertFailed();
    }

    public function test_repair_reads_back_only_the_metres_in_disagreement_and_fills_them(): void
    {
        $this->artisan('shakemetre:import --fresh --force')->assertSuccessful();

        $before = DB::table('metre_lines')->count();
        $this->loseLines(self::MET_2, 40);

        // Le journal HTTP court sur tout le test, import compris : seule la queue appartient à
        // la réparation. Sans ce repère, l'import initial suffirait à faire croire que la
        // réparation a tout relu.
        $alreadyRecorded = count(Http::recorded());

        $this->artisan('shakemetre:import --repair')
            ->expectsOutputToContain('40 ligne(s) de plus en base')
            ->assertSuccessful();

        $this->assertSame($before, DB::table('metre_lines')->count());

        // Et le métré intact n'a pas été relu : réparer, c'est relire ce qui manque, pas tout.
        $this->assertFalse(
            $this->findQueriedMetre(self::MET, $alreadyRecorded),
            'la réparation ne doit interroger que les métrés en écart',
        );
        $this->assertTrue($this->findQueriedMetre(self::MET_2, $alreadyRecorded));

        $this->artisan('shakemetre:import --audit')
            ->expectsOutputToContain('Aucun écart')
            ->assertSuccessful();
    }

    /**
     * Une ligne dont le métré n'existe pas ici est hors de portée, pas manquante — et l'audit
     * doit le dire séparément, sinon il réclamerait chaque nuit une réparation impossible.
     * C'est le cas des 13 lignes du fichier vivant dont `zkf_MET` est vide ou orphelin.
     */
    public function test_the_audit_separates_lines_no_metre_can_hold_from_lines_that_are_missing(): void
    {
        $this->records[0] = $this->sourceRecords();
        $this->records[0]['API_MIGRATION_METL'][] = $this->lineRecord(
            'FEEDFACE-0000-4000-8000-000000000001',
            '00000000-0000-4000-8000-000000000000',
        );

        $this->artisan('shakemetre:import --fresh --force')->assertSuccessful();

        $this->artisan('shakemetre:import --audit')
            ->expectsOutputToContain('hors de portée')
            ->expectsOutputToContain('Aucun écart')
            ->assertSuccessful();
    }

    /**
     * Faire disparaître N lignes d'un métré, comme une coupure en aurait laissé.
     *
     * Les identifiants sont choisis puis supprimés en deux temps : SQLite n'accepte pas de
     * `LIMIT` sur un DELETE, et le `limit()` d'Eloquent y est simplement ignoré — la première
     * version de ce test vidait donc le métré entier tout en prétendant en retirer quarante
     * lignes, ce qui rendait ses assertions incompréhensibles plutôt que fausses.
     */
    private function loseLines(string $metreId, int $count): void
    {
        $ids = DB::table('metre_lines')->where('metre_id', $metreId)->orderBy('id')->limit($count)->pluck('id');

        $this->assertCount($count, $ids, 'le métré doit porter assez de lignes pour en perdre autant');

        DB::table('metre_lines')->whereIn('id', $ids)->delete();
    }

    private function findQueriedMetre(string $metreId, int $from = 0): bool
    {
        foreach (array_slice(Http::recorded()->all(), $from) as [$request]) {
            if (! str_contains($request->url(), '/layouts/API_MIGRATION_METL/_find')) {
                continue;
            }

            $criteria = $request->data()['query'][0] ?? [];

            // Le comptage de l'audit interroge tous les métrés ; seule une LECTURE compte ici.
            if (($criteria['zkf_MET'] ?? '') === '=='.$metreId && (string) ($request->data()['limit'] ?? '') !== '1') {
                return true;
            }
        }

        return false;
    }

    private function calledFind(string $layout): bool
    {
        foreach (Http::recorded() as [$request]) {
            if (str_contains($request->url(), "/layouts/{$layout}/_find")) {
                return true;
            }
        }

        return false;
    }

    /**
     * Les six phases lues de bout en bout paginent aussi, et sur les vraies données elles le font
     * toutes : MET 877, LOT 852, REFSL 507, METC 3 717, contre une page de 500 par défaut.
     *
     * `--page=1` force la pagination sur des effectifs minuscules, ce qui évite de fabriquer des
     * centaines d'enregistrements pour l'éprouver. Écrit après avoir cassé exprès l'incrément
     * d'offset de `pages()` et constaté que RIEN n'échouait : les jeux d'essai tenaient tous en
     * une page, donc ce chemin-là n'était pas couvert du tout.
     */
    public function test_the_whole_layout_phases_paginate_without_losing_or_duplicating(): void
    {
        $this->artisan('shakemetre:import', ['--page' => 1])->assertSuccessful();

        $this->assertDatabaseCount('metre_references', 1);
        $this->assertDatabaseCount('sub_references', 1);
        $this->assertDatabaseCount('sub_reference_lines', 1);
        $this->assertDatabaseCount('metres', 2);
        $this->assertDatabaseCount('lots', 1);
        $this->assertDatabaseCount('metre_line_components', 1);

        // Les deux métrés sont bien deux enregistrements distincts, pas le premier lu deux fois.
        $this->assertDatabaseHas('metres', ['id' => self::MET, 'ind_project' => 4]);
        $this->assertDatabaseHas('metres', ['id' => self::MET_2, 'ind_project' => 7]);
    }

    /**
     * Rejouable, et c'est ce qui permet de rattraper une coupure au lieu de tout reprendre :
     * relancer réécrit à l'identique au lieu de se heurter à un doublon.
     */
    public function test_running_it_twice_changes_nothing(): void
    {
        $this->artisan('shakemetre:import')->assertSuccessful();

        $before = [
            'metres' => DB::table('metres')->count(),
            'metre_lines' => DB::table('metre_lines')->count(),
            'metre_line_components' => DB::table('metre_line_components')->count(),
        ];

        $this->artisan('shakemetre:import')->assertSuccessful();

        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), "{$table} a changé au deuxième passage");
        }
    }

    /**
     * Sans cette marque, la règle « un numéro de métré n'est jamais redonné » est fausse dès le
     * premier métré créé après l'import.
     */
    public function test_the_metre_numbering_high_water_mark_is_seeded(): void
    {
        $this->artisan('shakemetre:import')->assertSuccessful();

        // Les deux métrés du projet portent 4 et 7 : la marque est le plus haut, pas le compte.
        $this->assertDatabaseHas('metre_number_sequences', [
            'project_id' => self::PRJ,
            'last_ind_project' => 7,
        ]);

        $this->assertSame(8, Metre::nextIndProject(self::PRJ));
    }

    /* ------------------------------------------------------------------ les garde-fous */

    /**
     * Une lecture Data API ne voit que les champs POSÉS sur la mise en page, et l'absence d'un
     * champ ne produit AUCUNE erreur : la colonne arrive nulle, en silence. C'est la raison
     * d'être des sept mises en page `API_MIGRATION_*`, donc l'import refuse de tourner plutôt
     * que d'écrire des métrés sans langue et des lignes sans unité.
     */
    public function test_it_refuses_to_run_when_a_layout_is_missing_a_field(): void
    {
        $this->hiddenFields = ['API_MIGRATION_MET' => ['Language', 'IndProject']];

        // Assertions sur la sortie capturée plutôt que deux `expectsOutputToContain` : celui-ci
        // consomme les lignes de sortie dans l'ordre, donc deux sous-chaînes situées sur LA MÊME
        // ligne ne peuvent pas correspondre toutes les deux - la première consomme la ligne et
        // la seconde ne trouve plus rien. Les deux noms de champ manquants sont sur une ligne.
        $status = Artisan::call('shakemetre:import');
        $output = Artisan::output();

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('IndProject', $output);
        $this->assertStringContainsString('Language', $output);

        $this->assertDatabaseCount('metres', 0);
        $this->assertDatabaseCount('metre_references', 0);
    }

    /**
     * `metres` porte un index unique sur `(project_id, ind_project)`. La source n'a aucun doublon,
     * donc l'import ne s'y heurte pas tout seul — mais une base qui garde des données de
     * démonstration peut occuper un couple avec un autre id, et sans ce garde-fou l'`upsert`
     * mourrait sur une violation d'unicité en SQL brut, au milieu d'une phase de 877 métrés.
     */
    public function test_a_local_metre_holding_an_imported_number_is_skipped_with_a_usable_message(): void
    {
        // Même (projet, numéro) que le métré importé, id différent : le cas qui casse.
        Metre::forceCreate([
            'id' => 'FFFFFFFF-0000-0000-0000-000000000000',
            'project_id' => self::PRJ,
            'ind_project' => 4,
            'name' => 'démo locale',
        ]);

        $this->artisan('shakemetre:import')
            ->expectsOutputToContain('relancez avec --fresh')
            ->assertSuccessful();

        // Le métré local est intact, l'importé est écarté, et le reste de l'import a bien tourné.
        $this->assertDatabaseHas('metres', ['id' => 'FFFFFFFF-0000-0000-0000-000000000000']);
        $this->assertDatabaseMissing('metres', ['id' => self::MET]);
        $this->assertDatabaseHas('metres', ['id' => self::MET_2]);
    }

    /**
     * Le contrôle des mises en page passe AVANT le vidage, et l'ordre est tout l'intérêt : vider la
     * base puis échouer à l'importer laisserait l'application sans données du tout. Le pire résultat
     * possible de cette commande, et il ne tient qu'à deux appels dans le bon ordre.
     */
    public function test_an_incomplete_layout_never_leaves_the_database_wiped(): void
    {
        $this->hiddenFields = ['API_MIGRATION_MET' => ['Language']];

        $keep = Metre::forceCreate(['id' => 'FFFFFFFF-0000-0000-0000-000000000000', 'name' => 'à garder']);

        $this->assertSame(Command::FAILURE, Artisan::call('shakemetre:import', ['--fresh' => true, '--force' => true]));

        $this->assertDatabaseHas('metres', ['id' => $keep->id]);
    }

    /**
     * Une ligne que la base refuse ne doit coûter qu'elle-même.
     *
     * Les paquets partent à ~159 enregistrements : sans reprise ligne par ligne, un seul refus fait
     * échouer l'`upsert` des 158 autres et l'exception arrête la phase. Sur les lignes de métré,
     * c'est 27 minutes de lecture perdues pour un enregistrement — et l'import tourne la nuit.
     *
     * Le refus est provoqué par un déclencheur SQL plutôt que par une donnée fautive, et c'est
     * volontaire : les couches de conversion et de validation des clés pré-emptent déjà tout ce que
     * SQLite sait refuser — une valeur hors bornes devient null, une clé qui pend devient null, un
     * parent manquant écarte la ligne avant l'écriture. Ce que `salvage()` existe pour encaisser,
     * c'est le refus qu'on n'a PAS prévu (une longueur de colonne, un mode strict MySQL, une
     * contrainte ajoutée plus tard), et un déclencheur le reproduit fidèlement sans rien changer au
     * code de production. Première tentative faite avec une `unit` de 300 caractères : SQLite ne
     * contrôle pas la longueur d'un varchar, la ligne passait et le test ne prouvait rien.
     */
    public function test_a_row_the_database_refuses_costs_only_itself(): void
    {
        DB::statement(sprintf(
            "CREATE TRIGGER refuse_une_ligne BEFORE INSERT ON metre_lines WHEN NEW.id = '%s' ".
            "BEGIN SELECT RAISE(ABORT, 'refus simulé par le test'); END",
            self::METL_DANGLING_LOT,
        ));

        $this->artisan('shakemetre:import')->assertSuccessful();

        // L'intrus est écarté...
        $this->assertDatabaseMissing('metre_lines', ['id' => self::METL_DANGLING_LOT]);

        // ... et tout le reste du même paquet est bien passé.
        $this->assertDatabaseHas('metre_lines', ['id' => self::METL]);
        $this->assertSame(251, DB::table('metre_lines')->where('metre_id', self::MET_2)->count());

        // Et les phases suivantes ont tourné.
        $this->assertDatabaseHas('metre_line_components', ['id' => self::METC]);
    }

    /**
     * Un refus répété se lit comme un refus répété, avec son nombre.
     *
     * Payé sur les vraies données : 401 lignes de métré ont été refusées par MySQL pour un
     * `refsl_title` de plus de 255 caractères, et le rapport de l'époque en faisait 401 lignes
     * distinctes — le zkp était DANS le message, donc la déduplication ne prenait pas. Un rapport
     * qui déroule 401 lignes n'est plus un rapport : le trou de l'import a traversé sans être vu,
     * et il a fallu un audit métré par métré pour le retrouver.
     *
     * Le message porte donc la raison seule, le compte est devant, et quelques zkp suivent pour
     * que le fait reste vérifiable.
     */
    public function test_repeated_refusals_are_counted_rather_than_listed(): void
    {
        DB::statement(
            "CREATE TRIGGER refuse_les_lignes BEFORE INSERT ON metre_lines WHEN NEW.id LIKE 'C0FFEE00%' ".
            "BEGIN SELECT RAISE(ABORT, 'refus simulé par le test'); END"
        );

        // La sortie est capturée plutôt que passée à `expectsOutputToContain` : Mockery n'attribue
        // un appel qu'à UNE attente, donc deux sous-chaînes de la même ligne ne peuvent pas
        // correspondre toutes les deux — et c'est précisément une seule ligne qu'on veut vérifier.
        Artisan::call('shakemetre:import');
        $output = Artisan::output();

        $this->assertStringContainsString('250 ×', $output);
        $this->assertStringContainsString('(dont C0FFEE00-0000-4000-8000-000000000001', $output);

        // Le cœur du test : 250 refus, UNE ligne de rapport.
        $this->assertSame(1, substr_count($output, 'refus simulé par le test'));

        // Les 250 refusées sont absentes, la 251e du même métré est passée.
        $this->assertSame(1, DB::table('metre_lines')->where('metre_id', self::MET_2)->count());

        // Le reste de l'import n'en souffre pas : c'est ce que salvage() garantit.
        $this->assertDatabaseHas('metre_lines', ['id' => self::METL]);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->artisan('shakemetre:import', ['--dry-run' => true])->assertSuccessful();

        $this->assertDatabaseCount('metres', 0);
        $this->assertDatabaseCount('metre_lines', 0);
        $this->assertDatabaseCount('metre_references', 0);
        $this->assertDatabaseCount('metre_number_sequences', 0);
    }

    /**
     * `--fresh` vide les tables du domaine et rien d'autre. Vider la base du site ne veut pas
     * dire se déconnecter soi-même : `users` reste intacte.
     */
    public function test_fresh_empties_the_domain_but_never_the_accounts(): void
    {
        $user = User::factory()->create();
        $stale = Metre::forceCreate(['id' => 'FFFFFFFF-0000-0000-0000-000000000000', 'name' => 'à jeter']);

        $this->artisan('shakemetre:import', ['--fresh' => true, '--force' => true])->assertSuccessful();

        $this->assertDatabaseMissing('metres', ['id' => $stale->id]);
        $this->assertDatabaseHas('metres', ['id' => self::MET]);
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    /** Sans --force, refuser efface aussi l'import : --fresh sans vidage donnerait un mélange. */
    public function test_declining_the_wipe_aborts_the_whole_import(): void
    {
        Metre::forceCreate(['id' => 'FFFFFFFF-0000-0000-0000-000000000000', 'name' => 'à garder']);

        $this->artisan('shakemetre:import', ['--fresh' => true])
            ->expectsConfirmation('Supprimer définitivement 1 enregistrement(s) dans 1 table(s) ?', 'no')
            ->assertFailed();

        $this->assertDatabaseHas('metres', ['id' => 'FFFFFFFF-0000-0000-0000-000000000000']);
        $this->assertDatabaseMissing('metres', ['id' => self::MET]);
    }

    /**
     * Les observateurs ne doivent pas tourner : `MetreLineObserver` réécrirait la section de la
     * ligne depuis `reference_id` — qui est vide dans la source — et écraserait la copie que la
     * ligne porte, la seule vraie.
     */
    public function test_the_line_observer_does_not_rewrite_the_imported_section(): void
    {
        $this->artisan('shakemetre:import')->assertSuccessful();

        $line = MetreLine::find(self::METL);

        $this->assertSame('DEMOLITION', $line->ref_title);
        $this->assertSame('Démolition et évacuation plafonds', $line->refs_title);

        // Le garde-fou « pm » de l'observateur viderait la quantité. La source dit 37 500.
        $this->assertEquals(37500, $line->quantity);
    }

    public function test_only_runs_the_named_phases(): void
    {
        $this->artisan('shakemetre:import', ['--only' => 'REF,REFS'])->assertSuccessful();

        $this->assertDatabaseCount('metre_references', 1);
        $this->assertDatabaseCount('sub_references', 1);
        $this->assertDatabaseCount('metres', 0);
        $this->assertDatabaseCount('metre_lines', 0);
    }

    /* ------------------------------------------------------------------ le faux Data API */

    /**
     * Un faux serveur Data API, pas une pile de stubs.
     *
     * Trois pièges du vrai serveur sont reproduits exprès, parce que ce sont eux que le code
     * doit savoir encaisser : `_offset` commence à 1 ; un `_find` sans résultat répond 401 et
     * non une liste vide ; et les métadonnées d'une mise en page ne montrent que les champs
     * qui y sont posés.
     *
     * Les champs masqués sont lus dans `$this->hiddenFields` à chaque appel, et non figés à
     * l'installation : c'est ce qui permet à un test de rendre une mise en page incomplète sans
     * empiler un second stub par-dessus celui-ci.
     */
    private function fakeDataApi(): void
    {
        Http::fake(function (Request $request) {
            // Reconstruits à chaque appel, pas capturés à l'installation : les stubs s'empilent et
            // le premier qui correspond gagne, donc un test ne peut pas réinstaller un faux avec
            // d'autres données. Il change une propriété, et le faux la relit. Mémoïsé par jeu.
            $records = $this->records[(int) $this->withoutSummaryFields] ??= $this->sourceRecords();
            $url = $request->url();

            if (str_contains($url, '/sessions')) {
                return Http::response([
                    'response' => ['token' => 'fake-token'],
                    'messages' => [['code' => '0', 'message' => 'OK']],
                ]);
            }

            // Métadonnées : /layouts/{layout} et rien derrière.
            if (preg_match('#/layouts/([A-Z_]+)$#', $url, $m) === 1) {
                $layout = $m[1];
                $fields = $this->fieldsOf($records[$layout] ?? []);
                $fields = array_values(array_diff($fields, $this->hiddenFields[$layout] ?? []));

                return Http::response([
                    'response' => ['fieldMetaData' => array_map(
                        fn (string $name): array => ['name' => $name, 'result' => 'text'],
                        $fields,
                    )],
                    'messages' => [['code' => '0', 'message' => 'OK']],
                ]);
            }

            if (preg_match('#/layouts/([A-Z_]+)/records#', $url, $m) === 1) {
                $rows = $records[$m[1]] ?? [];
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

                // _offset est 1-basé côté FileMaker. Un 0 y répondrait 960.
                $offset = max(1, (int) ($query['_offset'] ?? 1));
                $limit = (int) ($query['_limit'] ?? 100);

                return Http::response([
                    'response' => [
                        'dataInfo' => ['totalRecordCount' => count($rows), 'foundCount' => count($rows)],
                        'data' => $this->wrap(array_slice($rows, $offset - 1, $limit)),
                    ],
                    'messages' => [['code' => '0', 'message' => 'OK']],
                ]);
            }

            if (preg_match('#/layouts/([A-Z_]+)/_find#', $url, $m) === 1) {
                $criteria = $request->data()['query'][0] ?? [];
                $rows = array_values(array_filter(
                    $records[$m[1]] ?? [],
                    function (array $row) use ($criteria): bool {
                        foreach ($criteria as $field => $value) {
                            if ((string) ($row[$field] ?? '') !== ltrim((string) $value, '=')) {
                                return false;
                            }
                        }

                        return true;
                    },
                ));

                // La pagination du `_find` est honorée, offset compris, parce que c'est elle qui
                // borne le pic mémoire de la phase des lignes : sans elle un test paginé
                // passerait alors que le vrai serveur renverrait tout, ou boucherait sans fin.
                $found = count($rows);
                $offset = max(1, (int) ($request->data()['offset'] ?? 1));
                $limit = (int) ($request->data()['limit'] ?? 100);
                $rows = array_slice($rows, $offset - 1, $limit);

                // Un found set vide est l'erreur 401, pas une liste vide : c'est ce que répond un
                // métré sans ligne — et aussi une page au-delà du found set, ce qui est la façon
                // dont la pagination s'arrête.
                if ($rows === []) {
                    return Http::response([
                        'response' => [],
                        'messages' => [['code' => '401', 'message' => 'No records match the request']],
                    ]);
                }

                return Http::response([
                    'response' => [
                        'dataInfo' => ['foundCount' => $found],
                        'data' => $this->wrap($rows),
                    ],
                    'messages' => [['code' => '0', 'message' => 'OK']],
                ]);
            }

            return Http::response(['messages' => [['code' => '105', 'message' => 'Layout is missing']]]);
        });
    }

    /**
     * Tous les noms de champ vus sur les enregistrements d'une mise en page.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    private function fieldsOf(array $rows): array
    {
        $names = [];

        foreach ($rows as $row) {
            $names = array_merge($names, array_keys($row));
        }

        return array_values(array_unique($names));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function wrap(array $rows): array
    {
        return array_values(array_map(
            fn (array $row, int $i): array => ['fieldData' => $row, 'recordId' => (string) ($i + 1), 'modId' => '1'],
            $rows,
            array_keys($rows),
        ));
    }

    /**
     * Le jeu d'essai, calqué sur de vrais enregistrements du fichier hébergé — mêmes formats de
     * date, mêmes codes en texte à zéros de tête, mêmes clés vides.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function sourceRecords(): array
    {
        $log = [
            'zlg_creaTimeStamp' => '01/21/2025 15:29:16',
            'zlg_modifTimeStamp' => '08/04/2026 13:50:10',
            'zlg_creaUserName' => 'Sophie',
            'zlg_modifUserName' => 'Sophie',
        ];

        return [
            'API_MIGRATION_REF' => [
                ['zkp' => self::REF, 'Code' => '00', 'Title_FR' => 'INSTALLATION DE CHANTIER', 'Title_EN' => '', 'Title_NL' => ''] + $log,
            ],
            'API_MIGRATION_REFS' => [
                ['zkp' => self::REFS, 'zkf_REF' => self::REF, 'Code' => 3, 'Title_FR' => 'Sols tapis', 'Title_EN' => '', 'Title_NL' => ''] + $log,
                // Les deux enregistrements blancs du fichier vivant : ni parent, ni code, ni titre.
                ['zkp' => self::REFS_ORPHAN, 'zkf_REF' => '', 'Code' => '', 'Title_FR' => '', 'Title_EN' => '', 'Title_NL' => ''] + $log,
            ],
            'API_MIGRATION_REFSL' => [
                [
                    'zkp' => self::REFSL, 'zkf_REF' => self::REF, 'zkf_REFS' => self::REFS,
                    'Code' => 1, 'Title_FR' => 'Accès', 'Title_EN' => '', 'Title_NL' => '',
                    'Price' => 1000, 'Unit' => 'Ff',
                    'Description_FR' => '', 'Description_EN' => '', 'Description_NL' => '',
                ] + $log,
                [
                    'zkp' => self::REFSL_OF_ORPHAN, 'zkf_REF' => self::REF, 'zkf_REFS' => self::REFS_ORPHAN,
                    'Code' => 2, 'Title_FR' => 'Ascenseurs', 'Title_EN' => '', 'Title_NL' => '',
                    'Price' => 325, 'Unit' => 'Ff',
                    'Description_FR' => '', 'Description_EN' => '', 'Description_NL' => '',
                ] + $log,
            ],
            'API_MIGRATION_MET' => [
                $this->metreRecord(self::MET, 4, [
                    'Name' => 'Rénovation Louise',
                    'Date_Agreement' => '04/10/2025',
                    'isAccepted_b' => 1,
                    'Total_Sales_METL_Stored' => 7895.08,
                ]) + $log,
                $this->metreRecord(self::MET_2, 7) + $log,
            ],
            'API_MIGRATION_LOT' => [
                $this->lotRecord() + $log,
            ],
            'API_MIGRATION_METL' => [
                $this->lineRecord(self::METL, self::MET, [
                    'zkf_LOT' => self::LOT,
                    'Order' => 3,
                    'REF_Code' => 10,
                    'REF_Title' => 'DEMOLITION',
                    'REFS_Code' => '02',
                    'REFS_Title' => 'Démolition et évacuation plafonds',
                    'REFSL_Title' => 'Cloisons à démonter',
                    'Quantity' => 37500,
                    'PriceBuy' => 12.5,
                    'isEstimatedPrice_b' => 1,
                    'SumTotal WorkFee' => 42.75,
                    'zkf_VAT_ae' => '315AA175-F60D-4061-A6BA-9B7DA058FADE',
                    // Tel quel dans le fichier vivant : un « ² » tapé dans une quantité, que
                    // FileMaker lit comme 59.
                    'TENDER_Supp3_Quantity' => '59²',
                    // Et un cas sans aucun nombre, qui doit rester null.
                    'TENDER_Supp4_Quantity' => 'n/a',
                    // Hors bornes de decimal(15,4), comme Tot_Percentage_TotalFees_Stored l'était.
                    'TENDER_Supp5_Price' => 3.5275847787813E+17,
                ]) + $log,
                // Son lot n'existe plus : la ligne doit survivre, le lot tomber à null.
                $this->lineRecord(self::METL_DANGLING_LOT, self::MET, [
                    'zkf_LOT' => self::LOT_GONE,
                    'Order' => 5,
                    'PriceSales' => 99.99,
                ]) + $log,
                $this->lineRecord(self::METL_OTHER_METRE, self::MET_2, ['Order' => 1]) + $log,

                // Assez de lignes sur le second métré pour dépasser la page de 100 du `_find`,
                // deux fois. C'est le garde-fou de la pagination : elle existe pour borner le pic
                // mémoire (le vrai import mourait à 128 Mo sans elle) et une pagination fausse se
                // manifeste par des pages perdues ou une boucle sans fin, pas par une erreur.
                ...array_map(
                    fn (int $i): array => $this->lineRecord(
                        sprintf('C0FFEE00-0000-4000-8000-%012d', $i),
                        self::MET_2,
                        ['Order' => $i + 1, 'PriceBuy' => $i],
                    ) + $log,
                    range(1, 250),
                ),
            ],
            'API_MIGRATION_METC' => [
                [
                    'zkp' => self::METC, 'zkf_METL' => self::METL, 'z_Order' => 1,
                    'Description' => 'Mur nord', 'Length' => 2.5, 'Width' => 1.2, 'Height' => '',
                    'QuantitySales' => 3, 'QuantityOrdered' => '',
                ] + $log,
                // Sa ligne n'existe pas : la clé étrangère est NOT NULL, l'enregistrement est écarté.
                [
                    'zkp' => self::METC_ORPHAN, 'zkf_METL' => '99999999-8888-7777-6666-555555555555', 'z_Order' => 1,
                    'Description' => '', 'Length' => '', 'Width' => '', 'Height' => '',
                    'QuantitySales' => 1, 'QuantityOrdered' => '',
                ] + $log,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function metreRecord(string $zkp, int $indProject, array $overrides = []): array
    {
        $record = [
            'zkp' => $zkp,
            'zkf_PRJ' => self::PRJ,
            'zkf_OFF' => '',
            'Name' => 'Métré',
            'IndProject' => $indProject,
            'Language' => 'FR',
            'Ratio_Markup' => '',
            'Sort_OrderTags' => 0,
            'isAccepted_b' => 0,
            'IsStatus_Site_b' => 0,
            'isImported_b' => 0,
            'isLocked_b' => 0,
            'isArchived_b' => 0,
            'Date_Creation' => '01/21/2025',
            'Date_Agreement' => '',
            'PROG_ProgressUpdateDate' => '',
            'DateTime_UpdateCalcsStored' => '08/04/2026 13:50:10',
            'Comment_Client' => '',
            'Comment_Internal' => '',
            'Comment_Supplier' => '',
        ];

        foreach (array_keys(LegacyFieldMap::metre()) as $column) {
            $field = LegacyFieldMap::metre()[$column][0];
            $record[$field] ??= '';
        }

        return array_merge($record, $overrides);
    }

    /** @return array<string, mixed> */
    private function lotRecord(): array
    {
        $record = [
            'zkp' => self::LOT,
            'zkf_PRJ' => self::PRJ,
            'zkf_CPY' => '0F227244-E541-423E-9B27-B668A5FCE973',
            'zkf_CTC' => '4DEE4E33-7556-41F2-80E1-9111ED1ED724',
            'Code' => '00',
            'Title_FR' => 'INSTALLATION DE CHANTIER',
            'Title_EN' => '',
            'Title_NL' => '',
            'Title_Custom' => '',
            'CPY_Name_ae' => 'Blue Pineapple srl',
            'CPY_ADR_ae' => '',
            'CTC_Name_ae' => '',
            'TENDER_Weighting_Price' => 80,
            'zkf_TENDER_Supp1' => 'BB4C07E1-55EE-441C-B3BC-4AEB90203C8E',
            'TENDER_Weighting_Crit1' => 30,
            'TENDER_Weighting_Crit1_Description' => 'Délai',
        ];

        foreach (LegacyFieldMap::lot() as [$field]) {
            $record[$field] ??= '';
        }

        return $record;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function lineRecord(string $zkp, string $metreId, array $overrides = []): array
    {
        $record = ['zkp' => $zkp, 'zkf_MET' => $metreId];

        foreach (LegacyFieldMap::metreLine() as [$field]) {
            $record[$field] ??= '';
        }

        /*
         * Le champ résumé qui existe sur la vraie mise en page, et qui décide de la stratégie de
         * lecture : tant qu'il est là, les lignes sont lues métré par métré pour réduire le found
         * set. Présent par défaut dans le jeu d'essai pour que les tests éprouvent le chemin
         * RÉELLEMENT emprunté aujourd'hui ; $withoutSummaryFields le retire pour éprouver l'autre.
         */
        if (! $this->withoutSummaryFields) {
            $record['zsm_zkf_VAT_List'] = "315AA175-F60D-4061-A6BA-9B7DA058FADE\r315AA175-F60D-4061-A6BA-9B7DA058FADE";
        }

        return array_merge($record, $overrides);
    }
}
