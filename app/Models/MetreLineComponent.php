<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetreLineComponent extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * METC_MetreLineComponent::ValueSales_c - a dimensioned quantity:
     *
     *   Let ( [ _qty = QuantitySales ; _l = Evaluate ( Length ) ;
     *           _h = Evaluate ( Height ) ; _w = Evaluate ( Width ) ] ;
     *     Round ( _qty
     *       * Case ( not IsEmpty ( _l ) ; _l ; 1 )
     *       * Case ( not IsEmpty ( _w ) ; _w ; 1 )
     *       * Case ( not IsEmpty ( _h ) ; _h ; 1 ) ; 2 ) )
     */
    protected function valueSales(): Attribute
    {
        return Attribute::get(fn (): float => $this->dimensionedValue($this->quantity_sales));
    }

    /**
     * METC_MetreLineComponent::ValueOrdered_c - identical to ValueSales_c above except that
     * the source reads the dimensions literally (`_l = Length`) where the sales formula wraps
     * each in `Evaluate ( ... )`.
     *
     * DELIBERATE DIVERGENCE FROM THE SOURCE: both go through the same helper here, so the two
     * behave identically. See the note on dimensionedValue() for why `Evaluate` has no
     * counterpart in this schema at all.
     */
    protected function valueOrdered(): Attribute
    {
        return Attribute::get(fn (): float => $this->dimensionedValue($this->quantity_ordered));
    }

    /**
     * Shared on purpose: one implementation is a stronger guarantee that the sales and ordered
     * values agree than two transcriptions that merely look alike.
     *
     * On `Evaluate`: in FileMaker the dimension fields are Number, but Number fields there are
     * loosely typed and will happily hold what a surveyor types - which is why the sales
     * formula wraps them in `Evaluate`, so "2+3" reads as 5 rather than 23. This schema stores
     * them as decimal(15,4), which cannot hold an expression at all, so there is nothing to
     * evaluate and the distinction cannot arise. Reproducing that capability would mean
     * migrating the three columns to strings - a schema decision, not a formula one.
     *
     * An absent dimension counts as 1 (it does not participate); a dimension of 0 stays 0 and
     * zeroes the result, matching FileMaker's IsEmpty test rather than a falsiness test.
     */
    private function dimensionedValue(mixed $quantity): float
    {
        $factor = fn (mixed $dimension): float => $dimension === null ? 1.0 : (float) $dimension;

        return round(
            (float) ($quantity ?? 0)
                * $factor($this->length)
                * $factor($this->width)
                * $factor($this->height),
            2,
        );
    }

    public function metreLine(): BelongsTo
    {
        return $this->belongsTo(MetreLine::class);
    }
}
