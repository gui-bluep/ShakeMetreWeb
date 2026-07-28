<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FileMaker source: CART_Carts (docs/filemaker-reference/ShakeMetre_data_dictionary.json)
     *
     * metre_id is created here WITHOUT a foreign key constraint because the `metres` table
     * doesn't exist yet at this point in the requested migration order (carts is migrated
     * before metres). The constraint is added afterwards in
     * 2026_07_28_000011_add_metre_foreign_keys_to_carts_and_tags_table.php, once `metres` exists.
     */
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Cross-system: points to PRJ_Projects in ShakeDesign, no local constraint.
            $table->uuid('project_id')->nullable();

            // Local, but constrained later (see note above).
            $table->uuid('metre_id')->nullable();

            $table->string('name')->nullable();
            $table->boolean('is_active')->default(false);

            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carts');
    }
};
