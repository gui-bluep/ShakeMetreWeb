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

    /**
     * PriceTotalBuy_noOptions_c: Case ( isOption_b ; 0 ; Round ( PriceBuy * Quantity ; 2 ) )
     *
     * Note the multiplier is Quantity, the same column PriceTotalSales uses - PriceBuy has no
     * quantity of its own. Only the ordered total has a separate one (QuantityOrdered).
     */
    protected function priceTotalBuyNoOptions(): Attribute
    {
        return Attribute::get(fn (): float => $this->is_option_b
            ? 0.0
            : round((float) $this->price_buy * (float) $this->quantity, 2));
    }

    /*
     * PriceTotal{Buy,Sales,Ordered}All_c - the same three products WITHOUT the option guard:
     *
     *     Round ( PriceBuy * Quantity ; 2 )
     *
     * The distinction is the point, and the list layout uses both: a line's own Total column shows
     * the All figure, so an option line displays what it would cost, while every subtotal and the
     * métré's stored totals use the noOptions variant, so that amount is not counted. Showing 0,00 €
     * on an option line - which is what this application did - hides the number the option exists
     * to state.
     */

    /** PriceTotalBuyAll_c: Round ( PriceBuy * Quantity ; 2 ) */
    protected function priceTotalBuyAll(): Attribute
    {
        return Attribute::get(fn (): float => round((float) $this->price_buy * (float) $this->quantity, 2));
    }

    /** PriceTotalSalesAll_c: Round ( PriceSales * Quantity ; 2 ) */
    protected function priceTotalSalesAll(): Attribute
    {
        return Attribute::get(fn (): float => round((float) $this->price_sales * (float) $this->quantity, 2));
    }

    /** PriceTotalOrderedAll_c: Round ( PriceOrdered * QuantityOrdered ; 2 ) */
    protected function priceTotalOrderedAll(): Attribute
    {
        return Attribute::get(fn (): float => round((float) $this->price_ordered * (float) $this->quantity_ordered, 2));
    }

    /**
     * The line's margin ratio: unit price sold to the client over unit purchase price.
     *
     * Derived, never stored. METL_MetreLines carries a `Ratio` column - a plain Number with no
     * formula anywhere in the export, so nothing could say what maintained it - and this
     * deliberately replaces it rather than reading it: a value computed from the two prices
     * beside it cannot drift out of step with them, which a stored copy can. The column is left
     * untouched in the database, holding whatever FileMaker put there.
     *
     * Null rather than a number when either price is absent, and null rather than an error when
     * the purchase price is zero - the same guard, and the same reasoning, as MET_Metre::Ratio_c
     * (`Case ( not IsEmpty ( a ) and not IsEmpty ( b ) ; ... ; "" )`). A sales price of exactly 0
     * is a real answer, not an absent one, so it yields 0.
     */
    protected function priceRatio(): Attribute
    {
        return Attribute::get(function (): ?float {
            $sales = $this->price_sales;
            $buy = $this->price_buy;

            if ($sales === null || $buy === null || (float) $buy === 0.0) {
                return null;
            }

            return round((float) $sales / (float) $buy, 2);
        });
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
     * The un-truncated export reads:
     *
     *     Let ( [ _min = Min ( TENDER_Supp1_TotalPrice_c ; ... ; TENDER_Supp5_TotalPrice_c ) ] ;
     *       Case ( isTenderLine_b ; 0 ; _min ) )
     *
     * Read literally, a tender line scores 0 here - the opposite of what the name and every
     * caller expect, since it is exactly the tender lines this metric exists to compare, and a
     * lot made up entirely of tender lines would benchmark at zero. isTenderLine_b carries no
     * formula and no comment anywhere else in the export, so there is nothing to confirm which
     * reading - or which polarity - is intended.
     *
     * The deliberate choice here is to drop the guard rather than reproduce it: this method
     * computes the per-line minimum unconditionally, and callers filter is_tender_line_b at the
     * query that selects which lines to compare (the tender-comparison endpoint), never inside
     * the price calculation itself. That keeps a single, unconditional meaning for "the cheapest
     * quote on this line" regardless of what the flag turns out to mean.
     *
     * Separately: a supplier who has not quoted totals 0, and 0 is not empty, so a literal Min
     * over the five values would return 0 as soon as one supplier is missing - collapsing every
     * percentage that divides by it. Suppliers who did not quote are therefore excluded from
     * the minimum; whatever the source's own Min does, it must do something equivalent, or the
     * metric could not work at all.
     */
    public function bestPriceAmongSuppliers(): ?float
    {
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

    /**
     * Les trois montants d'une ligne, hors options, en SQL - `PriceTotal*_noOptions_c`.
     *
     *     PriceTotalBuy_noOptions_c     Case ( isOption_b ; 0 ; Round ( PriceBuy * Quantity ; 2 ) )
     *     PriceTotalSales_noOptions_c   Case ( isOption_b ; 0 ; Round ( PriceSales * Quantity ; 2 ) )
     *     PriceTotalOrdered_noOptions_c Case ( isOption_b ; 0 ; Round ( PriceOrdered * QuantityOrdered ; 2 ) )
     *
     * Ici plutôt que dans le job qui les employait seul, parce qu'ils ont maintenant deux lecteurs :
     * `RecalculateMetreTotals` et la répartition par lot de la carte Fournisseur. Deux copies de la
     * même expression finiraient par ne plus dire la même chose, et ce sont des montants.
     *
     * Les colonnes sont préfixées de leur table : ces fragments servent aussi sous une jointure.
     * `COALESCE` reprend FileMaker, où un nombre vide vaut 0 dans un calcul.
     */
    public const SQL_BUY_NO_OPTIONS = 'CASE WHEN metre_lines.is_option_b = 1 THEN 0 ELSE ROUND(COALESCE(metre_lines.price_buy, 0) * COALESCE(metre_lines.quantity, 0), 2) END';

    public const SQL_SALES_NO_OPTIONS = 'CASE WHEN metre_lines.is_option_b = 1 THEN 0 ELSE ROUND(COALESCE(metre_lines.price_sales, 0) * COALESCE(metre_lines.quantity, 0), 2) END';

    public const SQL_ORDERED_NO_OPTIONS = 'CASE WHEN metre_lines.is_option_b = 1 THEN 0 ELSE ROUND(COALESCE(metre_lines.price_ordered, 0) * COALESCE(metre_lines.quantity_ordered, 0), 2) END';

    /**
     * Les mêmes sans le garde-fou « option » - `PriceTotal*All_c`, ce qu'une ligne affiche.
     *
     * En SQL et non par les accesseurs du même nom, pour les documents imprimés : `round()` de PHP
     * travaille sur un flottant binaire et la base sur un décimal, et les deux ne tranchent pas
     * pareil un demi-centime. Sur le métré de démonstration, 357 lignes sommées en PHP donnaient
     * 2 426 250,06 € contre 2 426 250,08 € pour le total stocké du métré, calculé par ces
     * fragments-ci. Deux centimes, sur un document qui part chez un client à côté d'une page qui
     * affiche l'autre chiffre. Un document compte donc comme le métré compte.
     *
     * Pas d'équivalent « achat » : aucun des sept documents ne montre le prix d'achat.
     */
    public const SQL_SALES_ALL = 'ROUND(COALESCE(metre_lines.price_sales, 0) * COALESCE(metre_lines.quantity, 0), 2)';

    public const SQL_ORDERED_ALL = 'ROUND(COALESCE(metre_lines.price_ordered, 0) * COALESCE(metre_lines.quantity_ordered, 0), 2)';

    public function metre(): BelongsTo
    {
        return $this->belongsTo(Metre::class);
    }

    public function reference(): BelongsTo
    {
        return $this->belongsTo(Reference::class);
    }

    /**
     * METL_MetreLines::REFSL_Code_c - the line's printed code, "20.2.2".
     *
     *     REF_Code & "." & REFS_Code & "." & Order
     *
     * Written by METL_SetREFSLCode in the source and re-read here instead, for the same reason
     * the line ratio is computed rather than read: a stored copy can disagree with the three
     * numbers it is made of, a derived one cannot.
     *
     * Null unless all three are present. A line with no section has no code, which is the
     * honest answer - the live file has such lines.
     */
    public function refLineCode(): ?string
    {
        foreach ([$this->ref_code, $this->refs_code, $this->ref_order] as $part) {
            if ($part === null || $part === '') {
                return null;
            }
        }

        return "{$this->ref_code}.{$this->refs_code}.{$this->ref_order}";
    }

    /**
     * The next `ref_order` inside one section of one métré - METL_New's
     * `SELECT MAX(Order) … WHERE zkf_MET = ? AND REF = ? AND REFS = ?` + 1.
     *
     * The source renumbers the whole group to 1..n first (METL_Reorder) and then appends; that
     * renumbering exists because FileMaker had no reliable way to keep the ranks contiguous.
     * Here max + 1 is enough: a gap left by a deleted line is not worth renumbering everybody's
     * printed code for, and the code has to keep meaning the same line on a document already
     * sent to a client.
     */
    public static function nextRefOrder(string $metreId, ?int $refCode, ?int $refsCode): int
    {
        return 1 + (int) static::query()
            ->where('metre_id', $metreId)
            // Un code absent est un groupe comme un autre : `= null` ne matcherait jamais.
            ->when($refCode === null, fn ($q) => $q->whereNull('ref_code'), fn ($q) => $q->where('ref_code', $refCode))
            ->when($refsCode === null, fn ($q) => $q->whereNull('refs_code'), fn ($q) => $q->where('refs_code', $refsCode))
            ->max('ref_order');
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
