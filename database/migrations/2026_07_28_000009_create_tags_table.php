<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FileMaker source: TAG_Tags (docs/filemaker-reference/ShakeMetre_data_dictionary.json)
     *
     * metre_id is created here WITHOUT a foreign key constraint for the same reason as in
     * carts: `metres` doesn't exist yet at this point. Constrained afterwards in
     * 2026_07_28_000011_add_metre_foreign_keys_to_carts_and_tags_table.php.
     */
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('metre_id')->nullable();

            $table->string('tag_text')->nullable();
            $table->string('tag_code')->nullable();

            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tags');
    }
};
