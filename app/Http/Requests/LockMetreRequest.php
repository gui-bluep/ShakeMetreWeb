<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * L'état de verrou visé pour un métré - MET_LockUnlock.
 *
 * `required` et booléen : la source bascule, cette application demande où aller. Un appel qui
 * oublie le champ est une erreur, pas une bascule.
 */
class LockMetreRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'locked' => ['required', 'boolean'],
        ];
    }
}
