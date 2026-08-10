<?php

namespace App\Console\Commands;

use App\Console\Commands\LegacyImport\LegacyFieldMap;
use App\Console\Commands\LegacyImport\LegacyFileMakerReader;
use App\Jobs\RecalculateMetreTotals;
use App\Models\Metre;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Helper\ProgressBar;
use Throwable;

/**
 * TEMPORAIRE — À SUPPRIMER AVEC L'IMPORT.
 *
 * Reprend les données de l'ancien ShakeMetre FileMaker dans la base de ce projet.
 *
 *     php artisan shakemetre:import --dry-run          # tout lire, tout vérifier, ne rien écrire
 *     php artisan shakemetre:import --fresh            # repartir d'une base vide
 *     php artisan shakemetre:import                    # compléter/corriger sans rien vider
 *     php artisan shakemetre:import --only=METL,METC   # reprendre une phase
 *
 * ## Ce qui gouverne l'ordre des phases
 *
 * Les clés étrangères. Un enfant ne peut pas être inséré avant son parent, donc :
 * REF → REFS → REFSL, puis MET et LOT (sans parent local : `project_id` est un zkp
 * ShakeDesign, lu à distance, sans contrainte ici), puis METL qui dépend des trois précédents,
 * puis METC qui dépend de METL. Vider fait le chemin inverse.
 *
 * ## Ce qui rend la commande rejouable
 *
 * Tout est écrit en `upsert` sur la clé primaire, et les UUID viennent de la source. Une
 * interruption — réseau, session Data API, Ctrl-C — se rattrape en relançant la même commande :
 * ce qui était passé est réécrit à l'identique, ce qui manquait arrive. C'est la raison pour
 * laquelle `--fresh` est une option et non le comportement par défaut.
 *
 * ## Les UUID sont préservés VERBATIM, et c'est tout l'enjeu
 *
 * `OFF_Offers.zkf_MET` et `SOR_SupplierOrders.zkf_MET`, dans ShakeDesign, sont des références
 * DURES vers `MET_Metre.zkp`. MET, METL, LOT, REF, REFS, REFSL et METC gardent donc leur clé
 * telle quelle, sinon ces clés étrangères cassent en silence. Aucune génération d'UUID ici :
 * les inserts passent par DB::table(), pas par Eloquent, donc `HasUuids` ne s'en mêle pas.
 *
 * ## Pourquoi DB::table() et pas les modèles
 *
 * Deux raisons, et la seconde est la coupante. La vitesse — 57 816 lignes en `upsert` par
 * paquets, pas 57 816 `save()`. Et les observateurs : `MetreLineObserver` réécrit la section de
 * la ligne depuis `reference_id` et déclenche `RecalculateMetreTotals` à chaque sauvegarde. Sur
 * un import, la première écraserait la copie que porte la source — la seule vraie — et la
 * seconde mettrait 57 816 tâches en file pour recalculer 877 métrés. Les totaux `_Stored` de la
 * source sont repris tels quels ; `--recalculate` les refait, séparément et sciemment.
 */
class ImportLegacyShakeMetre extends Command
{
    protected $signature = 'shakemetre:import
                            {--fresh : Vider les tables du domaine avant l\'import (repartir d\'une base vide)}
                            {--dry-run : Tout lire et tout vérifier sans écrire une ligne}
                            {--only= : Limiter aux phases nommées, séparées par des virgules : REF,REFS,REFSL,MET,LOT,METL,METC}
                            {--limit-metres= : Ne lire les lignes que des N premiers métrés, pour une répétition rapide}
                            {--recalculate : Recalculer les totaux des métrés après l\'import, au lieu de garder ceux de la source}
                            {--page=500 : Taille de page des lectures Data API}
                            {--timeout=300 : Délai HTTP par requête, en secondes}
                            {--audit : Comparer ce qui est en base à ce que la source contient, métré par métré, sans rien écrire}
                            {--repair : Réimporter les lignes des métrés que l\'audit trouve incomplets}
                            {--force : Ne pas demander confirmation pour --fresh}';

    protected $description = 'Importe les données de l\'ancien ShakeMetre FileMaker (temporaire, à supprimer après la migration)';

    /**
     * Les phases, dans l'ordre où les clés étrangères permettent de les jouer.
     *
     * @var list<array{key: string, layout: string, table: string, label: string}>
     */
    private const PHASES = [
        ['key' => 'REF', 'layout' => 'API_MIGRATION_REF', 'table' => 'metre_references', 'label' => 'Catalogue — références'],
        ['key' => 'REFS', 'layout' => 'API_MIGRATION_REFS', 'table' => 'sub_references', 'label' => 'Catalogue — sous-références'],
        ['key' => 'REFSL', 'layout' => 'API_MIGRATION_REFSL', 'table' => 'sub_reference_lines', 'label' => 'Catalogue — articles'],
        ['key' => 'MET', 'layout' => 'API_MIGRATION_MET', 'table' => 'metres', 'label' => 'Métrés'],
        ['key' => 'LOT', 'layout' => 'API_MIGRATION_LOT', 'table' => 'lots', 'label' => 'Lots'],
        ['key' => 'METL', 'layout' => 'API_MIGRATION_METL', 'table' => 'metre_lines', 'label' => 'Lignes de métré'],
        ['key' => 'METC', 'layout' => 'API_MIGRATION_METC', 'table' => 'metre_line_components', 'label' => 'Composants de ligne'],
    ];

    /**
     * Les tables du domaine ShakeMetre, enfants d'abord — l'ordre dans lequel on peut les vider
     * même sans désactiver les contraintes.
     *
     * Les treize tables du domaine, plus `metre_number_sequences`. Sept sont vides à la source
     * (le panier, le catalogue matériaux, les fournisseurs, les tags par métré n'ont jamais
     * servi) : les vider fait correspondre le local à la source exactement, ce que « partir sur
     * une base vide » veut dire. `users` et les tables d'infrastructure de Laravel ne sont
     * JAMAIS touchées — vider la base du site ne veut pas dire se déconnecter soi-même.
     *
     * @var list<string>
     */
    private const DOMAIN_TABLES = [
        'metre_line_components',
        'metre_lines',
        'cart_materials',
        'carts',
        'tags',
        'lots',
        'metres',
        'metre_number_sequences',
        'sub_reference_lines',
        'sub_references',
        'metre_references',
        'materials',
        'sub_categories',
        'categories',
    ];

    private LegacyFileMakerReader $reader;

    private LegacyFieldMap $map;

    private bool $dryRun = false;

    /** La phase en cours, pour attribuer un écart constaté au moment de l'écriture. Voir salvage(). */
    private string $phaseKey = '';

    /**
     * Les champs résumés (`zsm_*`) posés sur API_MIGRATION_METL, relevés au contrôle des mises en
     * page. Vide = la table peut se lire d'un bout à l'autre. Voir preflight() et
     * importMetreLines().
     *
     * @var list<string>
     */
    private array $metlSummaryFields = [];

    /**
     * Les clés acceptées, par table, pour valider les clés étrangères au vol.
     *
     * Amorcées depuis la base au début de chaque phase qui en a besoin, puis complétées par les
     * lignes de la passe en cours. Les deux moitiés comptent : la base rend `--only=METC`
     * utilisable seul, la passe en cours rend `--dry-run` utilisable sur une base vide.
     *
     * @var array<string, array<string, true>>
     */
    private array $ids = [];

    /**
     * Les couples (projet, numéro de métré) déjà pris, et par quel métré.
     *
     * `metres` porte un index unique sur `(project_id, ind_project)` — la règle « un numéro de
     * métré n'est jamais redonné », tenue par la base et non espérée. La source ne contient aucun
     * doublon (vérifié sur les 877), donc l'import ne s'y heurte pas tout seul. Mais une base qui
     * porte déjà des métrés de démonstration, elle, peut occuper un couple avec un AUTRE id : sans
     * `--fresh`, l'`upsert` mourrait alors sur une violation d'unicité, en SQL brut, au milieu
     * d'une phase. Détecté et signalé nommément à la place. Voir reject().
     *
     * @var array<string, string>
     */
    private array $takenNumbers = [];

    /** @var array<string, int> */
    private array $inserted = [];

    /** @var array<string, int> */
    private array $skipped = [];

    /**
     * Les zkp déjà vus pendant CETTE passe, par table, et les doublons qui en découlent.
     *
     * `upsert` sur la clé primaire écrase sans un mot : deux enregistrements de la source qui
     * portent le même zkp n'en laissent qu'un en base, et `$inserted` — qui compte les lignes
     * ENVOYÉES à l'écriture, pas celles qui s'y posent — continue d'annoncer le total de la
     * source. C'est la façon dont un écart peut traverser le rapport sans y laisser de trace,
     * et c'est le premier soupçon à lever quand la base compte moins de lignes que la source.
     *
     * L'ensemble est libéré à la fin de sa phase : 57 816 clés d'UUID pèsent une dizaine de
     * mégaoctets, inutile de les garder pendant les phases suivantes.
     *
     * @var array<string, array<string, true>>
     */
    private array $seen = [];

    /** @var array<string, int> */
    private array $duplicates = [];

    /** @var array<string, list<string>> les premiers zkp en double, pour pouvoir aller les voir */
    private array $duplicateIds = [];

    /** @var array<string, int> effectif de la source par phase, relevé au contrôle des mises en page */
    private array $sourceTotals = [];

    /** @var array<string, int> enregistrements effectivement lus par phase, avant tout écart */
    private array $read = [];

    /** @var array<string, int> ce que la table porte à la fin de sa phase, compté en base */
    private array $landed = [];

    /** @var array<string, array<string, int>> le message, et combien de fois il a été relevé */
    private array $notes = [];

    /** @var array<string, array<string, list<string>>> quelques zkp par message, pour aller les voir */
    private array $noteSamples = [];

    /** @var array<string, float> */
    private array $durations = [];

    public function handle(): int
    {
        $this->dryRun = (bool) $this->option('dry-run');
        $this->map = new LegacyFieldMap;

        $this->allowRoomForTheBiggestMetre();

        try {
            // Le délai de config('services.shakemetre_filemaker.timeout') vaut 30 s : c'est un
            // délai de requête web, et une lecture par lots n'en est pas une. Un simple comptage
            // sur API_MIGRATION_METL rapporte 2,1 Mo — le champ résumé `zsm_zkf_VAT_List` — et
            // dépassait 30 s sur le vrai serveur. Le premier essai réel s'est arrêté là.
            $this->reader = new LegacyFileMakerReader([
                ...(array) config('services.shakemetre_filemaker'),
                'timeout' => max(1, (int) $this->option('timeout')),
            ]);
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('audit') || $this->option('repair')) {
            try {
                return $this->auditAndRepair();
            } catch (Throwable $e) {
                $this->newLine();
                $this->components->error($e->getMessage());

                return self::FAILURE;
            } finally {
                $this->reader->logout();
            }
        }

        $phases = $this->selectedPhases();

        if ($phases === []) {
            $this->components->error('Aucune phase à jouer : --only ne reconnaît aucune des valeurs données.');

            return self::FAILURE;
        }

        $this->header($phases);

        $started = microtime(true);

        try {
            if (! $this->preflight($phases)) {
                return self::FAILURE;
            }

            if (! $this->wipeIfRequested()) {
                return self::FAILURE;
            }

            foreach ($phases as $phase) {
                $this->runPhase($phase);
            }

            if ($this->isSelected('MET', $phases)) {
                $this->seedNumberSequences();
            }

            if ($this->option('recalculate')) {
                $this->recalculateTotals();
            }
        } catch (Throwable $e) {
            $this->newLine(2);
            $this->components->error($e->getMessage());
            $this->line('  <fg=yellow>Rien n\'est perdu : la commande est rejouable. Relancez-la sans --fresh, '.
                'ce qui est déjà passé sera réécrit à l\'identique et la suite reprendra.</>');
            $this->report(microtime(true) - $started);

            return self::FAILURE;
        } finally {
            $this->reader->logout();
        }

        $this->report(microtime(true) - $started);

        return self::SUCCESS;
    }

    /**
     * 512 Mo pour la durée de l'import, et seulement si la limite en place est plus basse.
     *
     * Même geste que `MetreDocumentController::allowRoomForDompdf()`, pour une raison voisine et
     * un constat de terrain : la première exécution complète est morte à 13 % de la phase des
     * lignes, « Allowed memory size of 134217728 bytes exhausted » dans `fwrite` du flux Guzzle.
     * Les réponses sont maintenant paginées à 100 enregistrements, ce qui borne le pic — mais la
     * taille d'une réponse dépend d'un champ résumé de l'autre application, donc la marge ne se
     * calcule pas ici et un import qui meurt à 13 % coûte plus qu'un peu de mémoire réservée.
     *
     * `$this->ids` porte aussi les 57 816 clés de lignes, gardées pour valider les composants :
     * une dizaine de mégaoctets qui, eux, sont structurels.
     */
    private function allowRoomForTheBiggestMetre(): void
    {
        $current = trim((string) ini_get('memory_limit'));

        if ($current === '-1') {
            return;
        }

        $bytes = match (strtoupper(substr($current, -1))) {
            'G' => (int) $current * 1024 ** 3,
            'M' => (int) $current * 1024 ** 2,
            'K' => (int) $current * 1024,
            default => (int) $current,
        };

        if ($bytes < 512 * 1024 ** 2) {
            ini_set('memory_limit', '512M');
        }
    }

    /* ---------------------------------------------------------------- préparation */

    /** @return list<array{key: string, layout: string, table: string, label: string}> */
    private function selectedPhases(): array
    {
        $only = trim((string) $this->option('only'));

        if ($only === '') {
            return self::PHASES;
        }

        $wanted = array_map('strtoupper', array_map('trim', explode(',', $only)));

        return array_values(array_filter(
            self::PHASES,
            fn (array $phase): bool => in_array($phase['key'], $wanted, true),
        ));
    }

    /** @param list<array{key: string, ...}> $phases */
    private function isSelected(string $key, array $phases): bool
    {
        return in_array($key, array_column($phases, 'key'), true);
    }

    /** @param list<array{key: string, layout: string, table: string, label: string}> $phases */
    private function header(array $phases): void
    {
        $this->newLine();
        $this->line('<options=bold>  Import de l\'ancien ShakeMetre FileMaker</>');
        $this->line('  <fg=gray>'.str_repeat('─', 74).'</>');
        $this->line(sprintf('  source      <fg=cyan>%s</> / <fg=cyan>%s</>',
            (string) config('services.shakemetre_filemaker.host'),
            (string) config('services.shakemetre_filemaker.database'),
        ));
        $this->line(sprintf('  cible       <fg=cyan>%s</> (%s)',
            (string) config('database.connections.'.config('database.default').'.database'),
            (string) config('database.default'),
        ));
        $this->line(sprintf('  phases      %s', implode(' → ', array_column($phases, 'key'))));
        $this->line(sprintf('  mode        %s%s%s',
            $this->dryRun ? '<fg=yellow>simulation, aucune écriture</>' : '<fg=green>écriture</>',
            $this->option('fresh') ? ' + <fg=red>vidage préalable</>' : '',
            $this->option('recalculate') ? ' + recalcul des totaux' : '',
        ));

        if ($limit = (int) $this->option('limit-metres')) {
            $this->line(sprintf(
                '  <fg=yellow>répétition</> lignes des %d premiers métrés seulement — import PARTIEL. Les métrés et les lots '.
                'sont pris en entier (ils ne coûtent rien) ; les composants des autres métrés seront comptés comme écartés, '.
                'ce qui est attendu.',
                $limit,
            ));
        }

        $this->newLine();
    }

    /**
     * Refuser de tourner sur une mise en page incomplète, plutôt que d'importer des métrés sans
     * langue et des lignes sans unité.
     *
     * C'est la vérification qui justifie l'existence des sept `API_MIGRATION_*` : une lecture
     * Data API ne voit que les champs POSÉS sur la mise en page qu'elle interroge, et
     * l'absence d'un champ ne produit aucune erreur — la colonne arrive nulle, en silence. Un
     * champ manquant est donc un import corrompu qu'on ne voit qu'à l'usage, des semaines plus
     * tard, sur de l'argent.
     *
     * @param  list<array{key: string, layout: string, table: string, label: string}>  $phases
     */
    private function preflight(array $phases): bool
    {
        $this->line('<options=bold>  Vérification des mises en page</>');

        $rows = [];
        $missing = [];
        $heavy = null;

        foreach ($phases as $phase) {
            $exposed = $this->reader->fieldNames($phase['layout']);
            $needed = array_merge(
                array_column($this->mapFor($phase['key']), 0),
                ['zlg_creaTimeStamp', 'zlg_modifTimeStamp', 'zlg_creaUserName', 'zlg_modifUserName'],
            );

            $absent = array_values(array_diff(array_unique($needed), $exposed));
            $total = $this->reader->totalRecords($phase['layout']);

            $this->sourceTotals[$phase['key']] = $total;

            if ($absent !== []) {
                $missing[$phase['layout']] = $absent;
            }

            /*
             * Les champs résumés de METL décident de la STRATÉGIE de lecture, pas seulement du
             * volume. Un champ Summary (`zsm_`) s'évalue sur le FOUND SET et se retrouve sur chaque
             * enregistrement rendu : `zsm_zkf_VAT_List` pèse à lui seul 2,1 Mo par ligne, contre
             * 929 octets pour tout le reste de la mise en page.
             *
             * Tant qu'il y en a un, il faut lire métré par métré pour réduire le found set — 733 Mo
             * et 27 minutes, mesurés. S'il n'y en a plus, la table se lit d'un bout à l'autre en
             * pages de 500 : ~51 Mo et une centaine de requêtes au lieu de quinze cents.
             *
             * Détecté plutôt que configuré : la mise en page est modifiable par quelqu'un d'autre,
             * et une option à cocher se désynchroniserait de la réalité au premier oubli.
             */
            if ($phase['key'] === 'METL') {
                $summaries = array_values(array_filter($exposed, fn (string $f): bool => str_starts_with($f, 'zsm_')));

                $this->metlSummaryFields = $summaries;

                if ($summaries !== []) {
                    $heavy = $total;
                }
            }

            $rows[] = [
                $phase['key'],
                $phase['layout'],
                number_format($total, 0, ',', ' '),
                count($exposed),
                $absent === [] ? '<fg=green>complète</>' : '<fg=red>'.count($absent).' manquant(s)</>',
            ];
        }

        $this->table(['Phase', 'Mise en page', 'Enregistrements', 'Champs exposés', 'Contrôle'], $rows);

        if ($missing !== []) {
            $this->components->error('Des champs nécessaires ne sont pas posés sur les mises en page.');

            foreach ($missing as $layout => $fields) {
                $this->line("  <fg=red>{$layout}</> : ".implode(', ', $fields));
            }

            $this->line('  <fg=yellow>Posez-les (voir docs/filemaker-reference/API_MIGRATION_layouts.md), puis relancez. '.
                'Un champ absent n\'échoue pas à la lecture : la colonne arriverait nulle sans un mot.</>');

            return false;
        }

        if ($heavy !== null) {
            $this->line(sprintf(
                '  <fg=yellow>Lecture métré par métré</> — %d champ(s) résumé(s) (`zsm_*`) sont posés sur API_MIGRATION_METL, dont %s.',
                count($this->metlSummaryFields),
                implode(', ', array_slice($this->metlSummaryFields, 0, 3)).(count($this->metlSummaryFields) > 3 ? ', …' : ''),
            ));
            $this->line(sprintf(
                '  Un résumé s\'évalue sur le found set et revient sur CHAQUE enregistrement : `zsm_zkf_VAT_List` pèse '.
                '2,1 Mo par ligne à lui seul. Les %s lignes sont donc lues par métré, ce qui réduit le found set — '.
                '~733 Mo et ~27 min, au lieu de ~124 Go d\'une seule traite.',
                number_format($heavy, 0, ',', ' '),
            ));
            $this->line('  <fg=gray>Les retirer de la mise en page ferait passer la lecture en pleine table : ~51 Mo, ~100 requêtes. '.
                'La commande le détecte seule, rien à changer ici.</>');
            $this->newLine();
        } elseif ($this->isSelected('METL', $phases)) {
            $this->line('  <fg=green>Lecture en pleine table</> — aucun champ résumé sur API_MIGRATION_METL, '.
                'le found set n\'a plus besoin d\'être réduit.');
            $this->newLine();
        }

        return true;
    }

    /** @return array<string, array{0: string, 1: string}> */
    private function mapFor(string $key): array
    {
        return match ($key) {
            'REF' => LegacyFieldMap::reference(),
            'REFS' => LegacyFieldMap::subReference(),
            'REFSL' => LegacyFieldMap::subReferenceLine(),
            'MET' => LegacyFieldMap::metre(),
            'LOT' => LegacyFieldMap::lot(),
            'METL' => LegacyFieldMap::metreLine(),
            'METC' => LegacyFieldMap::metreLineComponent(),
        };
    }

    /* ---------------------------------------------------------------- vidage */

    private function wipeIfRequested(): bool
    {
        if (! $this->option('fresh')) {
            return true;
        }

        $counts = [];

        foreach (self::DOMAIN_TABLES as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        $populated = array_filter($counts);

        $this->line('<options=bold>  Vidage des tables du domaine</>');

        if ($populated === []) {
            $this->line('  <fg=gray>Déjà vides, rien à supprimer.</>');
            $this->newLine();

            return true;
        }

        foreach ($populated as $table => $count) {
            $this->line(sprintf('    %-26s <fg=red>%s</> enregistrement(s) à supprimer', $table, number_format($count, 0, ',', ' ')));
        }

        if ($this->dryRun) {
            $this->line('  <fg=yellow>Simulation : rien n\'est supprimé.</>');
            $this->newLine();

            return true;
        }

        // Une suppression irréversible sur de l'argent se confirme. --force existe pour un
        // lancement non interactif, pas pour éviter de lire la liste ci-dessus.
        if (! $this->option('force') && ! $this->confirm(sprintf(
            'Supprimer définitivement %s enregistrement(s) dans %d table(s) ?',
            number_format(array_sum($populated), 0, ',', ' '),
            count($populated),
        ))) {
            $this->components->warn('Vidage annulé — donc import annulé : --fresh sans vidage donnerait un mélange.');

            return false;
        }

        // Les contraintes sont levées le temps du vidage plutôt que l'ordre soigneusement
        // respecté : `metre_lines` référence `materials` et `cart_materials`, qui référencent
        // `carts`, qui référence `metres`... l'ordre existe, mais le lever est vérifiable d'un
        // coup d'œil là où l'ordre se relit table par table.
        Schema::withoutForeignKeyConstraints(function (): void {
            foreach (self::DOMAIN_TABLES as $table) {
                DB::table($table)->truncate();
            }
        });

        $this->line(sprintf('  <fg=green>Vidé.</> %s enregistrement(s) supprimé(s).', number_format(array_sum($populated), 0, ',', ' ')));
        $this->newLine();

        return true;
    }

    /* ---------------------------------------------------------------- phases */

    /** @param array{key: string, layout: string, table: string, label: string} $phase */
    private function runPhase(array $phase): void
    {
        $started = microtime(true);
        $this->phaseKey = $phase['key'];

        match ($phase['key']) {
            'METL' => $this->importMetreLines($phase),
            default => $this->importWholeLayout($phase),
        };

        // Ce que la table porte VRAIMENT, compté en base plutôt que déduit du nombre de lignes
        // envoyées à l'`upsert`. Les deux chiffres ne disent pas la même chose et leur écart est
        // le seul témoin d'un doublon de clé primaire ou d'un refus passé inaperçu.
        if (! $this->dryRun) {
            $this->landed[$phase['key']] = DB::table($phase['table'])->count();
        }

        unset($this->seen[$phase['table']]);

        $this->durations[$phase['key']] = microtime(true) - $started;
    }

    /**
     * Un zkp vu une fois de plus.
     *
     * Voir `$seen` : un doublon ne fait pas échouer l'écriture, il l'écrase. Compté ici pour que
     * l'arithmétique du rapport se referme — source = lus + non lus, lus = en base + écartés +
     * doublons — au lieu de laisser un reliquat que personne ne sait attribuer.
     */
    private function remember(string $key, string $table, string $id): void
    {
        if (isset($this->seen[$table][$id])) {
            $this->duplicates[$key] = ($this->duplicates[$key] ?? 0) + 1;

            if (count($this->duplicateIds[$key] ?? []) < 8) {
                $this->duplicateIds[$key][] = $id;
            }

            return;
        }

        $this->seen[$table][$id] = true;
    }

    /**
     * Une table lue de bout en bout, page par page.
     *
     * @param  array{key: string, layout: string, table: string, label: string}  $phase
     */
    private function importWholeLayout(array $phase): void
    {
        $map = $this->mapFor($phase['key']);
        $total = $this->reader->totalRecords($phase['layout']);

        $this->seedKnownIds($phase['key']);

        $bar = $this->bar($total, $phase['label']);
        $buffer = [];

        foreach ($this->reader->pages($phase['layout'], (int) $this->option('page')) as $page) {
            foreach ($page as $record) {
                $row = $this->map->row($map, $record, $phase['key']);
                $this->read[$phase['key']] = ($this->read[$phase['key']] ?? 0) + 1;

                if ($this->reject($phase['key'], $row)) {
                    $bar->advance();

                    continue;
                }

                $this->remember($phase['key'], $phase['table'], $row['id']);
                $buffer[] = $row;
                $this->ids[$phase['table']][$row['id']] = true;
                $bar->advance();
            }

            $buffer = $this->flush($phase['table'], $buffer, array_keys($map), force: true);
            $bar->setMessage($this->rate($bar), 'rate');
        }

        $this->flush($phase['table'], $buffer, array_keys($map), force: true);
        $this->finishBar($bar, $phase);
    }

    /**
     * Les lignes, métré par métré.
     *
     * Trois raisons, la première étant celle qui décide :
     *
     *  1. **Le coût.** `API_MIGRATION_METL` porte `zsm_zkf_VAT_List`, un résumé « liste de » qui
     *     rend la liste des clés TVA de tout le found set sur chaque enregistrement. Lue d'un
     *     bloc, la table pèse 2,1 Mo par ligne, soit ~124 Go. Un `_find` sur `zkf_MET` réduit le
     *     found set à un métré — 470 lignes au plus gros — et le champ tombe à 17 ko : ~370 Mo.
     *  2. La mémoire est bornée par le plus gros métré, pas par la table.
     *  3. L'avancement a une granularité qui veut dire quelque chose, et une reprise après
     *     coupure recommence au métré, pas au début.
     *
     * Conséquence assumée : une ligne dont `zkf_MET` est vide ou pointe un métré inexistant
     * n'est jamais lue. C'est le bon comportement — `metre_lines.metre_id` est NOT NULL avec
     * une clé étrangère, ces lignes ne peuvent pas exister ici — mais l'écart est compté et
     * rapporté, jamais passé sous silence.
     *
     * @param  array{key: string, layout: string, table: string, label: string}  $phase
     */
    private function importMetreLines(array $phase): void
    {
        // Aucun champ résumé sur la mise en page : plus rien n'oblige à réduire le found set, la
        // table se lit d'un bout à l'autre — ~51 Mo et une centaine de requêtes contre 733 Mo et
        // quinze cents. `--limit-metres` force malgré tout le parcours par métré, sans quoi
        // l'option n'aurait plus de prise sur quoi que ce soit.
        if ($this->metlSummaryFields === [] && ! $this->option('limit-metres')) {
            $this->importWholeLayout($phase);

            return;
        }

        $this->seedKnownIds('METL');

        $metreIds = array_keys($this->ids['metres'] ?? []);

        if ($limit = (int) $this->option('limit-metres')) {
            $metreIds = array_slice($metreIds, 0, $limit);
        }

        if ($metreIds === []) {
            $this->components->warn('Aucun métré connu : la phase METL n\'a rien à parcourir. Jouez MET d\'abord.');

            return;
        }

        $sourceTotal = $this->reader->totalRecords($phase['layout']);
        $bar = $this->bar($sourceTotal, $phase['label']);

        $seen = $this->readLinesOf($metreIds, $phase, $bar, $sourceTotal);

        $unreachable = $sourceTotal - $seen;

        if ($unreachable > 0 && ! $this->option('limit-metres')) {
            $this->note('METL', sprintf(
                '%d ligne(s) de la source ne sont rattachées à aucun métré existant (zkf_MET vide ou pointant un métré supprimé) '.
                'et sont donc absentes : metre_lines.metre_id est NOT NULL avec une clé étrangère.',
                $unreachable,
            ));
        }

        $this->finishBar($bar, $phase);
    }

    /**
     * Les lignes d'une liste de métrés, lues et écrites.
     *
     * Détaché d'importMetreLines() pour que `--repair` reprenne exactement le même chemin de
     * lecture que l'import : une réparation qui lirait autrement ne prouverait rien.
     *
     * @param  list<string>  $metreIds
     * @param  array{key: string, layout: string, table: string, label: string}  $phase
     * @return int le nombre d'enregistrements lus
     */
    private function readLinesOf(array $metreIds, array $phase, ?ProgressBar $bar = null, int $barMax = 0): int
    {
        $map = LegacyFieldMap::metreLine();
        $seen = 0;
        $buffer = [];

        foreach ($metreIds as $metreId) {
            // `==` est l'égalité stricte de FileMaker. Sans lui, un zkp serait un « commence
            // par » et ramènerait les lignes d'un autre métré.
            $pages = $this->reader->findPages($phase['layout'], ['zkf_MET' => '=='.$metreId], 100);

            foreach ($pages as $records) {
                foreach ($records as $record) {
                    $row = $this->map->row($map, $record, 'METL');
                    $seen++;
                    $this->read['METL'] = ($this->read['METL'] ?? 0) + 1;

                    if ($this->reject('METL', $row)) {
                        continue;
                    }

                    $this->remember('METL', $phase['table'], $row['id']);
                    $buffer[] = $row;
                    $this->ids['metre_lines'][$row['id']] = true;
                }

                // Rendue explicitement : chaque page porte le champ résumé sur chacun de ses
                // enregistrements, donc la laisser au ramasse-miettes suffit en théorie mais
                // garde le pic plus haut que nécessaire pendant la requête suivante.
                unset($records);

                $buffer = $this->flush($phase['table'], $buffer, array_keys($map));

                if ($bar !== null) {
                    $bar->setProgress(min($seen, $barMax));
                    $bar->setMessage($this->rate($bar), 'rate');
                }
            }
        }

        $this->flush($phase['table'], $buffer, array_keys($map), force: true);

        return $seen;
    }

    /**
     * Les clés déjà connues, pour que les clés étrangères soient validées et non espérées.
     *
     * Lues depuis la base — ce qui rend `--only=METC` utilisable seul — puis complétées au vol
     * par la passe en cours, ce qui rend `--dry-run` utilisable sur une base vide.
     */
    private function seedKnownIds(string $key): void
    {
        if ($key === 'MET' && $this->takenNumbers === []) {
            $this->takenNumbers = DB::table('metres')
                ->whereNotNull('project_id')
                ->whereNotNull('ind_project')
                ->get(['id', 'project_id', 'ind_project'])
                ->mapWithKeys(fn ($row) => [$row->project_id.'|'.$row->ind_project => $row->id])
                ->all();
        }

        $needed = match ($key) {
            'REFS' => ['metre_references'],
            'REFSL' => ['metre_references', 'sub_references'],
            'METL' => ['metres', 'lots', 'metre_references', 'sub_references', 'sub_reference_lines', 'materials', 'cart_materials'],
            'METC' => ['metre_lines'],
            default => [],
        };

        foreach ($needed as $table) {
            if (isset($this->ids[$table])) {
                continue;
            }

            $this->ids[$table] = DB::table($table)->pluck('id')->mapWithKeys(fn ($id) => [$id => true])->all();
        }
    }

    /**
     * Une ligne à écarter, ou à corriger.
     *
     * La distinction est délibérée et suit la nullabilité du schéma :
     *
     *  - Un parent **obligatoire** manquant écarte la ligne. Une sous-référence sans référence
     *    ne peut pas exister : `sub_references.reference_id` est NOT NULL. Il y en a deux dans le
     *    fichier vivant, sans code ni titre — des enregistrements blancs, sans enfant.
     *  - Une clé **facultative** qui pend est mise à null et la ligne est gardée. Une ligne de
     *    métré dont le lot a été supprimé reste une vraie ligne, avec son prix et sa quantité ;
     *    l'écarter perdrait de l'argent pour sauver une référence qui, elle, ne vaut plus rien.
     *
     * @param  array<string, mixed>  $row
     */
    private function reject(string $key, array &$row): bool
    {
        if ($row['id'] === null) {
            $this->skipped[$key] = ($this->skipped[$key] ?? 0) + 1;
            $this->note($key, '1 enregistrement sans zkp exploitable, écarté.');

            return true;
        }

        // Un couple (projet, numéro) déjà tenu par un AUTRE métré. Écarté avec un message qui dit
        // quoi faire, plutôt qu'une violation d'unicité en SQL brut au milieu de la phase — et le
        // métré n'est pas perdu pour autant : il est toujours dans FileMaker, un `--fresh` le
        // reprendra.
        if ($key === 'MET' && $row['project_id'] !== null && $row['ind_project'] !== null) {
            $slot = $row['project_id'].'|'.$row['ind_project'];
            $holder = $this->takenNumbers[$slot] ?? null;

            if ($holder !== null && $holder !== $row['id']) {
                $this->skipped[$key] = ($this->skipped[$key] ?? 0) + 1;
                $this->note($key, 'un métré local occupe déjà un couple (projet, numéro) importé '.
                    '→ écarté. C\'est le cas d\'une base qui porte des données de démonstration : '.
                    'relancez avec --fresh pour importer sur une base vide.');

                return true;
            }

            $this->takenNumbers[$slot] = $row['id'];
        }

        // Parents obligatoires : la colonne est NOT NULL et contrainte.
        $required = match ($key) {
            'REFS' => ['reference_id' => 'metre_references'],
            'REFSL' => ['sub_reference_id' => 'sub_references', 'reference_id' => 'metre_references'],
            'METL' => ['metre_id' => 'metres'],
            'METC' => ['metre_line_id' => 'metre_lines'],
            default => [],
        };

        foreach ($required as $column => $table) {
            if ($row[$column] === null || ! isset($this->ids[$table][$row[$column]])) {
                $this->skipped[$key] = ($this->skipped[$key] ?? 0) + 1;
                $this->note($key, sprintf('%s absent ou inconnu → enregistrement écarté (%s est NOT NULL).', $column, $column));

                return true;
            }
        }

        // Clés facultatives : la colonne est nullable, une référence qui pend devient nulle.
        $optional = match ($key) {
            'METL' => [
                'lot_id' => 'lots',
                'reference_id' => 'metre_references',
                'sub_reference_id' => 'sub_references',
                'sub_reference_line_id' => 'sub_reference_lines',
                'material_id' => 'materials',
                'cart_material_id' => 'cart_materials',
            ],
            default => [],
        };

        foreach ($optional as $column => $table) {
            if ($row[$column] !== null && ! isset($this->ids[$table][$row[$column]])) {
                $row[$column] = null;
                $this->note($key, sprintf('%s pointait un enregistrement absent → mis à null, la ligne est gardée.', $column));
            }
        }

        return false;
    }

    /**
     * Écrit un paquet, ou attend d'en avoir assez.
     *
     * `upsert` sur la clé primaire : la commande est rejouable, une relance réécrit à
     * l'identique au lieu de se heurter à un doublon.
     *
     * La taille de paquet est calculée, pas choisie : MySQL plafonne à 65 535 marqueurs par
     * requête et `metre_lines` a près de 90 colonnes. Un paquet fixe de 500 lignes passerait
     * sur les six autres tables et exploserait sur celle-là — sur la seule qui compte 57 816
     * enregistrements.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $columns
     * @return list<array<string, mixed>>
     */
    private function flush(string $table, array $rows, array $columns, bool $force = false): array
    {
        $columns = array_merge($columns, ['created_at', 'updated_at', 'created_by', 'updated_by']);
        $chunk = max(1, intdiv(15000, max(1, count($columns))));

        if (! $force && count($rows) < $chunk) {
            return $rows;
        }

        if ($this->dryRun) {
            $this->inserted[$table] = ($this->inserted[$table] ?? 0) + count($rows);

            return [];
        }

        $updatable = array_values(array_diff($columns, ['id']));

        foreach (array_chunk($rows, $chunk) as $slice) {
            try {
                DB::table($table)->upsert($slice, ['id'], $updatable);
                $this->inserted[$table] = ($this->inserted[$table] ?? 0) + count($slice);
            } catch (QueryException $e) {
                $this->salvage($table, $slice, $updatable, $e);
            }
        }

        return [];
    }

    /**
     * Un paquet refusé, repris ligne par ligne.
     *
     * Sans ça, un seul enregistrement que la base refuse fait échouer l'`upsert` des ~159 qui
     * voyagent avec lui, et l'exception remonte : la phase s'arrête. Sur METL c'est 27 minutes de
     * lecture perdues pour une ligne, et l'import se fait de nuit. Le paquet est donc rejoué
     * enregistrement par enregistrement — la ligne fautive est écartée et nommée, les autres
     * passent.
     *
     * Le coût est nul dans le cas normal : on n'arrive ici qu'après un refus.
     *
     * @param  list<array<string, mixed>>  $slice
     * @param  list<string>  $updatable
     */
    private function salvage(string $table, array $slice, array $updatable, QueryException $failure): void
    {
        foreach ($slice as $row) {
            try {
                DB::table($table)->upsert([$row], ['id'], $updatable);
                $this->inserted[$table] = ($this->inserted[$table] ?? 0) + 1;
            } catch (QueryException $e) {
                $this->skipped[$this->phaseKey] = ($this->skipped[$this->phaseKey] ?? 0) + 1;

                // Le message de la base, débarrassé du SQL : celui-ci porte les valeurs des ~159
                // enregistrements du paquet et noierait le rapport.
                $reason = trim(explode(' (Connection:', $e->getMessage(), 2)[0]);

                // Le zkp passe en échantillon et non dans le message : dans le message, il rendrait
                // chaque refus unique et le rapport déroulerait une ligne par enregistrement — ce
                // qui est exactement ce qui a laissé passer 401 lignes refusées pour la même raison.
                $this->note($this->phaseKey, sprintf('%s → enregistrement écarté.', $reason), (string) ($row['id'] ?? '?'));
            }
        }
    }

    /* ---------------------------------------------------------------- audit */

    /**
     * Ce que la base porte, comparé à ce que la source contient — et, sur demande, rattrapé.
     *
     * Pourquoi c'est une commande à part entière et non une vérification en fin d'import : le
     * rapport d'un import dit ce que la passe a fait, pas ce qui est là. Un import interrompu et
     * relancé, une phase rejouée, une session Data API morte au milieu d'un métré — chacun laisse
     * un rapport plausible et une base incomplète. La seule preuve est le comptage des deux côtés,
     * et il ne coûte rien à demander : six requêtes pour les six tables lues en pleine table, une
     * par métré pour les lignes (`foundCount` sur `zkf_MET`, un enregistrement rapatrié).
     *
     * L'écart est attribué, jamais globalisé. Une ligne peut manquer pour quatre raisons et elles
     * n'appellent pas la même réponse : son métré n'existe plus (structurel, rien à faire), son
     * zkp est en double dans la source (une seule survit à l'`upsert`), la base a refusé la ligne
     * (voir salvage()), ou elle n'a jamais été lue — le seul cas que `--repair` sait corriger, et
     * le plus courant après une interruption.
     */
    private function auditAndRepair(): int
    {
        $repair = (bool) $this->option('repair');

        $this->newLine();
        $this->line('<options=bold>  Audit de l\'import — la base contre la source</>');
        $this->line('  <fg=gray>'.str_repeat('─', 74).'</>');
        $this->line(sprintf('  source      <fg=cyan>%s</> / <fg=cyan>%s</>',
            (string) config('services.shakemetre_filemaker.host'),
            (string) config('services.shakemetre_filemaker.database'),
        ));
        $this->line(sprintf('  mode        %s', $repair
            ? '<fg=green>audit + réparation des métrés incomplets</>'
            : '<fg=yellow>audit seul, aucune écriture</>'));
        $this->newLine();

        // Les six tables lues en pleine table : un comptage de chaque côté suffit, et le total de
        // la source est exact — aucune ligne n'y est structurellement hors de portée.
        $rows = [];

        foreach (self::PHASES as $phase) {
            if ($phase['key'] === 'METL') {
                continue;
            }

            $source = $this->reader->totalRecords($phase['layout']);
            $here = DB::table($phase['table'])->count();

            $rows[] = [
                $phase['key'],
                $phase['table'],
                number_format($source, 0, ',', ' '),
                number_format($here, 0, ',', ' '),
                $source === $here
                    ? '<fg=green>identique</>'
                    : sprintf('<fg=%s>%+d</>', $here < $source ? 'red' : 'yellow', $here - $source),
            ];
        }

        $this->table(['Phase', 'Table', 'Source', 'En base', 'Écart'], $rows);

        return $this->auditMetreLines($repair);
    }

    /**
     * Les lignes, métré par métré — le seul comptage qui puisse nommer ce qui manque.
     *
     * Un total contre un total ne dirait que « il en manque 414 ». Par métré, l'écart devient une
     * liste d'identifiants : on sait quoi relire, `--repair` relit exactement ça, et le second
     * comptage dit si le trou s'est refermé. Un écart qui survit à la relecture a une cause que
     * la relecture ne traite pas, et les notes de la passe la nomment.
     */
    private function auditMetreLines(bool $repair): int
    {
        $phase = self::PHASES[array_search('METL', array_column(self::PHASES, 'key'), true)];

        $metreIds = DB::table('metres')->orderBy('id')->pluck('id')->all();

        if ($metreIds === []) {
            $this->components->warn('Aucun métré en base : il n\'y a rien à auditer. Jouez l\'import d\'abord.');

            return self::FAILURE;
        }

        $local = DB::table('metre_lines')
            ->select('metre_id', DB::raw('COUNT(*) AS n'))
            ->groupBy('metre_id')
            ->pluck('n', 'metre_id')
            ->all();

        $sourceTotal = $this->reader->totalRecords($phase['layout']);

        $bar = $this->bar(count($metreIds), 'Lignes, métré par métré');
        $gaps = [];
        $attached = 0;

        foreach ($metreIds as $metreId) {
            $found = $this->reader->foundCount($phase['layout'], ['zkf_MET' => '=='.$metreId]);
            $attached += $found;
            $here = (int) ($local[$metreId] ?? 0);

            if ($found !== $here) {
                $gaps[$metreId] = ['source' => $found, 'local' => $here];
            }

            $bar->advance();
            $bar->setMessage($this->rate($bar), 'rate');
        }

        $bar->finish();
        $this->newLine(2);

        $missing = array_sum(array_map(fn (array $g): int => max(0, $g['source'] - $g['local']), $gaps));
        $extra = array_sum(array_map(fn (array $g): int => max(0, $g['local'] - $g['source']), $gaps));

        $this->line('<options=bold>  Lignes de métré</>');
        $this->line(sprintf('    source, table entière                     %s', number_format($sourceTotal, 0, ',', ' ')));
        $this->line(sprintf('    source, rattachées à un métré d\'ici       %s', number_format($attached, 0, ',', ' ')));
        $this->line(sprintf('    <fg=gray>hors de portée (zkf_MET vide ou orphelin) %s</>',
            number_format($sourceTotal - $attached, 0, ',', ' ')));
        $this->line(sprintf('    en base                                   %s', number_format(array_sum($local), 0, ',', ' ')));
        $this->newLine();

        if ($gaps === []) {
            $this->components->info('Aucun écart : chaque métré porte ici exactement les lignes que la source lui donne.');

            return self::SUCCESS;
        }

        $this->line(sprintf(
            '  <fg=red>%d métré(s) en écart</> — %s ligne(s) manquante(s)%s.',
            count($gaps),
            number_format($missing, 0, ',', ' '),
            $extra > 0 ? sprintf(', %s en trop', number_format($extra, 0, ',', ' ')) : '',
        ));
        $this->newLine();

        $this->listGaps($gaps);

        if (! $repair) {
            $this->newLine();
            $this->line('  <fg=yellow>Relancez avec --repair pour relire les lignes de ces métrés seulement.</>');

            return self::FAILURE;
        }

        return $this->repairMetres(array_keys($gaps), $phase, $local);
    }

    /** @param array<string, array{source: int, local: int}> $gaps */
    private function listGaps(array $gaps): void
    {
        // Plafonnée : sur un import interrompu la liste peut faire des centaines de lignes, et
        // une liste qu'on ne lit pas ne vaut pas mieux qu'un total. Les plus gros écarts d'abord.
        uasort($gaps, fn (array $a, array $b): int => ($b['source'] - $b['local']) <=> ($a['source'] - $a['local']));

        $shown = array_slice($gaps, 0, 25, true);
        $names = DB::table('metres')->whereIn('id', array_keys($shown))->pluck('name', 'id')->all();

        $this->table(
            ['Métré', 'Nom', 'Source', 'En base', 'Écart'],
            array_map(
                fn (string $id, array $g): array => [
                    $id,
                    mb_substr((string) ($names[$id] ?? ''), 0, 34),
                    $g['source'],
                    $g['local'],
                    sprintf('<fg=%s>%+d</>', $g['local'] < $g['source'] ? 'red' : 'yellow', $g['local'] - $g['source']),
                ],
                array_keys($shown),
                $shown,
            ),
        );

        if (count($gaps) > count($shown)) {
            $this->line(sprintf('  <fg=gray>… et %d autre(s) métré(s) en écart.</>', count($gaps) - count($shown)));
        }
    }

    /**
     * Relire les lignes des métrés en écart, puis recompter.
     *
     * Le recomptage est la moitié qui compte. Relire et annoncer « réparé » supposerait que la
     * relecture suffise, or trois des quatre causes d'un écart y survivent — un zkp en double
     * n'en donnera jamais deux enregistrements, une ligne que la base refuse sera refusée encore,
     * un métré supprimé à la source restera supprimé. Ce qui reste après la relecture est donc le
     * vrai résidu, et les notes de la passe disent pourquoi.
     *
     * @param  list<string>  $metreIds
     * @param  array{key: string, layout: string, table: string, label: string}  $phase
     * @param  array<string, int>  $before
     */
    private function repairMetres(array $metreIds, array $phase, array $before): int
    {
        $this->newLine();
        $this->line('<options=bold>  Réparation</>');

        $this->phaseKey = 'METL';
        $this->seedKnownIds('METL');

        $bar = $this->bar(count($metreIds), 'Relecture des métrés en écart');
        $read = 0;

        foreach ($metreIds as $metreId) {
            $read += $this->readLinesOf([$metreId], $phase);
            $bar->advance();
            $bar->setMessage($this->rate($bar), 'rate');
        }

        $bar->finish();
        $this->newLine(2);

        $after = DB::table('metre_lines')
            ->whereIn('metre_id', $metreIds)
            ->select('metre_id', DB::raw('COUNT(*) AS n'))
            ->groupBy('metre_id')
            ->pluck('n', 'metre_id')
            ->all();

        $gained = array_sum($after) - array_sum(array_intersect_key($before, array_flip($metreIds)));

        $this->line(sprintf('  %s ligne(s) relue(s), <fg=green>%s ligne(s) de plus en base</>.',
            number_format($read, 0, ',', ' '),
            number_format($gained, 0, ',', ' '),
        ));

        foreach ($this->noteLines() as $line) {
            $this->line($line);
        }

        if (($this->duplicates['METL'] ?? 0) > 0) {
            $this->line(sprintf(
                '  <fg=yellow>METL</> — %d zkp en double dans la source, dont %s. Un `upsert` n\'en garde qu\'un : '.
                'ces lignes ne peuvent pas arriver ici tant que la source porte deux fois la même clé.',
                $this->duplicates['METL'],
                implode(', ', $this->duplicateIds['METL'] ?? []),
            ));
        }

        $this->newLine();
        $this->line('  <fg=yellow>Relancez --audit pour vérifier ce qui reste.</>');

        return self::SUCCESS;
    }

    /* ---------------------------------------------------------------- après */

    /**
     * `metre_number_sequences`, la marque haute par projet.
     *
     * Sans elle, la règle « un numéro de métré n'est jamais redonné » est fausse dès le premier
     * métré créé après l'import : le prochain numéro ne se lirait que sur les métrés encore
     * présents, et supprimer le dernier ferait redescendre le maximum. La marque est prise au
     * plus grand `IndProject` importé par projet, et ne peut que monter — si une marque existe
     * déjà plus haute (import partiel rejoué, métré créé entre deux passes), elle est gardée.
     */
    private function seedNumberSequences(): void
    {
        $marks = DB::table('metres')
            ->whereNotNull('project_id')
            ->whereNotNull('ind_project')
            ->groupBy('project_id')
            ->selectRaw('project_id, MAX(ind_project) AS high')
            ->get();

        if ($marks->isEmpty()) {
            return;
        }

        $existing = DB::table('metre_number_sequences')->pluck('last_ind_project', 'project_id');
        $now = now();
        $rows = [];

        foreach ($marks as $mark) {
            $rows[] = [
                'project_id' => $mark->project_id,
                'last_ind_project' => max((int) $mark->high, (int) ($existing[$mark->project_id] ?? 0)),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($this->dryRun) {
            $this->line(sprintf('  <fg=gray>Simulation : %d marque(s) de numérotation seraient posées.</>', count($rows)));
            $this->newLine();

            return;
        }

        foreach (array_chunk($rows, 500) as $slice) {
            DB::table('metre_number_sequences')->upsert($slice, ['project_id'], ['last_ind_project', 'updated_at']);
        }

        $this->line(sprintf('  <fg=green>Numérotation</> — %d projet(s) marqué(s) au plus haut numéro de métré importé.', count($rows)));
        $this->newLine();
    }

    /**
     * Refaire les totaux au lieu de garder ceux de la source.
     *
     * Volontairement facultatif et hors du chemin normal. Les colonnes `_Stored` de la source
     * sont les chiffres que FileMaker montrait : un import qui les recalcule ne migre pas des
     * données, il en fabrique — et les quatre `Total_*_METL_Stored` sont écrites ici sur une
     * hypothèse énoncée dans `RecalculateMetreTotals`, que l'export ne confirme pas. Les
     * recalculer est donc un acte séparé, à décider en connaissance de cause.
     *
     * `dispatchSync` plutôt que `dispatch` : mettre 877 tâches en file demanderait un worker qui
     * tourne et rendrait l'avancement invisible.
     */
    private function recalculateTotals(): void
    {
        if ($this->dryRun) {
            $this->line('  <fg=gray>Simulation : les totaux ne sont pas recalculés.</>');

            return;
        }

        $total = Metre::count();

        if ($total === 0) {
            return;
        }

        $this->components->warn('Recalcul des totaux : les valeurs `_Stored` importées de FileMaker vont être remplacées.');

        $bar = $this->bar($total, 'Recalcul des totaux');

        Metre::query()->chunkById(100, function ($metres) use ($bar): void {
            foreach ($metres as $metre) {
                RecalculateMetreTotals::dispatchSync($metre);
                $bar->advance();
                $bar->setMessage($this->rate($bar), 'rate');
            }
        });

        $bar->finish();
        $this->newLine(2);
    }

    /* ---------------------------------------------------------------- affichage */

    /**
     * Une barre d'avancement avec un ETA.
     *
     * `%remaining%` est l'estimation de Symfony : le temps écoulé rapporté au pourcentage fait.
     * Elle est honnête tant que le coût par unité est stable, ce qui est le cas ici à une
     * réserve près, dite dans le rapport final : sur METL le coût d'un métré croît avec le
     * carré de son nombre de lignes (le champ résumé), donc un gros métré fait momentanément
     * glisser l'estimation.
     */
    private function bar(int $max, string $label): ProgressBar
    {
        $bar = $this->output->createProgressBar($max);

        $bar->setFormat(
            '  %label:-24s% %current:7s%/%max:-7s% [%bar%] %percent:3s%%  '.
            '<fg=gray>%elapsed:6s% écoulé · reste ~%remaining:-6s%</> %rate%'
        );
        $bar->setBarCharacter('<fg=green>=</>');
        $bar->setProgressCharacter('<fg=green>></>');
        $bar->setMessage($label, 'label');
        $bar->setMessage('', 'rate');
        $bar->setRedrawFrequency(max(1, intdiv($max, 200)));
        $bar->start();

        return $bar;
    }

    private function rate(ProgressBar $bar): string
    {
        $elapsed = max(0.001, microtime(true) - $bar->getStartTime());
        $perSecond = $bar->getProgress() / $elapsed;

        return $perSecond > 0 ? sprintf('<fg=gray>· %s/s</>', number_format($perSecond, 0, ',', ' ')) : '';
    }

    /** @param array{key: string, layout: string, table: string, label: string} $phase */
    private function finishBar(ProgressBar $bar, array $phase): void
    {
        $bar->finish();
        $this->newLine();

        $written = $this->inserted[$phase['table']] ?? 0;
        $skipped = $this->skipped[$phase['key']] ?? 0;

        $this->line(sprintf(
            '  <fg=green>✓</> %s — %s enregistrement(s)%s',
            $phase['label'],
            number_format($written, 0, ',', ' '),
            $skipped > 0 ? sprintf(', <fg=yellow>%s écarté(s)</>', number_format($skipped, 0, ',', ' ')) : '',
        ));
        $this->newLine();
    }

    /**
     * Un fait relevé pendant la passe, dédupliqué mais COMPTÉ.
     *
     * Dédupliqué : « lot_id pointait un enregistrement absent » sur 4 000 lignes est un fait, pas
     * quatre mille. Compté : la première version de ce rapport se contentait de dédupliquer, et
     * 401 lignes refusées par la base pour un titre trop long — la moitié du trou de l'import —
     * s'y sont lues comme un incident isolé. Un message sans nombre ne dit pas s'il faut agir.
     *
     * L'échantillon suit la même logique : le zkp reste hors du message pour que la déduplication
     * fonctionne, mais quelques-uns sont gardés à côté, sans quoi le fait n'est pas vérifiable.
     */
    private function note(string $key, string $message, ?string $sample = null): void
    {
        $this->notes[$key][$message] = ($this->notes[$key][$message] ?? 0) + 1;

        if ($sample !== null && count($this->noteSamples[$key][$message] ?? []) < 5) {
            $this->noteSamples[$key][$message][] = $sample;
        }
    }

    /** @return list<string> les notes d'une passe, prêtes à afficher */
    private function noteLines(): array
    {
        $lines = [];

        foreach ($this->notes as $key => $messages) {
            arsort($messages);

            foreach ($messages as $message => $count) {
                $samples = $this->noteSamples[$key][$message] ?? [];

                $lines[] = sprintf(
                    '  <fg=yellow>%s</> — %s%s%s',
                    $key,
                    $count > 1 ? sprintf('<options=bold>%s ×</> ', number_format($count, 0, ',', ' ')) : '',
                    $message,
                    $samples === [] ? '' : sprintf(' <fg=gray>(dont %s)</>', implode(', ', $samples)),
                );
            }
        }

        return $lines;
    }

    private function report(float $seconds): void
    {
        $this->line('<options=bold>  Rapport</>');
        $this->line('  <fg=gray>'.str_repeat('─', 74).'</>');

        $rows = [];
        $unexplained = [];

        foreach (self::PHASES as $phase) {
            if (! array_key_exists($phase['table'], $this->inserted) && ! array_key_exists($phase['key'], $this->skipped)) {
                continue;
            }

            $key = $phase['key'];
            $source = $this->sourceTotals[$key] ?? 0;
            $read = $this->read[$key] ?? 0;
            $skipped = $this->skipped[$key] ?? 0;
            $duplicates = $this->duplicates[$key] ?? 0;
            $landed = $this->landed[$key] ?? null;

            /*
             * L'arithmétique qui doit se refermer : lus = en base + écartés + doublons. Ce qui
             * reste n'a pas de cause connue, et c'est exactement ce qu'il fallait pouvoir voir —
             * un `upsert` qui écrase et un refus avalé ne laissaient aucune trace dans un rapport
             * qui ne comptait que les lignes ENVOYÉES à l'écriture.
             *
             * Le calcul n'a de sens que sur une base partie de zéro : sans `--fresh`, la table
             * porte déjà des enregistrements que cette passe n'a pas écrits, et « en base » les
             * compte aussi. D'où le tiret plutôt qu'un chiffre trompeur.
             */
            $gap = null;

            if ($landed !== null && $this->option('fresh')) {
                $gap = $read - $skipped - $duplicates - $landed;

                if ($gap !== 0) {
                    $unexplained[$key] = $gap;
                }
            }

            $rows[] = [
                $key,
                $phase['table'],
                $source > 0 ? number_format($source, 0, ',', ' ') : '—',
                number_format($read, 0, ',', ' '),
                $skipped ?: '—',
                $duplicates ?: '—',
                $landed !== null ? number_format($landed, 0, ',', ' ') : '—',
                $gap === null ? '—' : ($gap === 0 ? '<fg=green>0</>' : sprintf('<fg=red>%+d</>', -$gap)),
                isset($this->durations[$key]) ? $this->duration($this->durations[$key]) : '—',
            ];
        }

        if ($rows !== []) {
            $this->table(
                ['Phase', 'Table', 'Source', 'Lus', 'Écartés', 'Doublons', 'En base', 'Écart', 'Durée'],
                $rows,
            );
        }

        foreach ($this->duplicateIds as $key => $ids) {
            $this->line(sprintf(
                '  <fg=yellow>%s</> — zkp vu(s) plus d\'une fois dans la source, dont %s. Un `upsert` sur la clé '.
                'primaire n\'en garde qu\'un : la source doit être corrigée, la relance n\'y changera rien.',
                $key,
                implode(', ', $ids),
            ));
        }

        if ($unexplained !== []) {
            $this->newLine();
            $this->components->warn(
                'Des enregistrements lus ne sont ni en base, ni écartés, ni en double : '.
                implode(', ', array_map(fn (string $k, int $n): string => "{$k} {$n}", array_keys($unexplained), $unexplained)).
                '. Lancez `php artisan shakemetre:import --audit` : il compare métré par métré et nomme les manquants.'
            );
        }

        foreach ($this->noteLines() as $line) {
            $this->line($line);
        }

        $anomalies = $this->map->anomalies();

        if ($anomalies !== []) {
            arsort($anomalies);

            // Plafonné : un libellé porte la valeur fautive, donc des valeurs variées feraient
            // autant de lignes. Les plus fréquentes sont celles qui disent quelque chose.
            $shown = array_slice($anomalies, 0, 20, true);

            $this->newLine();
            $this->line('  <options=bold>Valeurs réinterprétées ou écartées</> <fg=gray>(jamais écrites de travers)</>');

            foreach ($shown as $label => $count) {
                $this->line(sprintf('    %-72s <fg=yellow>%s</>', $label, number_format($count, 0, ',', ' ')));
            }

            if (count($anomalies) > count($shown)) {
                $this->line(sprintf('    <fg=gray>… et %d autre(s) cas.</>', count($anomalies) - count($shown)));
            }
        }

        $names = $this->map->accountNames();

        if ($names !== []) {
            // Plafonné, parce que la liste complète compte plus de 400 entrées sur les données
            // réelles : `zlg_modifUserName` porte souvent un nom de SCRIPT et son compteur
            // (« MET_Duplicate - r.vanhellemont 1996 »), pas une personne. Un rapport qui déroule
            // 400 lignes n'est plus un rapport. Les quinze premiers suffisent à voir qui a
            // travaillé dans le fichier, ce qui est la seule chose que cette liste sert à savoir.
            $top = array_slice($names, 0, 15, true);

            $this->newLine();
            $this->line('  <options=bold>Auteurs vus dans la source</> <fg=gray>(created_by / updated_by restent nuls : '.
                'FileMaker donne un nom de compte, la colonne attend un UUID)</>');

            foreach ($top as $name => $count) {
                $this->line(sprintf('    %-52s <fg=gray>%s</>', $name, number_format($count, 0, ',', ' ')));
            }

            if (count($names) > count($top)) {
                $this->line(sprintf('    <fg=gray>… et %d autre(s) valeur(s) distincte(s).</>', count($names) - count($top)));
            }
        }

        $this->newLine();
        $this->line(sprintf(
            '  durée totale <options=bold>%s</> · %s requête(s) Data API · %s lus',
            $this->duration($seconds),
            number_format($this->reader->requestCount(), 0, ',', ' '),
            $this->bytes($this->reader->bytesRead()),
        ));

        if ($this->dryRun) {
            $this->newLine();
            $this->components->info('Simulation terminée — aucune écriture. Relancez sans --dry-run pour importer.');
        }

        $this->newLine();
    }

    private function duration(float $seconds): string
    {
        if ($seconds < 60) {
            return sprintf('%.1f s', $seconds);
        }

        return sprintf('%d min %02d s', intdiv((int) $seconds, 60), (int) $seconds % 60);
    }

    private function bytes(int $bytes): string
    {
        foreach ([['Go', 1073741824], ['Mo', 1048576], ['ko', 1024]] as [$unit, $size]) {
            if ($bytes >= $size) {
                return sprintf('%.1f %s', $bytes / $size, $unit);
            }
        }

        return $bytes.' o';
    }
}
