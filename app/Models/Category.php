<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    public function subCategories(): HasMany
    {
        return $this->hasMany(SubCategory::class);
    }

    public function materials(): HasMany
    {
        return $this->hasMany(Material::class);
    }

    public function cartMaterials(): HasMany
    {
        return $this->hasMany(CartMaterial::class);
    }
}
