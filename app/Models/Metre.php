<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Metre extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'is_accepted_b' => 'boolean',
            'is_status_site_b' => 'boolean',
            'is_imported_b' => 'boolean',
            'is_locked_b' => 'boolean',
            'is_archived_b' => 'boolean',
            'date_creation' => 'date',
            'date_agreement' => 'date',
            'prog_progress_update_date' => 'date',
            'date_time_update_calcs_stored' => 'datetime',
        ];
    }

    public function carts(): HasMany
    {
        return $this->hasMany(Cart::class);
    }

    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class);
    }

    public function metreLines(): HasMany
    {
        return $this->hasMany(MetreLine::class);
    }

    /**
     * MET_Metre::Ratio_c, not stored - there is no ratio column:
     *
     *   Let ( [ _sales = Total_Sales_METL_Stored ; _purchase = Total_Purchase_METL_Stored ] ;
     *     Case ( not IsEmpty ( _sales ) and not IsEmpty ( _purchase ) ;
     *       Round ( _sales / _purchase ; 2 ) ; "" ) )
     *
     * FileMaker returns empty when either operand is empty, so null here. Division by zero
     * is guarded the same way: the source formula only checks for emptiness because
     * FileMaker yields an error rather than a value, which surfaces as empty in the portal.
     *
     * The single source of truth for this formula - both the ShakeDesign portal replica
     * (ProjectMetreResource) and the project page read it from here rather than each
     * keeping their own copy.
     */
    public function ratio(): ?float
    {
        return self::ratioFromSums($this->total_sales_metl_stored, $this->total_purchase_metl_stored);
    }

    /**
     * The same Ratio_c guard (empty or zero denominator yields null, not an error or 0),
     * shared so a project-wide ratio - Σ Total_Sales_METL_Stored / Σ Total_Purchase_METL_Stored
     * across every métré of a project - divides by exactly the same rule as a single métré's
     * own ratio. There is no FileMaker field for that project-wide figure; it is this same
     * formula applied to summed inputs, not a transcription of a source calculation.
     */
    public static function ratioFromSums(null|int|float|string $sales, null|int|float|string $purchase): ?float
    {
        if ($sales === null || $purchase === null || (float) $purchase === 0.0) {
            return null;
        }

        return round((float) $sales / (float) $purchase, 2);
    }

    /**
     * Cross-system reference: resolved via ShakeDesignClient::findProject() against
     * ShakeDesign's PRJ_Projects. Not a local Eloquent relation.
     */
    public function projectId(): ?string
    {
        return $this->project_id;
    }

    /**
     * Cross-system reference: resolved via ShakeDesignClient against ShakeDesign's
     * OFF_Offers. Not a local Eloquent relation.
     */
    public function offerId(): ?string
    {
        return $this->offer_id;
    }
}
