<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import Icon from '@/Components/Icon.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import LotManagerModal from '@/Components/LotManagerModal.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import StatTile from '@/Components/StatTile.vue';
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

// Re-seeded when the page is pointed at a different project: Inertia can reuse this component
// across a navigation, and a working copy seeded once at setup would keep showing the previous
// project's lots while every write targeted the new one.
watch(
    () => props.project.id,
    () => { lots.value = props.lots.map((lot) => ({ ...lot })); }
);

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

/**
 * Le ratio est un nombre nu (vendu / acheté), pas un montant : deux décimales et pas d'euro,
 * mais la même virgule décimale que le reste de l'écran.
 */
function ratio(value) {
    return value === null || value === undefined ? '—' : currency.format(value);
}

/**
 * Les mêmes chiffres que la ligne de total du tableau, remontés en tête d'écran.
 *
 * Redondance assumée : les tuiles donnent le résultat du projet sans avoir à lire un tableau,
 * la ligne de total garde l'alignement sous sa colonne — c'est ce qui permet de vérifier d'où
 * vient le chiffre. « Commandes » désigne ici la colonne du projet, et pas la même que sur la
 * page d'un métré : décision confirmée, commentée dans le contrôleur.
 */
const tiles = computed(() => [
    { label: 'Commandes', value: money(props.totals.total_ordered), numeric: props.totals.total_ordered, tone: 'mallow' },
    { label: 'Travaux', value: money(props.totals.total_works), numeric: props.totals.total_works, tone: 'olive' },
    { label: 'Gains', value: money(props.totals.total_gain), numeric: props.totals.total_gain, tone: 'accent' },
    {
        label: 'Ratio du projet',
        value: ratio(props.totals.total_ratio),
        numeric: null,
        tone: 'neutral',
        hint: 'Commandes ÷ Travaux, sur l’ensemble des métrés du projet',
    },
]);
</script>

<template>
    <Head :title="project.name || 'Projet'" />

    <AuthenticatedLayout
        :title="project.name || 'Projet sans nom'"
        :breadcrumbs="[
            { label: 'Projets', href: route('dashboard') },
            { label: project.name || 'Projet sans nom' },
        ]"
    >
        <template #meta>
            <span v-if="project.number" class="code-chip">N° {{ project.number }}</span>
            <span v-if="project.status" class="badge badge-neutral">{{ project.status }}</span>
            <span>{{ metres.length }} métré{{ metres.length === 1 ? '' : 's' }}</span>
            <span>{{ lots.length }} lot{{ lots.length === 1 ? '' : 's' }}</span>
        </template>

        <template #actions>
            <!-- Le lime est réservé au geste que l'écran attend vraiment. -->
            <button v-if="!readOnly()" type="button" class="btn btn-accent" @click="openMetreModal">
                <Icon name="plus" :size="4" />
                Nouveau métré
            </button>
        </template>

        <div class="space-y-4">
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <StatTile
                    v-for="tile in tiles"
                    :key="tile.label"
                    :label="tile.label"
                    :value="tile.value"
                    :numeric="tile.numeric"
                    :tone="tile.tone"
                    :hint="tile.hint"
                />
            </div>

            <div class="flex flex-col gap-4 xl:flex-row">
                <!-- Métrés -->
                <AppCard title="Métrés" flush class="min-w-0 flex-1">
                    <div class="overflow-x-auto">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <!-- L'ID du métré dans son projet (1, 2, 3…), pas son UUID :
                                         celui-ci est une clé ShakeDesign, illisible et jamais
                                         affichée. -->
                                    <th class="w-14">ID</th>
                                    <th>Nom</th>
                                    <!-- Commandes ÷ Travaux, les deux colonnes de droite - et pas
                                         le « Ratio réel » (vendu/acheté) de la page d'un métré.
                                         Voir ProjectController::metreRow(). -->
                                    <th class="text-right" title="Commandes ÷ Travaux">Ratio</th>
                                    <th class="text-right">Créé le</th>
                                    <th class="text-right">Accord le</th>
                                    <th class="text-center">Accepté</th>
                                    <th class="text-center">Site</th>
                                    <th class="text-right">Offres</th>
                                    <th class="text-right">Commandes</th>
                                    <th class="text-right">Travaux</th>
                                    <th class="text-right">Gains</th>
                                </tr>
                            </thead>

                            <tbody>
                                <tr v-for="metre in metres" :key="metre.id">
                                    <td>
                                        <span v-if="metre.ind_project !== null" class="code-chip">{{ metre.ind_project }}</span>
                                        <span v-else class="text-sand-300">—</span>
                                    </td>
                                    <td>
                                        <!-- The métré's own page, not straight to the line grid: the
                                             grid is one of the views reachable from there. -->
                                        <Link
                                            :href="`/metres/${metre.id}`"
                                            class="group inline-flex items-center gap-1.5 text-sand-900 hover:text-sand-950"
                                            style="font-variation-settings: 'wght' 550"
                                        >
                                            <span class="underline decoration-sand-300 decoration-1 underline-offset-2 group-hover:decoration-accent-500">
                                                {{ metre.name || 'Métré sans nom' }}
                                            </span>
                                            <Icon
                                                name="chevron-right"
                                                :size="3.5"
                                                class="text-sand-400 transition-transform group-hover:translate-x-0.5"
                                            />
                                        </Link>
                                    </td>
                                    <td class="num">{{ ratio(metre.ratio) }}</td>
                                    <td class="num text-sand-600">{{ date(metre.date_creation) }}</td>
                                    <td class="num text-sand-600">{{ date(metre.date_agreement) }}</td>

                                    <!-- Un état, pas une commande : une case à cocher désactivée se lit
                                         comme un champ qu'on n'arrive pas à modifier. -->
                                    <td class="text-center">
                                        <Icon
                                            v-if="metre.is_accepted_b"
                                            name="check"
                                            :size="4"
                                            class="mx-auto text-success-600"
                                            aria-label="Accepté"
                                        />
                                        <span v-else class="text-sand-300" aria-label="Non accepté">—</span>
                                    </td>
                                    <td class="text-center">
                                        <Icon
                                            v-if="metre.is_status_site_b"
                                            name="check"
                                            :size="4"
                                            class="mx-auto text-success-600"
                                            aria-label="Site"
                                        />
                                        <span v-else class="text-sand-300" aria-label="Hors site">—</span>
                                    </td>

                                    <td class="num">{{ money(metre.total_offers) }}</td>
                                    <td class="num">{{ money(metre.total_ordered) }}</td>
                                    <td class="num">{{ money(metre.total_works) }}</td>
                                    <td class="num" :class="metre.total_gain < 0 ? 'text-danger-600' : ''">
                                        {{ money(metre.total_gain) }}
                                    </td>
                                </tr>

                                <tr v-if="metres.length === 0" class="hover:bg-transparent">
                                    <td colspan="11" class="py-12">
                                        <div class="flex flex-col items-center gap-2 text-center">
                                            <Icon name="table" :size="6" class="text-sand-300" />
                                            <p class="text-[13px] text-sand-700">Ce projet n'a aucun métré.</p>
                                            <button
                                                v-if="!readOnly()"
                                                type="button"
                                                class="btn btn-secondary btn-sm mt-1"
                                                @click="openMetreModal"
                                            >
                                                <Icon name="plus" :size="3.5" />
                                                Créer le premier métré
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>

                            <!-- Project-wide ratio and sums of the same "commandes" / "travaux" / "gains"
                                 columns, across every métré of the project - not just the ones listed
                                 above with a non-null value. -->
                            <tfoot v-if="metres.length > 0">
                                <tr>
                                    <td colspan="2">Total du projet</td>
                                    <td class="num">{{ ratio(totals.total_ratio) }}</td>
                                    <!-- Centré et non aligné à droite : à droite, le tiret se
                                         collerait sous « Offres » et se lirait comme le total de
                                         cette colonne-là, alors qu'il couvre les cinq. -->
                                    <td colspan="5" class="text-center text-sand-300">—</td>
                                    <td class="num">{{ money(totals.total_ordered) }}</td>
                                    <td class="num">{{ money(totals.total_works) }}</td>
                                    <td class="num" :class="totals.total_gain < 0 ? 'text-danger-600' : ''">
                                        {{ money(totals.total_gain) }}
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </AppCard>

                <!-- Lots -->
                <AppCard title="Lots" flush class="w-full shrink-0 xl:w-72">
                    <template #actions>
                        <span class="badge badge-neutral">{{ lots.length }}</span>
                        <button type="button" class="btn btn-ghost btn-sm" @click="showLotManager = true">
                            <Icon name="layers" :size="3.5" />
                            Gérer
                        </button>
                    </template>

                    <!-- Plain list, deliberately not links: the tender comparison screen is
                         reached another way, not by clicking a lot here. -->
                    <ul class="divide-y divide-sand-200/70">
                        <li
                            v-for="lot in lots"
                            :key="lot.id"
                            class="flex items-center gap-2 px-3 py-2 text-[13px] text-sand-800"
                        >
                            <span v-if="lot.code" class="code-chip shrink-0">{{ lot.code }}</span>
                            <span class="min-w-0 truncate" :title="lot.title || 'Lot sans titre'">
                                {{ lot.title || 'Lot sans titre' }}
                            </span>
                        </li>

                        <li v-if="lots.length === 0" class="px-3 py-8 text-center">
                            <p class="text-[13px] text-sand-600">Aucun lot.</p>
                            <button type="button" class="btn btn-secondary btn-sm mt-2" @click="showLotManager = true">
                                Créer un lot
                            </button>
                        </li>
                    </ul>
                </AppCard>
            </div>
        </div>

        <!-- Add a métré -->
        <Modal :show="showMetreModal" max-width="md" @close="closeMetreModal">
            <form @submit.prevent="submitMetre">
                <header class="surface-head">
                    <h2 class="text-[15px] text-sand-900" style="font-variation-settings: 'wght' 600">Nouveau métré</h2>
                </header>

                <div class="p-5">
                    <InputLabel for="metre-name" value="Nom du métré" />
                    <TextInput
                        id="metre-name"
                        ref="metreNameInput"
                        v-model="metreForm.name"
                        type="text"
                        class="block w-full"
                    />
                    <InputError :message="metreForm.errors.name" class="mt-2" />
                </div>

                <div class="flex justify-end gap-2 border-t border-sand-200 bg-sand-50 px-5 py-3">
                    <SecondaryButton @click="closeMetreModal">Annuler</SecondaryButton>
                    <PrimaryButton :disabled="metreForm.processing">Créer le métré</PrimaryButton>
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
