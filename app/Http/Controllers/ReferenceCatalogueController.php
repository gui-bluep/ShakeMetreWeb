<?php

namespace App\Http\Controllers;

use App\Models\Reference;
use Illuminate\Http\JsonResponse;

/**
 * The reference catalogue - REF_Reference / REFS_SubReference / REFSL_SubReferenceLines - as the
 * three-level tree the FileMaker browser presents: section, sub-section, priced item.
 *
 * Sent whole rather than one level per request. The live file holds 19 references, 118
 * sub-references and 507 items; that is one small payload, and it makes the picker instant
 * instead of chatty. Read-only: this application does not yet maintain the catalogue, which is
 * still edited in FileMaker (REF_New / REFS_New / REFSL_New).
 *
 * Titles are the French ones, because the interface is French. The language that matters is the
 * one used when an item BECOMES a line, and that one is the métré's - applied server-side, see
 * MetreLineDetailController::storeFromCatalogue().
 */
class ReferenceCatalogueController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $references = Reference::query()
            ->with([
                'subReferences' => fn ($query) => $query->orderBy('code'),
                'subReferences.subReferenceLines' => fn ($query) => $query->orderBy('code'),
            ])
            ->orderBy('code')
            ->get();

        return response()->json([
            'data' => $references->map(fn (Reference $reference) => [
                'id' => $reference->id,
                'code' => $reference->code,
                'title' => $reference->localisedTitle('fr'),
                'sub_references' => $reference->subReferences->map(fn ($subReference) => [
                    'id' => $subReference->id,
                    'code' => $subReference->code,
                    'title' => $subReference->localisedTitle('fr'),
                    'lines' => $subReference->subReferenceLines->map(fn ($line) => [
                        'id' => $line->id,
                        'code' => $line->code,
                        'title' => $line->localisedTitle('fr'),
                        'unit' => $line->unit,
                        'price' => $line->price === null ? null : (float) $line->price,
                    ])->values(),
                ])->values(),
            ])->values(),
        ]);
    }
}
