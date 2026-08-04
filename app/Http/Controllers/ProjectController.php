<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLotRequest;
use App\Http\Requests\StoreMetreRequest;
use App\Models\Lot;
use App\Models\Metre;
use App\Services\ShakeDesign\ShakeDesignClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A ShakeDesign project's own métrés and lots - both are local, project_id being the only
 * link back to ShakeDesign (PRJ_Projects has no local table). An unknown or unreachable
 * project still renders: its métrés and lots, if any exist locally, are more useful to show
 * than a hard failure, and there is no local way to tell "project truly does not exist" apart
 * from "ShakeDesign could not be reached right now" - see ProjectMetreController for the same
 * reasoning on the machine-to-machine endpoint this page's list mirrors.
 */
class ProjectController extends Controller
{
    public function show(string $project, ShakeDesignClient $client): Response
    {
        $remoteProject = $client->findProject($project);

        $metres = Metre::query()
            ->where('project_id', $project)
            ->orderBy('ind_project')
            ->orderBy('name')
            ->get();

        $lots = Lot::query()
            ->where('project_id', $project)
            ->orderBy('code')
            ->get();

        return Inertia::render('Projects/Show', [
            'project' => [
                'id' => $project,
                'name' => $remoteProject['Name'] ?? null,
            ],
            'metres' => $metres->map(fn (Metre $metre) => $this->metreRow($metre))->values(),
            'lots' => $lots->map(fn (Lot $lot) => $this->lotRow($lot))->values(),
            'totals' => $this->projectTotals($project),
        ]);
    }

    /**
     * ind_project is allocated here rather than defaulted in the schema: it counts within one
     * project, which no column default can express. In a transaction because the allocation
     * reads the current maximum under a lock - see Metre::nextIndProject().
     */
    public function storeMetre(StoreMetreRequest $request, string $project): RedirectResponse
    {
        DB::transaction(fn () => Metre::forceCreate([
            'project_id' => $project,
            'name' => $request->validated('name'),
            'ind_project' => Metre::nextIndProject($project),
        ]));

        return redirect()->route('projects.show', $project);
    }

    /**
     * The same fields the "manage lots" panel edits afterwards (code, the three language
     * titles, the assigned supplier/contact) - a blank row in that panel, not a separate,
     * narrower form.
     *
     * Returns the created row as JSON rather than redirecting: the panel that calls this
     * appends it to its own list rather than reloading the page.
     */
    public function storeLot(StoreLotRequest $request, string $project): JsonResponse
    {
        $lot = Lot::forceCreate([
            'project_id' => $project,
            'code' => $request->validated('code'),
            'title_fr' => $request->validated('title_fr'),
            'title_en' => $request->validated('title_en'),
            'title_nl' => $request->validated('title_nl'),
            'company_id' => $request->validated('company_id'),
            'contact_id' => $request->validated('contact_id'),
        ]);

        return response()->json(['data' => $this->lotRow($lot)], 201);
    }

    /**
     * `ind_project` is what the page shows as the métré's ID: its number within this project,
     * from 1 up. The UUID stays in the payload because every link on the row is keyed on it,
     * but it is never displayed - it is a ShakeDesign key, not a number anyone reads out.
     *
     * Null for a métré created before the numbering existed and never backfilled; the page
     * shows an em dash rather than inventing a number that would collide with a real one.
     *
     * @return array{
     *     id: string, ind_project: ?int, name: ?string, ratio: ?float,
     *     date_creation: ?string, date_agreement: ?string,
     *     is_accepted_b: bool, is_status_site_b: bool,
     *     total_offers: ?float, total_ordered: ?float, total_works: ?float, total_gain: ?float,
     * }
     */
    private function metreRow(Metre $metre): array
    {
        return [
            'id' => $metre->id,
            'ind_project' => $metre->ind_project === null ? null : (int) $metre->ind_project,
            'name' => $metre->name,

            'ratio' => $metre->ratio(),
            'date_creation' => $metre->date_creation?->toDateString(),
            'date_agreement' => $metre->date_agreement?->toDateString(),
            'is_accepted_b' => (bool) $metre->is_accepted_b,
            'is_status_site_b' => (bool) $metre->is_status_site_b,

            // MET_Metre::Tot_Sum_TotalSalesOffer_cU - unconditional, the amount quoted to
            // the client (the offer), unlike Tot_Sum_TotalSales_Stored which is gated on
            // isStatus_Site_b and answers a different question ("sales once on site").
            'total_offers' => $this->decimal($metre->tot_sum_total_sales_offer_stored),

            // MET_Metre::Tot_Sum_TotalSales_Stored - "commandes" here, confirmed against the
            // source rather than Tot_Sum_TotalOrdered_Stored, which is "travaux" below.
            'total_ordered' => $this->decimal($metre->tot_sum_total_sales_stored),

            // MET_Metre::Tot_Sum_TotalOrdered_Stored - "travaux" here, confirmed against the
            // source rather than Tot_Sum_TotalBuy_Stored.
            'total_works' => $this->decimal($metre->tot_sum_total_ordered_stored),

            // MET_Metre::Tot_Sum_TotalGain_Stored - sales minus ordered.
            'total_gain' => $this->decimal($metre->tot_sum_total_gain_stored),
        ];
    }

    /**
     * `title` is the resolved display title (title_custom ?: title_fr ?: ...) the sidebar
     * link uses; the "manage lots" panel edits the underlying language fields directly,
     * title_custom included but not shown - this screen never writes it, so a lot managed
     * only from here keeps resolving its title from whichever of title_fr/en/nl is filled.
     *
     * company_name / contact_name come from the denormalized `_ae` snapshot columns, written
     * by LotController when the id changes. That is what lets this list show names without a
     * ShakeDesign call per lot - the picker stores the zkp, the snapshot carries the label.
     *
     * @return array{
     *     id: string, code: ?int, title: ?string,
     *     title_fr: ?string, title_en: ?string, title_nl: ?string,
     *     company_id: ?string, company_name: ?string,
     *     contact_id: ?string, contact_name: ?string,
     * }
     */
    private function lotRow(Lot $lot): array
    {
        return [
            'id' => $lot->id,
            'code' => $lot->code,
            'title' => $lot->title_custom ?: $lot->title_fr ?: $lot->title_en ?: $lot->title_nl,
            'title_fr' => $lot->title_fr,
            'title_en' => $lot->title_en,
            'title_nl' => $lot->title_nl,
            'company_id' => $lot->company_id,
            'company_name' => $lot->cpy_name_ae,
            'contact_id' => $lot->contact_id,
            'contact_name' => $lot->ctc_name_ae,
        ];
    }

    /**
     * The project-wide sums of the same three métré columns ("commandes", "travaux",
     * "gains") shown per row - every métré of the project, not just the ones with a
     * non-null total, since a métré not yet on site (isStatus_Site_b false) contributes 0
     * to a sum the same way FileMaker's Sum() treats an empty operand as 0.
     *
     * total_ratio is not a column anywhere: it is Metre::ratio()'s own formula (Σ sales /
     * Σ purchase, from the same two _metl_Stored inputs) applied across every métré of the
     * project rather than one - an extension of the per-métré figure, not a transcription
     * of a source calculation.
     *
     * @return array{total_ordered: float, total_works: float, total_gain: float, total_ratio: ?float}
     */
    private function projectTotals(string $project): array
    {
        $row = Metre::query()
            ->where('project_id', $project)
            ->selectRaw('
                COALESCE(SUM(tot_sum_total_sales_stored), 0) AS total_ordered,
                COALESCE(SUM(tot_sum_total_ordered_stored), 0) AS total_works,
                COALESCE(SUM(tot_sum_total_gain_stored), 0) AS total_gain,
                SUM(total_sales_metl_stored) AS sum_sales_metl,
                SUM(total_purchase_metl_stored) AS sum_purchase_metl
            ')
            ->first();

        return [
            'total_ordered' => (float) $row->total_ordered,
            'total_works' => (float) $row->total_works,
            'total_gain' => (float) $row->total_gain,
            'total_ratio' => Metre::ratioFromSums($row->sum_sales_metl, $row->sum_purchase_metl),
        ];
    }

    private function decimal(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
