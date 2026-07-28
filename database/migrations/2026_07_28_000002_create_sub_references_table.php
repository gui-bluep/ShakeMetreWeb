<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FileMaker source: REFS_SubReference (docs/filemaker-reference/ShakeMetre_data_dictionary.json)
     */
    public function up(): void
    {
        Schema::create('sub_references', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('reference_id')->constrained('references');

            $table->string('title_en')->nullable();
            $table->string('title_fr')->nullable();
            $table->string('title_nl')->nullable();
            $table->integer('code')->nullable();

            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sub_references');
    }
};
