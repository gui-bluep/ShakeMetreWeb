<script setup>
import { nextTick, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import LotManagerModal from '@/Components/LotManagerModal.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';

const props = defineProps({
    project: { type: Object, required: true },
    metres: { type: Array, required: true },
    lots: { type: Array, required: true },
    totals: { type: Object, required: true },
});

const page = usePage();
const readOnly = () => page.props.auth?.canWrite === false;

// Mutable local copy: LotManagerModal creates and edits lots in place (by reference), so
// the sidebar list below reflects them immediately without a page visit.
const lots = ref(props.lots.map((lot) => ({ ...lot })));

// --- create métré ------------------------------------------------------------------------

const showMetreModal = ref(false);
const metreNameInput = ref(null);
const metreForm = useForm({ name: '' });

function openMetreModal() {
    showMetreModal.value = true;
    nextTick(() => metreNameInput.value?.focus());
}

function closeMetreModal() {
    showMetreModal.value = false;
    metreForm.clearErrors();
    metreForm.reset();
}

function submitMetre() {
    metreForm.post(`/projects/${props.project.id}/metres`, {
        preserveScroll: true,
        onSuccess: () => closeMetreModal(),
    });
}

// --- manage lots -------------------------------------------------------------------------

const showLotManager = ref(false);

// --- display helpers ----------------------------------------------------------------------

const currency = new Intl.NumberFormat('fr-BE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const dateFormat = new Intl.DateTimeFormat('fr-BE');

function money(value) {
    return value === null || value === undefined ? '—' : `${currency.format(value)} €`;
}

function date(value) {
    return value ? dateFormat.format(new Date(value)) : '—';
}

function shortId(id) {
    return id ? `${id.slice(0, 8)}…` : '—';
}
</script>

<template>
    <Head :title="project.name || 'Projet'" />

    <AuthenticatedLayout>
        <template #header>
            <div class="grid grid-cols-3 items-center">
                <div>
                    <!-- Named explicitly rather than left to be inferred from greyed-out fields:
                         a readonly account otherwise just finds inputs that refuse to focus,
                         with nothing on screen saying why. -->
                    <span
                        v-if="readOnly()"
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
                    <PrimaryButton v-if="!readOnly()" @click="openMetreModal">+ Métré</PrimaryButton>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto flex max-w-screen-2xl gap-4 px-4 sm:px-6 lg:px-8">
                <!-- Métrés: 4/5 of the width. -->
                <div class="w-4/5 overflow-hidden overflow-x-auto rounded-lg bg-white shadow-sm">
                    <table class="w-full border-collapse text-xs">
                        <thead>
                            <tr class="border-b border-gray-200 bg-gray-100 text-[11px] uppercase tracking-wide text-gray-500">
                                <th class="px-3 py-2 text-left">ID</th>
                                <th class="px-3 py-2 text-left">Nom</th>
                                <th class="px-3 py-2 text-right">Ratio</th>
                                <th class="px-3 py-2 text-right">Créé le</th>
                                <th class="px-3 py-2 text-right">Accord le</th>
                                <th class="px-3 py-2 text-center">Accepté</th>
                                <th class="px-3 py-2 text-center">Site</th>
                                <th class="px-3 py-2 text-right">Offres</th>
                                <th class="px-3 py-2 text-right">Commandes</th>
                                <th class="px-3 py-2 text-right">Travaux</th>
                                <th class="px-3 py-2 text-right">Gains</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="metre in metres" :key="metre.id" class="border-b border-gray-100 hover:bg-blue-50/30">
                                <td class="px-3 py-2 font-mono text-gray-400" :title="metre.id">{{ shortId(metre.id) }}</td>
                                <td class="px-3 py-2">
                                    <Link
                                        :href="`/metres/${metre.id}/lines`"
                                        class="font-medium text-gray-900 hover:text-blue-600 hover:underline"
                                    >
                                        {{ metre.name || 'Métré sans nom' }}
                                    </Link>
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ metre.ratio ?? '—' }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-gray-500">{{ date(metre.date_creation) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-gray-500">{{ date(metre.date_agreement) }}</td>
                                <td class="px-3 py-2 text-center">
                                    <input type="checkbox" :checked="metre.is_accepted_b" disabled class="size-3.5 rounded border-gray-300" />
                                </td>
                                <td class="px-3 py-2 text-center">
                                    <input type="checkbox" :checked="metre.is_status_site_b" disabled class="size-3.5 rounded border-gray-300" />
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ money(metre.total_offers) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ money(metre.total_ordered) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ money(metre.total_works) }}</td>
                                <td
                                    class="px-3 py-2 text-right tabular-nums"
                                    :class="metre.total_gain < 0 ? 'text-red-600' : ''"
                                >
                                    {{ money(metre.total_gain) }}
                                </td>
                            </tr>

                            <tr v-if="metres.length === 0">
                                <td colspan="11" class="p-8 text-center text-gray-400">Ce projet n'a aucun métré.</td>
                            </tr>
                        </tbody>

                        <!-- Project-wide ratio and sums of the same "commandes" / "travaux" / "gains"
                             columns, across every métré of the project - not just the ones listed
                             above with a non-null value. -->
                        <tfoot v-if="metres.length > 0">
                            <tr class="border-t-2 border-gray-300 bg-gray-50 font-medium text-gray-700">
                                <td colspan="2" class="px-3 py-2">Total du projet</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ totals.total_ratio ?? '—' }}</td>
                                <td colspan="4" class="px-3 py-2 text-right tabular-nums text-gray-300">—</td>
                                <td class="px-3 py-2 text-right tabular-nums text-gray-300">—</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ money(totals.total_ordered) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ money(totals.total_works) }}</td>
                                <td
                                    class="px-3 py-2 text-right tabular-nums"
                                    :class="totals.total_gain < 0 ? 'text-red-600' : ''"
                                >
                                    {{ money(totals.total_gain) }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- Lots: 1/5 of the width. -->
                <div class="w-1/5 shrink-0 rounded-lg bg-white shadow-sm">
                    <div class="flex items-center justify-between border-b border-gray-200 px-3 py-2">
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Lots</h3>
                        <button
                            type="button"
                            class="rounded px-1.5 py-0.5 text-xs text-gray-500 transition hover:bg-gray-100 hover:text-gray-700"
                            @click="showLotManager = true"
                        >
                            Gérer
                        </button>
                    </div>

                    <!-- Plain list, deliberately not links: the tender comparison screen is
                         reached another way, not by clicking a lot here. -->
                    <ul>
                        <li
                            v-for="lot in lots"
                            :key="lot.id"
                            class="border-b border-gray-100 px-3 py-2 text-xs text-gray-700 last:border-b-0"
                        >
                            <span v-if="lot.code" class="tabular-nums text-gray-400">#{{ lot.code }}</span>
                            {{ lot.title || 'Lot sans titre' }}
                        </li>

                        <li v-if="lots.length === 0" class="p-4 text-center text-xs text-gray-400">Aucun lot.</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Add a métré -->
        <Modal :show="showMetreModal" @close="closeMetreModal">
            <form class="p-6" @submit.prevent="submitMetre">
                <h2 class="text-lg font-medium text-gray-900">Nouveau métré</h2>

                <div class="mt-4">
                    <InputLabel for="metre-name" value="Nom" />
                    <TextInput
                        id="metre-name"
                        ref="metreNameInput"
                        v-model="metreForm.name"
                        type="text"
                        class="mt-1 block w-full"
                    />
                    <InputError :message="metreForm.errors.name" class="mt-2" />
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <SecondaryButton @click="closeMetreModal">Annuler</SecondaryButton>
                    <PrimaryButton :class="{ 'opacity-25': metreForm.processing }" :disabled="metreForm.processing">
                        Créer
                    </PrimaryButton>
                </div>
            </form>
        </Modal>

        <!-- Create / edit lots -->
        <LotManagerModal
            :show="showLotManager"
            :lots="lots"
            :project-id="project.id"
            :read-only="readOnly()"
            @close="showLotManager = false"
        />
    </AuthenticatedLayout>
</template>
