<script setup>
import { computed, ref } from 'vue';
import DangerButton from './DangerButton.vue';
import Modal from './Modal.vue';
import SecondaryButton from './SecondaryButton.vue';
import ShakeDesignPickerModal from './ShakeDesignPickerModal.vue';
import { useDebouncedRowSave } from '../composables/useDebouncedRowSave';
import { u } from '@/basePath';

/**
 * "Gérer les lots" - create, edit and delete a project's lots, one row per lot.
 *
 * Edits go through PATCH /api/lots/{id}, the same endpoint and whitelist (UpdateLotRequest)
 * the tender comparison screen uses for weighting - one Lot, one write-surface. Creation and
 * deletion go through /api/projects/{project}/lots and /api/lots/{id}, mutating `lots`
 * directly: this array is the parent page's own (passed by reference), so the sidebar list
 * stays in sync without a page reload.
 *
 * Creating a lot writes an empty row immediately rather than collecting fields in a form
 * first. That means the new lot exists server-side from the moment it appears, so filling it
 * in afterwards is the same debounced per-field save as editing any other row - one code
 * path instead of two, and nothing is lost if the modal is closed midway.
 */
const props = defineProps({
    show: { type: Boolean, default: false },
    lots: { type: Array, required: true },
    projectId: { type: String, required: true },
    readOnly: { type: Boolean, default: false },
});

const emit = defineEmits(['close']);

/** The freely-typed columns. Fournisseur and Contact are pickers, handled separately. */
const COLUMNS = [
    { key: 'code', label: 'Code', type: 'number', width: '5rem' },
    { key: 'title_fr', label: 'Titre FR', type: 'text', width: 'minmax(8rem, 1fr)' },
    { key: 'title_en', label: 'Titre EN', type: 'text', width: 'minmax(8rem, 1fr)' },
    { key: 'title_nl', label: 'Titre NL', type: 'text', width: 'minmax(8rem, 1fr)' },
];

/** Typed columns, fournisseur, contact, save indicator, delete. */
const gridTemplate = [
    ...COLUMNS.map((c) => c.width),
    'minmax(9rem, 1.2fr)',
    'minmax(9rem, 1.2fr)',
    '0.75rem',
    '5.5rem',
].join(' ');

const busy = ref(false);
const error = ref(null);

/** The lot awaiting delete confirmation, or null. Also drives the confirmation modal. */
const pendingDelete = ref(null);
const deleting = ref(false);
const deleteError = ref(null);

/** How the lot being deleted is named in the confirmation, so it is unambiguous which one. */
const pendingDeleteLabel = computed(() => {
    const lot = pendingDelete.value;

    if (! lot) {
        return '';
    }

    const code = lot.code === null || lot.code === undefined || lot.code === '' ? null : `#${lot.code}`;
    const title = lot.title_fr || lot.title_en || lot.title_nl || null;

    return [code, title].filter(Boolean).join(' ') || 'ce lot sans code ni titre';
});

function askDelete(lot) {
    deleteError.value = null;
    pendingDelete.value = lot;
}

function cancelDelete() {
    pendingDelete.value = null;
    deleteError.value = null;
}

// --- company / contact pickers ------------------------------------------------------------

/** Which lot's picker is open, and which of the two. Null when neither is open. */
const picking = ref(null);

const companyPickerOpen = computed(() => picking.value?.field === 'company');
const contactPickerOpen = computed(() => picking.value?.field === 'contact');

/**
 * Contacts are scoped to a company through JCPYCTC, so there is nothing to list until a
 * supplier is chosen - the contact field stays closed rather than offering every contact in
 * ShakeDesign and letting one be attached to a company it has no link to.
 */
function openPicker(lot, field) {
    if (props.readOnly) {
        return;
    }

    if (field === 'contact' && ! lot.company_id) {
        return;
    }

    picking.value = { lot, field };
}

function closePicker() {
    picking.value = null;
}

/**
 * Only the zkp is sent. The server resolves the name from it and stores the snapshot, so the
 * name shown here and the name stored cannot drift apart - the picked label is applied
 * locally only as the optimistic value, and the response is not needed to correct it.
 */
function choose(row) {
    const { lot, field } = picking.value;

    if (field === 'company') {
        applyCompany(lot, row.zkp, row.name);
    } else {
        applyContact(lot, row.zkp, row.name);
    }

    closePicker();
}

function clearPicked() {
    const { lot, field } = picking.value;

    if (field === 'company') {
        applyCompany(lot, null, null);
    } else {
        applyContact(lot, null, null);
    }

    closePicker();
}

/**
 * Changing the supplier drops the contact locally too, mirroring what the server does for the
 * same reason: a contact linked to the previous company is not necessarily linked to the new
 * one. Flushed immediately rather than debounced - picking from a list is a deliberate act,
 * not typing.
 */
function applyCompany(lot, zkp, name) {
    const previous = lot.company_id;

    if (zkp === previous) {
        return;
    }

    lot.company_id = zkp;
    lot.company_name = name;
    lot.contact_id = null;
    lot.contact_name = null;

    queue(lot.id, 'company_id', zkp, previous);
    flush(lot.id);
}

function applyContact(lot, zkp, name) {
    const previous = lot.contact_id;

    if (zkp === previous) {
        return;
    }

    lot.contact_id = zkp;
    lot.contact_name = name;

    queue(lot.id, 'contact_id', zkp, previous);
    flush(lot.id);
}

const { queue, flush, status } = useDebouncedRowSave({
    endpoint: '/api/lots',
    delay: 500,
    onError: ({ rowId, rollback, message }) => {
        const lot = props.lots.find((candidate) => candidate.id === rowId);

        if (lot) {
            for (const [field, value] of Object.entries(rollback)) {
                lot[field] = value;
            }

            resolveTitle(lot);
        }

        error.value = message;
    },
});

/**
 * This panel never writes title_custom (not shown here), so for a lot managed only from
 * here the resolved display title the sidebar list uses is exactly this fallback.
 */
function resolveTitle(lot) {
    lot.title = lot.title_fr || lot.title_en || lot.title_nl || null;
}

function edit(lot, column, rawValue) {
    if (props.readOnly) {
        return;
    }

    const value = rawValue === '' ? null : column.type === 'number' ? Number(rawValue) : rawValue;
    const previous = lot[column.key];

    if (value === previous) {
        return;
    }

    lot[column.key] = value;
    resolveTitle(lot);
    queue(lot.id, column.key, value, previous);
}

/**
 * Creates the row server-side straight away, with no fields set - StoreLotRequest makes
 * every one of them optional for exactly this. The blank row then behaves like any other.
 */
async function addLot() {
    if (props.readOnly || busy.value) {
        return;
    }

    busy.value = true;
    error.value = null;

    try {
        const body = await request(`/api/projects/${props.projectId}/lots`, 'POST', {});
        props.lots.push(body.data);
    } catch (e) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}

/**
 * A refusal - the server rejects deleting a lot that still has metre lines - is shown inside
 * the confirmation modal and leaves it open, rather than dismissing it and surfacing the
 * reason behind it where the user is no longer looking.
 */
async function confirmDelete() {
    const lot = pendingDelete.value;

    if (props.readOnly || ! lot || deleting.value) {
        return;
    }

    deleting.value = true;
    deleteError.value = null;

    try {
        await request(`/api/lots/${lot.id}`, 'DELETE');

        const index = props.lots.findIndex((candidate) => candidate.id === lot.id);

        if (index !== -1) {
            props.lots.splice(index, 1);
        }

        pendingDelete.value = null;
    } catch (e) {
        deleteError.value = e.message;
    } finally {
        deleting.value = false;
    }
}

// --- transport --------------------------------------------------------------------------

async function request(url, method, payload = null) {
    const response = await fetch(u(url), {
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

    if (response.status === 204) {
        return {};
    }

    const body = await response.json().catch(() => null);

    if (!response.ok) {
        throw new Error(
            body?.errors ? Object.values(body.errors).flat()[0] : (body?.message ?? `Échec (HTTP ${response.status})`)
        );
    }

    return body;
}

function csrfToken() {
    const cookie = document.cookie.split('; ').find((entry) => entry.startsWith('XSRF-TOKEN='));

    return cookie
        ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length))
        : (document.querySelector('meta[name="csrf-token"]')?.content ?? '');
}
</script>

<template>
    <Modal :show="show" max-width="7xl" @close="$emit('close')">
        <div class="p-6">
            <div class="flex items-center justify-between gap-4">
                <h2 class="text-lg font-medium text-gray-900">Gérer les lots</h2>

                <SecondaryButton v-if="!readOnly" :disabled="busy" @click="addLot">
                    + Ajouter un lot
                </SecondaryButton>
            </div>

            <!-- Says why every field below refuses input, instead of leaving a readonly account
                 to conclude the modal is broken. -->
            <p v-if="readOnly" class="mt-2 rounded bg-amber-50 px-2 py-1 text-xs text-amber-800">
                Votre compte est en lecture seule : les lots sont consultables mais non modifiables.
            </p>

            <div v-if="lots.length > 0" class="mt-4 overflow-x-auto">
                <!-- Labels once, at the top - not repeated per lot. -->
                <div
                    class="grid items-center gap-2 border-b border-gray-200 pb-1 text-[11px] font-medium uppercase tracking-wide text-gray-400"
                    :style="{ gridTemplateColumns: gridTemplate }"
                >
                    <div v-for="column in COLUMNS" :key="column.key">{{ column.label }}</div>
                    <div>Fournisseur</div>
                    <div>Contact</div>
                    <div />
                    <div />
                </div>

                <div class="max-h-[26rem] overflow-y-auto">
                    <div
                        v-for="lot in lots"
                        :key="lot.id"
                        class="grid items-center gap-2 border-b border-gray-100 py-1.5"
                        :style="{ gridTemplateColumns: gridTemplate }"
                    >
                        <input
                            v-for="column in COLUMNS"
                            :key="column.key"
                            :type="column.type"
                            :value="lot[column.key]"
                            :disabled="readOnly"
                            class="block w-full min-w-0 px-2 py-1.5"
                            :class="column.type === 'number' ? 'text-right tabular-nums' : ''"
                            @input="edit(lot, column, $event.target.value)"
                            @blur="flush(lot.id)"
                        />

                        <!-- Picked, never typed: the button shows the name, the zkp is what
                             gets stored. -->
                        <button
                            type="button"
                            class="w-full min-w-0 truncate rounded-md border border-sand-300 bg-white px-2 py-1.5 text-left text-[13px] text-sand-800 transition-colors hover:border-sand-400 hover:bg-sand-50 disabled:bg-sand-100 disabled:text-sand-500"
                            :disabled="readOnly"
                            :title="lot.company_name || 'Choisir un fournisseur'"
                            @click="openPicker(lot, 'company')"
                        >
                            <span v-if="lot.company_name" class="text-gray-900">{{ lot.company_name }}</span>
                            <span v-else-if="lot.company_id" class="text-gray-500">{{ lot.company_id }}</span>
                            <span v-else class="text-gray-400">Choisir…</span>
                        </button>

                        <!-- Locked until a supplier exists: a company's contacts come from
                             JCPYCTC, so without one there is no list to choose from. -->
                        <button
                            type="button"
                            class="w-full min-w-0 truncate rounded-md border border-sand-300 bg-white px-2 py-1.5 text-left text-[13px] text-sand-800 transition-colors hover:border-sand-400 hover:bg-sand-50 disabled:bg-sand-100 disabled:text-sand-500 disabled:hover:border-sand-300"
                            :disabled="readOnly || !lot.company_id"
                            :title="
                                !lot.company_id
                                    ? 'Choisissez d\'abord un fournisseur'
                                    : lot.contact_name || 'Choisir un contact'
                            "
                            @click="openPicker(lot, 'contact')"
                        >
                            <span v-if="lot.contact_name" class="text-gray-900">{{ lot.contact_name }}</span>
                            <span v-else-if="lot.contact_id" class="text-gray-500">{{ lot.contact_id }}</span>
                            <span v-else class="text-gray-400">{{ lot.company_id ? 'Choisir…' : '—' }}</span>
                        </button>

                        <div class="flex justify-center">
                            <span
                                v-if="status[lot.id] === 'saving'"
                                class="size-1.5 rounded-full bg-blue-400"
                                title="Enregistrement…"
                            />
                            <span
                                v-else-if="status[lot.id] === 'saved'"
                                class="size-1.5 rounded-full bg-emerald-400"
                                title="Enregistré"
                            />
                            <span
                                v-else-if="status[lot.id] === 'error'"
                                class="size-1.5 rounded-full bg-red-500"
                                title="Échec de sauvegarde"
                            />
                        </div>

                        <div class="flex items-center justify-end whitespace-nowrap">
                            <button
                                v-if="!readOnly"
                                type="button"
                                class="text-xs text-red-600 hover:underline"
                                @click="askDelete(lot)"
                            >
                                Supprimer
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <p v-else class="mt-4 text-sm text-gray-400">
                Aucun lot pour ce projet.
                <span v-if="!readOnly">Utilisez « Ajouter un lot » pour en créer un.</span>
            </p>

            <p v-if="error" class="mt-3 text-sm text-red-600">{{ error }}</p>

            <div class="mt-6 flex justify-end">
                <SecondaryButton @click="$emit('close')">Fermer</SecondaryButton>
            </div>
        </div>
    </Modal>

    <!-- Companies: the list can be long, so searching goes back to the Data API. -->
    <ShakeDesignPickerModal
        :show="companyPickerOpen"
        title="Choisir un fournisseur"
        endpoint="/api/shakedesign/companies"
        :server-search="true"
        :selected-id="picking?.lot?.company_id ?? null"
        clear-label="Aucun fournisseur"
        @select="choose"
        @clear="clearPicked"
        @close="closePicker"
    />

    <!-- Contacts of the chosen company: few, and they arrive in one payload, so the search box
         filters what is already loaded instead of issuing another request per keystroke. -->
    <ShakeDesignPickerModal
        :show="contactPickerOpen"
        title="Choisir une personne de contact"
        :endpoint="
            picking?.lot?.company_id
                ? `/api/shakedesign/companies/${encodeURIComponent(picking.lot.company_id)}/contacts`
                : null
        "
        :selected-id="picking?.lot?.contact_id ?? null"
        clear-label="Aucun contact"
        @select="choose"
        @clear="clearPicked"
        @close="closePicker"
    />

    <!-- Sibling of the manager, not nested inside it: two dialogs side by side in the DOM,
         each pushed onto the top layer by its own showModal(), so the confirmation stacks
         above without being a descendant of the element it covers. -->
    <Modal :show="pendingDelete !== null" max-width="md" @close="cancelDelete">
        <div class="p-6">
            <h2 class="text-lg font-medium text-gray-900">Supprimer un lot</h2>

            <p class="mt-2 text-sm text-gray-600">
                Supprimer définitivement <span class="font-medium text-gray-900">{{ pendingDeleteLabel }}</span> ?
                Cette action est irréversible.
            </p>

            <p v-if="deleteError" class="mt-3 rounded bg-red-50 px-2 py-1 text-sm text-red-700">
                {{ deleteError }}
            </p>

            <div class="mt-6 flex justify-end gap-3">
                <SecondaryButton @click="cancelDelete">Annuler</SecondaryButton>
                <DangerButton :class="{ 'opacity-25': deleting }" :disabled="deleting" @click="confirmDelete">
                    Supprimer
                </DangerButton>
            </div>
        </div>
    </Modal>
</template>
