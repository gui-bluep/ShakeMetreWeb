<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Metre extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'is_accepted_b' => 'boolean',
            'is_status_site_b' => 'boolean',
            'is_imported_b' => 'boolean',
            'is_locked_b' => 'boolean',
            'is_archived_b' => 'boolean',
            'date_creation' => 'date',
            'date_agreement' => 'date',
            'prog_progress_update_date' => 'date',
            'date_time_update_calcs_stored' => 'datetime',
        ];
    }

    public function carts(): HasMany
    {
        return $this->hasMany(Cart::class);
    }

    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class);
    }

    public function metreLines(): HasMany
    {
        return $this->hasMany(MetreLine::class);
    }

    /**
     * MET_Metre::Ratio_c, not stored - there is no ratio column:
     *
     *   Let ( [ _sales = Total_Sales_METL_Stored ; _purchase = Total_Purchase_METL_Stored ] ;
     *     Case ( not IsEmpty ( _sales ) and not IsEmpty ( _purchase ) ;
     *       Round ( _sales / _purchase ; 2 ) ; "" ) )
     *
     * FileMaker returns empty when either operand is empty, so null here. Division by zero
     * is guarded the same way: the source formula only checks for emptiness because
     * FileMaker yields an error rather than a value, which surfaces as empty in the portal.
     *
     * Both operands are written by RecalculateMetreTotals - Σ of the lines' sales and Σ of the
     * lines' purchases - on an assumption stated in full there, the export carrying no formula
     * for either. Nothing wrote them before, which is why this ratio read empty everywhere.
     *
     * The single source of truth for this formula, read by the métré page ("Ratio réel") and
     * by the ShakeDesign portal replica (ProjectMetreResource) rather than each keeping its own
     * copy. The project page's Ratio column is a DIFFERENT division - Commandes / Travaux, see
     * ProjectController::metreRow() - and deliberately does not come through here.
     */
    /**
     * La répartition par lot de la carte « Fournisseur » - le portail `Prj_LOT__` de `MET_Form`.
     *
     * Relevé sur le fichier hébergé : la mise en page du métré montre les lots avec leur société
     * fournisseur (`LOT::CPY_Name_ae`) et, pour chacun, les montants DE CE MÉTRÉ ; puis trois
     * totaux, dont les formules sont
     *
     *     Tot_LotAssignedBuy_cU     Sum ( METL::LOT_AmountAssignedBuy )
     *     Tot_LotNotAssignedBuy_cU  Sum ( METL::LOT_AmountNotAssignedBuy )
     *     Tot_LotAssignedOrdered_cU Sum ( METL::LOT_AmountAssignedOrder )
     *
     * avec, par ligne,
     *
     *     LOT_AmountAssignedBuy     Case ( not IsEmpty ( zkf_LOT ) ; PriceTotalBuy_noOptions_c ; 0 )
     *     LOT_AmountNotAssignedBuy  Case ( IsEmpty ( zkf_LOT ) ; PriceTotalBuy_noOptions_c ; 0 )
     *     LOT_AmountAssignedOrder   Case ( not IsEmpty ( zkf_LOT ) ; PriceTotalOrdered_noOptions_c ; 0 )
     *
     * Le garde est donc « la ligne A un lot », et non « son lot a une société » - ce dernier est
     * celui de `GainOnPurchases_c`, qui répond à une autre question. Un lot sans fournisseur compte
     * ici comme assigné.
     *
     * Une seule requête agrégée, groupée par lot : la carte n'a pas à charger les lignes.
     */
    public function lotBreakdown(?string $language = null): array
    {
        $rows = DB::table('metre_lines')
            ->leftJoin('lots', 'metre_lines.lot_id', '=', 'lots.id')
            ->where('metre_lines.metre_id', $this->getKey())
            ->groupBy('metre_lines.lot_id', 'lots.code', 'lots.title_custom', 'lots.title_fr', 'lots.title_en', 'lots.title_nl', 'lots.cpy_name_ae')
            ->selectRaw('
                metre_lines.lot_id,
                lots.code AS lot_code,
                lots.title_custom, lots.title_fr, lots.title_en, lots.title_nl,
                lots.cpy_name_ae AS company,
                COALESCE(SUM('.MetreLine::SQL_BUY_NO_OPTIONS.'), 0) AS buy,
                COALESCE(SUM('.MetreLine::SQL_ORDERED_NO_OPTIONS.'), 0) AS ordered,
                COUNT(*) AS lines_count
            ')
            ->get();

        $assignedBuy = 0.0;
        $unassignedBuy = 0.0;
        $assignedOrdered = 0.0;
        $lots = [];

        foreach ($rows as $row) {
            $buy = (float) $row->buy;
            $ordered = (float) $row->ordered;

            if ($row->lot_id === null) {
                $unassignedBuy += $buy;

                continue;
            }

            $assignedBuy += $buy;
            $assignedOrdered += $ordered;

            $lots[] = [
                'id' => $row->lot_id,
                'code' => $row->lot_code,
                // Même règle de nom que partout où un métré nomme un lot - voir Lot::displayTitle().
                'name' => (new Lot)->forceFill([
                    'title_custom' => $row->title_custom,
                    'title_fr' => $row->title_fr,
                    'title_en' => $row->title_en,
                    'title_nl' => $row->title_nl,
                ])->displayTitle($language ?? $this->language),
                'company' => $row->company,
                'buy' => round($buy, 2),
                'ordered' => round($ordered, 2),
                'lines_count' => (int) $row->lines_count,
            ];
        }

        // Par code, comme toute liste de lots ; les lots sans code en dernier.
        usort($lots, fn (array $a, array $b) => [$a['code'] === null, (int) $a['code']] <=> [$b['code'] === null, (int) $b['code']]);

        return [
            'assigned_buy' => round($assignedBuy, 2),
            'unassigned_buy' => round($unassignedBuy, 2),
            'assigned_ordered' => round($assignedOrdered, 2),
            'lots' => $lots,
        ];
    }

    public function ratio(): ?float
    {
        return self::ratioFromSums($this->total_sales_metl_stored, $this->total_purchase_metl_stored);
    }

    /**
     * The Ratio_c guard on its own (empty or zero denominator yields null, not an error or 0),
     * kept separate from ratio() so the rule is stated in one place and can divide operands
     * that are not a single record's two columns: the project page divides its own pair
     * (Commandes / Travaux) and the project total divides the sums of that pair, both by
     * exactly this rule. There is no FileMaker field for a total-row ratio.
     */
    public static function ratioFromSums(null|int|float|string $sales, null|int|float|string $purchase): ?float
    {
        if ($sales === null || $purchase === null || (float) $purchase === 0.0) {
            return null;
        }

        return round((float) $sales / (float) $purchase, 2);
    }

    /**
     * The next `ind_project` for a project: MET_Metre::IndProject, the métré's number *within
     * its own project*, starting at 1 and restarting at 1 for the next project. It is the
     * identity the project page shows as "ID" - the UUID is a ShakeDesign key, unreadable and
     * not project-scoped.
     *
     * The rule is stated by the user, not read off the export: IndProject is a plain Number
     * field with no calculation, and no formula in the whole export references it, so one of
     * the 274 scripts maintained it and script bodies are absent (`Has_DDR_INFO="False"`).
     *
     * A number that has been handed out is never handed out again, deleted or not: a métré's ID
     * is how people refer to it, so three métrés numbered 1, 2, 3 minus the third means the
     * next one is 4, not 3. That cannot be read off the métrés that exist - deleting the last
     * one lowers their maximum - so the high-water mark is kept per project in
     * `metre_number_sequences` and only ever moves up.
     *
     * The mark and the current maximum are both consulted, and the higher wins. The mark alone
     * would miss a métré inserted with an explicit ind_project (an import, or the test suite);
     * the maximum alone is the reuse bug. Together, neither can hand out a live number.
     *
     * Call inside a transaction: the row lock is what stops two simultaneous creations reading
     * the same mark, and the (project_id, ind_project) unique index is the backstop if they do.
     *
     * A métré with no project has no project to be numbered within - only the test suite gets
     * there - and falls back to max + 1 rather than inventing a sequence row keyed on null,
     * which MySQL would not keep unique anyway.
     */
    public static function nextIndProject(?string $projectId): int
    {
        if ($projectId === null) {
            return 1 + (int) static::query()->whereNull('project_id')->lockForUpdate()->max('ind_project');
        }

        $mark = DB::table('metre_number_sequences')
            ->where('project_id', $projectId)
            ->lockForUpdate()
            ->value('last_ind_project');

        $highest = (int) static::query()->where('project_id', $projectId)->max('ind_project');
        $next = max((int) $mark, $highest) + 1;

        DB::table('metre_number_sequences')->upsert(
            [[
                'project_id' => $projectId,
                'last_ind_project' => $next,
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['project_id'],
            ['last_ind_project', 'updated_at'],
        );

        return $next;
    }

    /**
     * Cross-system reference: resolved via ShakeDesignClient::findProject() against
     * ShakeDesign's PRJ_Projects. Not a local Eloquent relation.
     */
    public function projectId(): ?string
    {
        return $this->project_id;
    }

    /**
     * Cross-system reference: resolved via ShakeDesignClient against ShakeDesign's
     * OFF_Offers. Not a local Eloquent relation.
     */
    public function offerId(): ?string
    {
        return $this->offer_id;
    }
}
