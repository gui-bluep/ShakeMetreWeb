<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A new métré line, optionally filed under a section given by hand.
 *
 * This is METL_NewFromREF's second branch. The first is "another line in this same sub-section",
 * which needs nothing but the group's codes; the second reads
 * `zg_REF_SelectedNewCode` / `zg_REF_SelectedNewTitle` off the section header and creates the
 * line under a sub-section that does not exist in the catalogue at all - the dialog says as much:
 * « Choisissez une section ou définissez en une nouvelle (code et titre) ».
 *
 * That is why these four fields are writable here while they are NOT in
 * UpdateMetreLineRequest: a line's section is set when the line is filed, and afterwards it is a
 * snapshot. Editing it later would silently change the printed code of a line that may already be
 * on a document - moving a line to another section is a re-filing, not a field edit, and it does
 * not exist in the source either.
 *
 * Both codes are required together with their titles, or neither: a section with a code and no
 * title prints as a heading nobody can read, and the source refuses that pair outright.
 */
class StoreMetreLineRequest extends FormRequest
{
    public const EDITABLE = ['ref_code', 'ref_title', 'refs_code', 'refs_title'];

    public function rules(): array
    {
        return [
            'ref_code' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:99999'],
            'ref_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'refs_code' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:99999'],
            'refs_title' => ['sometimes', 'nullable', 'string', 'max:255'],
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
                        $validator->errors()->add(
                            (string) $key,
                            "[{$key}] ne peut pas être posé à la création d'une ligne ; seule sa section peut l'être.",
                        );
                    }
                }

                // Une sous-section sans titre s'imprime comme un intitulé illisible.
                if (filled($this->input('refs_code')) && blank($this->input('refs_title'))) {
                    $validator->errors()->add('refs_title', 'Une nouvelle sous-section a besoin d\'un titre.');
                }

                if (filled($this->input('refs_title')) && $this->input('refs_code') === null && $this->has('refs_code')) {
                    $validator->errors()->add('refs_code', 'Une nouvelle sous-section a besoin d\'un code.');
                }
            },
        ];
    }
}
