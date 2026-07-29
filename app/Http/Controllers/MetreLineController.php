<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateMetreLineRequest;
use App\Http\Resources\MetreLineGridResource;
use App\Models\Metre;
use App\Models\MetreLine;
use App\Models\Reference;
use App\Models\SubReference;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

class MetreLineController extends Controller
{
    /**
     * The editable grid for a metre's lines - the Laravel counterpart of FileMaker's
     * METL_MetreComplete_List_Full layout.
     *
     * Every line is sent in one payload and the client virtualizes the rendering. That is
     * the right trade here: a metre holds hundreds of lines, not millions, and the users
     * this replaces are used to scrolling and re-sorting a whole métré at once, which
     * server-side paging would make worse, not better.
     */
    public function index(Metre $metre): Response
    {
        $lines = $metre->metreLines()
            // Counted rather than probed per row: hasComponents() reads this, so a métré of
            // hundreds of lines costs one extra query instead of one per line.
            ->withCount('metreLineComponents')
            ->orderBy('sort_order')
            ->orderBy('sequence_number')
            ->get();

        return Inertia::render('MetreLines/Index', [
            'metre' => [
                'id' => $metre->id,
                'name' => $metre->name,
                'is_locked_b' => (bool) $metre->is_locked_b,
            ],
            'lines' => MetreLineGridResource::collection($lines)->resolve(),
            'references' => Reference::orderBy('code')->get(['id', 'code', 'title_fr'])->map(fn (Reference $r) => [
                'id' => $r->id,
                'label' => trim(($r->code !== null ? $r->code.' — ' : '').($r->title_fr ?? '')),
            ]),
            // Grouped by reference so the dependent select filters without a round trip.
            'subReferencesByReference' => SubReference::orderBy('code')
                ->get(['id', 'reference_id', 'code', 'title_fr'])
                ->groupBy('reference_id')
                ->map(fn ($group) => $group->map(fn (SubReference $s) => [
                    'id' => $s->id,
                    'label' => trim(($s->code !== null ? $s->code.' — ' : '').($s->title_fr ?? '')),
                ])->values()),
        ]);
    }

    /**
     * Applies one debounced batch of cell edits to a line.
     *
     * Returns the line as stored, including the recomputed derived totals, so the client
     * can replace its optimistic estimate with the authoritative values rather than
     * trusting its own arithmetic.
     */
    public function update(UpdateMetreLineRequest $request, MetreLine $metreLine): JsonResponse
    {
        // METL_METC_UpdateQuantities opens with `If [ met__MET__::isLocked_b ]` -> dialog
        // "Métré verrouillé" -> Exit Script [-1]. The source refuses to write into a locked
        // metre, so this endpoint must too: the grid greys itself out when locked, but that is
        // presentation, not a guarantee. 423 rather than 403 - the record is locked, the
        // caller is not unauthorized.
        abort_if(
            (bool) $metreLine->metre?->is_locked_b,
            423,
            'Ce métré est verrouillé : ses lignes ne peuvent plus être modifiées.',
        );

        // forceFill rather than fill: these models declare no $fillable, and the guarantee
        // lives in UpdateMetreLineRequest instead - the keys have already been whitelisted
        // twice over (rules + rejection of anything outside EDITABLE) before reaching here.
        $metreLine->forceFill($request->safe()->only(UpdateMetreLineRequest::EDITABLE))->save();

        return response()->json([
            'data' => (new MetreLineGridResource($metreLine->refresh()))->resolve(),
        ]);
    }
}
