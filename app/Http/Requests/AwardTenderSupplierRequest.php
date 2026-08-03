<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the "Retenir ce fournisseur" action's own payload shape. The integrity check -
 * that company_id actually matches the slot named by supplier_number - is
 * SelectTenderSupplier's job, not a validation rule: it depends on the lot's stored data, and
 * its failure is a dedicated TenderSupplierMismatchException the controller reports as-is.
 */
class AwardTenderSupplierRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'supplier_number' => ['required', 'integer', 'between:1,5'],
            'company_id' => ['required', 'string'],
        ];
    }
}
