<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FileMaker source: METL_MetreLines (docs/filemaker-reference/ShakeMetre_data_dictionary.json)
     *
     * The biggest and most business-critical table (see section 4.1 of
     * ShakeMetre_Analyse_et_Plan_Migration_Laravel.md).
     *
     * Every foreign key except metre_id is nullable: FileMaker has no NOT NULL, and the
     * source calculations prove these are genuinely optional -
     *   isDefined_b       reads `not IsEmpty ( zkf_MAT )`
     *   LOT_AmountNotAssignedBuy reads `IsEmpty ( zkf_LOT )`
     * A line always belongs to a metre, so metre_id stays required.
     *
     * vat_value_id and accounting_code_id do NOT rest on the same evidence:
     *   vat_value_id (zkf_VAT_ae) is proven - relationship 168 in the export joins
     *     metl_ZVAL__VAT (ZVAL_Values, external source ShakeDesign) on `zkp = zkf_VAT_ae`.
     *   accounting_code_id (zkf_AccountingCode_ae) is an INFERENCE. No relationship and no
     *     calculation in the export mentions the field; it is an auto-enter (`_ae`) whose
     *     formula the export omits. ZVAL_Values is the likely target because a
     *     `f_ZVAL_AccountingCode_FromSmarter` value list exists alongside
     *     `f_ZVAL_VAT_FromSmarter`, ZVAL_Values carries a Print_AccountCode_c field behind a
     *     `Type` discriminator, and the migration plan describes it as holding "taux de TVA,
     *     codes comptables". Confirm against FileMaker before relying on it in Phase 4.
     * Either way the column is a bare uuid: no accounting-code table exists on this side to
     * constrain against.
     */
    public function up(): void
    {
        Schema::create('metre_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('metre_id')->constrained('metres');
            $table->foreignUuid('reference_id')->nullable()->constrained('metre_references');
            $table->foreignUuid('sub_reference_id')->nullable()->constrained('sub_references');
            $table->foreignUuid('sub_reference_line_id')->nullable()->constrained('sub_reference_lines');
            $table->foreignUuid('material_id')->nullable()->constrained('materials');
            $table->foreignUuid('cart_material_id')->nullable()->constrained('cart_materials');
            $table->foreignUuid('lot_id')->nullable()->constrained('lots');

            // Cross-system: ShakeDesign SOR_SupplierOrders / CPY_Companies / ZVAL_Values.
            $table->uuid('supplier_order_id')->nullable();
            $table->uuid('company_id')->nullable();
            $table->uuid('vat_value_id')->nullable();
            $table->uuid('accounting_code_id')->nullable();

            // Denormalized snapshots of the reference/sub-reference/sub-reference-line at
            // the time the line was created.
            $table->integer('ref_code')->nullable();
            $table->string('ref_title')->nullable();
            $table->integer('refs_code')->nullable();
            $table->string('refs_title')->nullable();
            $table->string('refsl_title')->nullable();

            $table->integer('sort_order')->nullable();
            $table->integer('sequence_number')->nullable();
            $table->string('language')->nullable();
            $table->string('unit')->nullable();
            $table->text('description')->nullable();
            $table->text('description_cch')->nullable();
            $table->text('comment_client')->nullable();
            $table->text('comment_supplier')->nullable();
            $table->text('localisation')->nullable();
            $table->string('tag1')->nullable();
            $table->string('tag2')->nullable();
            $table->string('lot_name_stored')->nullable();
            $table->string('sor_title_ref')->nullable();
            $table->string('image_500x500')->nullable();
            $table->string('procurement_method_code_ae')->nullable();

            $table->decimal('price_buy', 15, 4)->nullable();
            $table->decimal('price_ordered', 15, 4)->nullable();
            $table->decimal('price_sales', 15, 4)->nullable();
            $table->decimal('quantity', 15, 4)->nullable();
            $table->decimal('quantity_ordered', 15, 4)->nullable();
            $table->decimal('ratio', 15, 4)->nullable();
            $table->decimal('sum_total_work_fee', 15, 4)->nullable();
            $table->decimal('sum_total_work_fee_ordered', 15, 4)->nullable();
            $table->decimal('sum_total_company1_price', 15, 4)->nullable();
            $table->decimal('prog_progress_client', 15, 4)->nullable();
            $table->decimal('prog_progress_supp', 15, 4)->nullable();
            $table->decimal('vat_ae', 15, 4)->nullable();

            $table->boolean('is_option_b')->default(false);
            $table->boolean('is_locked_bae')->default(false);
            $table->boolean('is_imported_b')->default(false);
            $table->boolean('is_tender_line_b')->default(false);
            $table->boolean('is_estimated_price_b')->default(false);
            $table->boolean('is_delivered_b')->default(false);
            $table->boolean('metc_is_present_b')->default(false);

            // Supplier tender process (up to 5 candidate suppliers per line).
            $table->integer('tender_id')->nullable();
            for ($i = 1; $i <= 5; $i++) {
                $table->decimal("tender_supp{$i}_price", 15, 4)->nullable();
                $table->decimal("tender_supp{$i}_quantity", 15, 4)->nullable();
                $table->boolean("tender_supp{$i}_omit_b")->default(false);
            }

            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metre_lines');
    }
};
