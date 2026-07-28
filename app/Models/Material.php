<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Material extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'is_parent_b' => 'boolean',
            'is_child_b' => 'boolean',
            'is_master_b' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function subCategory(): BelongsTo
    {
        return $this->belongsTo(SubCategory::class);
    }

    public function reference(): BelongsTo
    {
        return $this->belongsTo(Reference::class);
    }

    public function subReference(): BelongsTo
    {
        return $this->belongsTo(SubReference::class);
    }

    public function subReferenceLine(): BelongsTo
    {
        return $this->belongsTo(SubReferenceLine::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Material::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Material::class, 'parent_id');
    }

    public function cartMaterials(): HasMany
    {
        return $this->hasMany(CartMaterial::class);
    }

    public function metreLines(): HasMany
    {
        return $this->hasMany(MetreLine::class);
    }

    /**
     * Cross-system reference: resolved via ShakeDesignClient::findContact() against
     * ShakeDesign's CTC_Contacts (supplier contact role). Not a local Eloquent relation.
     */
    public function supplierContactId(): ?string
    {
        return $this->supplier_contact_id;
    }
}
