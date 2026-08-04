<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The catalogue items to turn into lines - METL_New_Multi's `$REFSLs` list.
 *
 * A list rather than one id because that is the gesture: the FileMaker catalogue browser is
 * multi-select and inserts everything ticked in one go. `distinct` is deliberately NOT applied:
 * the same item twice is a legitimate ask (two doors of the same kind on two floors), and the
 * source loops over the list as given.
 */
class StoreLinesFromCatalogueRequest extends FormRequest
{
    /** More than a hundred lines in one gesture is a mis-click or a script, not a métré. */
    public const MAX_ITEMS = 100;

    public function rules(): array
    {
        return [
            'sub_reference_line_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_ITEMS],
            'sub_reference_line_ids.*' => ['required', 'uuid', 'exists:sub_reference_lines,id'],
        ];
    }
}
