<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cart extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function metre(): BelongsTo
    {
        return $this->belongsTo(Metre::class);
    }

    public function cartMaterials(): HasMany
    {
        return $this->hasMany(CartMaterial::class);
    }

    /**
     * Cross-system reference: resolved via ShakeDesignClient::findProject() against
     * ShakeDesign's PRJ_Projects. Not a local Eloquent relation.
     */
    public function projectId(): ?string
    {
        return $this->project_id;
    }
}
