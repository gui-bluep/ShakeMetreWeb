<?php

namespace App\Http\Controllers;

use App\Http\Requests\LockMetreRequest;
use App\Http\Requests\UpdateMetreRequest;
use App\Jobs\RecalculateMetreTotals;
use App\Models\Metre;
use App\Models\MetreLine;
use App\Models\MetreLineComponent;
use App\Services\ShakeDesign\ShakeDesignClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One métré's own page: its header fields, its totals, and the actions that act on the métré
 * as a whole.
 */
class MetreController extends Controller
{
    public function show(Metre $metre, ShakeDesignClient $client): Response
    {
        $project = $metre->project_id === null ? null : $client->findProject($metre->project_id);

        return Inertia::render('Metres/Show', [
            'metre' => $this->payload($metre),
            'project' => [
                'id' => $metre->project_id,
                'name' => $project['Name'] ?? null,
            ],
            'languages' => UpdateMetreRequest::LANGUAGES,
            // Shown in the delete confirmation, so what is about to be destroyed is stated
            // rather than left to be discovered.
            'lineCount' => $metre->metreLines()->count(),
        ]);
    }

    public function update(UpdateMetreRequest $request, Metre $metre): JsonResponse
    {
        $metre->forceFill($request->safe()->only(UpdateMetreRequest::EDITABLE));

        /*
         * isAccepted_b gates Tot_Sum_TotalBuy and IsStatus_Site_b gates Sales/Ordered/Gain, so
         * flipping either makes every stored total wrong until it is recomputed - the flag is
         * an input to those sums, not just a label. Run inline rather than queued so the
         * response carries the corrected figures instead of the stale ones, the same reasoning
         * as MetreLineComponentController; the queued job stays the safety net for every other
         * write path.
         */
        $gatesChanged = $metre->isDirty(['is_accepted_b', 'is_status_site_b']);

        $this->stampAgreementDate($request, $metre);

        $metre->save();

        if ($gatesChanged) {
            (new RecalculateMetreTotals($metre))->handle();
            $metre->refresh();
        }

        return response()->json(['data' => $this->payload($metre)]);
    }

    /**
     * Verrouille ou déverrouille le métré - MET_LockUnlock.
     *
     * Le script source tient en une ligne : `Set Field [ MET::isLocked_b ; GetAsBoolean ( Abs (
     * isLocked_b - 1 ) ) ]`. Pas de confirmation, pas de contrôle de privilège, aucune cascade -
     * un booléen que l'on retourne.
     *
     * Deux écarts assumés :
     *
     *  - L'état visé est envoyé (`locked`), plutôt que retourné en aveugle. Une bascule vaut pour
     *    un écran qui a le record sous les yeux ; ici deux onglets ouverts sur le même métré
     *    verrouilleraient et déverrouilleraient l'un après l'autre sans que personne ne l'ait
     *    demandé. Envoyer la cible rend l'appel idempotent.
     *  - Un point d'entrée à part, et non `is_locked_b` ajouté à `UpdateMetreRequest::EDITABLE` :
     *    un verrou est une transition d'état, pas la valeur d'un champ. Surtout, tout garde-fou
     *    « ce métré est verrouillé » posé un jour sur la mise à jour du métré empêcherait alors de
     *    le déverrouiller - le verrou se refermerait sur sa propre clé.
     *
     * Ce que le verrou empêche : l'écriture des lignes (423 par `MetreLineDetailController`), et
     * les deux grilles se mettent en lecture seule en le disant. C'est l'interprétation déjà en
     * place dans cette application ; la source ne la documente pas, `MET::isLocked_b` n'étant lu
     * par aucun calcul ni aucun autre script - le reste vivait dans le comportement des mises en
     * page, que l'export ne porte pas.
     */
    public function lock(LockMetreRequest $request, Metre $metre): JsonResponse
    {
        $metre->forceFill(['is_locked_b' => $request->validated('locked')])->save();

        return response()->json(['data' => $this->payload($metre)]);
    }

    /**
     * Date_Agreement follows isAccepted_b: stamped with today's date when the box is ticked,
     * emptied when it is unticked. Requested by the user - the date of an agreement is the day
     * somebody says yes, and typing it by hand right after ticking the box was a second gesture
     * for information the application already had.
     *
     * Three things this deliberately does NOT do:
     *
     *  - It does not remember. Unticking clears the date, and re-ticking stamps today rather
     *    than restoring what was there: the previous date described an agreement that was taken
     *    back, and bringing it back would assert a date nobody chose.
     *  - It does not lock the field. The date stays editable - a métré accepted at a meeting
     *    last Tuesday and recorded today has to be correctable.
     *  - It does not overwrite a date sent in the same request. The page batches a row's edits
     *    into one PATCH, so ticking the box and typing a date within the same 500 ms window
     *    arrive together; an explicit value is a decision and wins over the stamp.
     *
     * Only on the transition, hence isDirty: a PATCH on an already-accepted métré that touches
     * something else must not silently move its agreement date to today.
     *
     * WHICH "today" - the point of the third rule above. The date that belongs on an agreement
     * is the day of the person who ticked the box, and only their browser knows that, so the
     * page sends it (localToday(), Metres/Show.vue) and this method stands aside. What is left
     * here is the fallback for a caller that ticks the flag without saying which day it is: the
     * server's own clock, config('app.timezone'). Set APP_TIMEZONE if that clock should read as
     * the office's rather than UTC - it is the fallback's only source of truth, and in UTC a
     * date stamped just after midnight in Brussels reads as the day before.
     */
    private function stampAgreementDate(UpdateMetreRequest $request, Metre $metre): void
    {
        if (! $metre->isDirty('is_accepted_b') || $request->safe()->has('date_agreement')) {
            return;
        }

        $metre->date_agreement = $metre->is_accepted_b ? now()->toDateString() : null;
    }

    /**
     * Duplicates the métré with its lines and their components.
     *
     * Copying the lines is the point - a métré is its lines, and a duplicate without them would
     * be an empty shell rather than a working copy. New UUIDs throughout: the originals are
     * ShakeDesign's stored references, so reusing one would make two records answer to the same
     * key.
     *
     * Deliberately NOT carried over:
     *  - isAccepted_b and Date_Agreement, because a fresh copy has not been agreed to by
     *    anyone and asserting otherwise would be a claim about a client's commitment;
     *  - offer_id, since the copy is not the métré that offer was raised against;
     *  - isLocked_b and isArchived_b, so the copy is workable;
     *  - every *_stored total, which RecalculateMetreTotals derives from the copied lines.
     *
     * isStatus_Site_b IS carried over, and the line between the two is worth stating: accepted
     * is a claim about a client having agreed, which a copy has no right to make, whereas site
     * is an internal workflow status about nobody outside. Copying it also keeps the duplicate
     * legible, since Sales/Ordered/Gain are gated on it - a copy that dropped the flag would
     * show every total as empty and look broken. Note the consequence of the other choice:
     * Tot_Sum_TotalBuy is gated on isAccepted_b, so "achats" on a fresh duplicate reads empty
     * until it is accepted. That is correct, not a bug.
     *
     * ind_project is NOT copied: it is the métré's number within its project and has to stay
     * unique there, so the copy takes the next one. Copying it made two métrés of the same
     * project both answer to "ID 1", which is the whole thing that number exists to prevent.
     *
     * Carts and tags are not copied either: nothing in the export says a duplicate should
     * inherit them, and inventing that is a guess about a feature not yet built here.
     */
    public function duplicate(Metre $metre): RedirectResponse
    {
        $copy = DB::transaction(function () use ($metre) {
            $copy = Metre::forceCreate([
                'project_id' => $metre->project_id,
                'name' => trim(($metre->name ?? 'Métré').' (copie)'),
                'language' => $metre->language,
                'ratio_markup' => $metre->ratio_markup,
                'is_status_site_b' => (bool) $metre->is_status_site_b,
                'ind_project' => Metre::nextIndProject($metre->project_id),
                'sequence_number' => $metre->sequence_number,
                'comment_client' => $metre->comment_client,
                'comment_supplier' => $metre->comment_supplier,
                'comment_internal' => $metre->comment_internal,
                'date_creation' => now()->toDateString(),
            ]);

            foreach ($metre->metreLines()->orderBy('sort_order')->get() as $line) {
                $this->copyLine($line, $copy);
            }

            return $copy;
        });

        // After the transaction, so the recalculation reads committed rows.
        (new RecalculateMetreTotals($copy))->handle();

        return redirect()->route('metres.show', $copy);
    }

    /**
     * Deletes the métré and everything that hangs off it.
     *
     * Order matters and is explicit: only metre_line_components cascades in the schema, so
     * metre_lines, the carts' materials, the carts and the tags all have to go first or the
     * foreign keys refuse the delete. Doing it in application code rather than by widening the
     * schema keeps the cascade visible at the point it happens, and avoids a migration whose
     * blast radius would be every métré in the database.
     */
    public function destroy(Metre $metre): RedirectResponse
    {
        $project = $metre->project_id;

        DB::transaction(function () use ($metre) {
            // Components cascade from their line.
            $metre->metreLines()->delete();

            $cartIds = $metre->carts()->pluck('id');

            if ($cartIds->isNotEmpty()) {
                DB::table('cart_materials')->whereIn('cart_id', $cartIds)->delete();
                $metre->carts()->delete();
            }

            $metre->tags()->delete();
            $metre->delete();
        });

        return $project === null
            ? redirect()->route('dashboard')
            : redirect()->route('projects.show', $project);
    }

    /**
     * A line and its components, re-keyed onto the copy.
     *
     * Written through the models rather than as a bulk insert so the observers run: the pm-unit
     * rule and the component-driven quantities apply to the copy exactly as they would to a
     * hand-made line, instead of the copy inheriting whatever state the original happened to be
     * in.
     */
    private function copyLine(MetreLine $line, Metre $copy): void
    {
        $attributes = $line->getAttributes();

        unset(
            $attributes['id'],
            $attributes['created_at'],
            $attributes['updated_at'],
            $attributes['created_by'],
            $attributes['updated_by'],
        );

        $attributes['metre_id'] = $copy->getKey();

        $newLine = MetreLine::forceCreate($attributes);

        foreach ($line->metreLineComponents()->orderBy('sort_order')->get() as $component) {
            $componentAttributes = $component->getAttributes();

            unset(
                $componentAttributes['id'],
                $componentAttributes['created_at'],
                $componentAttributes['updated_at'],
                $componentAttributes['created_by'],
                $componentAttributes['updated_by'],
            );

            $componentAttributes['metre_line_id'] = $newLine->getKey();

            MetreLineComponent::forceCreate($componentAttributes);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Metre $metre): array
    {
        return [
            'id' => $metre->id,
            'name' => $metre->name,
            'ratio_markup' => $this->decimal($metre->ratio_markup),
            'language' => $metre->language,
            'date_agreement' => $metre->date_agreement?->toDateString(),
            'is_accepted_b' => (bool) $metre->is_accepted_b,
            'is_status_site_b' => (bool) $metre->is_status_site_b,
            'is_locked_b' => (bool) $metre->is_locked_b,

            'comment_client' => $metre->comment_client,
            'comment_supplier' => $metre->comment_supplier,
            'comment_internal' => $metre->comment_internal,

            // MET_Metre::Ratio_c - computed, no column, so read-only by construction.
            'ratio' => $metre->ratio(),

            /*
             * The four totals, mapped by the source field names per the ruling on this screen:
             * achats = Buy, ventes = Sales, commandes = Ordered, gains = Gain.
             *
             * Note "commandes" here is Ordered, while the SAME word on the project page means
             * Sales. That divergence is intentional and confirmed - do not "fix" one to match
             * the other without asking.
             *
             * Read from the UNGATED Total_*_METL_Stored columns, not from their Tot_Sum_*
             * counterparts, which are the same sums gated on isAccepted_b (Buy) and
             * IsStatus_Site_b (Sales/Ordered/Gain). Requested by the user, and right for this
             * screen: this is where a métré is worked on, and it showed four empty tiles until
             * somebody ticked "Accepté" and "Site" - which reads as a broken page rather than as
             * "nothing is committed yet". The gated columns still answer that second question,
             * on the project page's roll-up and in the ShakeDesign portal replica.
             */
            'totals' => [
                'purchases' => $this->decimal($metre->total_purchase_metl_stored),
                'sales' => $this->decimal($metre->total_sales_metl_stored),
                'ordered' => $this->decimal($metre->total_ordered_metl_stored),
                'gain' => $this->decimal($metre->total_gain_metl_stored),
            ],
        ];
    }

    private function decimal(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
