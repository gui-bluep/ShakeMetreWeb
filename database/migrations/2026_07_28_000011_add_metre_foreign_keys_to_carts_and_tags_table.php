<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds the metre_id foreign key constraints deferred from the carts and tags
     * migrations, now that the `metres` table exists.
     */
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->foreign('metre_id')->references('id')->on('metres');
        });

        Schema::table('tags', function (Blueprint $table) {
            $table->foreign('metre_id')->references('id')->on('metres');
        });
    }

    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->dropForeign(['metre_id']);
        });

        Schema::table('tags', function (Blueprint $table) {
            $table->dropForeign(['metre_id']);
        });
    }
};
