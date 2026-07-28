<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FileMaker source: REF_Reference (docs/filemaker-reference/ShakeMetre_data_dictionary.json)
     */
    public function up(): void
    {
        Schema::create('references', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('title_en')->nullable();
            $table->string('title_fr')->nullable();
            $table->string('title_nl')->nullable();
            $table->integer('code')->nullable();
            $table->integer('sequence_number')->nullable();

            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('references');
    }
};
