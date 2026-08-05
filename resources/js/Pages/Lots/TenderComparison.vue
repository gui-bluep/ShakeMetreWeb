<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppTopBar from '../../Components/AppTopBar.vue';
import Icon from '../../Components/Icon.vue';
import { useDebouncedRowSave } from '../../composables/useDebouncedRowSave';

/**
 * Supplier tender comparison for one lot.
 *
 * Every number in the synthesis panels - sum, percentage, price score, final score, rank -
 * comes straight from App\Http\Controllers\LotController, which reads it from App\Models\Lot
 * (sumForSupplier, bestPricePercentageForSupplier, priceScoreForSupplier,
 * finalScoreForSupplier - all covered by TenderScoringTest). Nothing here recomputes a score.
 * The one exception is each line's per-supplier `total`, mirrored client-side from
 * MetreLine::totalPriceForSupplier() the same way the main grid mirrors its own totals - purely
 * for instant feedback, and only because the metre-line PATCH endpoint's response does not
 * carry it back.
 */
const props = defineProps({
    lot: { type: Object, required: true },
    suppliers: { type: Array, required: true },
    lines: { type: Array, required: true },
    scoring: { type: Object, required: true },
    /**
     * Le métré d'où l'on vient, quand l'écran a été ouvert par « Appel d'offres » du cadre
     * Fournisseurs. La comparaison est alors restreinte à ses lignes - `METT_LOT_ShowLOT` cherche
     * `zkf_LOT AND zkf_MET` - et il faut de quoi y revenir : la source revient sur MET_Form.
     */
    metre: { type: Object, default: null },
});

const page = usePage();
const readOnly = computed(() => page.props.auth?.canWrite === false);

const CRITERIA = [1, 2, 3, 4, 5];

function cloneLot(source) {
    return {
        ...source,
        weighting: {
            price: source.weighting.price,
            criteria: Object.fromEntries(
                Object.entries(source.weighting.criteria).map(([criterion, data]) => [
                    criterion,
                    { weight: data.weight, description: data.description, notes: { ...data.notes } },
                ])
            ),
        },
    };
}

function cloneScoring(source) {
    return {
        best_price_sum: source.best_price_sum,
        suppliers: Object.fromEntries(Object.entries(source.suppliers).map(([slot, s]) => [slot, { ...s }])),
    };
}

function cloneLine(line) {
    return { ...line, quotes: Object.fromEntries(Object.entries(line.quotes).map(([slot, q]) => [slot, { ...q }])) };
}

const lot = ref(cloneLot(props.lot));
const scoring = ref(cloneScoring(props.scoring));
const lines = ref(props.lines.map(cloneLine));

// Same reason as elsewhere: a working copy seeded once at setup would survive a navigation to
// another lot and show that lot's predecessor while writing to the new one.
watch(
    () => props.lot.id,
    () => {
        lot.value = cloneLot(props.lot);
        scoring.value = cloneScoring(props.scoring);
        lines.value = props.lines.map(cloneLine);
    }
);

/** Which of the five slots actually hold a candidate supplier - fixed for the page's lifetime. */
const suppliers = props.suppliers;

const sortedSuppliers = computed(() =>
    [...suppliers].sort((a, b) => (scoring.value.suppliers[a.slot]?.rank ?? 99) - (scoring.value.suppliers[b.slot]?.rank ?? 99))
);

const totalWeight = computed(() => {
    const criteria = Object.values(lot.value.weighting.criteria).reduce((sum, c) => sum + Number(c.weight ?? 0), 0);

    return Number(lot.value.weighting.price ?? 0) + criteria;
});

// --- toasts -------------------------------------------------------------------------------

const toasts = ref([]);
let toastId = 0;

function notify(message) {
    const id = ++toastId;
    toasts.value.push({ id, message });
    setTimeout(() => dismiss(id), 6000);
}

function dismiss(id) {
    toasts.value = toasts.value.filter((toast) => toast.id !== id);
}

// --- quotes: price/quantity per line, per supplier ------------------------------------------

const { queue: queueQuote, flush: flushQuote } = useDebouncedRowSave({
    endpoint: '/api/metre-lines',
    delay: 500,
    onError: ({ rowId, rollback, message }) => {
        const line = lines.value.find((candidate) => candidate.id === rowId);

        if (line) {
            for (const [field, value] of Object.entries(rollback)) {
                applyQuoteField(line, field, value);
            }
        }

        notify(message);
    },
});

function quoteField(slot, type) {
    return `tender_supp${slot}_${type}`;
}

function applyQuoteField(line, field, value) {
    const match = field.match(/^tender_supp(\d)_(price|quantity)$/);

    if (!match) {
        return;
    }

    line.quotes[match[1]][match[2]] = value;
    recomputeQuoteTotal(line, match[1]);
}

/**
 * Mirrors MetreLine::totalPriceForSupplier() for instant feedback only: PATCH
 * /api/metre-lines/{id} returns the line in its main-grid shape, which does not carry this
 * per-supplier total back, so there is nothing server-side to reconcile against here.
 */
function recomputeQuoteTotal(line, slot) {
    if (line.is_option_b) {
        line.quotes[slot].total = null;

        return;
    }

    const price = Number(line.quotes[slot].price ?? 0);
    const quantity = Number(line.quotes[slot].quantity ?? 0);

    line.quotes[slot].total = Math.round(price * quantity * 100) / 100;
}

function editQuote(line, slot, type, rawValue) {
    if (readOnly.value) {
        return;
    }

    const value = rawValue === '' || rawValue === null ? null : Number(rawValue);
    const previous = line.quotes[slot][type];

    if (value === previous) {
        return;
    }

    line.quotes[slot][type] = value;
    recomputeQuoteTotal(line, slot);
    queueQuote(line.id, quoteField(slot, type), value, previous);
}

/** A quote changes the lot's sums, so the synthesis panel is refreshed once the save lands. */
async function flushQuoteRow(line) {
    const stored = await flushQuote(line.id);

    if (stored) {
        await refreshScoring();
    }
}

// --- weighting + criteria notes, all on the lot itself --------------------------------------

const { queue: queueLot, flush: flushLot } = useDebouncedRowSave({
    endpoint: '/api/lots',
    delay: 500,
    onError: ({ rollback, message }) => {
        for (const [field, value] of Object.entries(rollback)) {
            applyLotField(field, value);
        }

        notify(message);
    },
});

function applyLotField(field, value) {
    if (field === 'tender_weighting_price') {
        lot.value.weighting.price = value;

        return;
    }

    const criterionMatch = field.match(/^tender_weighting_crit(\d)$/);
    if (criterionMatch) {
        lot.value.weighting.criteria[criterionMatch[1]].weight = value;

        return;
    }

    const descriptionMatch = field.match(/^tender_weighting_crit(\d)_description$/);
    if (descriptionMatch) {
        lot.value.weighting.criteria[descriptionMatch[1]].description = value;

        return;
    }

    const noteMatch = field.match(/^tender_weighting_crit(\d)_supp(\d)$/);
    if (noteMatch) {
        lot.value.weighting.criteria[noteMatch[1]].notes[noteMatch[2]] = value;
    }
}

function editWeightingPrice(rawValue) {
    if (readOnly.value) {
        return;
    }

    const value = rawValue === '' ? null : Number(rawValue);
    const previous = lot.value.weighting.price;

    if (value === previous) {
        return;
    }

    lot.value.weighting.price = value;
    queueLot(lot.value.id, 'tender_weighting_price', value, previous);
}

function editCriterionWeight(criterion, rawValue) {
    if (readOnly.value) {
        return;
    }

    const value = rawValue === '' ? null : Number(rawValue);
    const data = lot.value.weighting.criteria[criterion];
    const previous = data.weight;

    if (value === previous) {
        return;
    }

    data.weight = value;
    queueLot(lot.value.id, `tender_weighting_crit${criterion}`, value, previous);
}

function editCriterionDescription(criterion, rawValue) {
    if (readOnly.value) {
        return;
    }

    const value = rawValue === '' ? null : rawValue;
    const data = lot.value.weighting.criteria[criterion];
    const previous = data.description;

    if (value === previous) {
        return;
    }

    data.description = value;
    queueLot(lot.value.id, `tender_weighting_crit${criterion}_description`, value, previous);
}

function editNote(criterion, slot, rawValue) {
    if (readOnly.value) {
        return;
    }

    const value = rawValue === '' ? null : Number(rawValue);
    const data = lot.value.weighting.criteria[criterion];
    const previous = data.notes[slot];

    if (value === previous) {
        return;
    }

    data.notes[slot] = value;
    queueLot(lot.value.id, `tender_weighting_crit${criterion}_supp${slot}`, value, previous);
}

/** Any weighting or note edit changes every supplier's score, so the panel is replaced whole. */
async function flushLotRow() {
    const body = await flushLot(lot.value.id);

    if (body) {
        lot.value.weighting = body.lot.weighting;
        lot.value.company_id = body.lot.company_id;
        scoring.value = body.scoring;
    }
}

async function refreshScoring() {
    try {
        const body = await request(`/api/lots/${lot.value.id}/tender-scoring`);
        lot.value.weighting = body.data.lot.weighting;
        lot.value.company_id = body.data.lot.company_id;
        scoring.value = body.data.scoring;
    } catch (error) {
        notify(error.message);
    }
}

// --- award ------------------------------------------------------------------------------

const awarding = ref(null);
const awardErrors = ref({});

async function award(supplier) {
    if (readOnly.value || awarding.value) {
        return;
    }

    awarding.value = supplier.slot;
    awardErrors.value = { ...awardErrors.value, [supplier.slot]: null };

    try {
        const body = await request(`/api/lots/${lot.value.id}/tender-award`, 'POST', {
            supplier_number: supplier.slot,
            company_id: supplier.company_id,
        });

        lot.value.company_id = body.data.lot.company_id;
        scoring.value = body.data.scoring;
    } catch (error) {
        // The exception's own message, verbatim - not a generic "failed to award" string.
        awardErrors.value = { ...awardErrors.value, [supplier.slot]: error.message };
    } finally {
        awarding.value = null;
    }
}

// --- transport --------------------------------------------------------------------------

async function request(url, method = 'GET', payload = null) {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': csrfToken(),
        },
        body: payload === null ? undefined : JSON.stringify(payload),
    });

    if (response.ok) {
        return response.json();
    }

    let body = null;

    try {
        body = await response.json();
    } catch {
        throw new Error(`Échec (HTTP ${response.status})`);
    }

    throw new Error(
        body?.errors ? Object.values(body.errors).flat()[0] : (body?.message ?? `Échec (HTTP ${response.status})`)
    );
}

function csrfToken() {
    const cookie = document.cookie.split('; ').find((entry) => entry.startsWith('XSRF-TOKEN='));

    return cookie
        ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length))
        : (document.querySelector('meta[name="csrf-token"]')?.content ?? '');
}

// --- display helpers ----------------------------------------------------------------------

const currency = new Intl.NumberFormat('fr-BE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

function money(value) {
    return value === null || value === undefined ? '—' : currency.format(value);
}

function percent(value) {
    return value === null || value === undefined ? '—' : `${value}%`;
}

function ordinal(rank) {
    if (!rank) {
        return '—';
    }

    return rank === 1 ? '1er' : `${rank}e`;
}

function shortId(id) {
    return id ? `${id.slice(0, 8)}…` : '—';
}
</script>

<template>
    <Head :title="`Appel d'offres — ${lot.title ?? 'Lot ' + lot.code}`" />

    <div class="min-h-screen bg-sand-100 pb-16">
        <AppTopBar
            :breadcrumbs="metre
                ? [
                    { label: 'Projets', href: route('dashboard') },
                    { label: metre.name ?? `Métré ${metre.ind_project}`, href: `/metres/${metre.id}` },
                    { label: `Appel d'offres — ${lot.title ?? 'Lot ' + lot.code}` },
                ]
                : [
                    { label: 'Projets', href: route('dashboard') },
                    { label: `Appel d'offres — ${lot.title ?? 'Lot ' + lot.code}` },
                ]"
        />

        <!-- Le badge « lecture seule » est dans la barre supérieure : c'est une propriété du
             compte, pas de cet écran. -->
        <header class="flex items-center justify-between gap-4 border-b border-sand-200 bg-white px-6 py-3">
            <div class="min-w-0">
                <h1 class="truncate text-[17px] leading-tight text-sand-900" style="font-variation-settings: 'wght' 600">
                    Comparaison fournisseurs — {{ lot.title ?? `Lot ${lot.code}` }}
                </h1>
                <!-- Dire la restriction : sans cela, un écran qui ne montre qu'une partie des
                     offres du lot se lit comme un lot qui en a peu. -->
                <p v-if="metre" class="mt-0.5 text-[12px] text-sand-600">
                    Restreint aux lignes de {{ metre.name ?? `métré ${metre.ind_project}` }}
                </p>
            </div>

            <!-- Avec le lot, pour la même raison que le bouton « Métré » des vues de lignes. -->
            <Link v-if="metre" :href="`/metres/${metre.id}?lot=${lot.id}`" class="btn btn-secondary shrink-0">
                <Icon name="arrow-left" :size="4" />
                Retour au métré
            </Link>
        </header>

        <!-- Weighting: shared across every supplier. Notes per supplier live in the panels below. -->
        <section class="surface mx-6 mt-4 p-4">
            <h2 class="eyebrow">Pondération</h2>

            <div class="mt-3 grid grid-cols-[120px_1fr_90px] items-center gap-x-3 gap-y-2 text-xs">
                <label class="text-gray-600">Prix</label>
                <div />
                <input
                    type="number"
                    step="any"
                    :value="lot.weighting.price"
                    :disabled="readOnly"
                    class="block w-full px-2 py-1 text-right tabular-nums"
                    @input="editWeightingPrice($event.target.value)"
                    @blur="flushLotRow"
                />

                <template v-for="criterion in CRITERIA" :key="criterion">
                    <label class="text-gray-600">Critère {{ criterion }}</label>
                    <input
                        type="text"
                        :value="lot.weighting.criteria[criterion].description"
                        :disabled="readOnly"
                        placeholder="Description du critère"
                        class="block w-full px-2 py-1"
                        @input="editCriterionDescription(criterion, $event.target.value)"
                        @blur="flushLotRow"
                    />
                    <input
                        type="number"
                        step="any"
                        :value="lot.weighting.criteria[criterion].weight"
                        :disabled="readOnly"
                        class="block w-full px-2 py-1 text-right tabular-nums"
                        @input="editCriterionWeight(criterion, $event.target.value)"
                        @blur="flushLotRow"
                    />
                </template>
            </div>

            <p class="mt-3 text-[11px] text-gray-400">
                Total des poids : {{ totalWeight }} — informatif, pas forcé à 100.
            </p>
        </section>

        <!-- Lines of the tender, one price + quantity column pair per assigned supplier. -->
        <section class="surface mx-6 mt-4 overflow-x-auto">
            <table class="w-full border-collapse text-xs">
                <thead>
                    <tr class="border-b border-sand-200 bg-sand-100 text-[10px] uppercase tracking-[0.06em] text-sand-600">
                        <th class="px-2 py-2 text-left" rowspan="2">Désignation</th>
                        <th
                            v-for="supplier in suppliers"
                            :key="supplier.slot"
                            class="border-l border-gray-200 px-2 py-1 text-center"
                            colspan="3"
                        >
                            Fournisseur {{ supplier.slot }}
                        </th>
                    </tr>
                    <tr class="border-b border-sand-200 bg-sand-50 text-[10px] uppercase tracking-[0.06em] text-sand-500">
                        <template v-for="supplier in suppliers" :key="supplier.slot">
                            <th class="border-l border-gray-200 px-2 py-1 text-right">P.U.</th>
                            <th class="px-2 py-1 text-right">Qté</th>
                            <th class="px-2 py-1 text-right">Total</th>
                        </template>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="line in lines" :key="line.id" class="border-b border-gray-100 hover:bg-blue-50/30">
                        <td class="px-2 py-1">
                            <span :class="line.is_option_b ? 'text-gray-400' : ''">{{ line.description || '—' }}</span>
                            <span v-if="line.is_option_b" class="badge badge-neutral ml-1">option</span>
                        </td>
                        <template v-for="supplier in suppliers" :key="supplier.slot">
                            <td class="border-l border-gray-100 px-1 py-1">
                                <input
                                    type="number"
                                    step="any"
                                    :value="line.quotes[supplier.slot].price"
                                    :disabled="readOnly"
                                    class="w-20 rounded border-none bg-transparent px-1 py-0.5 text-right tabular-nums focus:bg-white focus:ring-2 focus:ring-blue-500"
                                    @input="editQuote(line, supplier.slot, 'price', $event.target.value)"
                                    @blur="flushQuoteRow(line)"
                                />
                            </td>
                            <td class="px-1 py-1">
                                <input
                                    type="number"
                                    step="any"
                                    :value="line.quotes[supplier.slot].quantity"
                                    :disabled="readOnly"
                                    class="w-16 rounded border-none bg-transparent px-1 py-0.5 text-right tabular-nums focus:bg-white focus:ring-2 focus:ring-blue-500"
                                    @input="editQuote(line, supplier.slot, 'quantity', $event.target.value)"
                                    @blur="flushQuoteRow(line)"
                                />
                            </td>
                            <td class="px-2 py-1 text-right tabular-nums text-gray-500">
                                {{ money(line.quotes[supplier.slot].total) }}
                            </td>
                        </template>
                    </tr>

                    <tr v-if="lines.length === 0">
                        <td :colspan="1 + suppliers.length * 3" class="p-6 text-center text-gray-400">
                            Ce lot n'a aucune ligne d'appel d'offres.
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>

        <!-- Synthesis: one panel per assigned supplier, ranked by final score. -->
        <section class="mx-6 mt-4 grid gap-4" :style="{ gridTemplateColumns: `repeat(${suppliers.length || 1}, minmax(220px, 1fr))` }">
            <div
                v-for="supplier in sortedSuppliers"
                :key="supplier.slot"
                class="flex flex-col rounded border bg-white p-3"
                :class="lot.company_id === supplier.company_id ? 'border-emerald-300 ring-1 ring-emerald-200' : 'border-gray-200'"
            >
                <div class="flex items-start justify-between">
                    <div>
                        <p class="text-xs font-semibold text-gray-900">Fournisseur {{ supplier.slot }}</p>
                        <p class="text-[11px] text-gray-400" :title="supplier.company_id">{{ shortId(supplier.company_id) }}</p>
                    </div>
                    <span
                        class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                        :class="scoring.suppliers[supplier.slot]?.rank === 1
                            ? 'border border-accent-500 bg-accent-200 text-sand-900'
                            : 'bg-sand-100 text-sand-600'"
                    >
                        {{ ordinal(scoring.suppliers[supplier.slot]?.rank) }}
                    </span>
                </div>

                <dl class="mt-3 space-y-1 text-xs">
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Total</dt>
                        <dd class="tabular-nums text-gray-900">{{ money(scoring.suppliers[supplier.slot]?.sum) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500">% du repère</dt>
                        <dd class="tabular-nums text-gray-900">{{ percent(scoring.suppliers[supplier.slot]?.best_price_percentage) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Score prix</dt>
                        <dd class="tabular-nums text-gray-900">{{ scoring.suppliers[supplier.slot]?.price_score ?? '—' }}</dd>
                    </div>
                </dl>

                <div class="mt-3 border-t border-gray-100 pt-2">
                    <p class="text-[11px] font-medium uppercase tracking-wide text-gray-400">Critères</p>
                    <div v-for="criterion in CRITERIA" :key="criterion" class="mt-1 flex items-center justify-between gap-2">
                        <label class="min-w-0 flex-1 truncate text-[11px] text-gray-500" :title="lot.weighting.criteria[criterion].description">
                            {{ lot.weighting.criteria[criterion].description || `Critère ${criterion}` }}
                        </label>
                        <input
                            type="number"
                            min="0"
                            max="100"
                            :value="lot.weighting.criteria[criterion].notes[supplier.slot]"
                            :disabled="readOnly"
                            class="w-14 shrink-0 px-1 py-0.5 text-right text-xs tabular-nums"
                            @input="editNote(criterion, supplier.slot, $event.target.value)"
                            @blur="flushLotRow"
                        />
                    </div>
                </div>

                <div class="mt-3 flex items-baseline justify-between border-t border-gray-100 pt-2">
                    <span class="text-[11px] uppercase tracking-wide text-gray-400">Score final</span>
                    <span class="text-base font-semibold tabular-nums text-gray-900">
                        {{ scoring.suppliers[supplier.slot]?.final_score ?? '—' }}
                    </span>
                </div>

                <p v-if="awardErrors[supplier.slot]" class="mt-2 rounded bg-red-50 px-2 py-1 text-[11px] text-red-700">
                    {{ awardErrors[supplier.slot] }}
                </p>

                <p v-if="lot.company_id === supplier.company_id" class="mt-3 rounded bg-emerald-50 px-2 py-1 text-center text-xs font-medium text-emerald-700">
                    Fournisseur retenu
                </p>
                <button
                    v-else-if="!readOnly"
                    type="button"
                    class="btn btn-secondary btn-sm mt-3"
                    :disabled="awarding === supplier.slot"
                    @click="award(supplier)"
                >
                    {{ awarding === supplier.slot ? 'Attribution…' : 'Retenir ce fournisseur' }}
                </button>
            </div>
        </section>
    </div>

    <div class="pointer-events-none fixed bottom-4 right-4 z-50 flex w-80 flex-col gap-2" aria-live="polite">
        <TransitionGroup
            enter-active-class="transition duration-150 ease-out"
            enter-from-class="translate-y-1 opacity-0"
            leave-active-class="transition duration-100 ease-in"
            leave-to-class="opacity-0"
        >
            <div v-for="toast in toasts" :key="toast.id" class="pointer-events-auto rounded-md border border-red-200 bg-white px-3 py-2 shadow-sm" role="status">
                <div class="flex items-start gap-2">
                    <span class="mt-0.5 size-2 shrink-0 rounded-full bg-red-500" aria-hidden="true" />
                    <p class="min-w-0 flex-1 truncate text-xs text-gray-700" :title="toast.message">{{ toast.message }}</p>
                    <button type="button" class="shrink-0 text-gray-400 transition hover:text-gray-600" aria-label="Fermer" @click="dismiss(toast.id)">
                        &times;
                    </button>
                </div>
            </div>
        </TransitionGroup>
    </div>
</template>
