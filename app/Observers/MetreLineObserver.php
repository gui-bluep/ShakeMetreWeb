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
     * METL_MetreLines::QuantityOrdered auto-enter: `Case ( Unit = "pm" ; "" ; Self )`.
     *
     * `Self` means "leave the value alone", so the whole rule is: a "pm" (pour mémoire) line
     * carries no ordered quantity. It says nothing about Quantity - the two quantities are
     * independent, each fed from its own component sum by METL_METC_UpdateQuantities.
     *
     * Applied on every save rather than only when QuantityOrdered changes, because in
     * FileMaker the auto-enter re-evaluates whenever a referenced field does - including Unit.
     */
    public function saving(MetreLine $metreLine): void
    {
        // FileMaker text comparison is case-insensitive, so "PM" and "Pm" match too.
        if (mb_strtolower(trim((string) $metreLine->unit)) === 'pm') {
            $metreLine->quantity_ordered = null;
        }
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
