<?php

namespace App\Http\Resources;

use App\Models\MetreLineComponent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the components panel.
 *
 * Split like MetreLineGridResource: writable keys at the top level, the two derived values
 * under `computed`, so it is obvious on both sides which the client may send back.
 *
 * @mixin MetreLineComponent
 */
class MetreLineComponentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sort_order' => $this->sort_order,

            'description' => $this->description,
            'quantity_sales' => $this->number($this->quantity_sales),
            'quantity_ordered' => $this->number($this->quantity_ordered),
            'length' => $this->number($this->length),
            'width' => $this->number($this->width),
            'height' => $this->number($this->height),

            // Read-only: ValueSales_c / ValueOrdered_c are accessors, not columns.
            'computed' => [
                'value_sales' => $this->value_sales,
                'value_ordered' => $this->value_ordered,
            ],
        ];
    }

    private function number(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
