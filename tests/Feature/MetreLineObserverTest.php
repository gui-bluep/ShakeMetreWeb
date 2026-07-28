<?php

namespace Tests\Feature;

use App\Jobs\RecalculateMetreTotals;
use App\Models\Metre;
use App\Models\MetreLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MetreLineObserverTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_line_queues_a_recalculation(): void
    {
        $metre = Metre::forceCreate(['name' => 'Metre']);
        Queue::fake();

        MetreLine::forceCreate(['metre_id' => $metre->id, 'price_sales' => 10, 'quantity' => 1]);

        Queue::assertPushed(
            RecalculateMetreTotals::class,
            fn (RecalculateMetreTotals $job) => $job->metre->is($metre),
        );
    }

    public function test_updating_a_line_queues_a_recalculation(): void
    {
        $metre = Metre::forceCreate(['name' => 'Metre']);
        $line = MetreLine::forceCreate(['metre_id' => $metre->id, 'price_sales' => 10, 'quantity' => 1]);

        Queue::fake();
        $line->forceFill(['price_sales' => 20])->save();

        Queue::assertPushed(RecalculateMetreTotals::class, 1);
    }

    public function test_deleting_a_line_queues_a_recalculation(): void
    {
        $metre = Metre::forceCreate(['name' => 'Metre']);
        $line = MetreLine::forceCreate(['metre_id' => $metre->id, 'price_sales' => 10, 'quantity' => 1]);

        Queue::fake();
        $line->delete();

        Queue::assertPushed(
            RecalculateMetreTotals::class,
            fn (RecalculateMetreTotals $job) => $job->metre->is($metre),
        );
    }

    public function test_end_to_end_a_saved_line_updates_the_stored_totals(): void
    {
        $metre = Metre::forceCreate(['name' => 'Metre', 'is_status_site_b' => true]);

        // QUEUE_CONNECTION=sync in phpunit.xml, so the job runs inline here.
        MetreLine::forceCreate(['metre_id' => $metre->id, 'price_sales' => 100, 'quantity' => 3]);

        $this->assertEquals(300.0, (float) $metre->refresh()->tot_sum_total_sales_stored);
    }

    public function test_recalculation_does_not_bump_the_metre_updated_at(): void
    {
        $metre = Metre::forceCreate(['name' => 'Metre', 'is_status_site_b' => true]);
        $originalUpdatedAt = $metre->updated_at;

        $this->travel(1)->minutes();
        MetreLine::forceCreate(['metre_id' => $metre->id, 'price_sales' => 100, 'quantity' => 3]);

        // The recalc is a derived write; it must not look like a user edit of the metre.
        $this->assertEquals(
            $originalUpdatedAt->timestamp,
            $metre->refresh()->updated_at->timestamp,
        );
    }
}
