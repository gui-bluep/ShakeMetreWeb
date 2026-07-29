<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import { useVirtualizer } from '@tanstack/vue-virtual';
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
 * METL_MetreLines::QuantityOrdered auto-enter: `Case ( Unit = "pm" ; "" ; Self )`. A pour
 * mémoire line carries no ordered quantity, so the cell refuses input instead of letting the
 * server silently blank it a moment later. Mirrors MetreLineObserver::saving().
 */
function isCellDisabled(row, key) {
    if (readOnly.value) {
        return true;
    }

    return key === 'quantity_ordered' && String(row.unit ?? '').trim().toLowerCase() === 'pm';
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

const readOnly = computed(() => props.metre.is_locked_b);

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

    <div class="flex h-screen flex-col">
        <header class="flex items-baseline justify-between border-b border-gray-200 bg-white px-4 py-3">
            <div>
                <h1 class="text-sm font-semibold text-gray-900">{{ metre.name ?? 'Métré' }}</h1>
                <p class="mt-0.5 text-xs text-gray-500">
                    {{ rows.length }} ligne{{ rows.length === 1 ? '' : 's' }}
                    <span v-if="readOnly" class="ml-2 rounded bg-amber-100 px-1.5 py-0.5 text-amber-800">
                        verrouillé — lecture seule
                    </span>
                </p>
            </div>
            <p class="text-xs text-gray-400">
                Flèches / Tab / Entrée pour naviguer · sauvegarde automatique
            </p>
        </header>

        <!-- Column headers, outside the scroller so they stay put; same template as the rows. -->
        <div
            class="grid shrink-0 items-center gap-px border-b border-gray-200 bg-gray-100 px-2 text-[11px] font-medium uppercase tracking-wide text-gray-500"
            :style="{ gridTemplateColumns: gridTemplate, height: `${ROW_HEIGHT}px` }"
        >
            <div class="text-right tabular-nums">#</div>
            <div v-for="column in EDITABLE_COLUMNS" :key="column.key" class="truncate px-1">
                {{ column.label }}
            </div>
            <div
                v-for="column in COMPUTED_COLUMNS"
                :key="column.key"
                class="truncate px-1 text-right text-gray-400"
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
                    class="absolute left-0 top-0 grid w-full items-center gap-px border-b border-gray-100 px-2 hover:bg-blue-50/40"
                    :style="{
                        gridTemplateColumns: gridTemplate,
                        height: `${ROW_HEIGHT}px`,
                        transform: `translateY(${virtualRow.start}px)`,
                    }"
                >
                    <div class="pr-1 text-right text-xs tabular-nums text-gray-400">
                        {{ virtualRow.index + 1 }}
                    </div>

                    <template v-for="(column, colIndex) in EDITABLE_COLUMNS" :key="column.key">
                        <!-- reference -->
                        <select
                            v-if="column.type === 'reference'"
                            :value="rows[virtualRow.index].reference_id ?? ''"
                            :data-cell="`${virtualRow.index}-${colIndex}`"
                            :disabled="readOnly"
                            class="w-full rounded border-none bg-transparent px-1 py-0.5 text-xs focus:bg-white focus:ring-2 focus:ring-blue-500"
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
                            class="w-full rounded border-none bg-transparent px-1 py-0.5 text-xs focus:bg-white focus:ring-2 focus:ring-blue-500 disabled:text-gray-300"
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
                                class="size-3.5 rounded border-gray-300 text-blue-600 focus:ring-2 focus:ring-blue-500"
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
                            :title="
                                isCellDisabled(rows[virtualRow.index], column.key) && !readOnly
                                    ? 'Unité « pm » : pas de quantité commandée'
                                    : null
                            "
                            class="w-full rounded border-none bg-transparent px-1 py-0.5 text-right text-xs tabular-nums focus:bg-white focus:ring-2 focus:ring-blue-500 disabled:bg-gray-50 disabled:text-gray-300"
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
                            class="w-full rounded border-none bg-transparent px-1 py-0.5 text-xs focus:bg-white focus:ring-2 focus:ring-blue-500"
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
                                ? 'text-red-600'
                                : 'text-gray-500'
                        "
                    >
                        {{ money(rows[virtualRow.index].computed[column.key]) }}
                    </div>

                    <!-- Per-row save state. -->
                    <div class="flex justify-center">
                        <span
                            v-if="status[rows[virtualRow.index].id] === 'saving'"
                            class="size-1.5 rounded-full bg-blue-400"
                            title="Sauvegarde…"
                        />
                        <span
                            v-else-if="status[rows[virtualRow.index].id] === 'error'"
                            class="size-1.5 rounded-full bg-red-500"
                            title="Échec de sauvegarde"
                        />
                        <span
                            v-else-if="status[rows[virtualRow.index].id] === 'saved'"
                            class="size-1.5 rounded-full bg-emerald-400"
                            title="Enregistré"
                        />
                    </div>
                </div>
            </div>

            <p v-if="rows.length === 0" class="p-8 text-center text-sm text-gray-400">
                Ce métré n'a aucune ligne.
            </p>
        </div>
    </div>

    <GridToasts :toasts="toasts" @dismiss="dismiss" />
</template>
