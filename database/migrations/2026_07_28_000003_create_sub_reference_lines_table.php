<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FileMaker source: REFSL_SubReferenceLines (docs/filemaker-reference/ShakeMetre_data_dictionary.json)
     *
     * Carries both zkf_REFS (parent sub-reference) and zkf_REF (grandparent reference) as
     * they appear as two distinct stored foreign keys in the FileMaker schema.
     */
    public function up(): void
    {
        Schema::create('sub_reference_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('sub_reference_id')->constrained('sub_references');
            $table->foreignUuid('reference_id')->constrained('references');

            $table->integer('code')->nullable();
            $table->string('title_en')->nullable();
            $table->string('title_fr')->nullable();
            $table->string('title_nl')->nullable();
            $table->decimal('price', 15, 4)->nullable();
            $table->string('unit')->nullable();
            $table->text('description_en')->nullable();
            $table->text('description_fr')->nullable();
            $table->text('description_nl')->nullable();

            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sub_reference_lines');
    }
};
