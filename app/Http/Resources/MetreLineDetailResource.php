<?php

namespace App\Http\Resources;

use App\Models\MetreLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the Achats / Vendu client / Commande view.
 *
 * Split as everywhere else: writable keys at the top level, derived values under `computed`, so
 * both sides can see which is which.
 *
 * The three blocks do NOT have three quantities. PriceTotalBuy and PriceTotalSales both
 * multiply by Quantity in the source - only the ordered total has its own (QuantityOrdered) -
 * so `quantity` is what the Achats and Vendu client blocks share, and the view binds both to it
 * deliberately rather than inventing a third column.
 *
 * @mixin MetreLine
 */
class MetreLineDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sort_order' => $this->sort_order,

            'description' => $this->description,
            'unit' => $this->unit,
            'is_estimated_price_b' => (bool) $this->is_estimated_price_b,
            'is_option_b' => (bool) $this->is_option_b,
            'is_delivered_b' => (bool) $this->is_delivered_b,

            // Shared by Achats and Vendu client - see the class note.
            'quantity' => $this->number($this->quantity),
            'price_buy' => $this->number($this->price_buy),
            'price_sales' => $this->number($this->price_sales),

            'quantity_ordered' => $this->number($this->quantity_ordered),
            'price_ordered' => $this->number($this->price_ordered),

            'comment_client' => $this->comment_client,
            'comment_supplier' => $this->comment_supplier,

            // METL carries tag1/tag2 columns and there is also a separate TAG table keyed to
            // the métré; the Tags switch displays the stored column and nothing more until
            // which of the two it should drive is settled.
            'tag1' => $this->tag1,
            'tag2' => $this->tag2,

            'lot_id' => $this->lot_id,
            'lot_name' => $this->lot === null
                ? null
                : ($this->lot->title_custom ?: $this->lot->title_fr ?: $this->lot->title_en ?: $this->lot->title_nl),

            /*
             * The ShakeDesign supplier order this line was ordered through. `sor_title_ref` is
             * the denormalized title (METL::SOR_TitleRef); `supplier_order_id` is the zkp, kept
             * so the row can link into FileMaker once that link exists.
             */
            'supplier_order_id' => $this->supplier_order_id,
            'sor_title_ref' => $this->sor_title_ref,

            // Unstored FileMaker calculations - no columns, nothing to write.
            'computed' => [
                // Sales unit price over purchase unit price - derived here rather than read from
                // the stored METL::Ratio column, which nothing in the export claims to maintain.
                'price_ratio' => $this->price_ratio,
                'price_total_buy_no_options' => $this->price_total_buy_no_options,
                'price_total_sales_no_options' => $this->price_total_sales_no_options,
                'price_total_ordered_no_options' => $this->price_total_ordered_no_options,
            ],
        ];
    }

    private function number(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
