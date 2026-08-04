<?php

namespace App\Models;

use App\Models\Concerns\HasLocalisedTitle;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Reference extends Model
{
    use HasLocalisedTitle;
    use HasUuids;

    /**
     * `references` is a reserved SQL keyword, and Laravel's SQLite grammar emits the
     * referenced table name unquoted when it rebuilds a table (SQLiteGrammar::compileForeign),
     * so any FK pointing at it breaks the test suite. Hence the explicit table name; the
     * foreign key columns stay `reference_id`.
     */
    protected $table = 'metre_references';

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
