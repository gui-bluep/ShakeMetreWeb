<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class Lot extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    public function metreLines(): HasMany
    {
        return $this->hasMany(MetreLine::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Supplier tender scoring
    |--------------------------------------------------------------------------
    |
    | Transcribed from METL_MetreLines::zsm_TENDER_Total_Supp{n}_Final_Score_cU and the two
    | fields it builds on. The lot-level numbers are genuine sums across every metre line
    | assigned to the lot - unlike the MET_Metre totals, where a FileMaker "Total of X" summary
    | evaluated inside one record resolves to that record's own value.
    |
    | There is deliberately no counterpart to LOT_Lot::TENDER_Supp{n}_Total_cU. That field is
    | stale: its own source starts with
    |
    |     /*TENDER_Supp{n}_WeightedPricePercentage_cU +*\/
    |     zsm_TENDER_Total_Supp{n}_BestPricePercentage_Score_cU + ...
    |
    | - the weighted price term commented out and replaced by the raw, unweighted score, so it
    | ignores TENDER_Weighting_Price entirely. The METL formula applies the weighting. Keeping
    | both would mean two scores that disagree, so only the METL one exists here.
    */

    /**
     * Larger than any total decimal(15,4) can hold, used to keep a non-quoting supplier out of
     * a LEAST() without turning the whole expression null.
     */
    private const NO_QUOTE_SENTINEL = '10000000000000';

    /** @var array<string, float|null>|null */
    private ?array $tenderTotals = null;

    /**
     * Σ TENDER_Supp{n}_TotalPriceNoOption_c over every metre line of this lot.
     *
     * One aggregate query for all five suppliers plus the best-price column, memoised, so
     * scoring five suppliers costs one round trip rather than five - or, worse, one per line.
     */
    public function sumForSupplier(int $supplier): float
    {
        $this->assertSupplierSlot($supplier);

        return (float) ($this->tenderTotals()["supplier_{$supplier}"] ?? 0.0);
    }

    /**
     * Σ TENDER_BestPriceNoOption_c - the sum of each line's cheapest quote.
     *
     * Deliberately not "the cheapest supplier's total": taking the minimum per line and then
     * summing gives a benchmark no single supplier need match, which is what makes every
     * percentage below sit at or above 100.
     */
    public function bestPriceSum(): float
    {
        return (float) ($this->tenderTotals()['best'] ?? 0.0);
    }

    /**
     * METL_MetreLines::zsm_TENDER_Total_Supp{n}_BestPricePercentage_c
     *
     *     Case ( not IsEmpty ( zsm_TENDER_Total_Supp{n} ) ;
     *            Round ( zsm_TENDER_Total_Supp{n} / zsm_TENDER_Total_BestPriceNoOption * 100 ; 0 ) ; "" )
     *
     * DIVERGENCE FROM THE SOURCE, on purpose: this returns null when the supplier's own sum is
     * zero, where FileMaker would compute a percentage of 0. A supplier who quoted nothing sums
     * to 0, and 0 is not empty, so the source's IsEmpty test does not catch it - it would score
     * 200 on price and rank first. Guarding it here means a non-bidder simply has no price
     * score. Say the word and it can be made literal instead.
     */
    public function bestPricePercentageForSupplier(int $supplier): ?float
    {
        $total = $this->sumForSupplier($supplier);
        $best = $this->bestPriceSum();

        if ($total <= 0 || $best <= 0) {
            return null;
        }

        return round($total / $best * 100);
    }

    /**
     * METL_MetreLines::zsm_TENDER_Total_Supp{n}_BestPricePercentage_Score_cU
     *
     *     Case (
     *       IsEmpty ( TENDER_Weighting_Price ) or TENDER_Weighting_Price = 0 ; "" ;
     *       not IsEmpty ( zsm_TENDER_Total_Supp{n} ) ; ( 100 - ( percentage - 100 ) ) ;
     *       "" )
     *
     * `100 - (percentage - 100)` is `200 - percentage`, which is where the constant comes from:
     * the cheapest supplier sits at 100% and scores 100, and every point above the benchmark
     * costs one point of score.
     */
    public function priceScoreForSupplier(int $supplier): ?float
    {
        $weighting = (float) ($this->tender_weighting_price ?? 0);

        if ($weighting === 0.0) {
            return null;
        }

        $percentage = $this->bestPricePercentageForSupplier($supplier);

        return $percentage === null ? null : 200 - $percentage;
    }

    /**
     * METL_MetreLines::zsm_TENDER_Total_Supp{n}_Final_Score_cU
     *
     *     ( TENDER_Weighting_Price /100 * <price score> ) +
     *     ( TENDER_Weighting_Crit1 /100 * TENDER_Weighting_Crit1_Supp{n} ) +
     *     ... through Crit5
     *
     * The export truncates this one after the Crit2 term; the five-criteria shape is taken from
     * the repeating pattern and from TENDER_Weighting_Total_c, which sums exactly Price plus
     * Crit1 through Crit5.
     *
     * An absent price score or criterion note contributes 0, matching FileMaker's arithmetic on
     * empty values, so a lot with no price weighting scores purely on its criteria.
     */
    public function finalScoreForSupplier(int $supplier): float
    {
        $this->assertSupplierSlot($supplier);

        $score = ((float) ($this->tender_weighting_price ?? 0) / 100)
            * (float) ($this->priceScoreForSupplier($supplier) ?? 0);

        foreach (range(1, 5) as $criterion) {
            $score += ((float) ($this->{"tender_weighting_crit{$criterion}"} ?? 0) / 100)
                * (float) ($this->{"tender_weighting_crit{$criterion}_supp{$supplier}"} ?? 0);
        }

        return round($score, 2);
    }

    /**
     * Every supplier's final score, highest first, keyed by supplier slot.
     *
     * @return array<int, float>
     */
    public function tenderRanking(): array
    {
        $scores = [];

        foreach (MetreLine::SUPPLIER_SLOTS as $supplier) {
            $scores[$supplier] = $this->finalScoreForSupplier($supplier);
        }

        arsort($scores);

        return $scores;
    }

    /**
     * @return array<string, float|null>
     */
    private function tenderTotals(): array
    {
        if ($this->tenderTotals !== null) {
            return $this->tenderTotals;
        }

        $columns = [];

        foreach (MetreLine::SUPPLIER_SLOTS as $supplier) {
            $columns[] = 'COALESCE(SUM('.self::supplierTotalSql($supplier).'), 0) AS supplier_'.$supplier;
        }

        $columns[] = 'COALESCE(SUM('.self::bestPricePerLineSql().'), 0) AS best';

        $row = DB::table('metre_lines')
            ->where('lot_id', $this->getKey())
            ->selectRaw(implode(', ', $columns))
            ->first();

        return $this->tenderTotals = (array) $row;
    }

    /**
     * TENDER_Supp{n}_TotalPriceNoOption_c, as SQL: an option line contributes nothing.
     */
    private static function supplierTotalSql(int $supplier): string
    {
        return 'CASE WHEN metre_lines.is_option_b = 1 THEN 0 ELSE ROUND('
            ."COALESCE(metre_lines.tender_supp{$supplier}_price, 0) * "
            ."COALESCE(metre_lines.tender_supp{$supplier}_quantity, 0), 2) END";
    }

    /**
     * The cheapest quote on a line, or NULL when nobody quoted it.
     *
     * LEAST() returns NULL if any argument is NULL, so a non-quoting supplier cannot simply be
     * nulled out: each total becomes the sentinel instead, and the sentinel is mapped back to
     * NULL afterwards. SUM then skips those lines, which is what makes a line nobody priced
     * absent from the benchmark rather than counted as zero.
     */
    private static function bestPricePerLineSql(): string
    {
        $quoted = array_map(
            fn (int $supplier) => 'COALESCE(NULLIF('.self::supplierTotalSql($supplier).', 0), '.self::NO_QUOTE_SENTINEL.')',
            MetreLine::SUPPLIER_SLOTS,
        );

        return 'NULLIF('.self::scalarMinFunction().'('.implode(', ', $quoted).'), '.self::NO_QUOTE_SENTINEL.')';
    }

    /**
     * The row-wise minimum has no portable name: MySQL calls it LEAST, while in SQLite - which
     * the test suite runs on - MIN with more than one argument is the scalar form and LEAST does
     * not exist at all.
     */
    private static function scalarMinFunction(): string
    {
        return DB::connection()->getDriverName() === 'sqlite' ? 'MIN' : 'LEAST';
    }

    private function assertSupplierSlot(int $supplier): void
    {
        if (! in_array($supplier, MetreLine::SUPPLIER_SLOTS, true)) {
            throw new InvalidArgumentException("Supplier slot must be one of 1-5, got {$supplier}.");
        }
    }

    /**
     * Cross-system reference: resolved via ShakeDesignClient::findProject() against
     * ShakeDesign's PRJ_Projects. Not a local Eloquent relation.
     */
    public function projectId(): ?string
    {
        return $this->project_id;
    }

    /**
     * Cross-system reference: resolved via ShakeDesignClient::findCompany() against
     * ShakeDesign's CPY_Companies. Not a local Eloquent relation.
     */
    public function companyId(): ?string
    {
        return $this->company_id;
    }

    /**
     * Cross-system reference: resolved via ShakeDesignClient::findContact() against
     * ShakeDesign's CTC_Contacts. Not a local Eloquent relation.
     */
    public function contactId(): ?string
    {
        return $this->contact_id;
    }

    /**
     * Cross-system references: resolved via ShakeDesignClient::findCompany() against
     * ShakeDesign's CPY_Companies (candidate tender suppliers 1..5). Not local
     * Eloquent relations.
     */
    public function tenderSupplier1Id(): ?string
    {
        return $this->tender_supplier_1_id;
    }

    public function tenderSupplier2Id(): ?string
    {
        return $this->tender_supplier_2_id;
    }

    public function tenderSupplier3Id(): ?string
    {
        return $this->tender_supplier_3_id;
    }

    public function tenderSupplier4Id(): ?string
    {
        return $this->tender_supplier_4_id;
    }

    public function tenderSupplier5Id(): ?string
    {
        return $this->tender_supplier_5_id;
    }
}
