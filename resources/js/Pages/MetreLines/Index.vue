<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import { useVirtualizer } from '@tanstack/vue-virtual';
import AppTopBar from '../../Components/AppTopBar.vue';
import ComponentsPanel from '../../Components/ComponentsPanel.vue';
import GridToasts from '../../Components/GridToasts.vue';
import { useDebouncedRowSave } from '../../composables/useDebouncedRowSave';
import { useGridKeyboardNav } from '../../composables/useGridKeyboardNav';

const props = defineProps({
    metre: { type: Object, required: true },
    lines: { type: Array, required: true },
    references: { type: Array, required: true },
    subReferencesByReference: { type: Object, required: true },
});

/** Fixed row height keeps the virtualizer's measurements exact and its scrollbar stable. */
const ROW_HEIGHT = 36;

const EDITABLE_COLUMNS = [
    { key: 'reference_id', type: 'reference', label: 'Réf.', width: '150px' },
    { key: 'sub_reference_id', type: 'subReference', label: 'Sous-réf.', width: '150px' },
    { key: 'description', type: 'text', label: 'Désignation', width: 'minmax(220px, 1fr)' },
    { key: 'quantity', type: 'number', label: 'Qté', width: '90px' },
    // Independent of `quantity` - each is fed from its own component sum in FileMaker, and a
    // "pm" unit forces this one empty, hence the per-row disabling below.
    { key: 'quantity_ordered', type: 'number', label: 'Qté cmd.', width: '90px' },
    { key: 'price_sales', type: 'number', label: 'P.U. vente', width: '110px' },
    { key: 'price_ordered', type: 'number', label: 'P.U. commandé', width: '120px' },
    { key: 'price_buy', type: 'number', label: 'P.U. achat', width: '110px' },
    { key: 'is_option_b', type: 'checkbox', label: 'Option', width: '70px' },
];

/**
 * Two independent reasons a quantity cell refuses input, both of them cases where the server
 * would overwrite whatever was typed:
 *
 *  - a "pm" unit, from the auto-enter `Case ( Unit = "pm" ; "" ; Self )` on both quantity
 *    columns, which empties them on save (MetreLineObserver::saving);
 *  - a composed line, whose quantities are summed from its components by
 *    RecalculateMetreLineQuantitiesFromComponents.
 */
const QUANTITY_COLUMNS = ['quantity', 'quantity_ordered'];

function isPourMemoire(row) {
    return String(row.unit ?? '').trim().toLowerCase() === 'pm';
}

function isCellDisabled(row, key) {
    if (readOnly.value) {
        return true;
    }

    return QUANTITY_COLUMNS.includes(key) && (isPourMemoire(row) || row.has_components);
}

/** Why a quantity cell is locked, so the tooltip names the actual cause. */
function disabledReason(row, key) {
    if (readOnly.value || !QUANTITY_COLUMNS.includes(key)) {
        return null;
    }

    if (row.has_components) {
        return 'Calculé depuis les composants';
    }

    return isPourMemoire(row) ? 'Unité « pm » (pour mémoire) : pas de quantité' : null;
}

/**
 * Displayed, never sent. These are unstored FileMaker calculations with no column behind
 * them, materialized into metre totals by RecalculateMetreTotals - so there is nothing a
 * client could write even if it tried.
 */
const COMPUTED_COLUMNS = [
    { key: 'price_total_sales_no_options', label: 'Total vente' },
    { key: 'price_total_ordered_no_options', label: 'Total commandé' },
    { key: 'price_total_gain_no_options', label: 'Marge' },
];

const gridTemplate = computed(() =>
    ['56px', ...EDITABLE_COLUMNS.map((c) => c.width), ...COMPUTED_COLUMNS.map(() => '120px'), '28px'].join(' ')
);

/** Local working copy: edits land here first, the server confirms afterwards. */
const rows = ref(props.lines.map((line) => ({ ...line, computed: { ...line.computed } })));

/**
 * Two independent reasons the grid may refuse edits: the métré is locked, or the account is
 * readonly. Both are enforced server-side (423 and 403 respectively); this only spares the
 * user from typing into cells whose every save would be rejected.
 */
const page = usePage();
const readOnly = computed(() => props.metre.is_locked_b || page.props.auth?.canWrite === false);
const readOnlyReason = computed(() =>
    props.metre.is_locked_b ? 'verrouillé — lecture seule' : 'compte en lecture seule'
);

// --- components panel -------------------------------------------------------------------

/** Selected by id, not by object: the row is replaced whenever the server confirms a save. */
const selectedLineId = ref(null);
const selectedLine = computed(() => rows.value.find((row) => row.id === selectedLineId.value) ?? null);

/**
 * A component write recomputes the parent line's quantities server-side, and the endpoint
 * returns them. Applied here so the main grid never disagrees with the panel beside it.
 */
function applyLineUpdate(update) {
    const row = rows.value.find((candidate) => candidate.id === selectedLineId.value);

    if (!row || !update) {
        return;
    }

    row.quantity = update.quantity;
    row.quantity_ordered = update.quantity_ordered;
    row.has_components = update.has_components;
    row.computed = { ...update.computed };
}

// --- virtualization ---------------------------------------------------------------------

const scrollParent = ref(null);

const virtualizer = useVirtualizer(
    computed(() => ({
        count: rows.value.length,
        getScrollElement: () => scrollParent.value,
        estimateSize: () => ROW_HEIGHT,
        // Rows kept in the DOM above and below the viewport, so scrolling does not flash.
        overscan: 12,
    }))
);

const virtualRows = computed(() => virtualizer.value.getVirtualItems());
const totalHeight = computed(() => virtualizer.value.getTotalSize());

// --- deferred saving -------------------------------------------------------------------

const toasts = ref([]);
let toastId = 0;

function notify(rowId, message) {
    const index = rows.value.findIndex((row) => row.id === rowId);
    const id = ++toastId;

    toasts.value.push({ id, rowLabel: index >= 0 ? index + 1 : '?', message });

    setTimeout(() => dismiss(id), 6000);
}

function dismiss(id) {
    toasts.value = toasts.value.filter((toast) => toast.id !== id);
}

const { queue, flush, flushAll, status } = useDebouncedRowSave({
    endpoint: '/api/metre-lines',
    delay: 500,
    onError: ({ rowId, rollback, message }) => {
        const row = rows.value.find((candidate) => candidate.id === rowId);

        if (row) {
            // Put back the values as they were before the failed batch, then recompute the
            // derived cells from those restored values.
            Object.assign(row, rollback);
            recomputeLocally(row);
        }

        notify(rowId, message);
    },
});

/**
 * Mirror of the server-side accessors on App\Models\MetreLine, kept only so a cell reacts
 * instantly. The server's response overwrites it, so this is an estimate, never the truth.
 * If the formulas below drift from the model, the display flickers to the correct value on
 * save rather than staying wrong.
 */
function recomputeLocally(row) {
    const round2 = (value) => Math.round(value * 100) / 100;
    const sales = row.is_option_b ? 0 : round2((row.price_sales ?? 0) * (row.quantity ?? 0));
    const ordered = row.is_option_b ? 0 : round2((row.price_ordered ?? 0) * (row.quantity_ordered ?? 0));

    row.computed = {
        price_total_sales_no_options: sales,
        price_total_ordered_no_options: ordered,
        price_total_gain_no_options: round2(sales - ordered),
    };
}

async function edit(row, key, rawValue) {
    if (readOnly.value) {
        return;
    }

    const previous = row[key];
    const value = normalize(key, rawValue);

    if (value === previous) {
        return;
    }

    row[key] = value;

    // Changing the reference invalidates a sub-reference belonging to the old one. Clear it
    // in the same batch, otherwise the server rejects the pair and the whole batch rolls back.
    if (key === 'reference_id' && row.sub_reference_id !== null) {
        const stillValid = subReferencesFor(value).some((option) => option.id === row.sub_reference_id);

        if (!stillValid) {
            const previousSub = row.sub_reference_id;
            row.sub_reference_id = null;
            queue(row.id, 'sub_reference_id', null, previousSub);
        }
    }

    recomputeLocally(row);
    queue(row.id, key, value, previous);
}

function normalize(key, value) {
    const column = EDITABLE_COLUMNS.find((candidate) => candidate.key === key);

    if (column?.type === 'checkbox') {
        return Boolean(value);
    }

    if (column?.type === 'number') {
        return value === '' || value === null ? null : Number(value);
    }

    if (column?.type === 'reference' || column?.type === 'subReference') {
        return value === '' ? null : value;
    }

    return value === '' ? null : value;
}

/** Replace the optimistic estimate with what the server actually stored. */
async function flushRow(row) {
    const stored = await flush(row.id);

    if (stored) {
        Object.assign(row, stored, { computed: { ...stored.computed } });
    }
}

onBeforeUnmount(() => flushAll());

// Anything still pending must not be lost to a tab close.
window.addEventListener('beforeunload', flushAll);
onBeforeUnmount(() => window.removeEventListener('beforeunload', flushAll));

// --- keyboard navigation ---------------------------------------------------------------

const { cursor, focusCell, handleKeydown } = useGridKeyboardNav({
    rowCount: () => rows.value.length,
    columns: EDITABLE_COLUMNS,
    onRequestScroll: (rowIndex) => virtualizer.value.scrollToIndex(rowIndex, { align: 'auto' }),
});

/**
 * The target cell may be outside the rendered window, so focus is claimed after the
 * virtualizer has had a chance to mount it - a DOM lookup rather than a ref, because refs
 * for unrendered rows do not exist.
 */
watch(cursor, async (position) => {
    if (!position) {
        return;
    }

    await nextTick();

    scrollParent.value
        ?.querySelector(`[data-cell="${position.row}-${position.col}"]`)
        ?.focus();
});

function onCellKeydown(event, rowIndex, colIndex) {
    if (handleKeydown(event, { row: rowIndex, col: colIndex })) {
        event.preventDefault();
    }
}

function subReferencesFor(referenceId) {
    return props.subReferencesByReference[referenceId] ?? [];
}

const currency = new Intl.NumberFormat('fr-BE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

function money(value) {
    return value === null || value === undefined ? '' : currency.format(value);
}
</script>

<template>
    <Head :title="`Lignes — ${metre.name ?? 'Métré'}`" />

    <div class="flex h-screen flex-col bg-sand-100">
      <AppTopBar
          :breadcrumbs="[
              { label: 'Projets', href: route('dashboard') },
              { label: metre.name || 'Métré', href: `/metres/${metre.id}` },
              { label: 'Lignes du métré' },
          ]"
      />

      <div class="flex min-h-0 flex-1">
      <div class="flex min-w-0 flex-1 flex-col">
        <header class="flex shrink-0 flex-wrap items-center gap-3 border-b border-sand-200 bg-white px-4 py-2">
            <span class="text-[13px] text-sand-700">
                {{ rows.length }} ligne{{ rows.length === 1 ? '' : 's' }}
            </span>

            <!-- Le badge « compte en lecture seule » est dans la barre supérieure ; celui-ci
                 reste ici parce qu'il peut aussi dire « métré verrouillé », qui est une
                 propriété de l'écran et non du compte. -->
            <span v-if="readOnly" class="badge badge-warning">{{ readOnlyReason }}</span>

            <p class="ml-auto text-xs text-sand-600">
                Flèches / Tab / Entrée pour naviguer · sauvegarde automatique
            </p>
        </header>

        <!-- Column headers, outside the scroller so they stay put; same template as the rows. -->
        <div
            class="grid shrink-0 items-center gap-px border-b border-sand-300 bg-sand-100 px-2 text-[10px] uppercase tracking-[0.06em] text-sand-600"
            :style="{
                gridTemplateColumns: gridTemplate,
                height: `${ROW_HEIGHT}px`,
                fontVariationSettings: `'wght' 600`,
            }"
        >
            <div class="text-right tabular-nums">#</div>
            <div v-for="column in EDITABLE_COLUMNS" :key="column.key" class="truncate px-1">
                {{ column.label }}
            </div>
            <div
                v-for="column in COMPUTED_COLUMNS"
                :key="column.key"
                class="truncate px-1 text-right text-sand-500"
                :title="`${column.label} — calculé, non modifiable`"
            >
                {{ column.label }}
            </div>
            <div />
        </div>

        <div ref="scrollParent" class="flex-1 overflow-auto bg-white">
            <!-- Spacer carrying the full scroll height; only the visible slice is mounted. -->
            <div class="relative w-full" :style="{ height: `${totalHeight}px` }">
                <div
                    v-for="virtualRow in virtualRows"
                    :key="rows[virtualRow.index].id"
                    class="absolute left-0 top-0 grid w-full items-center gap-px border-b border-sand-200/70 px-2 hover:bg-accent-100/40"
                    :class="
                        rows[virtualRow.index].id === selectedLineId
                            ? 'bg-accent-100 shadow-[inset_2px_0_0_0_var(--color-accent-500)]'
                            : ''
                    "
                    :style="{
                        gridTemplateColumns: gridTemplate,
                        height: `${ROW_HEIGHT}px`,
                        transform: `translateY(${virtualRow.start}px)`,
                    }"
                    @click="selectedLineId = rows[virtualRow.index].id"
                >
                    <!-- Doubles as the components affordance: the marker shows which lines are
                         composed, and clicking anywhere on the row opens that line's panel. -->
                    <button
                        type="button"
                        class="flex w-full items-center justify-end gap-1 pr-1 text-xs tabular-nums text-gray-400 transition hover:text-blue-600"
                        :title="
                            rows[virtualRow.index].has_components
                                ? 'Ligne composée — voir ses composants'
                                : 'Ouvrir les composants'
                        "
                        @click.stop="selectedLineId = rows[virtualRow.index].id"
                    >
                        <span
                            v-if="rows[virtualRow.index].has_components"
                            class="size-1.5 rounded-full bg-mallow-500"
                            aria-hidden="true"
                        />
                        {{ virtualRow.index + 1 }}
                    </button>

                    <template v-for="(column, colIndex) in EDITABLE_COLUMNS" :key="column.key">
                        <!-- reference -->
                        <select
                            v-if="column.type === 'reference'"
                            :value="rows[virtualRow.index].reference_id ?? ''"
                            :data-cell="`${virtualRow.index}-${colIndex}`"
                            :disabled="readOnly"
                            class="cell-input focus:bg-white"
                            @change="edit(rows[virtualRow.index], column.key, $event.target.value)"
                            @blur="flushRow(rows[virtualRow.index])"
                            @keydown="onCellKeydown($event, virtualRow.index, colIndex)"
                        >
                            <option value="">—</option>
                            <option v-for="option in references" :key="option.id" :value="option.id">
                                {{ option.label }}
                            </option>
                        </select>

                        <!-- sub-reference, filtered by the row's reference -->
                        <select
                            v-else-if="column.type === 'subReference'"
                            :value="rows[virtualRow.index].sub_reference_id ?? ''"
                            :data-cell="`${virtualRow.index}-${colIndex}`"
                            :disabled="readOnly || !rows[virtualRow.index].reference_id"
                            class="cell-input focus:bg-white disabled:text-sand-400"
                            @change="edit(rows[virtualRow.index], column.key, $event.target.value)"
                            @blur="flushRow(rows[virtualRow.index])"
                            @keydown="onCellKeydown($event, virtualRow.index, colIndex)"
                        >
                            <option value="">—</option>
                            <option
                                v-for="option in subReferencesFor(rows[virtualRow.index].reference_id)"
                                :key="option.id"
                                :value="option.id"
                            >
                                {{ option.label }}
                            </option>
                        </select>

                        <!-- checkbox -->
                        <div v-else-if="column.type === 'checkbox'" class="flex justify-center">
                            <input
                                type="checkbox"
                                :checked="rows[virtualRow.index].is_option_b"
                                :data-cell="`${virtualRow.index}-${colIndex}`"
                                :disabled="readOnly"
                                class="size-3.5"
                                @change="edit(rows[virtualRow.index], column.key, $event.target.checked)"
                                @blur="flushRow(rows[virtualRow.index])"
                                @keydown="onCellKeydown($event, virtualRow.index, colIndex)"
                            />
                        </div>

                        <!-- number -->
                        <input
                            v-else-if="column.type === 'number'"
                            type="number"
                            step="any"
                            :value="rows[virtualRow.index][column.key]"
                            :data-cell="`${virtualRow.index}-${colIndex}`"
                            :disabled="isCellDisabled(rows[virtualRow.index], column.key)"
                            :title="disabledReason(rows[virtualRow.index], column.key)"
                            class="cell-input text-right tabular-nums focus:bg-white disabled:text-sand-400"
                            @input="edit(rows[virtualRow.index], column.key, $event.target.value)"
                            @blur="flushRow(rows[virtualRow.index])"
                            @keydown="onCellKeydown($event, virtualRow.index, colIndex)"
                        />

                        <!-- text -->
                        <input
                            v-else
                            type="text"
                            :value="rows[virtualRow.index][column.key]"
                            :data-cell="`${virtualRow.index}-${colIndex}`"
                            :disabled="readOnly"
                            class="cell-input focus:bg-white"
                            @input="edit(rows[virtualRow.index], column.key, $event.target.value)"
                            @blur="flushRow(rows[virtualRow.index])"
                            @keydown="onCellKeydown($event, virtualRow.index, colIndex)"
                        />
                    </template>

                    <!-- Derived cells: plain text, no input element at all. -->
                    <div
                        v-for="column in COMPUTED_COLUMNS"
                        :key="column.key"
                        class="px-1 text-right text-xs tabular-nums"
                        :class="
                            column.key === 'price_total_gain_no_options' &&
                            rows[virtualRow.index].computed[column.key] < 0
                                ? 'text-danger-600'
                                : 'text-sand-600'
                        "
                    >
                        {{ money(rows[virtualRow.index].computed[column.key]) }}
                    </div>

                    <!-- Per-row save state. -->
                    <div class="flex justify-center">
                        <span
                            v-if="status[rows[virtualRow.index].id] === 'saving'"
                            class="size-1.5 rounded-full bg-info-500"
                            title="Sauvegarde…"
                        />
                        <span
                            v-else-if="status[rows[virtualRow.index].id] === 'error'"
                            class="size-1.5 rounded-full bg-danger-500"
                            title="Échec de sauvegarde"
                        />
                        <span
                            v-else-if="status[rows[virtualRow.index].id] === 'saved'"
                            class="size-1.5 rounded-full bg-success-500"
                            title="Enregistré"
                        />
                    </div>
                </div>
            </div>

            <p v-if="rows.length === 0" class="p-10 text-center text-[13px] text-sand-600">
                Ce métré n'a aucune ligne.
            </p>
        </div>
      </div>

      <ComponentsPanel
          :line="selectedLine"
          :read-only="readOnly"
          @close="selectedLineId = null"
          @line-updated="applyLineUpdate"
          @error="(message) => notify(selectedLineId, message)"
      />
      </div>
    </div>

    <GridToasts :toasts="toasts" @dismiss="dismiss" />
</template>
