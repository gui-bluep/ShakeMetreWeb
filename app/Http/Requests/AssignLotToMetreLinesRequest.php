<?php

namespace App\Http\Requests;

use App\Models\Lot;
use App\Models\MetreLine;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Le lot à poser sur une sélection de lignes - METL_Lot_AssignToSelection.
 *
 * Le script source reçoit la liste des zkp sélectionnés (`$ZKM_METL`, tenu dans
 * `MET::zkm_METL_Selection_g`), ouvre une carte qui les liste, et n'écrit qu'après confirmation.
 * D'où une liste d'identifiants plutôt qu'un critère : c'est une sélection, pas une recherche, et
 * ce qui a été coché est ce qui sera changé - rien de plus.
 *
 * `lot_id` est `present` et non `required` : détacher le lot d'un paquet de lignes est un geste
 * légitime (le menu d'une ligne offre déjà « Aucun lot »), et c'est ce que fait le source avec un
 * `$LOT` vide. `present` distingue « on m'envoie null, détache » de « on a oublié le champ ».
 */
class AssignLotToMetreLinesRequest extends FormRequest
{
    /**
     * Plus large que les 100 postes d'un ajout au catalogue : ici « Tout sélectionner » sur un
     * métré de plusieurs centaines de lignes est le geste normal, pas un dérapage. La borne ne
     * garde que contre un appel automatisé.
     */
    public const MAX_LINES = 2000;

    public function rules(): array
    {
        return [
            'line_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_LINES],
            'line_ids.*' => ['required', 'uuid', Rule::exists('metre_lines', 'id')],
            'lot_id' => ['present', 'nullable', 'uuid', Rule::exists('lots', 'id')],
        ];
    }

    public function after(): array
    {
        return [
            $this->rejectLinesFromAnotherMetre(),
            $this->rejectLotFromAnotherProject(),
        ];
    }

    /**
     * Toutes les lignes doivent appartenir au métré de l'URL.
     *
     * `exists` ne dit que « cette ligne existe » : sans ce contrôle, une requête forgée poserait
     * un lot sur les lignes du métré de quelqu'un d'autre, en passant par un métré que l'on a le
     * droit d'écrire. Le contrôleur refiltre de toute façon sur le métré, mais un identifiant
     * étranger doit être refusé et dit, pas ignoré en silence : la réponse ne renverrait alors que
     * les lignes traitées, et l'écran croirait avoir tout changé.
     */
    private function rejectLinesFromAnotherMetre(): callable
    {
        return function (Validator $validator) {
            $metre = $this->route('metre');
            $ids = (array) $this->input('line_ids');

            if ($metre === null || $ids === []) {
                return;
            }

            $mine = MetreLine::query()
                ->whereKey($ids)
                ->where('metre_id', $metre->getKey())
                ->pluck('id')
                ->all();

            $foreign = array_values(array_diff($ids, $mine));

            if ($foreign !== []) {
                $validator->errors()->add(
                    'line_ids',
                    count($foreign).' ligne(s) de la sélection n\'appartiennent pas à ce métré.',
                );
            }
        };
    }

    /**
     * Même règle que `UpdateMetreLineRequest`, et pour la même raison : `lot_id` est ce par quoi
     * la comparaison d'offres trouve ses lignes (`Lot::sumForSupplier` somme toute `metre_line`
     * portant l'identifiant du lot). Une ligne rattachée à un lot d'un autre projet entrerait en
     * silence dans la comparaison de ce projet et changerait ce qu'un fournisseur semble avoir
     * remis. En lot, la même erreur se ferait sur des centaines de lignes d'un coup.
     */
    private function rejectLotFromAnotherProject(): callable
    {
        return function (Validator $validator) {
            $lotId = $this->input('lot_id');
            $project = $this->route('metre')?->project_id;

            if ($lotId === null || $project === null) {
                return;
            }

            if (! Lot::whereKey($lotId)->where('project_id', $project)->exists()) {
                $validator->errors()->add(
                    'lot_id',
                    'Ce lot appartient à un autre projet que le métré de ces lignes.',
                );
            }
        };
    }
}
