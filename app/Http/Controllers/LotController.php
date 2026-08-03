<?php

namespace App\Http\Controllers;

use App\Actions\SelectTenderSupplier;
use App\Exceptions\TenderSupplierMismatchException;
use App\Http\Requests\AwardTenderSupplierRequest;
use App\Http\Requests\UpdateLotRequest;
use App\Http\Resources\TenderMetreLineResource;
use App\Models\Lot;
use App\Models\MetreLine;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * The supplier tender comparison screen for one lot: weighting, quotes, and the scores that
 * decide an award.
 *
 * Every score shown here is read from App\Models\Lot - sumForSupplier, bestPricePercentage,
 * priceScore, finalScore - already transcribed formula-by-formula and covered by
 * TenderScoringTest. Nothing here recomputes a score; this controller only decides which of
 * the five supplier slots are actually assigned, and ranks them.
 */
class LotController extends Controller
{
    public function show(Lot $lot): Response
    {
        $slots = $this->assignedSlots($lot);

        $lines = $lot->metreLines()
            ->where('is_tender_line_b', true)
            ->orderBy('sort_order')
            ->orderBy('sequence_number')
            ->get();

        return Inertia::render('Lots/TenderComparison', [
            ...$this->weightingAndScoring($lot),
            'suppliers' => $slots->map(fn (int $slot) => [
                'slot' => $slot,
                'company_id' => $lot->{"tender_supplier_{$slot}_id"},
            ])->values(),
            'lines' => TenderMetreLineResource::collection($lines)->resolve(),
        ]);
    }

    /**
     * Refreshes the weighting header and the scoring panel after a change made elsewhere -
     * a quote's price or quantity, edited through PATCH /api/metre-lines/{id} - without
     * resending the whole page. Read-only: available to a readonly account like the rest of
     * the comparison screen.
     */
    public function scoring(Lot $lot): JsonResponse
    {
        return response()->json(['data' => $this->weightingAndScoring($lot)]);
    }

    public function update(UpdateLotRequest $request, Lot $lot): JsonResponse
    {
        $lot->forceFill($request->safe()->only(UpdateLotRequest::editable()))->save();

        return response()->json(['data' => $this->weightingAndScoring($lot->refresh())]);
    }

    /**
     * "Retenir ce fournisseur." SelectTenderSupplier does the one write that matters
     * (lots.company_id) and the one check that matters (the company actually holds that
     * slot); this only turns its exceptions into a response the button can show as-is,
     * rather than a generic error.
     */
    public function award(AwardTenderSupplierRequest $request, Lot $lot): JsonResponse
    {
        try {
            (new SelectTenderSupplier)->handle(
                $lot,
                (int) $request->validated('supplier_number'),
                (string) $request->validated('company_id'),
            );
        } catch (InvalidArgumentException|TenderSupplierMismatchException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->weightingAndScoring($lot->refresh())]);
    }

    /**
     * @return array{lot: array, scoring: array}
     */
    private function weightingAndScoring(Lot $lot): array
    {
        $slots = $this->assignedSlots($lot);

        return [
            'lot' => $this->lotPayload($lot),
            'scoring' => $this->scoringPayload($lot, $slots),
        ];
    }

    /**
     * @return Collection<int, int>
     */
    private function assignedSlots(Lot $lot): Collection
    {
        return collect(MetreLine::SUPPLIER_SLOTS)
            ->filter(fn (int $slot) => filled($lot->{"tender_supplier_{$slot}_id"}))
            ->values();
    }

    private function lotPayload(Lot $lot): array
    {
        return [
            'id' => $lot->id,
            'code' => $lot->code,
            'title' => $lot->title_custom ?: $lot->title_fr ?: $lot->title_en ?: $lot->title_nl,
            'company_id' => $lot->companyId(),
            'weighting' => [
                'price' => $this->number($lot->tender_weighting_price),
                'criteria' => collect(range(1, 5))->mapWithKeys(fn (int $criterion) => [
                    $criterion => [
                        'weight' => $lot->{"tender_weighting_crit{$criterion}"},
                        'description' => $lot->{"tender_weighting_crit{$criterion}_description"},
                        'notes' => collect(MetreLine::SUPPLIER_SLOTS)->mapWithKeys(fn (int $supplier) => [
                            $supplier => $lot->{"tender_weighting_crit{$criterion}_supp{$supplier}"},
                        ])->all(),
                    ],
                ])->all(),
            ],
        ];
    }

    /**
     * @param  Collection<int, int>  $slots
     */
    private function scoringPayload(Lot $lot, Collection $slots): array
    {
        $scores = $slots->mapWithKeys(fn (int $slot) => [
            $slot => [
                'sum' => $lot->sumForSupplier($slot),
                'best_price_percentage' => $lot->bestPricePercentageForSupplier($slot),
                'price_score' => $lot->priceScoreForSupplier($slot),
                'final_score' => $lot->finalScoreForSupplier($slot),
            ],
        ])->all();

        // Ranked among assigned suppliers only - an unassigned slot has no quotes and no
        // meaning to rank alongside ones that actually bid.
        $ranked = collect($scores)->sortByDesc('final_score')->keys()->values();

        foreach ($ranked as $index => $slot) {
            $scores[$slot]['rank'] = $index + 1;
        }

        return [
            'best_price_sum' => $lot->bestPriceSum(),
            'suppliers' => $scores,
        ];
    }

    private function number(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
