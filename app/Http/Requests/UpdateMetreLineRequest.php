<?php

namespace App\Http\Requests;

use App\Models\Lot;
use App\Models\MetreLine;
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
        /*
         * Le titre de la ligne, et le champ que les grilles éditent sous « Titre ».
         *
         * C'est METL::REFSL_Title, décidé sur les données réelles : rempli sur 57 079 des 57 809
         * lignes du fichier FileMaker, contre 29 pour Description - qui n'y sert que de note
         * libre (« 1374,07 € selon offre Collignon »). Le troisième niveau du référentiel EST la
         * ligne, et son titre est celui de la ligne ; METL_NewFromREF place d'ailleurs le curseur
         * dans ce champ juste après la création.
         */
        'refsl_title',
        // Reste éditable : la note libre du fichier source, et ce que portaient les lignes créées
        // par cette application avant que le titre ne soit rebranché.
        'description',
        'quantity',
        // Independent of `quantity`: METL_METC_UpdateQuantities feeds each from its own
        // component sum. A "pm" unit forces it empty - see MetreLineObserver::saving().
        'quantity_ordered',
        'price_sales',
        'price_ordered',
        'price_buy',
        'is_option_b',

        // The Achats/Ventes/Commandes view edits these too.
        'unit',
        'is_estimated_price_b',
        'is_delivered_b',
        'comment_client',
        'comment_supplier',
        // Which lot the line belongs to; constrained to the métré's own project below.
        'lot_id',

        // The five candidate suppliers' quotes on this line, edited from the tender
        // comparison screen rather than the main grid.
        'tender_supp1_price',
        'tender_supp1_quantity',
        'tender_supp2_price',
        'tender_supp2_quantity',
        'tender_supp3_price',
        'tender_supp3_quantity',
        'tender_supp4_price',
        'tender_supp4_quantity',
        'tender_supp5_price',
        'tender_supp5_quantity',
    ];

    /**
     * The units the interface offers. METL_MetreLines::Unit is backed by the `c_Units` value
     * list, which the export names but gives no values for - so this list is the interface
     * spec's, and "pm" matters beyond labelling: it is the unit the auto-enter rule empties both
     * quantities for (see MetreLineObserver::saving()).
     */
    public const UNITS = ['m\'', 'm2', 'm3', 'Ff', 'Pce', 'Pm'];

    /** Derived per-line values: unstored calculations with no column behind them. */
    private const COMPUTED = [
        'price_ratio',
        'price_total_buy_no_options',
        'price_total_sales_no_options',
        'price_total_ordered_no_options',
        'price_total_gain_no_options',
    ];

    public function rules(): array
    {
        $rules = [
            'reference_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('metre_references', 'id')],
            'sub_reference_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('sub_references', 'id')],
            'refsl_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'quantity' => ['sometimes', 'nullable', 'numeric', 'between:-99999999.9999,99999999.9999'],
            'quantity_ordered' => ['sometimes', 'nullable', 'numeric', 'between:-99999999.9999,99999999.9999'],
            'price_sales' => ['sometimes', 'nullable', 'numeric', 'between:-99999999.9999,99999999.9999'],
            'price_ordered' => ['sometimes', 'nullable', 'numeric', 'between:-99999999.9999,99999999.9999'],
            'price_buy' => ['sometimes', 'nullable', 'numeric', 'between:-99999999.9999,99999999.9999'],
            'is_option_b' => ['sometimes', 'boolean'],
            // The value list c_Units exists in the source but the export carries no values for
            // it, so this set comes from the interface spec, not from the FileMaker file.
            'unit' => ['sometimes', 'nullable', Rule::in(self::UNITS)],
            'is_estimated_price_b' => ['sometimes', 'boolean'],
            'is_delivered_b' => ['sometimes', 'boolean'],
            'comment_client' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'comment_supplier' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'lot_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('lots', 'id')],
        ];

        foreach (MetreLine::SUPPLIER_SLOTS as $supplier) {
            $rules["tender_supp{$supplier}_price"] = ['sometimes', 'nullable', 'numeric', 'between:-99999999.9999,99999999.9999'];
            $rules["tender_supp{$supplier}_quantity"] = ['sometimes', 'nullable', 'numeric', 'between:-99999999.9999,99999999.9999'];
        }

        return $rules;
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            $this->rejectNonEditableKeys(),
            $this->rejectSubReferenceFromAnotherReference(),
            $this->rejectLotFromAnotherProject(),
        ];
    }

    /**
     * A line may only be assigned to a lot of its own métré's project.
     *
     * The picker offers exactly those, so this is not about the interface - it is that lot_id is
     * how the tender scoring finds its lines (Lot::sumForSupplier sums every metre_line carrying
     * the lot's id). A line attached across projects would silently enter another project's
     * tender comparison and change what a supplier appears to have quoted.
     */
    private function rejectLotFromAnotherProject(): callable
    {
        return function (Validator $validator) {
            $lotId = $this->input('lot_id');

            if (! $this->has('lot_id') || $lotId === null) {
                return;
            }

            $project = $this->route('metreLine')?->metre?->project_id;

            if ($project === null) {
                return;
            }

            if (! Lot::whereKey($lotId)->where('project_id', $project)->exists()) {
                $validator->errors()->add(
                    'lot_id',
                    'The selected lot belongs to a different project than this line\'s métré.',
                );
            }
        };
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
