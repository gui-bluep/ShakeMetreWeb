<?php

namespace Tests\Feature;

use App\Models\Metre;
use App\Models\MetreLine;
use App\Models\MetreLineComponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * METC_MetreLineComponent::ValueSales_c / ValueOrdered_c.
 *
 * The point of this suite is the symmetry: the source computes the two differently (only the
 * sales formula wraps its dimensions in `Evaluate`), and this port deliberately does not.
 */
class MetreLineComponentValueTest extends TestCase
{
    use RefreshDatabase;

    private MetreLine $line;

    protected function setUp(): void
    {
        parent::setUp();

        $metre = Metre::forceCreate(['name' => 'Métré']);
        $this->line = MetreLine::forceCreate(['metre_id' => $metre->id]);
    }

    private function makeComponent(array $attributes): MetreLineComponent
    {
        return MetreLineComponent::forceCreate($attributes + ['metre_line_id' => $this->line->id]);
    }

    /** @return array<string, array{array<string, mixed>, float}> */
    public static function dimensionProvider(): array
    {
        return [
            'all three dimensions' => [['length' => 2, 'width' => 3, 'height' => 4], 24.0],
            'length only' => [['length' => 2.5], 2.5],
            'no dimension at all' => [[], 1.0],
            'a zero dimension zeroes the result' => [['length' => 0, 'width' => 3], 0.0],
            'fractional, rounded to two decimals' => [['length' => 1.005, 'width' => 1], 1.01],
        ];
    }

    #[DataProvider('dimensionProvider')]
    public function test_the_sales_value_multiplies_the_quantity_by_its_dimensions(array $dimensions, float $expected): void
    {
        $component = $this->makeComponent($dimensions + ['quantity_sales' => 1]);

        $this->assertSame($expected, $component->value_sales);
    }

    #[DataProvider('dimensionProvider')]
    public function test_the_ordered_value_behaves_exactly_like_the_sales_value(array $dimensions, float $expected): void
    {
        $component = $this->makeComponent($dimensions + ['quantity_ordered' => 1]);

        $this->assertSame($expected, $component->value_ordered);
    }

    public function test_both_values_agree_for_the_same_quantity_and_dimensions(): void
    {
        // The divergence this port removes: in FileMaker only the sales formula evaluates its
        // dimensions, so the two could disagree. Here they cannot.
        $component = $this->makeComponent([
            'quantity_sales' => 3,
            'quantity_ordered' => 3,
            'length' => 2,
            'width' => 1.5,
            'height' => 4,
        ]);

        $this->assertSame($component->value_sales, $component->value_ordered);
        $this->assertSame(36.0, $component->value_sales);
    }

    public function test_each_value_reads_its_own_quantity(): void
    {
        $component = $this->makeComponent([
            'quantity_sales' => 10,
            'quantity_ordered' => 2,
            'length' => 3,
        ]);

        $this->assertSame(30.0, $component->value_sales);
        $this->assertSame(6.0, $component->value_ordered);
    }

    public function test_a_missing_quantity_yields_zero_not_the_dimension_product(): void
    {
        // FileMaker: empty * n = 0.
        $component = $this->makeComponent(['length' => 2, 'width' => 3]);

        $this->assertSame(0.0, $component->value_sales);
        $this->assertSame(0.0, $component->value_ordered);
    }

    public function test_an_absent_dimension_does_not_participate_while_a_zero_one_does(): void
    {
        $absent = $this->makeComponent(['quantity_sales' => 5, 'length' => 2]);
        $zero = $this->makeComponent(['quantity_sales' => 5, 'length' => 2, 'width' => 0]);

        // Case ( not IsEmpty ( _w ) ; _w ; 1 ) - an IsEmpty test, not a falsiness test.
        $this->assertSame(10.0, $absent->value_sales);
        $this->assertSame(0.0, $zero->value_sales);
    }
}
