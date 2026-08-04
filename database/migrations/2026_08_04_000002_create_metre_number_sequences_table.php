<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row per project holding the highest métré ID ever handed out in it.
 *
 * Without it, "next ID" can only be read off the métrés that still exist, and deleting the
 * last one lowers the maximum: three métrés numbered 1, 2, 3, delete 3, and the next creation
 * takes 3 again. An ID has to keep meaning one métré - somebody says "métré 3" in a mail or a
 * phone call - so a number that has been used is spent, deleted or not. This table is the
 * high-water mark that survives the deletion.
 *
 * Seeded from the métrés that exist now, which is the best mark available: numbers handed out
 * before this table existed and already deleted are unrecoverable, and reusing one of those is
 * the last time it can happen.
 *
 * project_id is the primary key and non-nullable on purpose. A métré with no project has no
 * project to be numbered within, and the numbering of that (test-only) case falls back to
 * max + 1 - see Metre::nextIndProject().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metre_number_sequences', function (Blueprint $table) {
            $table->uuid('project_id')->primary();
            $table->unsignedInteger('last_ind_project');
            $table->timestamps();
        });

        $marks = DB::table('metres')
            ->whereNotNull('project_id')
            ->groupBy('project_id')
            ->selectRaw('project_id, MAX(ind_project) AS last_ind_project')
            ->get();

        $now = now();

        foreach ($marks as $mark) {
            if ($mark->last_ind_project === null) {
                continue;
            }

            DB::table('metre_number_sequences')->insert([
                'project_id' => $mark->project_id,
                'last_ind_project' => (int) $mark->last_ind_project,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('metre_number_sequences');
    }
};
