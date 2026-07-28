<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FileMaker source: MET_Metre (docs/filemaker-reference/ShakeMetre_data_dictionary.json)
     *
     * All *_stored columns are materialized aggregates over metre_lines, recomputed by a
     * MetreLineObserver + queued job (RecalculateMetreTotals) rather than at request time -
     * see section 6.3 of ShakeMetre_Analyse_et_Plan_Migration_Laravel.md.
     *
     * The source table's `zsm_*` fields are deliberately absent: FileMaker Summary fields
     * aggregate over the current found set (portal/list/report totals at display time), so
     * they are not per-record data and have no place as columns. That argument rests only on
     * the field's `type` being Summary, which is all the export records - it lists no
     * aggregation operator and no source field for any of them (`calc` is null throughout).
     *
     * Consequently, only two of the five removed columns have a verified meaning:
     * zsm_SumTotalSales_Stored and zsm_SumTotalOrdered_Stored were confirmed in the XML
     * export as operation="Total" over Tot_Sum_TotalSales_Stored / Tot_Sum_TotalOrdered_Stored,
     * which is why the job may substitute those per-record columns in Tot_Sum_TotalFees_Stored
     * and its percentage/ratio. The aggregation of zsm_SumTotalBuy_Stored,
     * zsm_SumTotalGain_Stored and zsm_SumGainOnPurchases_Stored is unverified - no calculation
     * in the export consumes them, so nothing depends on it. List-level totals are computed
     * by query instead.
     */
    public function up(): void
    {
        Schema::create('metres', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Cross-system: ShakeDesign PRJ_Projects / OFF_Offers, no local constraint.
            $table->uuid('project_id')->nullable();
            $table->uuid('offer_id')->nullable();

            $table->string('name')->nullable();
            $table->integer('ind_project')->nullable();
            $table->string('language')->nullable();
            $table->decimal('ratio_markup', 15, 4)->nullable();
            $table->integer('sort_order_tags')->nullable();
            $table->integer('sequence_number')->nullable();

            $table->boolean('is_accepted_b')->default(false);
            $table->boolean('is_status_site_b')->default(false);
            $table->boolean('is_imported_b')->default(false);
            $table->boolean('is_locked_b')->default(false);
            $table->boolean('is_archived_b')->default(false);

            $table->date('date_creation')->nullable();
            $table->date('date_agreement')->nullable();
            $table->date('prog_progress_update_date')->nullable();
            $table->timestamp('date_time_update_calcs_stored')->nullable();

            $table->text('comment_client')->nullable();
            $table->text('comment_internal')->nullable();
            $table->text('comment_supplier')->nullable();

            $table->string('lot_names_cache')->nullable();
            $table->string('lot_ids_cache')->nullable();

            $table->decimal('prog_progress_client_total_amount_stored', 15, 4)->nullable();
            $table->decimal('prog_progress_client_total_percent_stored', 15, 4)->nullable();
            $table->decimal('prog_progress_supp_total_amount_stored', 15, 4)->nullable();
            $table->decimal('prog_progress_supp_total_percent_stored', 15, 4)->nullable();
            $table->decimal('prog_progress_client_total_amount_valid_stored_c', 15, 4)->nullable();
            $table->decimal('prog_progress_client_total_percent_valid_stored_c', 15, 4)->nullable();
            $table->decimal('prog_progress_supp_total_amount_valid_stored_c', 15, 4)->nullable();
            $table->decimal('prog_progress_supp_total_percent_valid_stored_c', 15, 4)->nullable();

            $table->decimal('tot_sum_total_ordered_stored', 15, 4)->nullable();
            $table->decimal('tot_sum_total_sales_stored', 15, 4)->nullable();
            $table->decimal('tot_sum_total_sales_offer_stored', 15, 4)->nullable();
            $table->decimal('tot_sum_total_buy_stored', 15, 4)->nullable();
            $table->decimal('tot_sum_total_gain_stored', 15, 4)->nullable();
            $table->decimal('tot_sum_total_fees_stored', 15, 4)->nullable();
            $table->decimal('tot_percentage_total_fees_stored', 15, 4)->nullable();
            $table->decimal('tot_ratio_total_fees_stored', 15, 4)->nullable();
            $table->decimal('tot_lot_assigned_gain_on_purchase_stored', 15, 4)->nullable();

            $table->decimal('total_ordered_metl_stored', 15, 4)->nullable();
            $table->decimal('total_purchase_metl_stored', 15, 4)->nullable();
            $table->decimal('total_sales_metl_stored', 15, 4)->nullable();
            $table->decimal('total_gain_metl_stored', 15, 4)->nullable();

            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metres');
    }
};
