<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A catalogue section or sub-section - REF_Reference and REFS_SubReference, which carry exactly
 * the same editable fields: a code and the three language titles.
 *
 * Same two-layer shape as every other edit request here: rules, plus outright rejection of any
 * key outside the whitelist, so a client that tries to write a computed or foreign-key column
 * gets told why instead of having it silently dropped.
 *
 * The parent link is never in the payload - it is in the URL (a sub-section is created under
 * /api/references/{reference}/sub-references) and cannot be changed afterwards. Moving a
 * sub-section to another section would change the code of every métré line that quoted it, which
 * is not an edit but a data migration.
 */
class SaveReferenceRequest extends FormRequest
{
    public const EDITABLE = ['code', 'title_fr', 'title_en', 'title_nl'];

    public function rules(): array
    {
        return [
            // Le code est un nombre, et c'est ce nombre qui trie le catalogue et fabrique le code
            // d'une ligne ("20.8.1"). Négatif refusé : rien ne se classe avant le premier poste.
            'code' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:99999'],
            'title_fr' => ['sometimes', 'nullable', 'string', 'max:255'],
            'title_en' => ['sometimes', 'nullable', 'string', 'max:255'],
            'title_nl' => ['sometimes', 'nullable', 'string', 'max:255'],
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
                        $validator->errors()->add((string) $key, "[{$key}] n'est pas un champ modifiable d'une référence.");
                    }
                }
            },
        ];
    }
}
