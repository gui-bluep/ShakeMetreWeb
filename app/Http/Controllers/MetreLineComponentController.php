<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMetreLineComponentRequest;
use App\Http\Requests\UpdateMetreLineComponentRequest;
use App\Http\Resources\MetreLineComponentResource;
use App\Jobs\RecalculateMetreLineQuantitiesFromComponents;
use App\Models\MetreLine;
use App\Models\MetreLineComponent;
use Illuminate\Http\JsonResponse;

/**
 * The components of one metre line - FileMaker's METC portal on the metre line.
 *
 * Every write here changes the parent line's quantities, through
 * MetreLineComponentObserver, so each response also returns the line's freshly recomputed
 * quantities: the panel and the main grid are looking at the same numbers and must not drift.
 */
class MetreLineComponentController extends Controller
{
    public function index(MetreLine $metreLine): JsonResponse
    {
        return response()->json([
            'data' => MetreLineComponentResource::collection($this->componentsOf($metreLine))->resolve(),
            'line' => $this->lineQuantities($metreLine),
        ]);
    }

    public function store(StoreMetreLineComponentRequest $request, MetreLine $metreLine): JsonResponse
    {
        $this->assertWritable($metreLine);

        $component = new MetreLineComponent;
        $component->forceFill($request->safe()->only(StoreMetreLineComponentRequest::EDITABLE));
        $component->metre_line_id = $metreLine->getKey();

        // Appended unless the caller placed it, so a new row lands at the bottom rather than
        // silently sharing a position with an existing one.
        $component->sort_order ??= (int) $metreLine->metreLineComponents()->max('sort_order') + 1;

        $component->save();

        return response()->json([
            'data' => (new MetreLineComponentResource($component))->resolve(),
            'line' => $this->lineQuantities($this->recalculatedLine($metreLine)),
        ], 201);
    }

    public function update(
        UpdateMetreLineComponentRequest $request,
        MetreLineComponent $metreLineComponent,
    ): JsonResponse {
        $line = $metreLineComponent->metreLine;
        $this->assertWritable($line);

        $metreLineComponent
            ->forceFill($request->safe()->only(UpdateMetreLineComponentRequest::EDITABLE))
            ->save();

        return response()->json([
            'data' => (new MetreLineComponentResource($metreLineComponent->refresh()))->resolve(),
            'line' => $this->lineQuantities($this->recalculatedLine($line)),
        ]);
    }

    public function destroy(MetreLineComponent $metreLineComponent): JsonResponse
    {
        $line = $metreLineComponent->metreLine;
        $this->assertWritable($line);

        $metreLineComponent->delete();

        return response()->json([
            'line' => $this->lineQuantities($this->recalculatedLine($line)),
        ]);
    }

    /**
     * Same guard as the metre line endpoint: the source refuses to write into a locked metre
     * (METL_METC_UpdateQuantities exits on met__MET__::isLocked_b), and editing a component
     * rewrites the line's quantities, so it is a write to that metre by another route.
     */
    private function assertWritable(?MetreLine $line): void
    {
        abort_if(
            (bool) $line?->metre?->is_locked_b,
            423,
            'Ce métré est verrouillé : ses lignes ne peuvent plus être modifiées.',
        );
    }

    /**
     * MetreLineComponentObserver queues the recalculation, so by the time this response is
     * built the queued job has not run and the line still holds its previous quantities - the
     * panel would show stale numbers until the next page load, which is exactly what this
     * interface exists to avoid.
     *
     * Run here as a plain method call rather than dispatchSync: the job carries a uniqueness
     * lock meant for the queue, and a synchronous dispatch could find it held by the pending
     * one and skip the work. The recalculation is idempotent, so the queued job re-running it
     * later is harmless - it stays in place as the safety net for every write path that is not
     * this controller (seeders, imports, tinker).
     */
    private function recalculatedLine(MetreLine $line): MetreLine
    {
        (new RecalculateMetreLineQuantitiesFromComponents($line))->handle();

        return $line->refresh();
    }

    private function componentsOf(MetreLine $metreLine)
    {
        return $metreLine->metreLineComponents()
            ->orderBy('sort_order')
            ->orderBy('sequence_number')
            ->get();
    }

    /**
     * @return array{quantity: float|null, quantity_ordered: float|null, has_components: bool, computed: array<string, float>}
     */
    private function lineQuantities(MetreLine $line): array
    {
        return [
            'quantity' => $line->quantity === null ? null : (float) $line->quantity,
            'quantity_ordered' => $line->quantity_ordered === null ? null : (float) $line->quantity_ordered,
            'has_components' => $line->hasComponents(),
            'computed' => [
                'price_total_sales_no_options' => $line->price_total_sales_no_options,
                'price_total_ordered_no_options' => $line->price_total_ordered_no_options,
                'price_total_gain_no_options' => $line->price_total_gain_no_options,
            ],
        ];
    }
}
