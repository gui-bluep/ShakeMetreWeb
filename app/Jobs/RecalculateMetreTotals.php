<?php

namespace App\Jobs;

use App\Models\Metre;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Laravel equivalent of the FileMaker MET_UpdateStoredCalcs / MET_UpdateStoredCalcs_PSOS
 * scripts: materializes the MET_Metre aggregate fields over its METL_MetreLines.
 *
 * Every formula below is transcribed from the `calc` field of the corresponding FileMaker
 * calculation in docs/filemaker-reference/ShakeMetre_data_dictionary.json. Stored columns
 * whose source formula is absent from that export are deliberately NOT written here - see
 * the "NOT computed" list at the bottom of this class.
 *
 * Two FileMaker semantics are replicated on purpose:
 *  - `Case ( flag ; Sum (...) ; "" )` yields EMPTY (not zero) when the flag is false, so the
 *    corresponding column is set to null rather than 0.0.
 *  - `Round ( price * qty ; 2 )` happens per line and only then is summed, so the rounding
 *    is applied inside the aggregate, not to the total.
 */
class RecalculateMetreTotals implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /**
     * A burst of line edits (import, mass re-order, cascade delete) queues one job per line;
     * they all recompute the same thing from current DB state, so collapsing duplicates that
     * are still waiting is safe. The lock is released once processing starts, so an edit made
     * during a run still queues a fresh job.
     */
    public int $uniqueFor = 300;

    /**
     * If the metre itself was deleted (cascade delete of its lines), silently discard.
     */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public Metre $metre) {}

    public function uniqueId(): string
    {
        return $this->metre->getKey();
    }

    public function handle(): void
    {
        $lineTotals = $this->aggregateLineTotals();

        // MET_Metre::Tot_Sum_TotalSales_cU
        //   Case ( IsStatus_Site_b ; Sum ( metl__METL__::PriceTotalSales_noOptions_c ) ; "" )
        $salesStored = $this->metre->is_status_site_b ? (float) $lineTotals->sales_no_options : null;

        // MET_Metre::Tot_Sum_TotalOrdered_cU
        //   Case ( IsStatus_Site_b ; Sum ( metl__METL__::PriceTotalOrdered_noOptions_c ) ; "" )
        $orderedStored = $this->metre->is_status_site_b ? (float) $lineTotals->ordered_no_options : null;

        // MET_Metre::Tot_Sum_TotalBuy_cU
        //   Case ( isAccepted_b ; Sum ( metl__METL__::PriceTotalBuy_noOptions_c ) ; "" )
        //   Note: gated on isAccepted_b, NOT IsStatus_Site_b like its siblings.
        $buyStored = $this->metre->is_accepted_b ? (float) $lineTotals->buy_no_options : null;

        // MET_Metre::Tot_Sum_TotalGain_cU
        //   Case ( IsStatus_Site_b ; Sum ( metl__METL__::PriceTotalGain_noOptions_c ) ; "" )
        $gainStored = $this->metre->is_status_site_b ? (float) $lineTotals->gain_no_options : null;

        // MET_Metre::Tot_Sum_TotalFees_Stored
        //   zsm_SumTotalSales_Stored - zsm_SumTotalOrdered_Stored
        // Those two are Summary fields with operation="Total" over Tot_Sum_TotalSales_Stored /
        // Tot_Sum_TotalOrdered_Stored on MET_Metre itself; evaluated inside a single record's
        // calculation they resolve to that record's own value, so the columns substitute
        // directly. An empty operand behaves as 0 in FileMaker arithmetic.
        $feesStored = ($salesStored ?? 0.0) - ($orderedStored ?? 0.0);

        // MET_Metre::Tot_LotAssigned_GainOnPurchase_cU
        //   Case ( IsStatus_Site_b ; Sum ( metl__METL__::____TBDEL__GainOnPurchases_cU_v2 ) ; "" )
        //   That line field is flagged TBDEL but carries the same formula as the surviving
        //   METL_MetreLines::GainOnPurchases_c, which is what is transcribed below.
        $lotGainStored = $this->metre->is_status_site_b ? (float) $lineTotals->lot_assigned_gain : null;

        $values = [
            'tot_sum_total_sales_stored' => $salesStored,
            'tot_sum_total_ordered_stored' => $orderedStored,
            'tot_sum_total_buy_stored' => $buyStored,
            'tot_sum_total_gain_stored' => $gainStored,
            'tot_lot_assigned_gain_on_purchase_stored' => $lotGainStored,
            'tot_sum_total_fees_stored' => $feesStored,

            // MET_Metre::Tot_Percentage_TotalFees_Stored
            //   (Tot_Sum_TotalFees_Stored / zsm_SumTotalSales_Stored) * 100
            'tot_percentage_total_fees_stored' => $this->divide($feesStored, $salesStored, 100),

            // MET_Metre::Tot_Ratio_TotalFees_Stored
            //   ( zsm_SumTotalSales_Stored / zsm_SumTotalOrdered_Stored )
            'tot_ratio_total_fees_stored' => $this->divide($salesStored, $orderedStored),

            // MET_Metre::Tot_Sum_TotalSalesOffer_cU
            //   Sum ( metl__METL__::PriceTotalSales_noOptions_c )
            //   Unconditional - the only sibling with no status gate.
            'tot_sum_total_sales_offer_stored' => (float) $lineTotals->sales_no_options,

            // MET_Metre::PROG_ProgressClientTotal_Amount_Valid_Stored_c
            //   PROG_ProgressClientTotal_Amount_Stored * isAccepted_b
            'prog_progress_client_total_amount_valid_stored_c' => $this->validated(
                $this->metre->prog_progress_client_total_amount_stored,
                $this->metre->is_accepted_b,
            ),

            // MET_Metre::PROG_ProgressClientTotal_Percent_Valid_Stored_c
            //   PROG_ProgressClientTotal_Percent_Stored * isAccepted_b
            'prog_progress_client_total_percent_valid_stored_c' => $this->validated(
                $this->metre->prog_progress_client_total_percent_stored,
                $this->metre->is_accepted_b,
            ),

            // MET_Metre::PROG_ProgressSuppTotal_Amount_Valid_Stored_c
            //   PROG_ProgressSuppTotal_Amount_Stored * IsStatus_Site_b
            'prog_progress_supp_total_amount_valid_stored_c' => $this->validated(
                $this->metre->prog_progress_supp_total_amount_stored,
                $this->metre->is_status_site_b,
            ),

            // MET_Metre::PROG_ProgressSuppTotal_Percent_Valid_Stored_c
            //   PROG_ProgressSuppTotal_Percent_Stored * IsStatus_Site_b
            'prog_progress_supp_total_percent_valid_stored_c' => $this->validated(
                $this->metre->prog_progress_supp_total_percent_stored,
                $this->metre->is_status_site_b,
            ),

            // No formula in the export, but this is the field the FileMaker
            // MET_UpdateStoredCalcs scripts stamp so drift against the live _cU values can
            // be spotted (cf. Tot_Sum_*_TEMP_CHECK_cU). Set here as the recalc marker.
            'date_time_update_calcs_stored' => now(),
        ];

        // Derived write: it must not fire model events (no cascade back into observers) and
        // must not touch updated_at, which tracks genuine edits of the metre itself. The
        // recalc's own stamp is date_time_update_calcs_stored above.
        Metre::withoutTimestamps(fn () => $this->metre->forceFill($values)->saveQuietly());
    }

    /**
     * Sums the per-line calculations that feed the metre totals, in one query.
     *
     * Each expression is the transcription of a METL_MetreLines calculation:
     *   PriceTotalSales_noOptions_c   Case ( isOption_b ; 0 ; Round ( PriceSales * Quantity ; 2 ) )
     *   PriceTotalOrdered_noOptions_c Case ( isOption_b ; 0 ; Round ( PriceOrdered * QuantityOrdered ; 2 ) )
     *   PriceTotalBuy_noOptions_c     Case ( isOption_b ; 0 ; Round ( PriceBuy * Quantity ; 2 ) )
     *   PriceTotalGain_noOptions_c    PriceTotalSales_noOptions_c - PriceTotalOrdered_noOptions_c
     *   GainOnPurchases_c             Case ( not IsEmpty ( metl_LOT__::zkf_CPY ) ;
     *                                     PriceTotalBuy_noOptions_c - PriceTotalOrdered_noOptions_c ; "" )
     *
     * COALESCE mirrors FileMaker treating an empty number as 0 in arithmetic; the outer
     * COALESCE(SUM(...), 0) mirrors Sum() over an empty related set returning 0.
     */
    private function aggregateLineTotals(): object
    {
        $sales = 'CASE WHEN metre_lines.is_option_b = 1 THEN 0 ELSE ROUND(COALESCE(metre_lines.price_sales, 0) * COALESCE(metre_lines.quantity, 0), 2) END';
        $ordered = 'CASE WHEN metre_lines.is_option_b = 1 THEN 0 ELSE ROUND(COALESCE(metre_lines.price_ordered, 0) * COALESCE(metre_lines.quantity_ordered, 0), 2) END';
        $buy = 'CASE WHEN metre_lines.is_option_b = 1 THEN 0 ELSE ROUND(COALESCE(metre_lines.price_buy, 0) * COALESCE(metre_lines.quantity, 0), 2) END';

        // GainOnPurchases_c is gated on the line's LOT having a supplier company assigned.
        $lotAssigned = "lots.company_id IS NOT NULL AND lots.company_id <> ''";

        return DB::table('metre_lines')
            ->leftJoin('lots', 'metre_lines.lot_id', '=', 'lots.id')
            ->where('metre_lines.metre_id', $this->metre->getKey())
            ->selectRaw("
                COALESCE(SUM($sales), 0) AS sales_no_options,
                COALESCE(SUM($ordered), 0) AS ordered_no_options,
                COALESCE(SUM($buy), 0) AS buy_no_options,
                COALESCE(SUM(($sales) - ($ordered)), 0) AS gain_no_options,
                COALESCE(SUM(CASE WHEN $lotAssigned THEN ($buy) - ($ordered) ELSE 0 END), 0) AS lot_assigned_gain
            ")
            ->first();
    }

    /**
     * FileMaker `<amount> * <boolean flag>`: an empty amount multiplied by a flag yields 0,
     * so a never-computed base column resolves to 0 rather than null.
     *
     * The flag is nullable, not just falsy-able. A Metre built by forceCreate() carries only
     * the attributes that were passed, so a boolean column left to its database default reads
     * as null on that instance rather than false - and a strict `bool` parameter turned that
     * into a TypeError the moment this job was handed a freshly-created métré instead of one
     * read back from the database. Null means "not set", which is false here, matching
     * FileMaker treating an empty flag as 0.
     */
    private function validated(?float $amount, ?bool $flag): float
    {
        return ($amount ?? 0.0) * ($flag ? 1 : 0);
    }

    /**
     * Division guarded the way this FileMaker file guards its own ratios: an empty or zero
     * denominator yields empty rather than an evaluation error (cf. the
     * `Case ( not IsEmpty ( _total ) and not _total = 0 ; ... ; "" )` idiom in
     * zsm_ProgressClientTotalAmount_Valid_percentage_cU).
     */
    private function divide(?float $numerator, ?float $denominator, float $scale = 1.0): ?float
    {
        if ($denominator === null || $denominator == 0.0) {
            return null;
        }

        return (($numerator ?? 0.0) / $denominator) * $scale;
    }

    /*
     * NOT computed here - no formula for these exists in ShakeMetre_data_dictionary.json:
     *
     *   PROG_ProgressClientTotal_Amount_Stored / _Percent_Stored
     *   PROG_ProgressSuppTotal_Amount_Stored / _Percent_Stored
     *     Plain Normal fields written by the MET_UpdateStoredCalcs_PROG script, whose steps
     *     are absent from the export. Read as inputs above, never written.
     *
     *   Total_Sales_METL_Stored, Total_Purchase_METL_Stored,
     *   Total_Ordered_METL_Stored, Total_Gain_METL_Stored
     *     Plain Normal fields with no formula anywhere; only consumed (by Ratio_c).
     *
     * The MET_Metre / METL_MetreLines `zsm_*` Summary fields have no columns at all: they are
     * found-set aggregates (portal, list and report totals evaluated at display time), so
     * they belong in a query over metre_lines, not in this table.
     */
}
