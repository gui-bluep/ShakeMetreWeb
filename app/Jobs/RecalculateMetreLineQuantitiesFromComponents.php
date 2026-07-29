<?php

namespace App\Jobs;

use App\Models\MetreLine;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Laravel equivalent of the FileMaker METL_METC_UpdateQuantities script:
 *
 *     Set Field [ Quantity        ; Round ( METC_ValuesSalesSum_cU   ; 2 ) ]
 *     Set Field [ QuantityOrdered ; Round ( METC_ValuesOrderedSum_cU ; 2 ) ]
 *
 * Each quantity comes from its own component sum - they are independent, and neither mirrors
 * the other. The sums are ValueSales_c / ValueOrdered_c, a component's quantity multiplied by
 * whichever dimensions it carries.
 *
 * Summed in PHP rather than SQL because those two values are model accessors with no columns
 * behind them; a line holds a handful of components, so the cost is irrelevant.
 */
class RecalculateMetreLineQuantitiesFromComponents implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /**
     * Editing several components of one line queues one job per component, all computing the
     * same result, so collapsing the ones still waiting is safe. The lock releases when
     * processing starts, so a later edit still queues a fresh job.
     */
    public int $uniqueFor = 300;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public MetreLine $metreLine) {}

    public function uniqueId(): string
    {
        return $this->metreLine->getKey();
    }

    public function handle(): void
    {
        $components = $this->metreLine->metreLineComponents()->get();

        $sales = round($components->sum(fn ($component) => $component->value_sales), 2);
        $ordered = round($components->sum(fn ($component) => $component->value_ordered), 2);

        // A normal save, deliberately not a query-builder update. Two things depend on the
        // model lifecycle running here:
        //   - MetreLineObserver::saving() applies the pm rule, so a "pm" line ends up with no
        //     quantity at all even though its components sum to something.
        //   - MetreLineObserver::saved() queues RecalculateMetreTotals, so the metre's stored
        //     aggregates follow. An update() would write the columns and leave both stale.
        // saveQuietly() is therefore NOT usable either: it would skip the very observers this
        // cascade needs.
        $this->metreLine->forceFill([
            'quantity' => $sales,
            'quantity_ordered' => $ordered,
        ])->save();
    }
}
