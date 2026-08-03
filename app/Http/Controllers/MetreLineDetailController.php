<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateMetreLineRequest;
use App\Http\Resources\MetreLineDetailResource;
use App\Models\Lot;
use App\Models\Metre;
use App\Models\MetreLine;
use App\Models\MetreLineComponent;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The "Achats — Ventes — Commandes" view of a métré's lines: one row per METL, with the
 * purchase, client-sale and order figures side by side.
 *
 * Cell edits reuse PATCH /api/metre-lines/{id} and its whitelist - the same write surface as
 * the other grid - so the two views cannot disagree about what is editable. Only creating,
 * duplicating and deleting a line are new here.
 */
class MetreLineDetailController extends Controller
{
    public function show(Metre $metre): Response
    {
        $lines = $metre->metreLines()
            ->with('lot')
            ->orderBy('sort_order')
            ->orderBy('sequence_number')
            ->get();

        return Inertia::render('Metres/LinesDetail', [
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
     */
    public function store(Metre $metre): JsonResponse
    {
        $this->assertWritable($metre);

        $line = new MetreLine;
        $line->metre_id = $metre->getKey();
        $line->sort_order = (int) $metre->metreLines()->max('sort_order') + 1;
        $line->save();

        return response()->json([
            'data' => (new MetreLineDetailResource($line->fresh(['lot'])))->resolve(),
        ], 201);
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
