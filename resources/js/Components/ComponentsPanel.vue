<script setup>
import { computed, ref, watch } from 'vue';
import { useDebouncedRowSave } from '../composables/useDebouncedRowSave';

/**
 * The components of one metre line - FileMaker's METC portal, as a side panel.
 *
 * Its quantities drive the parent line's, so every write returns the recomputed line and the
 * panel forwards it upward: the main grid must never disagree with what this panel shows.
 */
const props = defineProps({
    line: { type: Object, default: null },
    readOnly: { type: Boolean, default: false },
});

const emit = defineEmits(['close', 'line-updated', 'error']);

const COLUMNS = [
    { key: 'description', type: 'text', label: 'Description', width: 'minmax(140px, 1fr)' },
    { key: 'quantity_sales', type: 'number', label: 'Qté vente', width: '84px' },
    { key: 'length', type: 'number', label: 'Long.', width: '72px' },
    { key: 'width', type: 'number', label: 'Larg.', width: '72px' },
    { key: 'height', type: 'number', label: 'Haut.', width: '72px' },
    { key: 'quantity_ordered', type: 'number', label: 'Qté cmd.', width: '84px' },
];

const COMPUTED_COLUMNS = [
    { key: 'value_sales', label: 'Val. vente' },
    { key: 'value_ordered', label: 'Val. cmd.' },
];

const gridTemplate = computed(() =>
    ['20px', ...COLUMNS.map((c) => c.width), ...COMPUTED_COLUMNS.map(() => '84px'), '24px'].join(' ')
);

const rows = ref([]);
const loading = ref(false);
const busy = ref(false);

/** rowId -> the sort_order it had when the drag started, for a rollback. */
const dragging = ref(null);

const { queue, flush, flushAll, status } = useDebouncedRowSave({
    endpoint: '/api/metre-line-components',
    delay: 500,
    onError: ({ rowId, rollback, message }) => {
        const row = rows.value.find((candidate) => candidate.id === rowId);

        if (row) {
            Object.assign(row, rollback);
            recomputeLocally(row);
        }

        emit('error', message);
    },
});

watch(
    () => props.line?.id,
    async (id) => {
        if (!id) {
            rows.value = [];

            return;
        }

        await flushAll();
        await load(id);
    },
    { immediate: true }
);

async function load(lineId) {
    loading.value = true;

    try {
        const body = await request(`/api/metre-lines/${lineId}/components`);
        rows.value = body.data.map((row) => ({ ...row, computed: { ...row.computed } }));
        emit('line-updated', body.line);
    } catch (error) {
        emit('error', error.message);
    } finally {
        loading.value = false;
    }
}

/**
 * Mirror of MetreLineComponent::ValueSales_c / ValueOrdered_c, for instant feedback only. The
 * server's response replaces it, so a drift here shows up as a value correcting itself on save
 * rather than as a wrong number that sticks.
 *
 * An absent dimension counts as 1; a dimension of 0 stays 0 and zeroes the result.
 */
function recomputeLocally(row) {
    const factor = (value) => (value === null || value === undefined ? 1 : Number(value));
    const round2 = (value) => Math.round(value * 100) / 100;
    const dimensions = factor(row.length) * factor(row.width) * factor(row.height);

    row.computed = {
        value_sales: round2((row.quantity_sales ?? 0) * dimensions),
        value_ordered: round2((row.quantity_ordered ?? 0) * dimensions),
    };
}

function edit(row, key, rawValue) {
    if (props.readOnly) {
        return;
    }

    const column = COLUMNS.find((candidate) => candidate.key === key);
    const value =
        column?.type === 'number'
            ? rawValue === '' || rawValue === null
                ? null
                : Number(rawValue)
            : rawValue === ''
              ? null
              : rawValue;

    const previous = row[key];

    if (value === previous) {
        return;
    }

    row[key] = value;
    recomputeLocally(row);
    queue(row.id, key, value, previous);
}

async function flushRow(row) {
    const body = await flush(row.id);

    if (body) {
        // `body` is the row; the endpoint also returns the recomputed parent line beside it.
        Object.assign(row, body, { computed: { ...body.computed } });
    }
}

async function addRow() {
    if (props.readOnly || busy.value) {
        return;
    }

    busy.value = true;

    try {
        const body = await request(`/api/metre-lines/${props.line.id}/components`, 'POST', {});
        rows.value.push({ ...body.data, computed: { ...body.data.computed } });
        emit('line-updated', body.line);
    } catch (error) {
        emit('error', error.message);
    } finally {
        busy.value = false;
    }
}

async function removeRow(row) {
    if (props.readOnly || busy.value) {
        return;
    }

    busy.value = true;
    const index = rows.value.indexOf(row);
    rows.value.splice(index, 1);

    try {
        const body = await request(`/api/metre-line-components/${row.id}`, 'DELETE');
        emit('line-updated', body.line);
    } catch (error) {
        // Put the row back where it was rather than leaving the panel disagreeing with the DB.
        rows.value.splice(index, 0, row);
        emit('error', error.message);
    } finally {
        busy.value = false;
    }
}

// --- reordering -------------------------------------------------------------------------

function onDragStart(row) {
    if (props.readOnly) {
        return;
    }

    dragging.value = row.id;
}

function onDragOver(target) {
    if (!dragging.value || dragging.value === target.id) {
        return;
    }

    const from = rows.value.findIndex((row) => row.id === dragging.value);
    const to = rows.value.indexOf(target);

    if (from === -1 || to === -1) {
        return;
    }

    // Moved locally as the pointer travels; positions are persisted once, on drop.
    rows.value.splice(to, 0, ...rows.value.splice(from, 1));
}

async function onDrop() {
    if (!dragging.value) {
        return;
    }

    dragging.value = null;

    // Renumbered from the visible order, so the stored sort_order matches what the user sees
    // instead of accumulating gaps from repeated moves.
    await Promise.all(
        rows.value.map((row, index) => {
            if (row.sort_order === index) {
                return null;
            }

            const previous = row.sort_order;
            row.sort_order = index;
            queue(row.id, 'sort_order', index, previous);

            return flush(row.id);
        })
    );
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

const currency = new Intl.NumberFormat('fr-BE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

function money(value) {
    return value === null || value === undefined ? '' : currency.format(value);
}
</script>

<template>
    <aside
        v-if="line"
        class="flex w-[46rem] shrink-0 flex-col border-l border-gray-200 bg-white"
        aria-label="Composants de la ligne"
    >
        <header class="flex items-start justify-between border-b border-gray-200 px-3 py-2">
            <div class="min-w-0">
                <h2 class="text-xs font-semibold text-gray-900">Composants</h2>
                <p class="mt-0.5 truncate text-xs text-gray-500" :title="line.description">
                    {{ line.description || 'Ligne sans désignation' }}
                </p>
                <p v-if="rows.length" class="mt-0.5 text-[11px] text-gray-400">
                    Les quantités de la ligne sont calculées depuis ces composants.
                </p>
            </div>
            <button
                type="button"
                class="btn btn-ghost btn-sm ml-2 px-1"
                aria-label="Fermer le panneau"
                @click="$emit('close')"
            >
                &times;
            </button>
        </header>

        <div
            class="grid shrink-0 items-center gap-px border-b border-sand-300 bg-sand-100 px-2 py-1 text-[10px] uppercase tracking-[0.06em] text-sand-600"
            :style="{ gridTemplateColumns: gridTemplate }"
        >
            <div />
            <div v-for="column in COLUMNS" :key="column.key" class="truncate px-1">{{ column.label }}</div>
            <div
                v-for="column in COMPUTED_COLUMNS"
                :key="column.key"
                class="truncate px-1 text-right text-gray-400"
                title="Calculé, non modifiable"
            >
                {{ column.label }}
            </div>
            <div />
        </div>

        <div class="flex-1 overflow-auto">
            <p v-if="loading" class="p-4 text-center text-xs text-gray-400">Chargement…</p>

            <p v-else-if="rows.length === 0" class="p-4 text-center text-xs text-gray-400">
                Aucun composant. La quantité de la ligne reste saisie à la main.
            </p>

            <div
                v-for="row in rows"
                :key="row.id"
                class="grid items-center gap-px border-b border-gray-100 px-2 hover:bg-blue-50/40"
                :class="dragging === row.id ? 'opacity-40' : ''"
                :style="{ gridTemplateColumns: gridTemplate, height: '30px' }"
                :draggable="!readOnly"
                @dragstart="onDragStart(row)"
                @dragover.prevent="onDragOver(row)"
                @drop.prevent="onDrop"
                @dragend="onDrop"
            >
                <div
                    class="cursor-grab select-none text-center text-xs text-gray-300"
                    :title="readOnly ? null : 'Glisser pour réordonner'"
                >
                    ⋮⋮
                </div>

                <template v-for="column in COLUMNS" :key="column.key">
                    <input
                        :type="column.type === 'number' ? 'number' : 'text'"
                        :step="column.type === 'number' ? 'any' : null"
                        :value="row[column.key]"
                        :disabled="readOnly"
                        class="cell-input focus:bg-white"
                        :class="column.type === 'number' ? 'text-right tabular-nums' : ''"
                        @input="edit(row, column.key, $event.target.value)"
                        @blur="flushRow(row)"
                    />
                </template>

                <div
                    v-for="column in COMPUTED_COLUMNS"
                    :key="column.key"
                    class="px-1 text-right text-xs tabular-nums text-gray-500"
                >
                    {{ money(row.computed[column.key]) }}
                </div>

                <div class="flex items-center justify-center gap-1">
                    <span
                        v-if="status[row.id] === 'saving'"
                        class="size-1.5 rounded-full bg-blue-400"
                        title="Sauvegarde…"
                    />
                    <span
                        v-else-if="status[row.id] === 'error'"
                        class="size-1.5 rounded-full bg-red-500"
                        title="Échec de sauvegarde"
                    />
                    <button
                        v-if="!readOnly"
                        type="button"
                        class="rounded px-1 text-xs text-gray-300 transition hover:bg-red-50 hover:text-red-600"
                        aria-label="Supprimer le composant"
                        @click="removeRow(row)"
                    >
                        &times;
                    </button>
                </div>
            </div>
        </div>

        <footer v-if="!readOnly" class="shrink-0 border-t border-gray-200 px-3 py-2">
            <button
                type="button"
                class="btn btn-secondary btn-sm"
                :disabled="busy"
                @click="addRow"
            >
                + Ajouter un composant
            </button>
        </footer>
    </aside>
</template>
