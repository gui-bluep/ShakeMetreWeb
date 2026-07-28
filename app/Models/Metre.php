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
