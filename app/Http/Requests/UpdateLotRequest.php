<?php

namespace App\Http\Requests;

use App\Models\MetreLine;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a PATCH to a lot: either its identity (code, the three language titles, the
 * assigned supplier/contact) from the project page's "manage lots" panel, or its tender
 * weighting/scoring matrix (the price weighting, the five criteria with their descriptions
 * and weights, and each criterion's note per candidate supplier) from the tender comparison
 * screen. Both write to the same Lot, so both go through the one whitelist rather than two
 * competing ones.
 *
 * company_id is also SelectTenderSupplier's one write (awarding the lot), and that action
 * guards it: the company must match the slot named by supplier_number
 * (TenderSupplierMismatchException otherwise). This whitelist enforces no such check -
 * "manage lots" is a direct edit, deliberately available for a lot that never goes through a
 * tender comparison, or to correct a mistake - but it means this is also a way to set
 * company_id to a company that never quoted, bypassing the very guard SelectTenderSupplier
 * exists for. Flagged rather than silently allowed: say the word if that should be narrowed.
 */
class UpdateLotRequest extends FormRequest
{
    /**
     * @return list<string>
     */
    public static function editable(): array
    {
        $keys = [
            'title_custom', 'title_fr', 'title_en', 'title_nl',
            'code', 'company_id', 'contact_id',
            'tender_weighting_price',
        ];

        foreach (range(1, 5) as $criterion) {
            $keys[] = "tender_weighting_crit{$criterion}";
            $keys[] = "tender_weighting_crit{$criterion}_description";

            foreach (MetreLine::SUPPLIER_SLOTS as $supplier) {
                $keys[] = "tender_weighting_crit{$criterion}_supp{$supplier}";
            }
        }

        return $keys;
    }

    public function rules(): array
    {
        $rules = [
            'title_custom' => ['sometimes', 'nullable', 'string', 'max:255'],
            'title_fr' => ['sometimes', 'nullable', 'string', 'max:255'],
            'title_en' => ['sometimes', 'nullable', 'string', 'max:255'],
            'title_nl' => ['sometimes', 'nullable', 'string', 'max:255'],
            'code' => ['sometimes', 'nullable', 'integer'],
            'company_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'contact_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tender_weighting_price' => ['sometimes', 'nullable', 'numeric', 'between:-99999999.9999,99999999.9999'],
        ];

        foreach (range(1, 5) as $criterion) {
            $rules["tender_weighting_crit{$criterion}"] = ['sometimes', 'nullable', 'integer'];
            $rules["tender_weighting_crit{$criterion}_description"] = ['sometimes', 'nullable', 'string', 'max:65535'];

            foreach (MetreLine::SUPPLIER_SLOTS as $supplier) {
                // The notes are explicitly 0-100, unlike the weightings above.
                $rules["tender_weighting_crit{$criterion}_supp{$supplier}"] = ['sometimes', 'nullable', 'integer', 'between:0,100'];
            }
        }

        return $rules;
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                foreach (array_keys($this->all()) as $key) {
                    if (in_array($key, self::editable(), true)) {
                        continue;
                    }

                    $validator->errors()->add(
                        (string) $key,
                        "[{$key}] is not an editable field of a lot."
                    );
                }
            },
        ];
    }
}
