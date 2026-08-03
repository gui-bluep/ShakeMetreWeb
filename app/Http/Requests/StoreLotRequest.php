<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Creates a new lot on a project from the "manage lots" panel - the same fields that panel
 * edits afterwards (code, the three language titles, the assigned supplier/contact), all
 * individually optional since a blank new row is filled in gradually. Everything about the
 * tender itself (weighting, candidate suppliers, quotes) is edited afterwards from the
 * comparison screen, not at creation.
 */
class StoreLotRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'code' => ['sometimes', 'nullable', 'integer'],
            'title_fr' => ['sometimes', 'nullable', 'string', 'max:255'],
            'title_en' => ['sometimes', 'nullable', 'string', 'max:255'],
            'title_nl' => ['sometimes', 'nullable', 'string', 'max:255'],
            // Bare ShakeDesign zkps (CPY_Companies / CTC_Contacts) - not validated as
            // uuid, since a zkp's format isn't guaranteed to be one.
            'company_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'contact_id' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
