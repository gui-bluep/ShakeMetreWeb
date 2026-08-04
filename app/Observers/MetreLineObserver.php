<?php

namespace App\Observers;

use App\Jobs\RecalculateMetreTotals;
use App\Models\Metre;
use App\Models\MetreLine;
use App\Models\Reference;
use App\Models\SubReference;

/**
 * Carries the two things FileMaker attached to METL_MetreLines itself: the field-level rule
 * that ran on entry (an auto-enter calculation) and the trigger that recomputed the parent
 * metre's aggregates afterwards.
 */
class MetreLineObserver
{
    /**
     * Both quantity columns carry the same auto-enter in METL_MetreLines:
     *
     *     Quantity         Case ( Unit = "pm" ; "" ; Self )
     *     QuantityOrdered  Case ( Unit = "pm" ; "" ; Self )
     *
     * `Self` means "leave the value alone", so the rule reads: a "pm" (pour mémoire) line
     * carries no quantity at all - neither sold nor ordered. Such a line is a placeholder in
     * the métré, priced later, so every total derived from it lands on zero.
     *
     * This says nothing about the two quantities tracking each other: they remain
     * independent, each fed from its own component sum by METL_METC_UpdateQuantities. They
     * merely share the same emptying rule.
     *
     * Applied on every save rather than only when a quantity changes, because in FileMaker
     * the auto-enter re-evaluates whenever a referenced field does - including Unit.
     */
    private const QUANTITY_COLUMNS = ['quantity', 'quantity_ordered'];

    public function saving(MetreLine $metreLine): void
    {
        $this->syncSectionSnapshot($metreLine);

        if (! $this->isPourMemoire($metreLine)) {
            return;
        }

        // Iterated rather than assigned twice, so the two columns cannot drift apart.
        foreach (self::QUANTITY_COLUMNS as $column) {
            $metreLine->{$column} = null;
        }
    }

    /**
     * The line's section, copied onto the line - and the rank that makes its code.
     *
     * How the source works, established by reading the live application rather than the export
     * (see CLAUDE.md): a line does NOT point at the catalogue. On all 57 809 lines of the live
     * file, zkf_REF / zkf_REFS / zkf_REFSL are EMPTY, while REF_Code, REF_Title, REFS_Code and
     * REFS_Title are filled on nearly every one. METL_New copies the four values in and never
     * looks back, which is what lets a section be renamed in the catalogue - or invented on the
     * spot - without rewriting métrés that were already sent to a client.
     *
     * So the catalogue is a source to copy FROM, not a table to join to. The foreign keys are
     * kept as provenance (which entry this came from) and are never read to display a line.
     *
     * Done here rather than in a controller because it is a property of the record: the import,
     * a duplicate and every screen get the same rule, and there is one write surface for lines.
     *
     * ref_order is (re)assigned only when the section itself changes, never when the line is
     * merely edited - the printed code must keep designating the same line.
     */
    private function syncSectionSnapshot(MetreLine $metreLine): void
    {
        if (! $metreLine->isDirty(['reference_id', 'sub_reference_id'])) {
            return;
        }

        $language = $metreLine->metre_id === null
            ? null
            : Metre::query()->whereKey($metreLine->metre_id)->value('language');

        $reference = $metreLine->reference_id === null
            ? null
            : Reference::find($metreLine->reference_id);

        $subReference = $metreLine->sub_reference_id === null
            ? null
            : SubReference::find($metreLine->sub_reference_id);

        $metreLine->ref_code = $reference?->code;
        $metreLine->ref_title = $reference?->localisedTitle($language);
        $metreLine->refs_code = $subReference?->code;
        $metreLine->refs_title = $subReference?->localisedTitle($language);

        if ($metreLine->isDirty(['ref_code', 'refs_code']) && $metreLine->metre_id !== null) {
            $metreLine->ref_order = MetreLine::nextRefOrder(
                $metreLine->metre_id,
                $metreLine->ref_code,
                $metreLine->refs_code,
            );
        }
    }

    /** FileMaker text comparison is case-insensitive, so "PM" and "Pm" match too. */
    private function isPourMemoire(MetreLine $metreLine): bool
    {
        return mb_strtolower(trim((string) $metreLine->unit)) === 'pm';
    }

    public function saved(MetreLine $metreLine): void
    {
        $this->queueRecalculation($metreLine);
    }

    public function deleted(MetreLine $metreLine): void
    {
        $this->queueRecalculation($metreLine);
    }

    /**
     * Dispatched afterCommit so the job never reads the pre-write state of a transaction it
     * was queued inside.
     */
    private function queueRecalculation(MetreLine $metreLine): void
    {
        $metre = $metreLine->metre;

        if ($metre === null) {
            return;
        }

        RecalculateMetreTotals::dispatch($metre)->afterCommit();
    }
}
