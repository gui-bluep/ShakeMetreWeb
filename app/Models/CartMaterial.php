<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CartMaterial extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function metreLines(): HasMany
    {
        return $this->hasMany(MetreLine::class);
    }
}
