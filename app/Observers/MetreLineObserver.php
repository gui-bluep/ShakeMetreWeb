<?php

namespace App\Observers;

use App\Jobs\RecalculateMetreTotals;
use App\Models\MetreLine;

/**
 * Laravel equivalent of the FileMaker triggers that ran MET_UpdateStoredCalcs after a metre
 * line changed: any create/update/delete invalidates the parent metre's stored aggregates.
 */
class MetreLineObserver
{
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
