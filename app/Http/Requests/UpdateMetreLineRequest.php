<?php

namespace App\Http\Requests;

use App\Models\SubReference;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a grid cell edit.
 *
 * Two layers, and the second is the point of this class: a whitelist alone would silently
 * ignore an attempt to write a derived or materialized column, which is exactly the kind
 * of bug that surfaces months later as "the totals disagree with the lines". Any key
 * outside EDITABLE is rejected outright, and anything ending in `_stored` or matching a
 * known computed name is rejected with a message that says why.
 */
class UpdateMetreLineRequest extends FormRequest
{
    /**
     * The only columns a client may write. Everything else on metre_lines is either
     * derived, materialized by RecalculateMetreTotals, a denormalized snapshot, or part of
     * the ShakeDesign boundary.
     */
    public const EDITABLE = [
        'reference_id',
        'sub_reference_id',
        'description',
        'quantity',
        // Independent of `quantity`: METL_METC_UpdateQuantities feeds each from its own
        // component sum. A "pm" unit forces it empty - see MetreLineObserver::saving().
        'quantity_ordered',
        'price_sales',
        'price_ordered',
        'price_buy',
        'is_option_b',
    ];

    /** Derived per-line values: unstored calculations with no column behind them. */
    private const COMPUTED = [
        'price_total_sales_no_options',
        'price_total_ordered_no_options',
        'price_total_gain_no_options',
    ];

    public function rules(): array
    {
        return [
            'reference_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('metre_references', 'id')],
            'sub_reference_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('sub_references', 'id')],
            'description' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'quantity' => ['sometimes', 'nullable', 'numeric', 'between:-99999999.9999,99999999.9999'],
            'quantity_ordered' => ['sometimes', 'nullable', 'numeric', 'between:-99999999.9999,99999999.9999'],
            'price_sales' => ['sometimes', 'nullable', 'numeric', 'between:-99999999.9999,99999999.9999'],
            'price_ordered' => ['sometimes', 'nullable', 'numeric', 'between:-99999999.9999,99999999.9999'],
            'price_buy' => ['sometimes', 'nullable', 'numeric', 'between:-99999999.9999,99999999.9999'],
            'is_option_b' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            $this->rejectNonEditableKeys(),
            $this->rejectSubReferenceFromAnotherReference(),
        ];
    }

    private function rejectNonEditableKeys(): callable
    {
        return function (Validator $validator) {
            foreach (array_keys($this->all()) as $key) {
                if (in_array($key, self::EDITABLE, true)) {
                    continue;
                }

                $validator->errors()->add((string) $key, $this->rejectionReason((string) $key));
            }
        };
    }

    /**
     * A sub-reference must belong to the reference the row points at, otherwise the grid
     * could persist a pair the catalogue does not allow.
     */
    private function rejectSubReferenceFromAnotherReference(): callable
    {
        return function (Validator $validator) {
            if (! $this->has('sub_reference_id') || $this->input('sub_reference_id') === null) {
                return;
            }

            // A batch may change the reference and the sub-reference together; when it only
            // changes the sub-reference, validate against the reference already stored.
            $referenceId = $this->has('reference_id')
                ? $this->input('reference_id')
                : $this->route('metreLine')?->reference_id;

            $belongs = SubReference::whereKey($this->input('sub_reference_id'))
                ->where('reference_id', $referenceId)
                ->exists();

            if (! $belongs) {
                $validator->errors()->add(
                    'sub_reference_id',
                    'The selected sub-reference does not belong to the selected reference.',
                );
            }
        };
    }

    private function rejectionReason(string $key): string
    {
        if (in_array($key, self::COMPUTED, true)) {
            return "[{$key}] is a derived per-line total with no column behind it; it cannot be written.";
        }

        if (str_ends_with($key, '_stored')) {
            return "[{$key}] is materialized by RecalculateMetreTotals and cannot be written directly.";
        }

        return "[{$key}] is not an editable field of a metre line.";
    }
}
