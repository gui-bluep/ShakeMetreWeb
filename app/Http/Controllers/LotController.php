<?php

namespace App\Http\Controllers;

use App\Actions\SelectTenderSupplier;
use App\Exceptions\TenderSupplierMismatchException;
use App\Http\Requests\AwardTenderSupplierRequest;
use App\Http\Requests\UpdateLotRequest;
use App\Http\Resources\TenderMetreLineResource;
use App\Models\Lot;
use App\Models\MetreLine;
use App\Services\ShakeDesign\ShakeDesignApiException;
use App\Services\ShakeDesign\ShakeDesignClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
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
 *
 * update() is also the write path for the project page's "manage lots" panel (title and
 * code): one Lot, one PATCH endpoint, one whitelist (UpdateLotRequest) - not a second
 * endpoint that would let the two screens disagree about what is editable.
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

    public function update(UpdateLotRequest $request, Lot $lot, ShakeDesignClient $client): JsonResponse
    {
        $changes = $request->safe()->only(UpdateLotRequest::editable());
        $companyChanged = array_key_exists('company_id', $changes)
            && trim((string) $changes['company_id']) !== trim((string) $lot->company_id);

        $lot->forceFill($changes);

        /*
         * Contacts are company-scoped (JCPYCTC), so a contact chosen for the previous company
         * is not necessarily linked to the new one - keeping it would leave the lot pointing at
         * a contact that does not belong to its supplier. Cleared unless the same request also
         * names a contact explicitly, which is the caller replacing both at once.
         */
        if ($companyChanged && ! array_key_exists('contact_id', $changes)) {
            $lot->contact_id = null;
        }

        $this->syncDenormalizedNames($lot, $client);

        $lot->save();

        return response()->json(['data' => $this->weightingAndScoring($lot->refresh())]);
    }

    /**
     * Keeps lots.cpy_name_ae / ctc_name_ae in step with the ids they describe.
     *
     * The name is resolved server-side from the id rather than accepted from the client, so
     * the stored snapshot cannot disagree with the key it is a snapshot of - and neither
     * column is in UpdateLotRequest's whitelist, so a caller cannot write one directly.
     * FileMaker keeps the same pair of auto-enter columns (`_ae`) for the same reason.
     *
     * Only touched when the id actually changed, so editing the tender weighting never calls
     * ShakeDesign. A lookup failure leaves the name empty and is logged rather than failing
     * the write: the id is the part that matters for integrity, the name is for display.
     */
    private function syncDenormalizedNames(Lot $lot, ShakeDesignClient $client): void
    {
        if ($lot->isDirty('company_id')) {
            $lot->cpy_name_ae = $this->resolveName(
                fn () => $client->findCompany((string) $lot->company_id),
                fn (array $company) => trim((string) ($company['Name'] ?? '')) ?: null,
                $lot->company_id,
                'company',
            );
        }

        if ($lot->isDirty('contact_id')) {
            $lot->ctc_name_ae = $this->resolveName(
                fn () => $client->findContact((string) $lot->contact_id),
                fn (array $contact) => ShakeDesignClient::contactName($contact),
                $lot->contact_id,
                'contact',
            );
        }
    }

    /**
     * @param  callable(): (array<string, mixed>|null)  $lookup
     * @param  callable(array<string, mixed>): ?string  $name
     */
    private function resolveName(callable $lookup, callable $name, ?string $id, string $entity): ?string
    {
        if ($id === null || trim($id) === '') {
            return null;
        }

        try {
            $record = $lookup();
        } catch (ShakeDesignApiException $e) {
            Log::warning("Could not resolve the ShakeDesign {$entity} name for a lot; storing it without.", [
                'entity' => $entity,
                'zkp' => $id,
                'reason' => $e->getMessage(),
            ]);

            return null;
        }

        return $record === null ? null : $name($record);
    }

    /**
     * Refused rather than cascaded or detached when the lot still has metre_lines: a tender
     * line's lot_id is data (which lot it was compared under), and silently orphaning that
     * on delete would be a quieter loss than refusing outright and saying why.
     */
    public function destroy(Lot $lot): JsonResponse
    {
        if ($lot->metreLines()->exists()) {
            return response()->json([
                'message' => 'Ce lot contient des lignes de métré et ne peut pas être supprimé.',
            ], 422);
        }

        $lot->delete();

        return response()->json(status: 204);
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
