<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A catalogue item - REFSL_SubReferenceLines, the only level that carries money: a unit and a
 * price, which is a PURCHASE price (METL_New writes it into PriceBuy), plus a descriptive text
 * per language on top of the title.
 *
 * `unit` is deliberately NOT constrained to UpdateMetreLineRequest::UNITS. The live catalogue
 * holds units that list does not ("Ff" for forfait, among others), and refusing them would make
 * existing entries unsavable - the catalogue is the older vocabulary of the two.
 */
class SaveSubReferenceLineRequest extends FormRequest
{
    public const EDITABLE = [
        'code',
        'title_fr', 'title_en', 'title_nl',
        'description_fr', 'description_en', 'description_nl',
        'unit',
        'price',
    ];

    public function rules(): array
    {
        return [
            'code' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:99999'],
            'title_fr' => ['sometimes', 'nullable', 'string', 'max:255'],
            'title_en' => ['sometimes', 'nullable', 'string', 'max:255'],
            'title_nl' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description_fr' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'description_en' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'description_nl' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'unit' => ['sometimes', 'nullable', 'string', 'max:20'],
            'price' => ['sometimes', 'nullable', 'numeric', 'between:-99999999.9999,99999999.9999'],
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                foreach (array_keys($this->all()) as $key) {
                    if (! in_array($key, self::EDITABLE, true)) {
                        $validator->errors()->add((string) $key, "[{$key}] n'est pas un champ modifiable d'un article.");
                    }
                }
            },
        ];
    }
}
