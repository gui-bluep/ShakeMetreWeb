<?php

namespace App\Http\Resources;

use App\Models\Metre;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the métré_prj_MET__ portal as ShakeDesign shows it today on the Project
 * layout. The shape is deliberately frozen to that portal's columns - this endpoint
 * exists to replace it, not to expose the metres table.
 *
 * @mixin Metre
 */
class ProjectMetreResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            // The FileMaker zkp, preserved verbatim through the migration.
            'id' => $this->id,
            'name' => $this->name,
            'ratio' => $this->portalRatio(),
            'tot_sum_total_sales_stored' => $this->decimal($this->tot_sum_total_sales_stored),
            'tot_sum_total_ordered_stored' => $this->decimal($this->tot_sum_total_ordered_stored),
            'is_accepted_b' => (bool) $this->is_accepted_b,
            'is_status_site_b' => (bool) $this->is_status_site_b,
            'is_archived_b' => (bool) $this->is_archived_b,
        ];
    }

    /**
     * MET_Metre::Ratio_c, computed rather than stored - there is no ratio column:
     *
     *   Let ( [ _sales = Total_Sales_METL_Stored ; _purchase = Total_Purchase_METL_Stored ] ;
     *     Case ( not IsEmpty ( _sales ) and not IsEmpty ( _purchase ) ;
     *       Round ( _sales / _purchase ; 2 ) ; "" ) )
     *
     * FileMaker returns empty when either operand is empty, so null here. Division by zero
     * is guarded the same way: the source formula only checks for emptiness because
     * FileMaker yields an error rather than a value, which surfaces as empty in the portal.
     */
    private function portalRatio(): ?float
    {
        $sales = $this->total_sales_metl_stored;
        $purchase = $this->total_purchase_metl_stored;

        if ($sales === null || $purchase === null || (float) $purchase === 0.0) {
            return null;
        }

        return round((float) $sales / (float) $purchase, 2);
    }

    /**
     * Decimal columns come back from the driver as strings; the portal shows numbers.
     */
    private function decimal(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
