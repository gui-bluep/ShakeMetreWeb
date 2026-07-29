<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A component cannot outlive its metre line: it is part of that line, not something merely
     * associated with it, and its only purpose is to be summed into that line's quantities.
     *
     * The foreign key was created with ON DELETE NO ACTION in Phase 1, which made deleting a
     * composed line fail outright with an integrity violation - including in the stress
     * seeder, which clears a metre's lines before reseeding, and in any future "delete line"
     * action in the grid.
     *
     * Only this relation is changed. Every other child foreign key in the schema still has NO
     * ACTION, which is deliberate for now: those cascades need deciding one relation at a
     * time, and quietly cascading a metre delete through its lines is a much larger decision
     * than this one.
     */
    public function up(): void
    {
        Schema::table('metre_line_components', function (Blueprint $table) {
            $table->dropForeign(['metre_line_id']);
            $table->foreign('metre_line_id')->references('id')->on('metre_lines')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('metre_line_components', function (Blueprint $table) {
            $table->dropForeign(['metre_line_id']);
            $table->foreign('metre_line_id')->references('id')->on('metre_lines');
        });
    }
};
