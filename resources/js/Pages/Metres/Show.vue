<script setup>
import { computed, reactive, ref, watch } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DangerButton from '@/Components/DangerButton.vue';
import Modal from '@/Components/Modal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { useDebouncedRowSave } from '@/composables/useDebouncedRowSave';

/**
 * One métré's own page. Header fields save as they are edited, through the same debounced
 * mechanism as the grids; the totals and the real ratio are read-only, materialized by
 * RecalculateMetreTotals and MET_Metre::Ratio_c respectively.
 */
const props = defineProps({
    metre: { type: Object, required: true },
    project: { type: Object, required: true },
    languages: { type: Array, required: true },
    lineCount: { type: Number, default: 0 },
});

const page = usePage();
const readOnly = computed(() => page.props.auth?.canWrite === false);

/** Local working copy: edits land here first, the server confirms afterwards. */
const form = reactive({ ...props.metre, totals: { ...props.metre.totals } });

const error = ref(null);
const busy = ref(false);
const confirmingDelete = ref(false);

/**
 * Re-seeds the working copy whenever the page is pointed at a different métré.
 *
 * Without this, duplicating showed the ORIGINAL's values under the COPY's id: Inertia can
 * reuse this component across a navigation between two métrés, so `props.metre` changed while
 * `form` - seeded once at setup - did not. Worse than cosmetic, because edits are keyed on
 * `props.metre.id`: typing in a field would have written the previous métré's stale values
 * onto the new one.
 */
watch(
    () => props.metre.id,
    () => {
        Object.assign(form, props.metre, { totals: { ...props.metre.totals } });
        error.value = null;
    }
);

const { queue, flush } = useDebouncedRowSave({
    endpoint: '/api/metres',
    delay: 500,
    onError: ({ rollback, message }) => {
        Object.assign(form, rollback);
        error.value = message;
    },
});

function edit(field, value) {
    if (readOnly.value) {
        return;
    }

    const previous = form[field];

    if (value === previous) {
        return;
    }

    form[field] = value;
    error.value = null;
    queue(props.metre.id, field, value, previous);
}

function number(field, raw) {
    edit(field, raw === '' ? null : Number(raw));
}

function text(field, raw) {
    edit(field, raw === '' ? null : raw);
}

/**
 * The two status flags gate the stored totals server-side, so the response carries recomputed
 * figures - applied here rather than left until the next page load.
 */
async function toggle(field, checked) {
    edit(field, checked);

    const body = await flush(props.metre.id);

    if (body) {
        Object.assign(form, body, { totals: { ...body.totals } });
    }
}

async function flushField() {
    const body = await flush(props.metre.id);

    if (body) {
        Object.assign(form, body, { totals: { ...body.totals } });
    }
}

// --- actions ------------------------------------------------------------------------------

async function duplicate() {
    if (readOnly.value || busy.value) {
        return;
    }

    busy.value = true;

    // Any debounced edit is written before the copy is taken, so the duplicate reflects what is
    // on screen rather than the last saved state.
    await flush(props.metre.id);

    router.post(`/metres/${props.metre.id}/duplicate`, {}, {
        onFinish: () => { busy.value = false; },
    });
}

function destroy() {
    busy.value = true;
    router.delete(`/metres/${props.metre.id}`, {
        onFinish: () => { busy.value = false; confirmingDelete.value = false; },
    });
}

// --- not yet wired up ---------------------------------------------------------------------

/**
 * Rendered as disabled buttons rather than omitted: the set of documents and views a métré
 * offers is part of what this screen is, and hiding them would make the page look finished
 * when it is not. Each is enabled as it gets built.
 */
const DOCUMENTS = [
    'Budget client — complet',
    'Budget client — complet avec composition',
    'Budget client — simplifié',
    'Budget client — simplifié avec composition',
    'Budget client — sous-catégories',
    'Budget client — catégories',
    'Fournisseur — budget achats',
];

const VIEWS = ['Achats — Ventes', 'Achats — Commandes', 'Achats — Ventes — Commandes', 'Ventes'];

// --- display ------------------------------------------------------------------------------

const currency = new Intl.NumberFormat('fr-BE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

function money(value) {
    return value === null || value === undefined ? '—' : `${currency.format(value)} €`;
}
</script>

<template>
    <Head :title="form.name || 'Métré'" />

    <AuthenticatedLayout>
        <template #header>
            <div class="grid grid-cols-3 items-center">
                <div>
                    <span
                        v-if="readOnly"
                        class="rounded bg-amber-100 px-1.5 py-0.5 text-xs text-amber-800"
                        title="Votre compte ShakeDesign est en lecture seule : les champs sont désactivés."
                    >
                        compte en lecture seule
                    </span>
                </div>

                <h2 class="justify-self-center text-xl font-semibold leading-tight text-gray-800">
                    {{ project.name || 'Projet sans nom' }}
                </h2>

                <div class="justify-self-end">
                    <Link
                        v-if="project.id"
                        :href="`/projects/${project.id}`"
                        class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-xs font-semibold uppercase tracking-widest text-gray-700 shadow-sm transition hover:bg-gray-50"
                    >
                        ← Retour au projet
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-screen-2xl space-y-4 px-4 sm:px-6 lg:px-8">
                <p v-if="error" class="rounded bg-red-50 px-3 py-2 text-sm text-red-700">{{ error }}</p>

                <!-- Totals and comments live outside the cards. -->
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div v-for="total in [
                        { label: 'Total des achats', key: 'purchases' },
                        { label: 'Total des ventes', key: 'sales' },
                        { label: 'Total des commandes', key: 'ordered' },
                        { label: 'Gains', key: 'gain' },
                    ]" :key="total.key" class="rounded-lg bg-white px-4 py-3 shadow-sm">
                        <p class="text-[11px] font-medium uppercase tracking-wide text-gray-400">{{ total.label }}</p>
                        <p
                            class="mt-1 text-lg font-semibold tabular-nums"
                            :class="form.totals[total.key] < 0 ? 'text-red-600' : 'text-gray-900'"
                        >
                            {{ money(form.totals[total.key]) }}
                        </p>
                    </div>
                </div>

                <div class="grid gap-4 lg:grid-cols-3">
                    <!-- Informations -->
                    <section class="rounded-lg bg-white p-4 shadow-sm">
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Informations</h3>

                        <div class="mt-3 space-y-3">
                            <label class="block text-xs text-gray-500">
                                Nom du métré
                                <input
                                    type="text"
                                    :value="form.name"
                                    :disabled="readOnly"
                                    class="mt-1 block w-full rounded-md border-gray-300 text-sm text-gray-900 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 disabled:bg-gray-50"
                                    @input="text('name', $event.target.value)"
                                    @blur="flushField"
                                />
                            </label>

                            <div class="grid grid-cols-2 gap-3">
                                <label class="block text-xs text-gray-500">
                                    Ratio par défaut
                                    <input
                                        type="number"
                                        step="any"
                                        :value="form.ratio_markup"
                                        :disabled="readOnly"
                                        class="mt-1 block w-full rounded-md border-gray-300 text-right text-sm tabular-nums text-gray-900 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 disabled:bg-gray-50"
                                        @input="number('ratio_markup', $event.target.value)"
                                        @blur="flushField"
                                    />
                                </label>

                                <label class="block text-xs text-gray-500">
                                    Langue
                                    <select
                                        :value="form.language"
                                        :disabled="readOnly"
                                        class="mt-1 block w-full rounded-md border-gray-300 text-sm text-gray-900 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 disabled:bg-gray-50"
                                        @change="edit('language', $event.target.value || null); flushField()"
                                    >
                                        <option value="">—</option>
                                        <option v-for="lang in languages" :key="lang" :value="lang">{{ lang }}</option>
                                    </select>
                                </label>
                            </div>

                            <div class="grid grid-cols-2 gap-3">
                                <label class="block text-xs text-gray-500">
                                    Date accord
                                    <input
                                        type="date"
                                        :value="form.date_agreement"
                                        :disabled="readOnly"
                                        class="mt-1 block w-full rounded-md border-gray-300 text-sm text-gray-900 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 disabled:bg-gray-50"
                                        @change="text('date_agreement', $event.target.value); flushField()"
                                    />
                                </label>

                                <!-- MET_Metre::Ratio_c - a calculation, so there is nothing to write. -->
                                <div class="text-xs text-gray-500">
                                    Ratio réel
                                    <p
                                        class="mt-1 rounded-md bg-gray-50 px-3 py-2 text-right text-sm tabular-nums text-gray-900"
                                        title="Calculé depuis les totaux du métré — non modifiable"
                                    >
                                        {{ form.ratio ?? '—' }}
                                    </p>
                                </div>
                            </div>

                            <div class="flex gap-6 pt-1">
                                <label class="flex items-center gap-2 text-xs text-gray-600">
                                    <input
                                        type="checkbox"
                                        :checked="form.is_accepted_b"
                                        :disabled="readOnly"
                                        class="size-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                        @change="toggle('is_accepted_b', $event.target.checked)"
                                    />
                                    Accepté
                                </label>

                                <label class="flex items-center gap-2 text-xs text-gray-600">
                                    <input
                                        type="checkbox"
                                        :checked="form.is_status_site_b"
                                        :disabled="readOnly"
                                        class="size-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                        @change="toggle('is_status_site_b', $event.target.checked)"
                                    />
                                    Site
                                </label>
                            </div>
                        </div>
                    </section>

                    <!-- Actions -->
                    <section class="rounded-lg bg-white p-4 shadow-sm">
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Actions</h3>

                        <div class="mt-3 flex flex-col items-start gap-2">
                            <SecondaryButton disabled title="Pas encore disponible">
                                Nouvelle offre client
                            </SecondaryButton>

                            <SecondaryButton v-if="!readOnly" :disabled="busy" @click="duplicate">
                                Dupliquer le métré
                            </SecondaryButton>

                            <DangerButton v-if="!readOnly" :disabled="busy" @click="confirmingDelete = true">
                                Supprimer le métré
                            </DangerButton>
                        </div>
                    </section>

                    <!-- Fournisseur - deliberately empty for now. -->
                    <section class="rounded-lg bg-white p-4 shadow-sm">
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Fournisseur</h3>
                        <p class="mt-3 text-xs text-gray-400">—</p>
                    </section>

                    <!-- Documents -->
                    <section class="rounded-lg bg-white p-4 shadow-sm lg:col-span-2">
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Documents</h3>

                        <div class="mt-3 flex flex-wrap gap-2">
                            <SecondaryButton
                                v-for="document in DOCUMENTS"
                                :key="document"
                                disabled
                                title="Pas encore disponible"
                            >
                                {{ document }}
                            </SecondaryButton>
                        </div>
                    </section>

                    <!-- Vues du métré -->
                    <section class="rounded-lg bg-white p-4 shadow-sm">
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Vues du métré</h3>

                        <div class="mt-3 flex flex-col items-start gap-2">
                            <SecondaryButton v-for="view in VIEWS" :key="view" disabled title="Pas encore disponible">
                                {{ view }}
                            </SecondaryButton>
                        </div>
                    </section>
                </div>

                <!-- Comments, outside the cards. -->
                <div class="grid gap-4 lg:grid-cols-3">
                    <label
                        v-for="comment in [
                            { label: 'Commentaires client', key: 'comment_client' },
                            { label: 'Commentaires fournisseur', key: 'comment_supplier' },
                            { label: 'Commentaires internes', key: 'comment_internal' },
                        ]"
                        :key="comment.key"
                        class="block text-xs text-gray-500"
                    >
                        {{ comment.label }}
                        <textarea
                            rows="4"
                            :value="form[comment.key]"
                            :disabled="readOnly"
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm text-gray-900 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 disabled:bg-gray-50"
                            @input="text(comment.key, $event.target.value)"
                            @blur="flushField"
                        />
                    </label>
                </div>
            </div>
        </div>

        <!-- Deleting a métré destroys its lines too, so the count is stated rather than left
             to be discovered afterwards. -->
        <Modal :show="confirmingDelete" max-width="md" @close="confirmingDelete = false">
            <div class="p-6">
                <h2 class="text-lg font-medium text-gray-900">Supprimer le métré</h2>

                <p class="mt-2 text-sm text-gray-600">
                    Supprimer définitivement
                    <span class="font-medium text-gray-900">{{ form.name || 'ce métré' }}</span>
                    <span v-if="lineCount > 0">
                        et ses {{ lineCount }} ligne{{ lineCount === 1 ? '' : 's' }} de métré</span>
                    ? Cette action est irréversible.
                </p>

                <div class="mt-6 flex justify-end gap-3">
                    <SecondaryButton @click="confirmingDelete = false">Annuler</SecondaryButton>
                    <DangerButton :class="{ 'opacity-25': busy }" :disabled="busy" @click="destroy">
                        Supprimer
                    </DangerButton>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
