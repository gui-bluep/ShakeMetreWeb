<?php

namespace App\Observers;

use App\Jobs\RecalculateMetreTotals;
use App\Models\MetreLine;

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
        if (! $this->isPourMemoire($metreLine)) {
            return;
        }

        // Iterated rather than assigned twice, so the two columns cannot drift apart.
        foreach (self::QUANTITY_COLUMNS as $column) {
            $metreLine->{$column} = null;
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
