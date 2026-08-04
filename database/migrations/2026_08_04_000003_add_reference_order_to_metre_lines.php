<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * METL_MetreLines::Order - the line's rank inside its section, established by reading the live
 * FileMaker application rather than the export (see CLAUDE.md, "Reading the old application").
 *
 * It is NOT the same thing as `sort_order`, which this project already uses as a free rank
 * within the whole métré. `Order` counts from 1 inside one (métré, ref_code, refs_code) group,
 * and it is the third component of the line's printed code: METL_SetREFSLCode writes
 * `REF_Code & "." & REFS_Code & "." & Order`, giving "20.2.2". Hence a separate column - the
 * two orders answer different questions and both exist in the source.
 *
 * Nullable because a line need not belong to a section: 4 of the 57 809 lines in the live file
 * carry no REF_Code at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('metre_lines', function (Blueprint $table) {
            $table->integer('ref_order')->nullable()->after('refsl_title');

            // Every list of a métré's lines sorts on these three, in this order - it is what
            // METL_Sort does (REF_Code, REFS_Code, REFS_Title, Order) and the order the lines
            // are printed in.
            $table->index(['metre_id', 'ref_code', 'refs_code', 'ref_order'], 'metre_lines_section_order_index');
        });
    }

    public function down(): void
    {
        Schema::table('metre_lines', function (Blueprint $table) {
            $table->dropIndex('metre_lines_section_order_index');
            $table->dropColumn('ref_order');
        });
    }
};
