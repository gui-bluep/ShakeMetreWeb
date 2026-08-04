<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssignLotToMetreLinesRequest;
use App\Http\Requests\StoreLinesFromCatalogueRequest;
use App\Http\Requests\StoreMetreLineRequest;
use App\Http\Requests\UpdateMetreLineRequest;
use App\Http\Resources\MetreLineDetailResource;
use App\Jobs\RecalculateMetreTotals;
use App\Models\Lot;
use App\Models\Metre;
use App\Models\MetreLine;
use App\Models\MetreLineComponent;
use App\Models\SubReferenceLine;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The four money views of a métré's lines: one row per METL, with the purchase, client-sale
 * and order figures side by side - and the three narrower cuts of the same grid.
 *
 * One controller, one page, one payload for all four: they differ only in which money blocks
 * are on screen, never in what a row is or in what may be written to it. The alternative -
 * four near-identical pages - is four places to fix the next grid bug in.
 *
 * Cell edits reuse PATCH /api/metre-lines/{id} and its whitelist - the same write surface as
 * the other grid - so no view can disagree with another about what is editable. Only creating,
 * duplicating and deleting a line are new here.
 */
class MetreLineDetailController extends Controller
{
    /**
     * The four views, and what each one is: its money blocks, in the order they appear.
     *
     * The mapping lives here rather than in the page because it is what a view *is*, not how it
     * looks - the URL whitelist and the block list are then one fact instead of two that can
     * drift. The page owns only the appearance of a block (its tint, its labels).
     *
     * Slugs are the URLs, and the view named for all three blocks keeps its own URL unchanged.
     */
    public const VIEWS = [
        'achats-ventes-commandes' => ['achats', 'ventes', 'commandes'],
        'achats-ventes' => ['achats', 'ventes'],
        'achats-commandes' => ['achats', 'commandes'],
        'ventes' => ['ventes'],
    ];

    public function show(Metre $metre, string $view): Response
    {
        $lines = $metre->metreLines()
            ->with('lot')
            /*
             * L'ordre d'affichage d'un métré, celui de METL_Sort tel que l'appellent les écrans de
             * travail (METL_TRI__OnLayoutEnter, METL_GoTo, METL_New) :
             *
             *     REF_Code, REFS_Code, REFS_Title, Order, REFSL_Code_c
             *
             * Croissant et sans traitement particulier des vides, donc une ligne sans section
             * passe en tête - c'est ce que fait FileMaker, où un nombre vide se trie avant 0.
             * `sort_order` ne départage plus que les lignes de même rang.
             *
             * Les deux variantes du tri source ne sont PAS reprises ici : `isOption_b` en premier
             * n'est utilisé que par l'offre client, la comptabilité et les impressions, et le tri
             * par TAG_Choice1/2 dépend de MET::Sort_OrderTags, qui appartient au bascule
             * Lots/Tags dont le rôle reste à définir.
             */
            ->orderBy('ref_code')
            ->orderBy('refs_code')
            ->orderBy('refs_title')
            ->orderBy('ref_order')
            ->orderBy('sort_order')
            ->orderBy('sequence_number')
            ->get();

        return Inertia::render('Metres/LinesDetail', [
            'view' => $view,
            'blocks' => self::VIEWS[$view],

            'metre' => [
                'id' => $metre->id,
                'name' => $metre->name,
                'project_id' => $metre->project_id,
                'is_locked_b' => (bool) $metre->is_locked_b,
            ],
            'lines' => MetreLineDetailResource::collection($lines)->resolve(),
            'units' => UpdateMetreLineRequest::UNITS,

            // The lots a line may be assigned to: this project's, and only this project's - the
            // same constraint UpdateMetreLineRequest enforces on the way in.
            'lots' => $metre->project_id === null ? [] : Lot::query()
                ->where('project_id', $metre->project_id)
                ->orderBy('code')
                ->get()
                ->map(fn (Lot $lot) => [
                    'id' => $lot->id,
                    'code' => $lot->code,
                    'name' => $lot->title_custom ?: $lot->title_fr ?: $lot->title_en ?: $lot->title_nl,
                ])->values(),
        ]);
    }

    /**
     * Appends an empty line, so filling it in is the ordinary debounced edit path rather than a
     * second one - the same reasoning as the lot manager's "add".
     *
     * The line may be filed under a section on the way in, which is METL_NewFromREF: either into
     * an existing group (its four values are simply repeated) or into a sub-section defined on the
     * spot, code and title typed in the section header, that exists in no catalogue. The source
     * offers both from the same button, and the second is why a line's section is a copy rather
     * than a link - there is nothing to link to.
     *
     * `ref_order` is assigned here rather than by the observer, which only reacts to a catalogue
     * entry being picked. Both paths end up at MetreLine::nextRefOrder(), so a line filed by hand
     * and a line filed from the catalogue are numbered by the same rule.
     */
    public function store(StoreMetreLineRequest $request, Metre $metre): JsonResponse
    {
        $this->assertWritable($metre);

        $section = $request->safe()->only(StoreMetreLineRequest::EDITABLE);

        $line = new MetreLine;
        $line->metre_id = $metre->getKey();
        $line->sort_order = (int) $metre->metreLines()->max('sort_order') + 1;

        if ($section !== []) {
            $line->forceFill($section);
            $line->ref_order = MetreLine::nextRefOrder($metre->getKey(), $line->ref_code, $line->refs_code);
        }

        $line->save();

        return response()->json([
            'data' => (new MetreLineDetailResource($line->fresh(['lot'])))->resolve(),
        ], 201);
    }

    /**
     * Creates one line per chosen catalogue item - METL_New_Multi, which is how a métré is
     * actually filled in the FileMaker application.
     *
     * For each REFSL the source reads, in this order: its parent REFS and grandparent REF, then
     * REF.Code and REF's localised title, REFS.Code and REFS's localised title, the item's own
     * localised title, its Unit and its Price - then calls METL_New with all of it. METL_New
     * writes the four section values, the title, the unit, and the price into **PriceBuy**: the
     * catalogue is a purchase-price book, and nothing in it feeds the client price.
     *
     * The four values are copied, not linked - see MetreLineObserver::syncSectionSnapshot(). The
     * foreign keys are set as well, which the source does not do, purely as provenance: knowing
     * which catalogue entry a line came from is what a later "push this price back to the
     * catalogue" needs, and nothing reads them to display a line.
     *
     * Items are inserted in the order they were chosen, and each lands at the end of its own
     * section, so choosing three items of two different sections files them under both.
     */
    public function storeFromCatalogue(StoreLinesFromCatalogueRequest $request, Metre $metre): JsonResponse
    {
        $this->assertWritable($metre);

        $items = SubReferenceLine::query()
            ->with(['reference', 'subReference'])
            ->whereKey($request->validated('sub_reference_line_ids'))
            ->get()
            ->keyBy('id');

        $created = DB::transaction(function () use ($request, $metre, $items) {
            $lines = [];
            $sortOrder = (int) $metre->metreLines()->max('sort_order');

            // The requested order, not the query's: a user who picked three items expects them
            // in the order they picked them.
            foreach ($request->validated('sub_reference_line_ids') as $id) {
                $item = $items[$id] ?? null;

                if ($item === null) {
                    continue;
                }

                $line = new MetreLine;
                $line->metre_id = $metre->getKey();
                $line->sort_order = ++$sortOrder;

                // Provenance only. The observer copies the section from these two.
                $line->reference_id = $item->reference_id;
                $line->sub_reference_id = $item->sub_reference_id;
                $line->sub_reference_line_id = $item->getKey();

                // METL_New écrit le libellé du catalogue dans REFSL_Title, qui est le titre de la ligne.
                $line->refsl_title = $item->localisedTitle($metre->language);
                $line->unit = $item->unit;
                $line->price_buy = $item->price;

                $line->save();

                $lines[] = $line;
            }

            return $lines;
        });

        return response()->json([
            'data' => MetreLineDetailResource::collection(
                collect($created)->map(fn (MetreLine $line) => $line->fresh(['lot']))
            )->resolve(),
        ], 201);
    }

    /**
     * Pose un lot sur une sélection de lignes, ou le retire - METL_Lot_AssignToSelection.
     *
     * Le source écrit avec `Replace Field Contents` sur l'ensemble trouvé, dans une transaction
     * ouverte à la main (Open Transaction / Revert on error / Commit), et vide la sélection après
     * un succès. `Replace Field Contents` *est* une écriture de masse sur une colonne : c'est donc
     * une seule requête ici aussi, et non une boucle de `save()`.
     *
     * Ce n'est pas qu'une affaire de coût. Passer par les modèles ferait tourner l'observateur sur
     * chaque ligne : sa règle « pm » remettrait à vide les quantités d'une ligne pour mémoire qui en
     * porterait encore, et chaque enregistrement mettrait un `RecalculateMetreTotals` en file -
     * plusieurs centaines pour un « Tout sélectionner ». Une seule instruction n'écrit que la
     * colonne visée et ne peut rien réécrire d'autre, ce qui est la propriété qu'on veut quand le
     * geste porte sur des centaines de lignes d'argent.
     *
     * Le recalcul est donc demandé une fois, explicitement, et il est nécessaire : `GainOnPurchases_c`
     * ne compte une ligne que si SON lot porte une société fournisseur (voir
     * `RecalculateMetreTotals::aggregateLineTotals()`), donc changer de lot peut déplacer un total
     * du métré même si aucun prix n'a bougé.
     *
     * Ce qui n'est pas repris du script : sa seconde écriture, `METL::LOT_Name_Stored`, le nom du
     * lot recopié sur la ligne dans la langue du métré. La colonne existe (`lot_name_stored`) mais
     * rien ne l'écrit ni ne la lit dans cette application - le nom affiché est dérivé du lot à la
     * lecture, ce qui fait qu'un lot renommé se voit partout - et la remplir ici seulement
     * mettrait les deux chemins d'écriture en désaccord. Voir la question ouverte dans CLAUDE.md.
     */
    public function assignLot(AssignLotToMetreLinesRequest $request, Metre $metre): JsonResponse
    {
        $this->assertWritable($metre);

        $ids = $request->validated('line_ids');
        $lotId = $request->validated('lot_id');

        DB::transaction(function () use ($metre, $ids, $lotId) {
            $metre->metreLines()->whereKey($ids)->update(['lot_id' => $lotId]);
        });

        // Après le commit, comme l'observateur le fait : le job ne doit pas lire l'état d'avant.
        RecalculateMetreTotals::dispatch($metre)->afterCommit();

        return response()->json([
            'data' => MetreLineDetailResource::collection(
                $metre->metreLines()->with('lot')->whereKey($ids)->get()
            )->resolve(),
        ]);
    }

    /**
     * Duplicates one line, its components included, immediately after it.
     *
     * Written through the models so the observers run - the pm-unit rule, the component-driven
     * quantities, and the métré totals all apply to the copy as they would to a hand-made line.
     */
    public function duplicate(MetreLine $metreLine): JsonResponse
    {
        $this->assertWritable($metreLine->metre);

        $copy = DB::transaction(function () use ($metreLine) {
            $attributes = $metreLine->getAttributes();

            unset(
                $attributes['id'],
                $attributes['created_at'],
                $attributes['updated_at'],
                $attributes['created_by'],
                $attributes['updated_by'],
                // The copy has not been ordered or delivered through anything.
                $attributes['supplier_order_id'],
                $attributes['sor_title_ref'],
            );

            $attributes['is_delivered_b'] = false;
            $attributes['sort_order'] = $metreLine->sort_order;

            $copy = MetreLine::forceCreate($attributes);

            foreach ($metreLine->metreLineComponents()->orderBy('sort_order')->get() as $component) {
                $componentAttributes = $component->getAttributes();

                unset(
                    $componentAttributes['id'],
                    $componentAttributes['created_at'],
                    $componentAttributes['updated_at'],
                    $componentAttributes['created_by'],
                    $componentAttributes['updated_by'],
                );

                $componentAttributes['metre_line_id'] = $copy->getKey();

                MetreLineComponent::forceCreate($componentAttributes);
            }

            return $copy;
        });

        return response()->json([
            'data' => (new MetreLineDetailResource($copy->fresh(['lot'])))->resolve(),
        ], 201);
    }

    public function destroy(MetreLine $metreLine): JsonResponse
    {
        $this->assertWritable($metreLine->metre);

        // Components cascade from the line; the métré totals are recomputed by the observer.
        $metreLine->delete();

        return response()->json(status: 204);
    }

    /**
     * Same guard as every other write against a métré's lines: METL_METC_UpdateQuantities exits
     * on met__MET__::isLocked_b, so a locked métré refuses line writes. 423 rather than 403 -
     * the record is locked, the caller is not unauthorized.
     */
    private function assertWritable(?Metre $metre): void
    {
        abort_if(
            (bool) $metre?->is_locked_b,
            423,
            'Ce métré est verrouillé : ses lignes ne peuvent plus être modifiées.',
        );
    }
}
