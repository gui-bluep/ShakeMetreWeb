<?php

namespace Tests\Feature;

use App\Jobs\RecalculateMetreTotals;
use App\Models\Lot;
use App\Models\Metre;
use App\Models\MetreLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RecalculateMetreTotalsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sums_line_totals_excluding_options(): void
    {
        $metre = $this->metre(['is_status_site_b' => true, 'is_accepted_b' => true]);

        // sales 200, ordered 160, buy 180, gain 40
        $this->line($metre, ['price_sales' => 100, 'price_ordered' => 80, 'price_buy' => 90, 'quantity' => 2, 'quantity_ordered' => 2]);

        // An option line contributes 0 to every *_noOptions total.
        $this->line($metre, ['is_option_b' => true, 'price_sales' => 999, 'price_ordered' => 999, 'price_buy' => 999, 'quantity' => 9, 'quantity_ordered' => 9]);

        // sales 50, ordered 40, buy 45, gain 10
        $this->line($metre, ['price_sales' => 50, 'price_ordered' => 40, 'price_buy' => 45, 'quantity' => 1, 'quantity_ordered' => 1]);

        (new RecalculateMetreTotals($metre))->handle();
        $metre->refresh();

        $this->assertEquals(250.0, (float) $metre->tot_sum_total_sales_stored);
        $this->assertEquals(200.0, (float) $metre->tot_sum_total_ordered_stored);
        $this->assertEquals(225.0, (float) $metre->tot_sum_total_buy_stored);
        $this->assertEquals(50.0, (float) $metre->tot_sum_total_gain_stored);
        $this->assertEquals(250.0, (float) $metre->tot_sum_total_sales_offer_stored);
        $this->assertNotNull($metre->date_time_update_calcs_stored);
    }

    /**
     * The two inputs of Ratio_c. Nothing wrote them before, so the métré ratio was empty on
     * every screen that shows it; they are Σ sales and Σ buy over the lines, on the assumption
     * spelled out in the job (the export carries no formula for either).
     */
    public function test_it_writes_the_two_ratio_inputs(): void
    {
        $metre = $this->metre(['is_status_site_b' => true, 'is_accepted_b' => true]);

        // sales 250, buy 200 -> ratio 1.25
        $this->line($metre, ['price_sales' => 100, 'price_buy' => 80, 'quantity' => 2]);
        $this->line($metre, ['price_sales' => 50, 'price_buy' => 40, 'quantity' => 1]);
        $this->line($metre, ['is_option_b' => true, 'price_sales' => 999, 'price_buy' => 999, 'quantity' => 9]);

        (new RecalculateMetreTotals($metre))->handle();
        $metre->refresh();

        $this->assertEquals(250.0, (float) $metre->total_sales_metl_stored);
        $this->assertEquals(200.0, (float) $metre->total_purchase_metl_stored);
        $this->assertSame(1.25, $metre->ratio());
    }

    /**
     * Ungated, unlike every sibling total: the ratio describes what the métré says, so it must
     * not disappear because nobody has ticked "accepté" or "site" yet. That is exactly how it
     * read as a permanently empty column.
     */
    public function test_the_ratio_inputs_are_not_gated_on_acceptance_or_site(): void
    {
        $metre = $this->metre(['is_status_site_b' => false, 'is_accepted_b' => false]);
        $this->line($metre, ['price_sales' => 100, 'price_buy' => 80, 'quantity' => 2]);

        (new RecalculateMetreTotals($metre))->handle();
        $metre->refresh();

        $this->assertEquals(200.0, (float) $metre->total_sales_metl_stored);
        $this->assertEquals(160.0, (float) $metre->total_purchase_metl_stored);
        $this->assertSame(1.25, $metre->ratio());

        // While the gated totals stay empty, as their own formulas require.
        $this->assertNull($metre->tot_sum_total_sales_stored);
        $this->assertNull($metre->tot_sum_total_buy_stored);
    }

    /**
     * A métré with no purchase price anywhere divides by zero: Ratio_c yields empty, and the
     * column keeps the honest 0 rather than a made-up ratio.
     */
    public function test_a_metre_with_no_purchase_price_has_no_ratio(): void
    {
        $metre = $this->metre();
        $this->line($metre, ['price_sales' => 100, 'quantity' => 2]);

        (new RecalculateMetreTotals($metre))->handle();
        $metre->refresh();

        $this->assertEquals(0.0, (float) $metre->total_purchase_metl_stored);
        $this->assertNull($metre->ratio());
    }

    public function test_status_site_gate_empties_totals_but_not_the_offer_total(): void
    {
        $metre = $this->metre(['is_status_site_b' => false, 'is_accepted_b' => false]);
        $this->line($metre, ['price_sales' => 100, 'price_ordered' => 80, 'price_buy' => 90, 'quantity' => 2, 'quantity_ordered' => 2]);

        (new RecalculateMetreTotals($metre))->handle();
        $metre->refresh();

        // Case ( flag ; Sum (...) ; "" ) -> empty, not zero.
        $this->assertNull($metre->tot_sum_total_sales_stored);
        $this->assertNull($metre->tot_sum_total_ordered_stored);
        $this->assertNull($metre->tot_sum_total_gain_stored);
        $this->assertNull($metre->tot_lot_assigned_gain_on_purchase_stored);

        // Tot_Sum_TotalBuy_cU is gated on isAccepted_b.
        $this->assertNull($metre->tot_sum_total_buy_stored);

        // Tot_Sum_TotalSalesOffer_cU has no gate at all.
        $this->assertEquals(200.0, (float) $metre->tot_sum_total_sales_offer_stored);
    }

    public function test_buy_total_follows_is_accepted_not_status_site(): void
    {
        $metre = $this->metre(['is_status_site_b' => false, 'is_accepted_b' => true]);
        $this->line($metre, ['price_buy' => 90, 'price_sales' => 100, 'price_ordered' => 80, 'quantity' => 2, 'quantity_ordered' => 2]);

        (new RecalculateMetreTotals($metre))->handle();
        $metre->refresh();

        $this->assertEquals(180.0, (float) $metre->tot_sum_total_buy_stored);
        $this->assertNull($metre->tot_sum_total_sales_stored);
    }

    public function test_lot_gain_only_counts_lines_whose_lot_has_a_supplier_company(): void
    {
        $metre = $this->metre(['is_status_site_b' => true, 'is_accepted_b' => true]);

        $assignedLot = Lot::forceCreate(['code' => 1, 'company_id' => (string) Str::uuid()]);
        $unassignedLot = Lot::forceCreate(['code' => 2]);

        // buy 45 - ordered 40 = 5, and its lot has a company -> counted
        $this->line($metre, ['lot_id' => $assignedLot->id, 'price_buy' => 45, 'price_ordered' => 40, 'price_sales' => 50, 'quantity' => 1, 'quantity_ordered' => 1]);

        // lot without company -> not counted
        $this->line($metre, ['lot_id' => $unassignedLot->id, 'price_buy' => 900, 'price_ordered' => 100, 'price_sales' => 950, 'quantity' => 1, 'quantity_ordered' => 1]);

        // no lot at all -> not counted
        $this->line($metre, ['price_buy' => 700, 'price_ordered' => 100, 'price_sales' => 750, 'quantity' => 1, 'quantity_ordered' => 1]);

        (new RecalculateMetreTotals($metre))->handle();
        $metre->refresh();

        $this->assertEquals(5.0, (float) $metre->tot_lot_assigned_gain_on_purchase_stored);
    }

    public function test_rounding_happens_per_line_before_summing(): void
    {
        $metre = $this->metre(['is_status_site_b' => true, 'is_accepted_b' => true]);

        // Each line rounds to 0.34; summing the rounded values gives 0.68, whereas summing
        // first (0.335 + 0.335 = 0.67) then rounding would give 0.67.
        $this->line($metre, ['price_sales' => 0.335, 'quantity' => 1]);
        $this->line($metre, ['price_sales' => 0.335, 'quantity' => 1]);

        (new RecalculateMetreTotals($metre))->handle();
        $metre->refresh();

        $this->assertEquals(0.68, (float) $metre->tot_sum_total_sales_stored);
    }

    public function test_progress_valid_totals_are_gated_by_their_status_flag(): void
    {
        $metre = $this->metre([
            'is_status_site_b' => false,
            'is_accepted_b' => true,
            'prog_progress_client_total_amount_stored' => 500,
            'prog_progress_client_total_percent_stored' => 25,
            'prog_progress_supp_total_amount_stored' => 400,
            'prog_progress_supp_total_percent_stored' => 20,
        ]);

        (new RecalculateMetreTotals($metre))->handle();
        $metre->refresh();

        // * isAccepted_b (true)
        $this->assertEquals(500.0, (float) $metre->prog_progress_client_total_amount_valid_stored_c);
        $this->assertEquals(25.0, (float) $metre->prog_progress_client_total_percent_valid_stored_c);

        // * IsStatus_Site_b (false)
        $this->assertEquals(0.0, (float) $metre->prog_progress_supp_total_amount_valid_stored_c);
        $this->assertEquals(0.0, (float) $metre->prog_progress_supp_total_percent_valid_stored_c);
    }

    public function test_it_derives_the_fees_total_percentage_and_ratio(): void
    {
        $metre = $this->metre(['is_status_site_b' => true, 'is_accepted_b' => true]);

        // sales 250, ordered 200 -> fees 50, percentage 20%, ratio 1.25
        $this->line($metre, ['price_sales' => 250, 'price_ordered' => 200, 'quantity' => 1, 'quantity_ordered' => 1]);

        (new RecalculateMetreTotals($metre))->handle();
        $metre->refresh();

        $this->assertEquals(50.0, (float) $metre->tot_sum_total_fees_stored);
        $this->assertEquals(20.0, (float) $metre->tot_percentage_total_fees_stored);
        $this->assertEquals(1.25, (float) $metre->tot_ratio_total_fees_stored);
    }

    public function test_fees_ratios_stay_empty_instead_of_dividing_by_zero(): void
    {
        // No lines at all -> sales and ordered are both 0, so both denominators are zero.
        $metre = $this->metre(['is_status_site_b' => true, 'is_accepted_b' => true]);

        (new RecalculateMetreTotals($metre))->handle();
        $metre->refresh();

        $this->assertEquals(0.0, (float) $metre->tot_sum_total_fees_stored);
        $this->assertNull($metre->tot_percentage_total_fees_stored);
        $this->assertNull($metre->tot_ratio_total_fees_stored);
    }

    public function test_metre_with_no_lines_totals_zero_like_filemaker_sum(): void
    {
        $metre = $this->metre(['is_status_site_b' => true, 'is_accepted_b' => true]);

        (new RecalculateMetreTotals($metre))->handle();
        $metre->refresh();

        $this->assertEquals(0.0, (float) $metre->tot_sum_total_sales_stored);
        $this->assertEquals(0.0, (float) $metre->tot_sum_total_buy_stored);
    }

    private function metre(array $attributes = []): Metre
    {
        return Metre::forceCreate($attributes + ['name' => 'Metre']);
    }

    private function line(Metre $metre, array $attributes = []): MetreLine
    {
        return MetreLine::forceCreate($attributes + ['metre_id' => $metre->id]);
    }
}
