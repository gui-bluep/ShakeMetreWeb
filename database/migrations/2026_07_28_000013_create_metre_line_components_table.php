<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FileMaker source: METC_MetreLineComponent (docs/filemaker-reference/ShakeMetre_data_dictionary.json)
     */
    public function up(): void
    {
        Schema::create('metre_line_components', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('metre_line_id')->constrained('metre_lines');

            $table->integer('sequence_number')->nullable();
            $table->integer('sort_order')->nullable();
            $table->text('description')->nullable();
            $table->decimal('length', 15, 4)->nullable();
            $table->decimal('width', 15, 4)->nullable();
            $table->decimal('height', 15, 4)->nullable();
            $table->decimal('quantity_sales', 15, 4)->nullable();
            $table->decimal('quantity_ordered', 15, 4)->nullable();

            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metre_line_components');
    }
};
