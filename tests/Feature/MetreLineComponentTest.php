<?php

namespace Tests\Feature;

use App\Jobs\RecalculateMetreLineQuantitiesFromComponents;
use App\Jobs\RecalculateMetreTotals;
use App\Models\Metre;
use App\Models\MetreLine;
use App\Models\MetreLineComponent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * METC_MetreLineComponent: the components a metre line's quantities are summed from.
 */
class MetreLineComponentTest extends TestCase
{
    use RefreshDatabase;

    private Metre $metre;

    private MetreLine $line;

    protected function setUp(): void
    {
        parent::setUp();

        $this->metre = Metre::forceCreate([
            'name' => 'Métré',
            'project_id' => 'PRJ-1',
            'is_status_site_b' => true,
            'is_accepted_b' => true,
        ]);

        $this->line = MetreLine::forceCreate([
            'metre_id' => $this->metre->id,
            'unit' => 'm2',
            'quantity' => 1,
            'quantity_ordered' => 1,
            'price_sales' => 100,
            'price_ordered' => 80,
        ]);
    }

    private function makeComponent(array $attributes = []): MetreLineComponent
    {
        return MetreLineComponent::forceCreate($attributes + ['metre_line_id' => $this->line->id]);
    }

    private function actAsWriter(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    // --- hasComponents ------------------------------------------------------------------

    public function test_a_line_without_components_reports_none(): void
    {
        $this->assertFalse($this->line->hasComponents());
    }

    public function test_a_line_with_components_reports_them(): void
    {
        $this->makeComponent(['quantity_sales' => 1]);

        $this->assertTrue($this->line->fresh()->hasComponents());
    }

    public function test_it_does_not_read_the_denormalized_filemaker_flag(): void
    {
        // metc_is_present_b exists from Phase 1 but is a second source of truth; the accessor
        // must ignore it, or a stale flag would silently lock or unlock the quantity cells.
        $this->line->forceFill(['metc_is_present_b' => true])->saveQuietly();

        $this->assertFalse($this->line->fresh()->hasComponents());
    }

    public function test_it_answers_from_a_with_count_query_without_extra_queries(): void
    {
        $this->makeComponent(['quantity_sales' => 1]);

        $line = MetreLine::withCount('metreLineComponents')->findOrFail($this->line->id);

        DB::enableQueryLog();
        $this->assertTrue($line->hasComponents());
        $this->assertCount(0, DB::getQueryLog());
        DB::disableQueryLog();
    }

    // --- the recalculation --------------------------------------------------------------

    public function test_it_sums_component_values_into_the_line_quantities(): void
    {
        // 2 * (3 * 1 * 1) = 6 sales ; 1 * 3 = 3 ordered
        $this->makeComponent(['quantity_sales' => 2, 'quantity_ordered' => 1, 'length' => 3]);
        // 5 sales, 5 ordered, no dimensions
        $this->makeComponent(['quantity_sales' => 5, 'quantity_ordered' => 5]);

        (new RecalculateMetreLineQuantitiesFromComponents($this->line))->handle();

        $this->line->refresh();
        $this->assertEquals(11, (float) $this->line->quantity);
        $this->assertEquals(8, (float) $this->line->quantity_ordered);
    }

    public function test_the_two_quantities_are_summed_independently(): void
    {
        // Each comes from its own component quantity, mirroring METL_METC_UpdateQuantities.
        $this->makeComponent(['quantity_sales' => 10, 'quantity_ordered' => 2]);

        (new RecalculateMetreLineQuantitiesFromComponents($this->line))->handle();

        $this->line->refresh();
        $this->assertEquals(10, (float) $this->line->quantity);
        $this->assertEquals(2, (float) $this->line->quantity_ordered);
    }

    public function test_it_rounds_to_two_decimals(): void
    {
        $this->makeComponent(['quantity_sales' => 1, 'length' => 0.3333]);
        $this->makeComponent(['quantity_sales' => 1, 'length' => 0.3333]);

        (new RecalculateMetreLineQuantitiesFromComponents($this->line))->handle();

        // Each component value rounds to 0.33, so the line holds 0.66.
        $this->assertEquals(0.66, (float) $this->line->refresh()->quantity);
    }

    public function test_removing_every_component_leaves_the_quantities_at_zero(): void
    {
        $component = $this->makeComponent(['quantity_sales' => 4, 'quantity_ordered' => 4]);
        $component->delete();

        $this->line->refresh();
        $this->assertEquals(0, (float) $this->line->quantity);
        $this->assertEquals(0, (float) $this->line->quantity_ordered);
    }

    // --- the cascade, which is the point of using a normal save --------------------------

    public function test_saving_a_component_queues_the_line_recalculation(): void
    {
        Queue::fake();

        $this->makeComponent(['quantity_sales' => 1]);

        Queue::assertPushed(RecalculateMetreLineQuantitiesFromComponents::class);
    }

    public function test_deleting_a_component_queues_the_line_recalculation(): void
    {
        $component = $this->makeComponent(['quantity_sales' => 1]);
        Queue::fake();

        $component->delete();

        Queue::assertPushed(RecalculateMetreLineQuantitiesFromComponents::class);
    }

    public function test_the_recalculation_cascades_into_the_metre_totals(): void
    {
        $this->makeComponent(['quantity_sales' => 3]);
        Queue::fake();

        (new RecalculateMetreLineQuantitiesFromComponents($this->line))->handle();

        // Writing through the model, not an update(), is what makes MetreLineObserver::saved
        // fire and the metre's stored aggregates follow.
        Queue::assertPushed(RecalculateMetreTotals::class);
    }

    public function test_the_metre_totals_actually_follow_the_component_sums(): void
    {
        // QUEUE_CONNECTION=sync here, so the whole chain runs inline.
        $this->makeComponent(['quantity_sales' => 3]);

        // 3 * price_sales 100 = 300
        $this->assertEquals(300, (float) $this->metre->refresh()->tot_sum_total_sales_stored);
    }

    public function test_a_pour_memoire_line_ends_up_with_no_quantity_despite_its_components(): void
    {
        $this->line->forceFill(['unit' => 'pm'])->save();

        $this->makeComponent(['quantity_sales' => 7, 'quantity_ordered' => 7]);

        (new RecalculateMetreLineQuantitiesFromComponents($this->line))->handle();

        // The pm auto-enter runs on the same save that writes the sums, so it wins - which is
        // only true because the job goes through the model rather than a direct update.
        $this->line->refresh();
        $this->assertNull($this->line->quantity);
        $this->assertNull($this->line->quantity_ordered);
    }

    // --- the endpoints ------------------------------------------------------------------

    public function test_listing_components_requires_authentication(): void
    {
        $this->getJson("/api/metre-lines/{$this->line->id}/components")->assertUnauthorized();
    }

    public function test_it_lists_the_components_of_a_line_in_order(): void
    {
        $this->actAsWriter();
        $this->makeComponent(['description' => 'B', 'sort_order' => 2]);
        $this->makeComponent(['description' => 'A', 'sort_order' => 1]);

        $response = $this->getJson("/api/metre-lines/{$this->line->id}/components")->assertOk();

        $this->assertSame(['A', 'B'], array_column($response->json('data'), 'description'));
    }

    public function test_it_creates_a_component_and_returns_the_recomputed_line(): void
    {
        $this->actAsWriter();

        $response = $this->postJson("/api/metre-lines/{$this->line->id}/components", [
            'description' => 'Dalle',
            'quantity_sales' => 2,
            'length' => 3,
        ])->assertCreated();

        // The response must already carry the new quantities, or the panel would show stale
        // numbers until a reload - the queued job has not run yet at this point.
        $response->assertJsonPath('line.quantity', 6);
        $response->assertJsonPath('line.has_components', true);
        $this->assertEquals(6, (float) $this->line->refresh()->quantity);
    }

    public function test_a_new_component_is_appended_rather_than_colliding(): void
    {
        $this->actAsWriter();
        $this->makeComponent(['sort_order' => 0]);
        $this->makeComponent(['sort_order' => 1]);

        $this->postJson("/api/metre-lines/{$this->line->id}/components", [])
            ->assertCreated()
            ->assertJsonPath('data.sort_order', 2);
    }

    public function test_it_updates_a_component_and_returns_the_recomputed_line(): void
    {
        $this->actAsWriter();
        $component = $this->makeComponent(['quantity_sales' => 1]);

        $this->patchJson("/api/metre-line-components/{$component->id}", ['quantity_sales' => 9])
            ->assertOk()
            ->assertJsonPath('data.computed.value_sales', 9)
            ->assertJsonPath('line.quantity', 9);
    }

    public function test_it_deletes_a_component_and_returns_the_recomputed_line(): void
    {
        $this->actAsWriter();
        $component = $this->makeComponent(['quantity_sales' => 4]);

        $this->deleteJson("/api/metre-line-components/{$component->id}")
            ->assertOk()
            ->assertJsonPath('line.quantity', 0)
            ->assertJsonPath('line.has_components', false);

        $this->assertModelMissing($component);
    }

    public function test_reordering_persists_the_new_positions(): void
    {
        $this->actAsWriter();
        $first = $this->makeComponent(['sort_order' => 0, 'description' => 'A']);
        $second = $this->makeComponent(['sort_order' => 1, 'description' => 'B']);

        $this->patchJson("/api/metre-line-components/{$first->id}", ['sort_order' => 1])->assertOk();
        $this->patchJson("/api/metre-line-components/{$second->id}", ['sort_order' => 0])->assertOk();

        $response = $this->getJson("/api/metre-lines/{$this->line->id}/components")->assertOk();
        $this->assertSame(['B', 'A'], array_column($response->json('data'), 'description'));
    }

    // --- the whitelist ------------------------------------------------------------------

    /** @return array<string, array{string, mixed}> */
    public static function protectedFieldProvider(): array
    {
        return [
            'derived sales value' => ['value_sales', 99],
            'derived ordered value' => ['value_ordered', 99],
            'reparenting' => ['metre_line_id', 'some-other-line'],
            'sequence number' => ['sequence_number', 7],
            'identity' => ['id', 'forged-id'],
            'unknown field' => ['nonsense', 1],
        ];
    }

    #[DataProvider('protectedFieldProvider')]
    public function test_it_rejects_a_field_outside_the_whitelist_on_update(string $field, mixed $value): void
    {
        $this->actAsWriter();
        $component = $this->makeComponent(['quantity_sales' => 1]);

        $this->patchJson("/api/metre-line-components/{$component->id}", [$field => $value])
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);
    }

    #[DataProvider('protectedFieldProvider')]
    public function test_it_rejects_a_field_outside_the_whitelist_on_create(string $field, mixed $value): void
    {
        $this->actAsWriter();

        $this->postJson("/api/metre-lines/{$this->line->id}/components", [$field => $value])
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);
    }

    public function test_rejecting_a_derived_value_explains_why(): void
    {
        $this->actAsWriter();
        $component = $this->makeComponent();

        $this->patchJson("/api/metre-line-components/{$component->id}", ['value_sales' => 1])
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.value_sales.0',
                '[value_sales] is a derived value with no column behind it; it cannot be written.'
            );
    }

    public function test_a_component_cannot_be_moved_to_another_line_through_the_payload(): void
    {
        $this->actAsWriter();
        $other = MetreLine::forceCreate(['metre_id' => $this->metre->id]);
        $component = $this->makeComponent();

        $this->patchJson("/api/metre-line-components/{$component->id}", ['metre_line_id' => $other->id])
            ->assertStatus(422);

        $this->assertSame($this->line->id, $component->refresh()->metre_line_id);
    }

    public function test_a_rejected_batch_writes_nothing(): void
    {
        $this->actAsWriter();
        $component = $this->makeComponent(['quantity_sales' => 1]);

        $this->patchJson("/api/metre-line-components/{$component->id}", [
            'quantity_sales' => 99,
            'value_sales' => 1,
        ])->assertStatus(422);

        $this->assertEquals(1, (float) $component->refresh()->quantity_sales);
    }

    // --- guards -------------------------------------------------------------------------

    public function test_a_readonly_account_cannot_write_a_component(): void
    {
        $this->actingAs(User::factory()->readOnly()->create());
        $component = $this->makeComponent(['quantity_sales' => 1]);

        $this->patchJson("/api/metre-line-components/{$component->id}", ['quantity_sales' => 5])
            ->assertForbidden();
        $this->postJson("/api/metre-lines/{$this->line->id}/components", [])->assertForbidden();
        $this->deleteJson("/api/metre-line-components/{$component->id}")->assertForbidden();
    }

    public function test_a_readonly_account_may_still_list_components(): void
    {
        $this->actingAs(User::factory()->readOnly()->create());
        $this->makeComponent(['quantity_sales' => 1]);

        $this->getJson("/api/metre-lines/{$this->line->id}/components")->assertOk();
    }

    public function test_a_locked_metre_refuses_component_writes(): void
    {
        $this->actAsWriter();
        $component = $this->makeComponent(['quantity_sales' => 1]);
        $this->metre->forceFill(['is_locked_b' => true])->save();

        // Editing a component rewrites the line's quantities, so it is a write to the metre by
        // another route and must meet the same guard.
        $this->patchJson("/api/metre-line-components/{$component->id}", ['quantity_sales' => 5])
            ->assertStatus(423);
        $this->postJson("/api/metre-lines/{$this->line->id}/components", [])->assertStatus(423);
        $this->deleteJson("/api/metre-line-components/{$component->id}")->assertStatus(423);

        $this->assertEquals(1, (float) $component->refresh()->quantity_sales);
    }

    // --- the grid payload ---------------------------------------------------------------

    public function test_the_grid_marks_a_composed_line(): void
    {
        $this->actAsWriter();
        $this->makeComponent(['quantity_sales' => 1]);

        $this->get("/metres/{$this->metre->id}/lines")
            ->assertInertia(fn ($page) => $page->where('lines.0.has_components', true));
    }

    public function test_the_grid_does_not_mark_a_plain_line(): void
    {
        $this->actAsWriter();

        $this->get("/metres/{$this->metre->id}/lines")
            ->assertInertia(fn ($page) => $page->where('lines.0.has_components', false));
    }
}
