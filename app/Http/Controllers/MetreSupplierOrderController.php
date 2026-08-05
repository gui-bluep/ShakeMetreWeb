<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateMetreLineRequest;
use App\Models\Lot;
use App\Models\Metre;
use App\Models\MetreLine;
use App\Services\ShakeDesign\ShakeDesignApiException;
use App\Services\ShakeDesign\ShakeDesignClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * La commande fournisseur d'un lot, depuis un métré - `MET_SOR_CreateCSupplierOrder`.
 *
 * Le script source a deux temps, et les deux sont repris tels quels :
 *
 *  - `Action = "Validation"` réduit aux lignes du lot dans ce métré et affiche un écran de
 *    contrôle, `METL_SupplierOrderValidation`. Ici, `preview()`.
 *  - `Action = "SOR_Creation"` revérifie, demande confirmation, crée la commande dans
 *    ShakeDesign, puis reporte sa référence sur chaque ligne. Ici, `store()`.
 *
 * Le contrôle n'est pas une politesse : c'est une écriture chez quelqu'un d'autre, sur des
 * montants, et elle n'est pas défaisable depuis le web - le compte API de ShakeDesign n'a même
 * pas le droit de supprimer un enregistrement.
 *
 * Ce que la source fait et qui n'est PAS repris : le PDF du budget fournisseur, classé dans
 * ShakeDesign par `DOC_New` puis désigné document contractuel par `SOR_DOC_SetAsContractual`.
 * Décidé avec l'utilisateur. Les deux sont des scripts ShakeDesign et le classement d'un document
 * suppose de téléverser un conteneur, ce que ce projet ne sait pas encore faire.
 */
class MetreSupplierOrderController extends Controller
{
    /**
     * L'écran de contrôle : ce qui partirait, et ce qui l'empêche.
     *
     * Ouvert en lecture - regarder ce qu'on s'apprête à commander n'est pas commander. Les
     * empêchements sont renvoyés plutôt que levés : l'écran les montre tous à la fois, là où la
     * source enchaîne les boîtes de dialogue une par une.
     */
    public function preview(Metre $metre, Lot $lot): JsonResponse
    {
        $this->assertLotBelongsToMetreProject($metre, $lot);

        $lines = $metre->supplierOrderLines($lot);

        return response()->json(['data' => [
            'lot' => [
                'id' => $lot->getKey(),
                'code' => $lot->code,
                'name' => $lot->displayTitle($metre->language),
                'company' => $lot->cpy_name_ae,
            ],
            /*
             * Les lignes portent leur section : l'écran de contrôle de la source est groupé par
             * REF_Code puis REFS_Title, avec un sous-total par groupe, exactement comme la vue
             * Achats/Ventes/Commandes. Une commande se relit par corps de métier.
             *
             * Elles portent aussi de quoi être modifiées : `METL_SupplierOrderValidation` laisse
             * l'unité, la quantité commandée, le prix et la case « option » en saisie - c'est là
             * qu'on ajuste avant d'engager, et cocher « option » est la façon de sortir une ligne
             * de la commande.
             */
            'lines' => $lines->map(fn (MetreLine $line) => [
                'id' => $line->getKey(),
                'code' => $line->refLineCode(),
                'title' => $line->refsl_title,
                'ref_code' => $line->ref_code,
                'ref_title' => $line->ref_title,
                'refs_code' => $line->refs_code,
                'refs_title' => $line->refs_title,
                'unit' => $line->unit,
                'quantity_ordered' => $line->quantity_ordered === null ? null : (float) $line->quantity_ordered,
                'price_ordered' => $line->price_ordered === null ? null : (float) $line->price_ordered,
                'total' => (float) $line->ordered_amount,
                // Toujours false à l'ouverture - le jeu les exclut - mais l'écran peut le cocher,
                // et la ligne reste alors visible, grisée, hors du total.
                'is_option_b' => (bool) $line->is_option_b,
            ])->values(),
            'units' => UpdateMetreLineRequest::UNITS,
            'total' => $this->total($lines),
            'blockers' => $this->blockers($metre, $lot, $lines),
        ]]);
    }

    /**
     * Crée la commande dans ShakeDesign et l'inscrit sur les lignes.
     *
     * L'ordre est celui de la source, et il compte : créer, obtenir le numéro, l'écrire, puis
     * reporter. Numéroter d'abord brûlerait un numéro si la création échouait ensuite, et un trou
     * dans une séquence de commandes se remarque.
     */
    public function store(Metre $metre, Lot $lot, ShakeDesignClient $client): JsonResponse
    {
        $this->assertLotBelongsToMetreProject($metre, $lot);

        $lines = $metre->supplierOrderLines($lot);
        $blockers = $this->blockers($metre, $lot, $lines);

        if ($blockers !== []) {
            // 422 et non 403 : rien n'est interdit à cette personne, c'est l'état du métré qui
            // n'est pas prêt. L'écran répète le premier motif, qui est celui à corriger.
            return response()->json([
                'message' => $blockers[0]['message'],
                'blockers' => $blockers,
            ], 422);
        }

        $title = trim(($metre->ind_project === null ? '' : $metre->ind_project.' - ').(string) $metre->name);

        /*
         * Un en-tête et UNE ligne, comme ShakeDesign :: SOR_NewFromMetre : `SOL_New` y reçoit
         * `Qty = 1`, `priceUnit = $SOLPrice` - le total commandé du lot, options exclues -,
         * `vatRate = 0` et le titre du métré. Une commande fournisseur ne détaille donc pas les
         * postes côté ShakeDesign ; le détail vit dans le métré, et le PDF du budget achats est
         * là pour l'accompagner.
         */
        $created = $client->createSupplierOrder(
            header: array_filter([
                'zkf_MET' => $metre->getKey(),
                'zkf_PRJ' => $metre->project_id,
                'zkf_CPY' => $lot->company_id,
                'zkf_CTC' => $lot->contact_id,
                'Language' => $metre->language,
                'Title' => $title,
            ], fn ($v) => $v !== null && $v !== ''),
            lines: [[
                'Title' => $title,
                'Quantity' => 1,
                'PriceUnit' => $this->total($lines),
                'VATRate' => 0,
            ]],
        );

        $number = $this->numberOrder($client, $created['recordId']);

        /*
         * `Replace Field Contents` de la source, sur les deux champs : la clé de la commande et
         * sa référence lisible. En une transaction - la moitié des lignes marquées serait pire
         * que rien, puisque le garde-fou « des lignes sont déjà commandées » bloquerait alors
         * toute reprise.
         */
        DB::transaction(function () use ($lines, $created, $number) {
            MetreLine::whereKey($lines->modelKeys())->update([
                'supplier_order_id' => $created['zkp'],
                'sor_title_ref' => $number,
            ]);
        });

        return response()->json(['data' => [
            'supplier_order' => [
                'zkp' => $created['zkp'],
                'number' => $number,
                'title' => $title,
                'total' => $this->total($lines),
            ],
            'lines_marked' => $lines->count(),
            'lot_breakdown' => $metre->lotBreakdown(),
        ]], 201);
    }

    /**
     * Le numéro de la commande, obtenu de `ZSET_Numbering` et écrit sur l'enregistrement créé.
     *
     * Séparé, et sans faire échouer la commande : la numérotation est un service de ShakeDesign
     * qui peut être fermé au compte API, et une commande sans numéro reste une commande - elle
     * porte déjà sa clé, donc le lien depuis les lignes fonctionne. `null` remonte jusqu'à
     * l'écran, qui le dit.
     */
    private function numberOrder(ShakeDesignClient $client, string $recordId): ?string
    {
        $number = $client->nextNumber('SOR');

        if ($number === null) {
            return null;
        }

        try {
            $client->updateSupplierOrder($recordId, ['Number' => $number]);
        } catch (ShakeDesignApiException) {
            // Le numéro est consommé mais non posé : le dire plutôt que de laisser croire que la
            // commande le porte.
            return null;
        }

        return $number;
    }

    /**
     * Les quatre refus de la source, dans son ordre, rendus tous ensemble.
     *
     * @return list<array{code: string, message: string}>
     */
    private function blockers(Metre $metre, Lot $lot, iterable $lines): array
    {
        $blockers = [];

        if (! $metre->is_accepted_b) {
            $blockers[] = ['code' => 'metre_not_accepted', 'message' => 'Le métré doit être accepté.'];
        }

        if (trim((string) $lot->company_id) === '') {
            $blockers[] = [
                'code' => 'lot_without_supplier',
                'message' => 'Un fournisseur doit être assigné au lot sélectionné.',
            ];
        }

        $lines = collect($lines);

        if ($lines->isEmpty()) {
            $blockers[] = ['code' => 'no_line', 'message' => 'Aucune ligne de métré pour ce lot.'];
        }

        /*
         * `zsm_Count_LockedLines_cU > 0` : une ligne déjà attribuée à une commande. Le commentaire
         * de la source date le changement - « AR 02/07/2024 : now based on the real existence of
         * the SOR linked and no more on the presence of a zkf_SOR ». Ce projet n'a que la clé,
         * donc c'est elle qui sert ; la nuance ne se rejouerait qu'en interrogeant ShakeDesign
         * ligne par ligne pour savoir si la commande existe encore.
         */
        $alreadyOrdered = $lines->filter(fn (MetreLine $l) => trim((string) $l->supplier_order_id) !== '');

        if ($alreadyOrdered->isNotEmpty()) {
            $blockers[] = [
                'code' => 'lines_already_ordered',
                'message' => 'Il existe des lignes déjà attribuées à une commande fournisseur ('
                    .$alreadyOrdered->count().').',
            ];
        }

        return $blockers;
    }

    private function total(iterable $lines): float
    {
        return round(collect($lines)->sum(fn (MetreLine $l) => (float) $l->ordered_amount), 2);
    }

    /**
     * Un lot appartient à un projet, un métré aussi : commander le lot d'un autre chantier depuis
     * ce métré n'a pas de sens, et l'URL en porte les deux moitiés. Même garde que l'affectation
     * de lot en masse.
     */
    private function assertLotBelongsToMetreProject(Metre $metre, Lot $lot): void
    {
        abort_unless(
            $metre->project_id !== null && $lot->project_id === $metre->project_id,
            404,
        );
    }
}
