<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FileMaker source: LOT_Lot (docs/filemaker-reference/ShakeMetre_data_dictionary.json)
     *
     * tender_supplier_{1..5}_id are treated as cross-system (ShakeDesign CPY_Companies) per
     * the migration plan doc (section 3.1: "met_lot_CPY__TenderSupplier1..5"), even though
     * they weren't in the literal zkf_* exception list - flag this inference if it's wrong.
     *
     * "zkf_PRJ Count" from the source schema was dropped: it's a FileMaker Summary
     * (aggregate) field, not a real stored foreign key.
     */
    public function up(): void
    {
        Schema::create('lots', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Cross-system: ShakeDesign PRJ_Projects / CPY_Companies / CTC_Contacts.
            $table->uuid('project_id')->nullable();
            $table->uuid('company_id')->nullable();
            $table->uuid('contact_id')->nullable();
            for ($i = 1; $i <= 5; $i++) {
                $table->uuid("tender_supplier_{$i}_id")->nullable();
            }

            $table->integer('code')->nullable();
            $table->integer('sequence_number')->nullable();
            $table->string('title_en')->nullable();
            $table->string('title_fr')->nullable();
            $table->string('title_nl')->nullable();
            $table->string('title_custom')->nullable();

            // Denormalized snapshots of the linked ShakeDesign company/contact.
            $table->string('cpy_name_ae')->nullable();
            $table->string('cpy_adr_ae')->nullable();
            $table->string('ctc_name_ae')->nullable();

            // Supplier tender weighting/scoring matrix.
            $table->decimal('tender_weighting_price', 15, 4)->nullable();
            for ($crit = 1; $crit <= 5; $crit++) {
                $table->integer("tender_weighting_crit{$crit}")->nullable();
                $table->text("tender_weighting_crit{$crit}_description")->nullable();
                for ($supp = 1; $supp <= 5; $supp++) {
                    $table->integer("tender_weighting_crit{$crit}_supp{$supp}")->nullable();
                }
            }
            for ($i = 1; $i <= 5; $i++) {
                $table->text("tender_supp{$i}_comment")->nullable();
            }

            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lots');
    }
};
