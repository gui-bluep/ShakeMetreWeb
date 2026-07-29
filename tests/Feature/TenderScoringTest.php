<?php

namespace Tests\Feature;

use App\Actions\SelectTenderSupplier;
use App\Exceptions\TenderSupplierMismatchException;
use App\Models\Lot;
use App\Models\Metre;
use App\Models\MetreLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Supplier tender scoring, transcribed from METL_MetreLines::zsm_TENDER_Total_Supp{n}_Final_Score_cU.
 *
 * The central case is worked out by hand below so the expected numbers are checkable without
 * re-running the implementation that produced them.
 */
class TenderScoringTest extends TestCase
{
    use RefreshDatabase;

    private Metre $metre;

    private Lot $lot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->metre = Metre::forceCreate(['name' => 'Métré', 'project_id' => 'PRJ-1']);

        /*
         * Weightings total 100: price 60, criterion 1 (technique) 25, criterion 2 (délai) 15.
         *
         * Criteria notes, out of 100:
         *              supp1   supp2   supp3
         *   crit1        80      60     100
         *   crit2        50      90      70
         */
        $this->lot = Lot::forceCreate([
            'code' => 1,
            'project_id' => 'PRJ-1',
            'tender_weighting_price' => 60,
            'tender_weighting_crit1' => 25,
            'tender_weighting_crit2' => 15,
            'tender_weighting_crit1_supp1' => 80,
            'tender_weighting_crit1_supp2' => 60,
            'tender_weighting_crit1_supp3' => 100,
            'tender_weighting_crit2_supp1' => 50,
            'tender_weighting_crit2_supp2' => 90,
            'tender_weighting_crit2_supp3' => 70,
        ]);
    }

    /**
     * @param  array<int, array{qty: float, price: float}>  $quotes  keyed by supplier slot
     */
    private function line(array $quotes, array $attributes = []): MetreLine
    {
        $row = $attributes + [
            'metre_id' => $this->metre->id,
            'lot_id' => $this->lot->id,
            'is_tender_line_b' => true,
            'description' => 'Ligne '.Str::random(4),
        ];

        foreach ($quotes as $supplier => $quote) {
            $row["tender_supp{$supplier}_quantity"] = $quote['qty'];
            $row["tender_supp{$supplier}_price"] = $quote['price'];
        }

        return MetreLine::forceCreate($row);
    }

    /**
     * The worked example, two lines and three bidders:
     *
     *              supp1        supp2        supp3
     *   line A   10 x 10 = 100  10 x 12 = 120  10 x  9 =  90   cheapest  90
     *   line B    5 x 20 = 100   5 x 18 =  90   5 x 22 = 110   cheapest  90
     *   sum          200            210            200         benchmark 180
     *
     * Percentages against the 180 benchmark, rounded to whole numbers:
     *   supp1 200/180 = 111.11 -> 111    supp2 210/180 = 116.67 -> 117    supp3 -> 111
     *
     * Price scores, 200 - percentage:
     *   supp1  89          supp2  83          supp3  89
     *
     * Final, 0.60 x price + 0.25 x crit1 + 0.15 x crit2:
     *   supp1  0.6*89 + 0.25*80 + 0.15*50 = 53.4 + 20   + 7.5  = 80.9
     *   supp2  0.6*83 + 0.25*60 + 0.15*90 = 49.8 + 15   + 13.5 = 78.3
     *   supp3  0.6*89 + 0.25*100 + 0.15*70 = 53.4 + 25  + 10.5 = 88.9
     */
    private function seedWorkedExample(): void
    {
        $this->line([
            1 => ['qty' => 10, 'price' => 10],
            2 => ['qty' => 10, 'price' => 12],
            3 => ['qty' => 10, 'price' => 9],
        ]);

        $this->line([
            1 => ['qty' => 5, 'price' => 20],
            2 => ['qty' => 5, 'price' => 18],
            3 => ['qty' => 5, 'price' => 22],
        ]);
    }

    // --- per line -----------------------------------------------------------------------

    public function test_a_line_total_is_quantity_times_price_rounded(): void
    {
        // 2.125 is exactly representable in binary, so this isolates the rounding rule from
        // float representation: 3 * 2.125 = 6.375, rounded half away from zero gives 6.38.
        $line = $this->line([1 => ['qty' => 3, 'price' => 2.125]]);

        $this->assertSame(6.38, $line->totalPriceForSupplier(1));
    }

    public function test_float_arithmetic_can_disagree_with_filemaker_by_one_cent(): void
    {
        $line = $this->line([1 => ['qty' => 3, 'price' => 1.005]]);

        /*
         * Recorded rather than hidden. FileMaker computes in decimal: 3 x 1.005 is exactly
         * 3.015 and rounds to 3.02. PHP computes in binary floats, where the product is
         * 3.0149999999999997, which rounds to 3.01.
         *
         * The same gap exists between the SQL aggregates below and FileMaker, and between
         * SQLite's floating-point ROUND and MySQL's exact DECIMAL one. Closing it means
         * carrying these amounts as decimal strings through every calculation - a change worth
         * making deliberately, across the whole tender and totals chain at once, rather than
         * here in passing.
         */
        $this->assertSame(3.01, $line->totalPriceForSupplier(1));
    }

    public function test_an_option_line_has_no_supplier_total(): void
    {
        $line = $this->line([1 => ['qty' => 10, 'price' => 10]], ['is_option_b' => true]);

        // Case ( isOption_b ; "" ; ... ) - empty, which is null here and not zero.
        $this->assertNull($line->totalPriceForSupplier(1));
    }

    public function test_a_supplier_who_did_not_quote_totals_zero_not_null(): void
    {
        // FileMaker multiplies empty by empty and rounds, which gives 0.
        $line = $this->line([1 => ['qty' => 10, 'price' => 10]]);

        $this->assertSame(0.0, $line->totalPriceForSupplier(4));
    }

    public function test_the_best_price_on_a_line_ignores_suppliers_who_did_not_quote(): void
    {
        $line = $this->line([
            1 => ['qty' => 10, 'price' => 10],
            2 => ['qty' => 10, 'price' => 8],
        ]);

        // A literal Min over five values would return 0, since slots 3-5 total 0.
        $this->assertSame(80.0, $line->bestPriceAmongSuppliers());
    }

    public function test_a_line_outside_the_tender_has_no_best_price(): void
    {
        $line = $this->line([1 => ['qty' => 10, 'price' => 10]], ['is_tender_line_b' => false]);

        $this->assertSame(0.0, $line->bestPriceAmongSuppliers());
    }

    public function test_the_per_line_percentage_is_relative_to_that_line(): void
    {
        $line = $this->line([
            1 => ['qty' => 1, 'price' => 120],
            2 => ['qty' => 1, 'price' => 100],
        ]);

        $this->assertSame(120.0, $line->bestPricePercentageForSupplier(1));
        $this->assertSame(100.0, $line->bestPricePercentageForSupplier(2));
    }

    public function test_a_supplier_slot_outside_one_to_five_is_refused(): void
    {
        $line = $this->line([1 => ['qty' => 1, 'price' => 1]]);

        $this->expectException(InvalidArgumentException::class);
        $line->totalPriceForSupplier(6);
    }

    // --- lot level sums -----------------------------------------------------------------

    public function test_it_sums_a_supplier_across_every_line_of_the_lot(): void
    {
        $this->seedWorkedExample();

        $this->assertSame(200.0, $this->lot->sumForSupplier(1));
        $this->assertSame(210.0, $this->lot->sumForSupplier(2));
        $this->assertSame(200.0, $this->lot->sumForSupplier(3));
    }

    public function test_the_benchmark_is_the_sum_of_each_line_cheapest_quote(): void
    {
        $this->seedWorkedExample();

        // 90 + 90, which no single supplier matches - that is the point of the benchmark.
        $this->assertSame(180.0, $this->lot->bestPriceSum());
        $this->assertLessThan($this->lot->sumForSupplier(1), $this->lot->bestPriceSum());
    }

    public function test_option_lines_are_left_out_of_the_sums(): void
    {
        $this->line([1 => ['qty' => 1, 'price' => 100]]);
        $this->line([1 => ['qty' => 1, 'price' => 999]], ['is_option_b' => true]);

        $this->assertSame(100.0, $this->lot->sumForSupplier(1));
    }

    public function test_lines_of_another_lot_are_left_out(): void
    {
        $other = Lot::forceCreate(['code' => 2, 'project_id' => 'PRJ-1']);
        $this->line([1 => ['qty' => 1, 'price' => 100]]);
        $this->line([1 => ['qty' => 1, 'price' => 500]], ['lot_id' => $other->id]);

        $this->assertSame(100.0, $this->lot->sumForSupplier(1));
    }

    public function test_a_line_nobody_quoted_is_absent_from_the_benchmark(): void
    {
        $this->line([1 => ['qty' => 2, 'price' => 50]]);
        $this->line([]); // no quote at all

        // 100, not 100 + 0: an unpriced line must not drag the benchmark down.
        $this->assertSame(100.0, $this->lot->bestPriceSum());
    }

    public function test_the_sums_cost_a_single_query(): void
    {
        $this->seedWorkedExample();
        $lot = Lot::findOrFail($this->lot->id);

        DB::enableQueryLog();
        foreach (MetreLine::SUPPLIER_SLOTS as $supplier) {
            $lot->sumForSupplier($supplier);
        }
        $lot->bestPriceSum();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Aggregated and memoised: five suppliers plus the benchmark, one round trip.
        $this->assertCount(1, $queries);
    }

    // --- the score -----------------------------------------------------------------------

    public function test_the_lot_percentage_is_relative_to_the_benchmark(): void
    {
        $this->seedWorkedExample();

        $this->assertSame(111.0, $this->lot->bestPricePercentageForSupplier(1));
        $this->assertSame(117.0, $this->lot->bestPricePercentageForSupplier(2));
        $this->assertSame(111.0, $this->lot->bestPricePercentageForSupplier(3));
    }

    public function test_the_price_score_is_two_hundred_minus_the_percentage(): void
    {
        $this->seedWorkedExample();

        // 100 - (percentage - 100), which is 200 - percentage.
        $this->assertSame(89.0, $this->lot->priceScoreForSupplier(1));
        $this->assertSame(83.0, $this->lot->priceScoreForSupplier(2));
        $this->assertSame(89.0, $this->lot->priceScoreForSupplier(3));
    }

    public function test_the_cheapest_supplier_scores_one_hundred_on_price(): void
    {
        // A single line, so the benchmark equals the cheapest supplier's total.
        $this->line([1 => ['qty' => 1, 'price' => 100], 2 => ['qty' => 1, 'price' => 150]]);

        $this->assertSame(100.0, $this->lot->priceScoreForSupplier(1));
        // 150/100 = 150% -> 200 - 150 = 50
        $this->assertSame(50.0, $this->lot->priceScoreForSupplier(2));
    }

    public function test_the_final_score_combines_price_and_criteria(): void
    {
        $this->seedWorkedExample();

        $this->assertSame(80.9, $this->lot->finalScoreForSupplier(1));
        $this->assertSame(78.3, $this->lot->finalScoreForSupplier(2));
        $this->assertSame(88.9, $this->lot->finalScoreForSupplier(3));
    }

    public function test_the_ranking_puts_the_best_overall_offer_first(): void
    {
        $this->seedWorkedExample();

        $ranking = $this->lot->tenderRanking();

        // Supplier 3 wins on criteria despite tying supplier 1 on price; supplier 2 is cheapest
        // on line B yet finishes last, which is the whole reason for weighting.
        $this->assertSame([3, 1, 2], array_slice(array_keys($ranking), 0, 3));
        $this->assertSame(88.9, reset($ranking));
    }

    public function test_criteria_alone_can_overturn_the_cheapest_price(): void
    {
        // One line, supplier 1 clearly cheaper, but hopeless on both criteria.
        $this->lot->forceFill([
            'tender_weighting_crit1_supp1' => 0,
            'tender_weighting_crit2_supp1' => 0,
            'tender_weighting_crit1_supp2' => 100,
            'tender_weighting_crit2_supp2' => 100,
        ])->save();

        $this->line([1 => ['qty' => 1, 'price' => 100], 2 => ['qty' => 1, 'price' => 130]]);

        // supp1: 0.6*100 + 0 + 0 = 60 ; supp2: 0.6*(200-130) + 0.25*100 + 0.15*100 = 42 + 40 = 82
        $this->assertSame(60.0, $this->lot->finalScoreForSupplier(1));
        $this->assertSame(82.0, $this->lot->finalScoreForSupplier(2));
        $this->assertSame(2, array_key_first($this->lot->tenderRanking()));
    }

    public function test_a_supplier_who_did_not_quote_gets_no_price_score(): void
    {
        $this->seedWorkedExample();

        // Slots 4 and 5 never quoted. Scoring them 200 on price - which a literal reading of
        // the source produces - would rank them first.
        $this->assertNull($this->lot->priceScoreForSupplier(4));
        $this->assertSame(0.0, $this->lot->finalScoreForSupplier(4));
    }

    public function test_without_a_price_weighting_the_score_is_criteria_only(): void
    {
        $this->lot->forceFill(['tender_weighting_price' => 0])->save();
        $this->seedWorkedExample();

        // Case ( IsEmpty ( TENDER_Weighting_Price ) or = 0 ; "" ; ... )
        $this->assertNull($this->lot->priceScoreForSupplier(1));
        // 0.25*80 + 0.15*50 = 20 + 7.5
        $this->assertSame(27.5, $this->lot->finalScoreForSupplier(1));
    }

    public function test_a_lot_with_no_lines_scores_on_criteria_alone(): void
    {
        $this->assertSame(0.0, $this->lot->bestPriceSum());
        $this->assertNull($this->lot->priceScoreForSupplier(1));
        $this->assertSame(27.5, $this->lot->finalScoreForSupplier(1));
    }

    public function test_all_five_criteria_are_taken_into_account(): void
    {
        // The export truncates the formula after criterion 2; this pins the full shape.
        $lot = Lot::forceCreate([
            'code' => 9,
            'project_id' => 'PRJ-1',
            'tender_weighting_price' => 0,
            'tender_weighting_crit1' => 20,
            'tender_weighting_crit2' => 20,
            'tender_weighting_crit3' => 20,
            'tender_weighting_crit4' => 20,
            'tender_weighting_crit5' => 20,
            'tender_weighting_crit1_supp1' => 100,
            'tender_weighting_crit2_supp1' => 100,
            'tender_weighting_crit3_supp1' => 100,
            'tender_weighting_crit4_supp1' => 100,
            'tender_weighting_crit5_supp1' => 100,
        ]);

        $this->assertSame(100.0, $lot->finalScoreForSupplier(1));
    }

    // --- awarding the lot -----------------------------------------------------------------

    /** Puts a company in a supplier slot, which is what awarding that slot requires. */
    private function bidder(int $supplier, ?string $company = null): string
    {
        $company ??= (string) Str::uuid();
        $this->lot->forceFill(["tender_supplier_{$supplier}_id" => $company])->save();

        return $company;
    }

    public function test_selecting_a_supplier_records_the_company(): void
    {
        $company = $this->bidder(3);

        (new SelectTenderSupplier)->handle($this->lot, 3, $company);

        $this->assertSame($company, $this->lot->refresh()->company_id);
    }

    public function test_selecting_a_supplier_writes_nothing_else(): void
    {
        $this->seedWorkedExample();
        $company = $this->bidder(3);
        $before = $this->lot->refresh()->only([
            'tender_weighting_price', 'tender_weighting_crit1_supp3', 'contact_id', 'tender_supplier_3_id',
        ]);

        (new SelectTenderSupplier)->handle($this->lot, 3, $company);

        // The quotes and notes are the record of how the decision was reached; awarding the lot
        // must not disturb them.
        $this->assertSame($before, $this->lot->refresh()->only(array_keys($before)));
    }

    public function test_awarding_a_slot_to_the_wrong_company_is_refused(): void
    {
        $this->bidder(3, 'CPY-QUOTED-FOR-SLOT-3');
        $intruder = 'CPY-NEVER-QUOTED';

        try {
            (new SelectTenderSupplier)->handle($this->lot, 3, $intruder);
            $this->fail('Expected TenderSupplierMismatchException.');
        } catch (TenderSupplierMismatchException $e) {
            $this->assertSame(3, $e->supplierNumber);
            $this->assertSame('CPY-QUOTED-FOR-SLOT-3', $e->expectedCompanyId);
            $this->assertSame($intruder, $e->givenCompanyId);
        }

        // Nothing written: the mismatch would otherwise be invisible afterwards.
        $this->assertNull($this->lot->refresh()->company_id);
    }

    public function test_awarding_the_company_of_a_different_slot_is_refused(): void
    {
        // The realistic mistake: a screen reads the slot from one place and the company from
        // another, and they fall out of step by one.
        $this->bidder(1, 'CPY-ONE');
        $this->bidder(2, 'CPY-TWO');

        $this->expectException(TenderSupplierMismatchException::class);
        (new SelectTenderSupplier)->handle($this->lot, 1, 'CPY-TWO');
    }

    public function test_awarding_a_slot_that_nobody_quoted_is_refused(): void
    {
        try {
            // Slot 4 holds no company at all.
            (new SelectTenderSupplier)->handle($this->lot, 4, 'CPY-SOMEONE');
            $this->fail('Expected TenderSupplierMismatchException.');
        } catch (TenderSupplierMismatchException $e) {
            $this->assertNull($e->expectedCompanyId);
            $this->assertStringContainsString('never quoted', $e->getMessage());
        }

        $this->assertNull($this->lot->refresh()->company_id);
    }

    public function test_a_differently_cased_or_padded_zkp_is_still_the_same_company(): void
    {
        $canonical = 'AABBCCDD-1122-3344-5566-778899AABBCC';
        $this->bidder(2, $canonical);

        // Two different zkps cannot differ only in case, so accepting this is exactly as strict
        // as a byte comparison - and avoids refusing a legitimate award over formatting.
        (new SelectTenderSupplier)->handle($this->lot, 2, '  aabbccdd-1122-3344-5566-778899aabbcc  ');

        $lot = $this->lot->refresh();

        // Written as the slot spells it, not as the caller typed it: company_id is looked up
        // verbatim against ShakeDesign, so tolerating a case variant in the comparison must not
        // be a way of persisting one.
        $this->assertSame($canonical, $lot->company_id);
        $this->assertSame($lot->tender_supplier_2_id, $lot->company_id);
    }

    public function test_the_award_always_matches_the_slot_byte_for_byte(): void
    {
        $canonical = 'DEADBEEF-0000-1111-2222-333344445555';
        $this->bidder(4, $canonical);

        foreach ([$canonical, strtolower($canonical), " {$canonical} "] as $spelling) {
            (new SelectTenderSupplier)->handle($this->lot, 4, $spelling);

            $lot = $this->lot->refresh();
            $this->assertSame($canonical, $lot->company_id, "spelling: {$spelling}");
            $this->assertSame($lot->tender_supplier_4_id, $lot->company_id);
        }
    }

    public function test_the_mismatch_exception_is_not_an_argument_error(): void
    {
        // Callers should be able to catch the integrity failure without also swallowing a
        // genuine programming mistake like an out-of-range slot.
        $this->bidder(1, 'CPY-ONE');

        $this->assertNotInstanceOf(
            InvalidArgumentException::class,
            TenderSupplierMismatchException::slotHasNoCompany($this->lot, 1, 'CPY-X'),
        );
    }

    public function test_selecting_an_invalid_slot_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new SelectTenderSupplier)->handle($this->lot, 0, (string) Str::uuid());
    }

    public function test_selecting_without_a_company_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new SelectTenderSupplier)->handle($this->lot, 1, '   ');
    }
}
