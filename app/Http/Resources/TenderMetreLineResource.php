<?php

namespace App\Http\Resources;

use App\Models\MetreLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the tender comparison grid: a metre line and its five candidate suppliers'
 * quotes.
 *
 * Always carries all five slots, same as the lot's own scoring keeps all five suppliers -
 * the comparison page decides which columns to render from the lot's assigned suppliers, not
 * from what a given line happens to have quoted.
 *
 * `total` is MetreLine::totalPriceForSupplier(), read-only: quoting price and quantity are
 * editable through PATCH /api/metre-lines/{id}, the total is computed from them.
 *
 * @mixin MetreLine
 */
class TenderMetreLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sort_order' => $this->sort_order,
            'description' => $this->description,
            'is_option_b' => (bool) $this->is_option_b,

            'quotes' => collect(MetreLine::SUPPLIER_SLOTS)->mapWithKeys(fn (int $n) => [
                $n => [
                    'price' => $this->number($this->{"tender_supp{$n}_price"}),
                    'quantity' => $this->number($this->{"tender_supp{$n}_quantity"}),
                    'total' => $this->totalPriceForSupplier($n),
                ],
            ])->all(),
        ];
    }

    private function number(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
