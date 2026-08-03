<?php

namespace App\Http\Requests;

use App\Models\MetreLine;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates an edit to a lot's tender weighting/scoring matrix from the tender comparison
 * screen: the price weighting, the five criteria (weight + description), and each
 * criterion's note per candidate supplier.
 *
 * Awarding the lot (company_id) is a different, narrower write and goes through
 * SelectTenderSupplier instead - not exposed here, same two-layer whitelist shape as the
 * other edit requests.
 */
class UpdateLotRequest extends FormRequest
{
    /**
     * @return list<string>
     */
    public static function editable(): array
    {
        $keys = ['tender_weighting_price'];

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
                        "[{$key}] is not an editable field of a lot's tender weighting."
                    );
                }
            },
        ];
    }
}
