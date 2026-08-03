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
            'ratio' => $this->ratio(),
            'tot_sum_total_sales_stored' => $this->decimal($this->tot_sum_total_sales_stored),
            'tot_sum_total_ordered_stored' => $this->decimal($this->tot_sum_total_ordered_stored),
            'is_accepted_b' => (bool) $this->is_accepted_b,
            'is_status_site_b' => (bool) $this->is_status_site_b,
            'is_archived_b' => (bool) $this->is_archived_b,
        ];
    }

    /**
     * Decimal columns come back from the driver as strings; the portal shows numbers.
     */
    private function decimal(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
