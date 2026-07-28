<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds the lot_id foreign key constraint deferred from the metre_lines migration,
     * now that the `lots` table exists.
     */
    public function up(): void
    {
        Schema::table('metre_lines', function (Blueprint $table) {
            $table->foreign('lot_id')->references('id')->on('lots');
        });
    }

    public function down(): void
    {
        Schema::table('metre_lines', function (Blueprint $table) {
            $table->dropForeign(['lot_id']);
        });
    }
};
