<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Creates a new, empty métré on a project - just its name. Everything else on MET_Metre is
 * either optional detail a human fills in later or a materialized total with nothing to
 * write yet, since a métré with no lines has none.
 */
class StoreMetreRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
