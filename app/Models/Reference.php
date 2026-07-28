<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Reference extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    public function subReferences(): HasMany
    {
        return $this->hasMany(SubReference::class);
    }

    public function subReferenceLines(): HasMany
    {
        return $this->hasMany(SubReferenceLine::class);
    }

    public function materials(): HasMany
    {
        return $this->hasMany(Material::class);
    }

    public function metreLines(): HasMany
    {
        return $this->hasMany(MetreLine::class);
    }
}
