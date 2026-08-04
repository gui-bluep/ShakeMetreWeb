<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `metres.ind_project` (MET_Metre::IndProject) becomes the métré's ID within its project - the
 * number the project page shows, 1, 2, 3 for one project and starting again at 1 for the next.
 * The column already existed and was already ordered on; what it did not have was a rule, an
 * allocator, or anything stopping two métrés of the same project claiming the same number.
 *
 * Two steps, in this order:
 *
 *  1. Existing rows are numbered. A value already in the column is a number a user may have
 *     read off the FileMaker solution, so it is kept - the export gives no formula for
 *     IndProject (plain Number field, no calculation, referenced by no other formula) and
 *     renumbering everything would silently move IDs people recognise. Only rows that are
 *     null, non-positive, or collide with a row already holding that number in the same
 *     project get a fresh number, taken after the project's current maximum. Collisions do
 *     exist locally: MetreController::duplicate() copied ind_project verbatim, so a métré and
 *     its copy both read as 1.
 *
 *  2. (project_id, ind_project) becomes unique, so the invariant is enforced by the database
 *     rather than hoped for. Both engines treat NULLs as distinct in a unique index, which is
 *     what lets a row with no number coexist with the numbered ones - and lets the test suite
 *     keep creating métrés without one.
 *
 * Order within a project is date_creation, then created_at, then id: oldest first, so the
 * numbering runs the same way a person reading the list would.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->projectIds() as $projectId) {
            $this->numberProject($projectId);
        }

        Schema::table('metres', function (Blueprint $table) {
            $table->unique(['project_id', 'ind_project']);
        });
    }

    /**
     * Only the index is dropped. The numbers assigned above are identities from the moment
     * they are shown to anyone, so putting the nulls back would destroy information rather
     * than restore a previous state.
     */
    public function down(): void
    {
        Schema::table('metres', function (Blueprint $table) {
            $table->dropUnique(['project_id', 'ind_project']);
        });
    }

    /** @return list<?string> */
    private function projectIds(): array
    {
        return DB::table('metres')->distinct()->pluck('project_id')->all();
    }

    private function numberProject(?string $projectId): void
    {
        $rows = DB::table('metres')
            ->when(
                $projectId === null,
                fn ($query) => $query->whereNull('project_id'),
                fn ($query) => $query->where('project_id', $projectId),
            )
            ->orderBy('date_creation')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'ind_project']);

        $kept = [];
        $toNumber = [];

        foreach ($rows as $row) {
            $number = $row->ind_project === null ? null : (int) $row->ind_project;

            if ($number !== null && $number >= 1 && ! in_array($number, $kept, true)) {
                $kept[] = $number;

                continue;
            }

            $toNumber[] = $row->id;
        }

        $next = $kept === [] ? 1 : max($kept) + 1;

        foreach ($toNumber as $id) {
            DB::table('metres')->where('id', $id)->update(['ind_project' => $next++]);
        }
    }
};
