<?php

namespace Tests\Feature;

use App\Jobs\RecalculateMetreTotals;
use App\Models\Metre;
use App\Models\MetreLine;
use App\Models\Reference;
use App\Models\SubReference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MetreLineGridTest extends TestCase
{
    use RefreshDatabase;

    private Metre $metre;

    private Reference $reference;

    private SubReference $subReference;

    protected function setUp(): void
    {
        parent::setUp();

        $this->metre = Metre::forceCreate([
            'name' => 'Métré test',
            'project_id' => 'PRJ-1',
            'is_status_site_b' => true,
            'is_accepted_b' => true,
        ]);

        $this->reference = Reference::forceCreate(['code' => 10, 'title_fr' => 'Poste']);
        $this->subReference = SubReference::forceCreate([
            'reference_id' => $this->reference->id,
            'code' => 101,
            'title_fr' => 'Sous-poste',
        ]);
    }

    private function line(array $attributes = []): MetreLine
    {
        return MetreLine::forceCreate($attributes + [
            'metre_id' => $this->metre->id,
            'reference_id' => $this->reference->id,
            'sub_reference_id' => $this->subReference->id,
            'description' => 'Ligne',
            'quantity' => 2,
            'quantity_ordered' => 2,
            'price_sales' => 100,
            'price_ordered' => 80,
            'price_buy' => 70,
        ]);
    }

    // --- the grid page ----------------------------------------------------------------

    public function test_the_grid_requires_authentication(): void
    {
        $this->get("/metres/{$this->metre->id}/lines")->assertRedirect('/');
    }

    public function test_it_renders_the_grid_with_its_lines_and_catalogues(): void
    {
        $this->actingAs(User::factory()->create());
        $this->line(['description' => 'Première ligne']);

        $this->get("/metres/{$this->metre->id}/lines")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('MetreLines/Index')
                ->where('metre.id', $this->metre->id)
                ->has('lines', 1)
                ->where('lines.0.description', 'Première ligne')
                ->has('references', 1)
                ->has('subReferencesByReference.'.$this->reference->id, 1)
            );
    }

    public function test_the_page_exposes_the_computed_totals_separately_from_editable_fields(): void
    {
        $this->actingAs(User::factory()->create());
        $this->line();

        $this->get("/metres/{$this->metre->id}/lines")
            ->assertInertia(fn (AssertableInertia $page) => $page
                // 100 * 2 = 200 sales, 80 * 2 = 160 ordered, margin 40
                ->where('lines.0.computed.price_total_sales_no_options', 200)
                ->where('lines.0.computed.price_total_ordered_no_options', 160)
                ->where('lines.0.computed.price_total_gain_no_options', 40)
                // The derived values are not mirrored at the top level, where writes come from.
                ->missing('lines.0.price_total_sales_no_options')
            );
    }

    public function test_an_option_line_reports_zero_totals(): void
    {
        $this->actingAs(User::factory()->create());
        $this->line(['is_option_b' => true]);

        $this->get("/metres/{$this->metre->id}/lines")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('lines.0.computed.price_total_sales_no_options', 0)
                ->where('lines.0.computed.price_total_ordered_no_options', 0)
                ->where('lines.0.computed.price_total_gain_no_options', 0)
            );
    }

    // --- PATCH: protection ------------------------------------------------------------

    public function test_the_update_endpoint_requires_authentication(): void
    {
        $line = $this->line();

        $this->patchJson("/api/metre-lines/{$line->id}", ['quantity' => 5])
            ->assertUnauthorized();
    }

    /** @return array<string, array{string, mixed}> */
    public static function protectedFieldProvider(): array
    {
        return [
            // Derived per-line values: no column behind them at all.
            'derived sales total' => ['price_total_sales_no_options', 999],
            'derived ordered total' => ['price_total_ordered_no_options', 999],
            'derived margin' => ['price_total_gain_no_options', 999],
            // Materialized by RecalculateMetreTotals.
            'stored lot name' => ['lot_name_stored', 'forged'],
            // Not editable from the grid for other reasons.
            'metre reassignment' => ['metre_id', 'some-other-metre'],
            'supplier order link' => ['supplier_order_id', 'SOR-1'],
            'vat link' => ['vat_value_id', 'VAT-1'],
            'denormalized snapshot' => ['ref_title', 'forged'],
            'sequence number' => ['sequence_number', 7],
        ];
    }

    #[DataProvider('protectedFieldProvider')]
    public function test_it_rejects_any_field_outside_the_editable_whitelist(string $field, mixed $value): void
    {
        $this->actingAs(User::factory()->create());
        $line = $this->line();

        $this->patchJson("/api/metre-lines/{$line->id}", [$field => $value])
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);
    }

    public function test_rejecting_a_stored_column_explains_why(): void
    {
        $this->actingAs(User::factory()->create());
        $line = $this->line();

        $this->patchJson("/api/metre-lines/{$line->id}", ['lot_name_stored' => 'forged'])
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.lot_name_stored.0',
                '[lot_name_stored] is materialized by RecalculateMetreTotals and cannot be written directly.'
            );
    }

    public function test_rejecting_a_derived_total_explains_why(): void
    {
        $this->actingAs(User::factory()->create());
        $line = $this->line();

        $this->patchJson("/api/metre-lines/{$line->id}", ['price_total_gain_no_options' => 1])
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.price_total_gain_no_options.0',
                '[price_total_gain_no_options] is a derived per-line total with no column behind it; it cannot be written.'
            );
    }

    public function test_a_rejected_batch_writes_nothing_at_all(): void
    {
        $this->actingAs(User::factory()->create());
        $line = $this->line(['quantity' => 2]);

        // One legitimate field alongside one forbidden field: the whole batch must fail.
        $this->patchJson("/api/metre-lines/{$line->id}", [
            'quantity' => 99,
            'price_total_sales_no_options' => 1,
        ])->assertStatus(422);

        $this->assertEquals(2, (float) $line->refresh()->quantity);
    }

    // --- PATCH: happy path ------------------------------------------------------------

    public function test_it_applies_a_batch_of_editable_fields_in_one_call(): void
    {
        $this->actingAs(User::factory()->create());
        $line = $this->line();

        $this->patchJson("/api/metre-lines/{$line->id}", [
            'description' => 'Modifiée',
            'quantity' => 3,
            'price_sales' => 150,
            'is_option_b' => true,
        ])->assertOk();

        $line->refresh();
        $this->assertSame('Modifiée', $line->description);
        $this->assertEquals(3, (float) $line->quantity);
        $this->assertEquals(150, (float) $line->price_sales);
        $this->assertTrue($line->is_option_b);
    }

    public function test_the_response_carries_the_recomputed_totals(): void
    {
        $this->actingAs(User::factory()->create());
        $line = $this->line();

        // 3 * 150 = 450 sales; ordered untouched at 80 * 2 = 160; margin 290.
        $this->patchJson("/api/metre-lines/{$line->id}", ['quantity' => 3, 'price_sales' => 150])
            ->assertOk()
            ->assertJsonPath('data.computed.price_total_sales_no_options', 450)
            ->assertJsonPath('data.computed.price_total_ordered_no_options', 160)
            ->assertJsonPath('data.computed.price_total_gain_no_options', 290);
    }

    public function test_flagging_a_line_as_an_option_zeroes_its_totals_in_the_response(): void
    {
        $this->actingAs(User::factory()->create());
        $line = $this->line();

        $this->patchJson("/api/metre-lines/{$line->id}", ['is_option_b' => true])
            ->assertOk()
            ->assertJsonPath('data.computed.price_total_sales_no_options', 0)
            ->assertJsonPath('data.computed.price_total_gain_no_options', 0);
    }

    public function test_saving_a_line_queues_the_metre_recalculation(): void
    {
        $this->actingAs(User::factory()->create());
        $line = $this->line();
        Queue::fake();

        $this->patchJson("/api/metre-lines/{$line->id}", ['quantity' => 9])->assertOk();

        Queue::assertPushed(RecalculateMetreTotals::class);
    }

    public function test_null_clears_an_optional_value(): void
    {
        $this->actingAs(User::factory()->create());
        $line = $this->line();

        $this->patchJson("/api/metre-lines/{$line->id}", ['price_buy' => null, 'description' => null])
            ->assertOk();

        $line->refresh();
        $this->assertNull($line->price_buy);
        $this->assertNull($line->description);
    }

    // --- PATCH: catalogue consistency -------------------------------------------------

    public function test_it_rejects_a_sub_reference_belonging_to_another_reference(): void
    {
        $this->actingAs(User::factory()->create());
        $line = $this->line();

        $otherReference = Reference::forceCreate(['code' => 20]);
        $foreignSub = SubReference::forceCreate(['reference_id' => $otherReference->id, 'code' => 201]);

        $this->patchJson("/api/metre-lines/{$line->id}", ['sub_reference_id' => $foreignSub->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('sub_reference_id');
    }

    public function test_it_accepts_a_reference_and_sub_reference_changed_together(): void
    {
        $this->actingAs(User::factory()->create());
        $line = $this->line();

        $otherReference = Reference::forceCreate(['code' => 20]);
        $itsSub = SubReference::forceCreate(['reference_id' => $otherReference->id, 'code' => 201]);

        // This is the batch the grid sends when the reference select changes.
        $this->patchJson("/api/metre-lines/{$line->id}", [
            'reference_id' => $otherReference->id,
            'sub_reference_id' => $itsSub->id,
        ])->assertOk();

        $line->refresh();
        $this->assertSame($otherReference->id, $line->reference_id);
        $this->assertSame($itsSub->id, $line->sub_reference_id);
    }

    public function test_clearing_the_sub_reference_is_allowed(): void
    {
        $this->actingAs(User::factory()->create());
        $line = $this->line();

        $this->patchJson("/api/metre-lines/{$line->id}", ['sub_reference_id' => null])->assertOk();

        $this->assertNull($line->refresh()->sub_reference_id);
    }

    public function test_it_rejects_an_unknown_reference(): void
    {
        $this->actingAs(User::factory()->create());
        $line = $this->line();

        $this->patchJson("/api/metre-lines/{$line->id}", ['reference_id' => (string) Str::uuid()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reference_id');
    }

    // --- quantity_ordered: an independent quantity, not a mirror of quantity -----------

    public function test_quantity_ordered_is_editable(): void
    {
        $this->actingAs(User::factory()->create());
        $line = $this->line(['unit' => 'm2']);

        $this->patchJson("/api/metre-lines/{$line->id}", ['quantity_ordered' => 7.5])->assertOk();

        $this->assertEquals(7.5, (float) $line->refresh()->quantity_ordered);
    }

    public function test_editing_quantity_leaves_quantity_ordered_alone(): void
    {
        $this->actingAs(User::factory()->create());
        $line = $this->line(['unit' => 'm2', 'quantity' => 2, 'quantity_ordered' => 2]);

        // The two are independent: METL_METC_UpdateQuantities feeds each from its own
        // component sum, and nothing in the source copies one into the other.
        $this->patchJson("/api/metre-lines/{$line->id}", ['quantity' => 10])->assertOk();

        $line->refresh();
        $this->assertEquals(10, (float) $line->quantity);
        $this->assertEquals(2, (float) $line->quantity_ordered);
    }

    public function test_editing_quantity_ordered_leaves_quantity_alone(): void
    {
        $this->actingAs(User::factory()->create());
        $line = $this->line(['unit' => 'm2', 'quantity' => 2, 'quantity_ordered' => 2]);

        $this->patchJson("/api/metre-lines/{$line->id}", ['quantity_ordered' => 10])->assertOk();

        $line->refresh();
        $this->assertEquals(2, (float) $line->quantity);
        $this->assertEquals(10, (float) $line->quantity_ordered);
    }

    public function test_the_ordered_total_follows_quantity_ordered_not_quantity(): void
    {
        $this->actingAs(User::factory()->create());
        $line = $this->line(['unit' => 'm2', 'price_ordered' => 80, 'quantity_ordered' => 2]);

        // 80 * 5 = 400, driven by quantity_ordered alone.
        $this->patchJson("/api/metre-lines/{$line->id}", ['quantity_ordered' => 5])
            ->assertOk()
            ->assertJsonPath('data.computed.price_total_ordered_no_options', 400);
    }

    /** @return array<string, array{string}> */
    public static function pourMemoireUnitProvider(): array
    {
        // FileMaker text comparison ignores case, so every spelling matches.
        return ['lower' => ['pm'], 'upper' => ['PM'], 'mixed' => ['Pm'], 'padded' => [' pm ']];
    }

    #[DataProvider('pourMemoireUnitProvider')]
    public function test_a_pour_memoire_unit_forces_the_ordered_quantity_empty(string $unit): void
    {
        $this->actingAs(User::factory()->create());
        $line = $this->line(['unit' => $unit, 'quantity_ordered' => 3]);

        // QuantityOrdered auto-enter: Case ( Unit = "pm" ; "" ; Self )
        $this->patchJson("/api/metre-lines/{$line->id}", ['quantity_ordered' => 9])
            ->assertOk()
            ->assertJsonPath('data.quantity_ordered', null);

        $this->assertNull($line->refresh()->quantity_ordered);
    }

    #[DataProvider('pourMemoireUnitProvider')]
    public function test_a_pour_memoire_unit_forces_the_sales_quantity_empty_too(string $unit): void
    {
        $this->actingAs(User::factory()->create());
        $line = $this->line(['unit' => $unit, 'quantity' => 4]);

        // Quantity carries the same auto-enter as QuantityOrdered:
        //   Case ( Unit = "pm" ; "" ; Self )
        $this->patchJson("/api/metre-lines/{$line->id}", ['quantity' => 6])
            ->assertOk()
            ->assertJsonPath('data.quantity', null);

        $this->assertNull($line->refresh()->quantity);
    }

    public function test_a_pour_memoire_line_carries_no_quantity_at_all(): void
    {
        $this->actingAs(User::factory()->create());
        $line = $this->line(['unit' => 'm2', 'quantity' => 4, 'quantity_ordered' => 3]);

        // Switching the unit to pm empties both, because the auto-enter re-evaluates when
        // Unit changes - not only when a quantity does.
        $line->forceFill(['unit' => 'pm'])->save();

        $line->refresh();
        $this->assertNull($line->quantity);
        $this->assertNull($line->quantity_ordered);
    }

    public function test_a_pour_memoire_line_reports_every_total_at_zero(): void
    {
        $this->actingAs(User::factory()->create());
        $line = $this->line([
            'unit' => 'pm',
            'price_sales' => 100,
            'price_ordered' => 80,
            'quantity' => 4,
            'quantity_ordered' => 3,
        ]);

        // A placeholder line is priced later, so nothing it holds may reach a total.
        $this->patchJson("/api/metre-lines/{$line->id}", ['price_ordered' => 90])
            ->assertOk()
            ->assertJsonPath('data.quantity', null)
            ->assertJsonPath('data.quantity_ordered', null)
            ->assertJsonPath('data.computed.price_total_sales_no_options', 0)
            ->assertJsonPath('data.computed.price_total_ordered_no_options', 0)
            ->assertJsonPath('data.computed.price_total_gain_no_options', 0);
    }

    public function test_a_non_pour_memoire_unit_leaves_both_quantities_alone(): void
    {
        $this->actingAs(User::factory()->create());
        // "pmt" and "m" must not match a rule keyed on exactly "pm".
        foreach (['m2', 'pmt', 'm'] as $unit) {
            $line = $this->line(['unit' => $unit, 'quantity' => 4, 'quantity_ordered' => 3]);

            $this->patchJson("/api/metre-lines/{$line->id}", ['quantity' => 6])->assertOk();

            $line->refresh();
            $this->assertEquals(6, (float) $line->quantity, "unit {$unit}");
            $this->assertEquals(3, (float) $line->quantity_ordered, "unit {$unit}");
        }
    }

    public function test_the_grid_exposes_the_unit_so_the_cell_can_disable_itself(): void
    {
        $this->actingAs(User::factory()->create());
        $this->line(['unit' => 'pm']);

        $this->get("/metres/{$this->metre->id}/lines")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('lines.0.unit', 'pm'));
    }

    // --- the locked-metre guard --------------------------------------------------------

    public function test_it_refuses_to_write_into_a_locked_metre(): void
    {
        $this->actingAs(User::factory()->create());
        $line = $this->line(['quantity' => 2]);
        $this->metre->forceFill(['is_locked_b' => true])->save();

        // METL_METC_UpdateQuantities: If [ met__MET__::isLocked_b ] -> "Métré verrouillé" -> -1
        $this->patchJson("/api/metre-lines/{$line->id}", ['quantity' => 99])
            ->assertStatus(423)
            ->assertJsonPath('message', 'Ce métré est verrouillé : ses lignes ne peuvent plus être modifiées.');

        $this->assertEquals(2, (float) $line->refresh()->quantity);
    }

    public function test_an_unlocked_metre_still_accepts_writes(): void
    {
        $this->actingAs(User::factory()->create());
        $line = $this->line();

        $this->patchJson("/api/metre-lines/{$line->id}", ['quantity' => 99])->assertOk();
    }

    public function test_it_rejects_a_non_numeric_quantity(): void
    {
        $this->actingAs(User::factory()->create());
        $line = $this->line();

        $this->patchJson("/api/metre-lines/{$line->id}", ['quantity' => 'beaucoup'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('quantity');
    }
}
