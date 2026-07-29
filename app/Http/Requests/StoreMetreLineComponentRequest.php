<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a new component. Same two-layer shape as UpdateMetreLineRequest: rules for the
 * editable fields, plus outright rejection of anything outside the whitelist so an attempt to
 * write a derived value fails loudly instead of being silently dropped.
 */
class StoreMetreLineComponentRequest extends FormRequest
{
    /**
     * metre_line_id is absent on purpose: the parent comes from the route, so a payload cannot
     * attach a component to a line the caller never named.
     */
    public const EDITABLE = [
        'description',
        'quantity_sales',
        'quantity_ordered',
        'length',
        'width',
        'height',
        'sort_order',
    ];

    /** Derived per-component values: accessors with no column behind them. */
    private const COMPUTED = ['value_sales', 'value_ordered'];

    public function rules(): array
    {
        return [
            'description' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'quantity_sales' => ['sometimes', 'nullable', 'numeric', 'between:-99999999.9999,99999999.9999'],
            'quantity_ordered' => ['sometimes', 'nullable', 'numeric', 'between:-99999999.9999,99999999.9999'],
            'length' => ['sometimes', 'nullable', 'numeric', 'between:-99999999.9999,99999999.9999'],
            'width' => ['sometimes', 'nullable', 'numeric', 'between:-99999999.9999,99999999.9999'],
            'height' => ['sometimes', 'nullable', 'numeric', 'between:-99999999.9999,99999999.9999'],
            'sort_order' => ['sometimes', 'nullable', 'integer', 'min:0'],
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
                    if (in_array($key, static::EDITABLE, true)) {
                        continue;
                    }

                    $validator->errors()->add((string) $key, $this->rejectionReason((string) $key));
                }
            },
        ];
    }

    protected function rejectionReason(string $key): string
    {
        if (in_array($key, self::COMPUTED, true)) {
            return "[{$key}] is a derived value with no column behind it; it cannot be written.";
        }

        if ($key === 'metre_line_id') {
            return '[metre_line_id] comes from the route, not the payload.';
        }

        return "[{$key}] is not an editable field of a metre line component.";
    }
}
