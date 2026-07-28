<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetreLineComponent extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    public function metreLine(): BelongsTo
    {
        return $this->belongsTo(MetreLine::class);
    }
}
