<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the métré's own header fields, edited from its detail page: identity, the default
 * markup ratio, language, agreement date, the two status flags, and the three comment areas.
 *
 * Same two-layer shape as the other edit requests - rules plus outright rejection of anything
 * outside the whitelist - so an attempt to write a materialized total fails loudly instead of
 * being silently dropped. Every `*_stored` column is absent by construction: those are owned by
 * RecalculateMetreTotals, and Ratio_c is a calculation with no column at all.
 */
class UpdateMetreRequest extends FormRequest
{
    /** The three languages the interface offers, matching MET_Metre::Language. */
    public const LANGUAGES = ['FR', 'EN', 'NL'];

    public const EDITABLE = [
        'name',
        // MET_Metre::Ratio_Markup - the default markup, not the computed Ratio_c.
        'ratio_markup',
        'language',
        'date_agreement',
        // Both gate the stored totals, so writing them forces a recalculation - see
        // MetreController::update().
        'is_accepted_b',
        'is_status_site_b',
        'comment_client',
        'comment_supplier',
        'comment_internal',
    ];

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'ratio_markup' => ['sometimes', 'nullable', 'numeric', 'between:-99999999.9999,99999999.9999'],
            'language' => ['sometimes', 'nullable', Rule::in(self::LANGUAGES)],
            'date_agreement' => ['sometimes', 'nullable', 'date'],
            'is_accepted_b' => ['sometimes', 'boolean'],
            'is_status_site_b' => ['sometimes', 'boolean'],
            'comment_client' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'comment_supplier' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'comment_internal' => ['sometimes', 'nullable', 'string', 'max:65535'],
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
                    if (in_array($key, self::EDITABLE, true)) {
                        continue;
                    }

                    $validator->errors()->add((string) $key, $this->rejectionReason((string) $key));
                }
            },
        ];
    }

    private function rejectionReason(string $key): string
    {
        if (str_ends_with($key, '_stored')) {
            return "[{$key}] is materialized by RecalculateMetreTotals and cannot be written directly.";
        }

        if ($key === 'ratio') {
            return '[ratio] is MET_Metre::Ratio_c, a calculation with no column behind it; it cannot be written.';
        }

        return "[{$key}] is not an editable field of a metre.";
    }
}
