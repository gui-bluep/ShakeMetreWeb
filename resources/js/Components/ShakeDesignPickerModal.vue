<script setup>
import { ref, watch } from 'vue';
import Modal from './Modal.vue';
import SecondaryButton from './SecondaryButton.vue';
import { fold, matches } from '@/searchMatch';
import { u } from '@/basePath';

/**
 * A FileMaker-style value picker over a ShakeDesign list: search at the top, a scrollable
 * list of records, one click to choose. Shows the name, emits the zkp.
 *
 * Deliberately generic over "what is being picked": the caller supplies the endpoint and
 * whether searching happens server-side. Companies are searched by the Data API (the list can
 * be long); a company's contacts are few and arrive in one payload, so those are filtered
 * client-side without another round trip.
 */
const props = defineProps({
    show: { type: Boolean, default: false },
    title: { type: String, required: true },
    /** Endpoint returning `{data: [{zkp, name, ...}]}`. Null renders nothing and loads nothing. */
    endpoint: { type: String, default: null },
    /** True: re-query the endpoint with ?q= on each keystroke. False: filter what we have. */
    serverSearch: { type: Boolean, default: false },
    /** Currently stored zkp, highlighted in the list. */
    selectedId: { type: String, default: null },
    /** Whether choosing "no value" is offered. */
    clearable: { type: Boolean, default: true },
    clearLabel: { type: String, default: 'Aucun' },
});

const emit = defineEmits(['close', 'select', 'clear']);

const query = ref('');
const rows = ref([]);
const loading = ref(false);
const error = ref(null);

/** Set when the endpoint capped its result, so a partial list is not shown as a whole one. */
const truncated = ref(false);
const limit = ref(null);

let debounceTimer = null;
let requestToken = 0;

// Reloads whenever the picker opens, so it never shows a list built for another company.
watch(
    () => [props.show, props.endpoint],
    () => {
        if (! props.show || ! props.endpoint) {
            return;
        }

        query.value = '';
        rows.value = [];
        error.value = null;
        load('');
    },
    { immediate: true }
);

function onInput(value) {
    query.value = value;

    if (! props.serverSearch) {
        return;
    }

    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => load(value.trim()), 300);
}

async function load(term) {
    if (! props.endpoint) {
        return;
    }

    const thisRequest = ++requestToken;
    loading.value = true;
    error.value = null;

    try {
        const url = term === '' ? props.endpoint : `${props.endpoint}?q=${encodeURIComponent(term)}`;
        const response = await fetch(u(url), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });

        const body = await response.json().catch(() => null);

        if (! response.ok) {
            throw new Error(body?.message ?? `Chargement impossible (HTTP ${response.status})`);
        }

        // A slower earlier request must not overwrite a faster later one.
        if (thisRequest === requestToken) {
            rows.value = body.data ?? [];
            truncated.value = body.truncated === true;
            limit.value = body.limit ?? null;
        }
    } catch (e) {
        if (thisRequest === requestToken) {
            error.value = e.message;
            rows.value = [];
            truncated.value = false;
        }
    } finally {
        if (thisRequest === requestToken) {
            loading.value = false;
        }
    }
}

/** Client-side narrowing, used when serverSearch is false. Insensible aux accents comme la
 *  recherche des lignes : « societe » doit trouver « Société ». */
function visibleRows() {
    const term = fold(query.value.trim());

    if (props.serverSearch || term === '') {
        return rows.value;
    }

    return rows.value.filter((row) =>
        [row.name, row.role, row.vat, row.city].some((field) => matches(field, term))
    );
}

/** The secondary line under a name: whatever identifying detail the endpoint returned. */
function detail(row) {
    return [row.role, row.vat, row.city].filter(Boolean).join(' · ');
}
</script>

<template>
    <Modal :show="show" max-width="lg" @close="$emit('close')">
        <div class="p-6">
            <h2 class="text-lg font-medium text-gray-900">{{ title }}</h2>

            <input
                type="search"
                :value="query"
                placeholder="Rechercher…"
                class="mt-3 block w-full px-2.5 py-1.5"
                @input="onInput($event.target.value)"
            />

            <p v-if="truncated" class="mt-2 rounded bg-amber-50 px-2 py-1 text-xs text-amber-800">
                Seuls les {{ limit }} premiers résultats sont affichés — affinez la recherche.
            </p>

            <div class="mt-3 max-h-80 divide-y divide-sand-200/70 overflow-y-auto rounded-md border border-sand-200">
                <p v-if="loading" class="p-4 text-center text-sm text-gray-400">Chargement…</p>

                <p v-else-if="error" class="p-4 text-sm text-red-600">{{ error }}</p>

                <p v-else-if="visibleRows().length === 0" class="p-4 text-center text-sm text-gray-400">
                    Aucun résultat.
                </p>

                <template v-else>
                    <button
                        v-for="row in visibleRows()"
                        :key="row.zkp"
                        type="button"
                        class="block w-full px-3 py-2 text-left hover:bg-blue-50"
                        :class="row.zkp === selectedId ? 'bg-blue-50' : ''"
                        @click="$emit('select', row)"
                    >
                        <span class="block text-sm text-gray-900">{{ row.name }}</span>
                        <span v-if="detail(row)" class="block text-xs text-gray-500">{{ detail(row) }}</span>
                    </button>
                </template>
            </div>

            <div class="mt-6 flex justify-between">
                <SecondaryButton v-if="clearable" @click="$emit('clear')">{{ clearLabel }}</SecondaryButton>
                <span v-else />
                <SecondaryButton @click="$emit('close')">Annuler</SecondaryButton>
            </div>
        </div>
    </Modal>
</template>
