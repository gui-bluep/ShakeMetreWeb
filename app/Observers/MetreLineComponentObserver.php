<?php

namespace App\Observers;

use App\Jobs\RecalculateMetreLineQuantitiesFromComponents;
use App\Models\MetreLineComponent;

/**
 * Keeps a composed line's quantities in step with its components, the way the FileMaker
 * METL_METC_UpdateQuantities script did when run from the components portal.
 *
 * Same shape as MetreLineObserver: the observer only decides that a recalculation is due, the
 * job does the work, and it is queued afterCommit so it never reads the pre-write state of the
 * transaction it was queued inside.
 */
class MetreLineComponentObserver
{
    public function saved(MetreLineComponent $component): void
    {
        $this->queueRecalculation($component);
    }

    public function deleted(MetreLineComponent $component): void
    {
        $this->queueRecalculation($component);
    }

    private function queueRecalculation(MetreLineComponent $component): void
    {
        $line = $component->metreLine;

        if ($line === null) {
            return;
        }

        RecalculateMetreLineQuantitiesFromComponents::dispatch($line)->afterCommit();
    }
}
