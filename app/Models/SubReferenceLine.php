<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubReferenceLine extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    public function subReference(): BelongsTo
    {
        return $this->belongsTo(SubReference::class);
    }

    public function reference(): BelongsTo
    {
        return $this->belongsTo(Reference::class);
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
