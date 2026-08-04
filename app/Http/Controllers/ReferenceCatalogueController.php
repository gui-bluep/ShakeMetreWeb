<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveReferenceRequest;
use App\Http\Requests\SaveSubReferenceLineRequest;
use App\Models\MetreLine;
use App\Models\Reference;
use App\Models\SubReference;
use App\Models\SubReferenceLine;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The reference catalogue - REF_Reference / REFS_SubReference / REFSL_SubReferenceLines - as the
 * three-level tree the FileMaker browser presents: section, sub-section, priced item.
 *
 * Reading and writing it are the same screen, as they are in the source (REF_Form / REF_List with
 * their three tabs). Creation follows REF_New: an empty row appears at the right level and is
 * filled in afterwards, so filling it is the ordinary debounced edit rather than a second code
 * path - the same reasoning as the lot manager's "add".
 *
 * Deletion follows REF_Delete and cascades, with the confirmation the source words per level:
 * deleting a section deletes its sub-sections and their items. What it does NOT do is touch a
 * métré: a line keeps its own copy of the section it was filed under (see
 * MetreLineObserver::syncSectionSnapshot()), so a catalogue entry can be retired without
 * rewriting documents already sent to a client. The provenance keys on those lines are cleared,
 * because after the delete there is nothing left for them to point at.
 *
 * Titles are served in French, the language of the interface. The language that matters is the
 * one used when an item BECOMES a line, and that one is the métré's - applied server-side, see
 * MetreLineDetailController::storeFromCatalogue().
 */
class ReferenceCatalogueController extends Controller
{
    public function page(): Response
    {
        return Inertia::render('References/Index', [
            'references' => $this->tree(),
        ]);
    }

    /**
     * The same tree as JSON, for the "add from catalogue" picker of the line views.
     *
     * Sent whole rather than one level per request: the live file holds 19 sections, 118
     * sub-sections and 507 items, which is one small payload, and it makes the picker instant
     * instead of chatty.
     */
    public function catalogue(): JsonResponse
    {
        return response()->json(['data' => $this->tree()]);
    }

    // --- sections -----------------------------------------------------------------------------

    public function storeReference(): JsonResponse
    {
        $reference = new Reference;
        $reference->code = $this->nextCode(Reference::query(), 10);
        $reference->save();

        return response()->json(['data' => $this->referenceRow($reference->fresh())], 201);
    }

    public function updateReference(SaveReferenceRequest $request, Reference $reference): JsonResponse
    {
        $reference->forceFill($request->safe()->only(SaveReferenceRequest::EDITABLE))->save();

        return response()->json(['data' => $this->referenceRow($reference->fresh())]);
    }

    public function destroyReference(Reference $reference): JsonResponse
    {
        DB::transaction(function () use ($reference) {
            $subReferenceIds = $reference->subReferences()->pluck('id')->all();
            $lineIds = SubReferenceLine::query()->where('reference_id', $reference->id)->pluck('id')->all();

            $this->clearProvenance($lineIds, $subReferenceIds, [$reference->id]);

            SubReferenceLine::query()->where('reference_id', $reference->id)->delete();
            SubReference::query()->whereIn('id', $subReferenceIds)->delete();
            $reference->delete();
        });

        return response()->json(status: 204);
    }

    // --- sub-sections -------------------------------------------------------------------------

    public function storeSubReference(Reference $reference): JsonResponse
    {
        $subReference = new SubReference;
        $subReference->reference_id = $reference->getKey();
        $subReference->code = $this->nextCode($reference->subReferences(), 1);
        $subReference->save();

        return response()->json(['data' => $this->subReferenceRow($subReference->fresh())], 201);
    }

    public function updateSubReference(SaveReferenceRequest $request, SubReference $subReference): JsonResponse
    {
        $subReference->forceFill($request->safe()->only(SaveReferenceRequest::EDITABLE))->save();

        return response()->json(['data' => $this->subReferenceRow($subReference->fresh())]);
    }

    public function destroySubReference(SubReference $subReference): JsonResponse
    {
        DB::transaction(function () use ($subReference) {
            $this->clearProvenance($subReference->subReferenceLines()->pluck('id')->all(), [$subReference->id], []);

            $subReference->subReferenceLines()->delete();
            $subReference->delete();
        });

        return response()->json(status: 204);
    }

    // --- items --------------------------------------------------------------------------------

    public function storeSubReferenceLine(SubReference $subReference): JsonResponse
    {
        $line = new SubReferenceLine;
        $line->sub_reference_id = $subReference->getKey();
        // REFSL carries the grandparent key as well as the parent one, exactly as the source does.
        $line->reference_id = $subReference->reference_id;
        $line->code = $this->nextCode($subReference->subReferenceLines(), 1);
        $line->save();

        return response()->json(['data' => $this->lineRow($line->fresh())], 201);
    }

    public function updateSubReferenceLine(
        SaveSubReferenceLineRequest $request,
        SubReferenceLine $subReferenceLine,
    ): JsonResponse {
        $subReferenceLine->forceFill($request->safe()->only(SaveSubReferenceLineRequest::EDITABLE))->save();

        return response()->json(['data' => $this->lineRow($subReferenceLine->fresh())]);
    }

    public function destroySubReferenceLine(SubReferenceLine $subReferenceLine): JsonResponse
    {
        DB::transaction(function () use ($subReferenceLine) {
            $this->clearProvenance([$subReferenceLine->id], [], []);
            $subReferenceLine->delete();
        });

        return response()->json(status: 204);
    }

    // --- helpers ------------------------------------------------------------------------------

    /**
     * The next code at one level: the highest plus ten for a section, plus one below it.
     *
     * Ten, because that is how the live catalogue is numbered - sections run 00, 10, 20, 30, 40 -
     * and those gaps are what let a new chapter be filed between two existing ones without
     * renumbering everything after it. Sub-sections and items are numbered one by one there.
     */
    private function nextCode(mixed $query, int $step): int
    {
        return ((int) $query->max('code')) + $step;
    }

    /**
     * A métré line keeps the section it was filed under, but not a pointer to a catalogue entry
     * that no longer exists. Cleared with a plain update rather than a foreign-key rule, so the
     * cascade stays visible where it happens - the same reasoning as deleting a métré.
     *
     * @param  list<string>  $lineIds
     * @param  list<string>  $subReferenceIds
     * @param  list<string>  $referenceIds
     */
    private function clearProvenance(array $lineIds, array $subReferenceIds, array $referenceIds): void
    {
        if ($lineIds !== []) {
            MetreLine::query()->whereIn('sub_reference_line_id', $lineIds)
                ->update(['sub_reference_line_id' => null]);
        }

        if ($subReferenceIds !== []) {
            MetreLine::query()->whereIn('sub_reference_id', $subReferenceIds)
                ->update(['sub_reference_id' => null]);
        }

        if ($referenceIds !== []) {
            MetreLine::query()->whereIn('reference_id', $referenceIds)
                ->update(['reference_id' => null]);
        }
    }

    /** @return list<array<string, mixed>> */
    private function tree(): array
    {
        return Reference::query()
            ->with([
                'subReferences' => fn ($query) => $query->orderBy('code'),
                'subReferences.subReferenceLines' => fn ($query) => $query->orderBy('code'),
            ])
            ->orderBy('code')
            ->get()
            ->map(fn (Reference $reference) => $this->referenceRow($reference) + [
                'sub_references' => $reference->subReferences
                    ->map(fn (SubReference $subReference) => $this->subReferenceRow($subReference) + [
                        'lines' => $subReference->subReferenceLines
                            ->map(fn (SubReferenceLine $line) => $this->lineRow($line))
                            ->values()->all(),
                    ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function referenceRow(Reference $reference): array
    {
        return [
            'id' => $reference->id,
            'code' => $reference->code,
            'title' => $reference->localisedTitle('fr'),
            'title_fr' => $reference->title_fr,
            'title_en' => $reference->title_en,
            'title_nl' => $reference->title_nl,
        ];
    }

    /** @return array<string, mixed> */
    private function subReferenceRow(SubReference $subReference): array
    {
        return [
            'id' => $subReference->id,
            'reference_id' => $subReference->reference_id,
            'code' => $subReference->code,
            'title' => $subReference->localisedTitle('fr'),
            'title_fr' => $subReference->title_fr,
            'title_en' => $subReference->title_en,
            'title_nl' => $subReference->title_nl,
        ];
    }

    /** @return array<string, mixed> */
    private function lineRow(SubReferenceLine $line): array
    {
        return [
            'id' => $line->id,
            'sub_reference_id' => $line->sub_reference_id,
            'code' => $line->code,
            'title' => $line->localisedTitle('fr'),
            'title_fr' => $line->title_fr,
            'title_en' => $line->title_en,
            'title_nl' => $line->title_nl,
            'description_fr' => $line->description_fr,
            'description_en' => $line->description_en,
            'description_nl' => $line->description_nl,
            'unit' => $line->unit,
            'price' => $line->price === null ? null : (float) $line->price,
        ];
    }
}
