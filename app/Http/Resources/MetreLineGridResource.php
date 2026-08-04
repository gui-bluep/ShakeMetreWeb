<?php

namespace App\Http\Resources;

use App\Models\MetreLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the editable metre grid.
 *
 * The payload is split deliberately: the keys the client may send back live at the top
 * level, the three derived totals are grouped under `computed` so it is obvious in the
 * component - and in the network tab - which side owns what.
 *
 * @mixin MetreLine
 */
class MetreLineGridResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sort_order' => $this->sort_order,

            // Editable.
            'reference_id' => $this->reference_id,
            'sub_reference_id' => $this->sub_reference_id,

            /*
             * La section de la ligne, recopiée sur la ligne elle-même : c'est ainsi que la source
             * fonctionne (les clés étrangères vers le catalogue sont vides sur les 57 809 lignes
             * du fichier réel). Voir MetreLineObserver::syncSectionSnapshot().
             */
            'ref_code' => $this->ref_code,
            'ref_title' => $this->ref_title,
            'refs_code' => $this->refs_code,
            'refs_title' => $this->refs_title,
            'refsl_title' => $this->refsl_title,

            'description' => $this->description,
            'quantity' => $this->number($this->quantity),
            'quantity_ordered' => $this->number($this->quantity_ordered),
            'price_sales' => $this->number($this->price_sales),
            'price_ordered' => $this->number($this->price_ordered),
            'price_buy' => $this->number($this->price_buy),
            'is_option_b' => (bool) $this->is_option_b,

            /*
             * Read-only: unstored FileMaker calculations, no column behind them.
             *
             * The buy total is here even though this grid does not show it, because this is the
             * response the shared PATCH /api/metre-lines/{id} returns - and the Achats/Ventes/
             * Commandes view, which does show it, replaces its whole `computed` block from that
             * response. Omitting one made that view's Achats total blank itself on every save.
             */
            'computed' => [
                'price_ratio' => $this->price_ratio,

                // METL::REFSL_Code_c - « 20.2.2 », recalculé plutôt que lu (MetreLine::refLineCode()).
                'ref_line_code' => $this->refLineCode(),
                'price_total_buy_no_options' => $this->price_total_buy_no_options,
                'price_total_sales_no_options' => $this->price_total_sales_no_options,
                'price_total_ordered_no_options' => $this->price_total_ordered_no_options,
                'price_total_gain_no_options' => $this->price_total_gain_no_options,
            ],

            // Read-only, but the grid needs it: a "pm" unit forces quantity_ordered empty
            // (METL_MetreLines::QuantityOrdered auto-enter), so the cell disables itself
            // rather than accepting a value the server will immediately clear.
            'unit' => $this->unit,

            // Likewise: on a composed line both quantities come from the components, so the
            // cells disable rather than accept a value the next recalculation would overwrite.
            'has_components' => $this->hasComponents(),
        ];
    }

    /** Decimal columns arrive from the driver as strings; the grid needs numbers. */
    private function number(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
