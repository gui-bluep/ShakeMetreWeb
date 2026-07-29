<?php

namespace App\Models;

use App\Observers\MetreLineObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

#[ObservedBy(MetreLineObserver::class)]
class MetreLine extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'is_option_b' => 'boolean',
            'is_locked_bae' => 'boolean',
            'is_imported_b' => 'boolean',
            'is_tender_line_b' => 'boolean',
            'is_estimated_price_b' => 'boolean',
            'is_delivered_b' => 'boolean',
            'metc_is_present_b' => 'boolean',
            'tender_supp1_omit_b' => 'boolean',
            'tender_supp2_omit_b' => 'boolean',
            'tender_supp3_omit_b' => 'boolean',
            'tender_supp4_omit_b' => 'boolean',
            'tender_supp5_omit_b' => 'boolean',
        ];
    }

    /**
     * The three per-line totals the metre grid displays. They are NOT columns and never
     * were: in FileMaker they are unstored calculations (`_c`), so there is nothing to
     * write - which is what makes them structurally read-only rather than merely
     * read-only by convention.
     *
     * RecalculateMetreTotals sums these same expressions in SQL to materialize the metre's
     * aggregates; the formulas are transcribed identically here. Both derive from
     * ShakeMetre_data_dictionary.json (table METL_MetreLines).
     */

    /** PriceTotalSales_noOptions_c: Case ( isOption_b ; 0 ; Round ( PriceSales * Quantity ; 2 ) ) */
    protected function priceTotalSalesNoOptions(): Attribute
    {
        return Attribute::get(fn (): float => $this->is_option_b
            ? 0.0
            : round((float) $this->price_sales * (float) $this->quantity, 2));
    }

    /** PriceTotalOrdered_noOptions_c: Case ( isOption_b ; 0 ; Round ( PriceOrdered * QuantityOrdered ; 2 ) ) */
    protected function priceTotalOrderedNoOptions(): Attribute
    {
        return Attribute::get(fn (): float => $this->is_option_b
            ? 0.0
            : round((float) $this->price_ordered * (float) $this->quantity_ordered, 2));
    }

    /** PriceTotalGain_noOptions_c: PriceTotalSales_noOptions_c - PriceTotalOrdered_noOptions_c */
    protected function priceTotalGainNoOptions(): Attribute
    {
        return Attribute::get(fn (): float => round(
            $this->price_total_sales_no_options - $this->price_total_ordered_no_options,
            2,
        ));
    }

    /** A tender compares five candidate suppliers per lot, no more and no fewer. */
    public const SUPPLIER_SLOTS = [1, 2, 3, 4, 5];

    /**
     * METL_MetreLines::TENDER_Supp{n}_TotalPriceNoOption_c
     *
     *     Case ( isOption_b ; "" ; TENDER_Supp{n}_TotalPrice_c )
     *     TENDER_Supp{n}_TotalPrice_c = Round ( TENDER_Supp{n}_Quantity * TENDER_Supp{n}_Price ; 2 )
     *
     * Note the source keeps these as two fields: the bare TotalPrice_c has no option guard and
     * feeds the per-line best price, while the NoOption variant is what the lot-level summaries
     * add up. This method is the NoOption one - the option guard is what makes it return null.
     *
     * A supplier who quoted nothing yields 0.0, not null: FileMaker multiplies empty by empty
     * and rounds, which gives 0. Only an option line is empty.
     */
    public function totalPriceForSupplier(int $supplier): ?float
    {
        $this->assertSupplierSlot($supplier);

        if ($this->is_option_b) {
            return null;
        }

        return round(
            (float) $this->{"tender_supp{$supplier}_price"} * (float) $this->{"tender_supp{$supplier}_quantity"},
            2,
        );
    }

    /**
     * METL_MetreLines::TENDER_BestPrice_c - the cheapest quote on this line.
     *
     * The source reads:
     *
     *     Let ( [ _min = Min ( TENDER_Supp1_TotalPrice_c ; ... ; TENDER_Supp5_TotalPrice_c ) ] ;
     *       Case ( <<truncated in the export>>
     *
     * Two things about that, both of which the implementation has to decide without the source:
     *
     *  - The export caps every calculation at 250 characters, and this one is cut off exactly at
     *    its Case condition. The `is_tender_line_b` guard below is what the requirement
     *    specified, not something the export confirms - no formula in it references that field
     *    at all.
     *  - A supplier who has not quoted totals 0, and 0 is not empty, so a literal Min over the
     *    five values would return 0 as soon as one supplier is missing - collapsing every
     *    percentage that divides by it. Suppliers who did not quote are therefore excluded.
     *    Whatever the truncated Case does, it must do something equivalent, or the metric
     *    could not work at all.
     */
    public function bestPriceAmongSuppliers(): ?float
    {
        if (! $this->is_tender_line_b) {
            return 0.0;
        }

        $quoted = array_filter(
            array_map(fn (int $n) => $this->totalPriceForSupplier($n), self::SUPPLIER_SLOTS),
            fn (?float $total) => $total !== null && $total > 0,
        );

        return $quoted === [] ? null : min($quoted);
    }

    /**
     * METL_MetreLines::TENDER_Supp{n}_BestPricePercentage_c
     *
     *     Case ( not IsEmpty ( TENDER_Supp{n}_TotalPrice_c ) ;
     *            Round ( TENDER_Supp{n}_TotalPrice_c / TENDER_BestPrice_c * 100 ; "" ) ; "" )
     *
     * `Round ( x ; "" )` is FileMaker for zero decimals, so the percentage is a whole number.
     * 100 means this supplier is the cheapest on the line; 120 means twenty percent above it.
     *
     * This is the per-line percentage. The lot-level score uses Lot's own percentage, computed
     * from the summed totals - the two are different numbers and the source keeps them apart.
     */
    public function bestPricePercentageForSupplier(int $supplier): ?float
    {
        $total = $this->totalPriceForSupplier($supplier);
        $best = $this->bestPriceAmongSuppliers();

        if ($total === null || $total <= 0 || $best === null || $best <= 0) {
            return null;
        }

        return round($total / $best * 100);
    }

    private function assertSupplierSlot(int $supplier): void
    {
        if (! in_array($supplier, self::SUPPLIER_SLOTS, true)) {
            throw new InvalidArgumentException(
                "Supplier slot must be one of 1-5, got {$supplier}."
            );
        }
    }

    /**
     * Whether this line is "composed" - its quantities come from its components rather than
     * being typed in.
     *
     * Derived from the relation, never from metc_is_present_b. That column exists (Phase 1
     * migrated FileMaker's own denormalized flag) but a flag maintained alongside the rows it
     * describes is a second source of truth that drifts the moment one write path forgets it.
     *
     * Reads a withCount() result when the caller loaded one, so a grid of hundreds of lines
     * costs one query rather than one per row.
     */
    public function hasComponents(): bool
    {
        if ($this->relationLoaded('metreLineComponents')) {
            return $this->metreLineComponents->isNotEmpty();
        }

        if ($this->metre_line_components_count !== null) {
            return $this->metre_line_components_count > 0;
        }

        return $this->metreLineComponents()->exists();
    }

    public function metre(): BelongsTo
    {
        return $this->belongsTo(Metre::class);
    }

    public function reference(): BelongsTo
    {
        return $this->belongsTo(Reference::class);
    }

    public function subReference(): BelongsTo
    {
        return $this->belongsTo(SubReference::class);
    }

    public function subReferenceLine(): BelongsTo
    {
        return $this->belongsTo(SubReferenceLine::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function cartMaterial(): BelongsTo
    {
        return $this->belongsTo(CartMaterial::class);
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }

    public function metreLineComponents(): HasMany
    {
        return $this->hasMany(MetreLineComponent::class);
    }

    /**
     * Cross-system reference: resolved via ShakeDesignClient against ShakeDesign's
     * SOR_SupplierOrders. Not a local Eloquent relation.
     */
    public function supplierOrderId(): ?string
    {
        return $this->supplier_order_id;
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
     * Cross-system reference: resolved via ShakeDesignClient::findVatValue() against
     * ShakeDesign's ZVAL_Values. Not a local Eloquent relation.
     */
    public function vatValueId(): ?string
    {
        return $this->vat_value_id;
    }

    /**
     * Cross-system reference: resolved via ShakeDesignClient against ShakeDesign's
     * ZVAL_Values (accounting code). Not a local Eloquent relation.
     */
    public function accountingCodeId(): ?string
    {
        return $this->accounting_code_id;
    }
}
