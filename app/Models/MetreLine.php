<?php

namespace App\Models;

use App\Observers\MetreLineObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
