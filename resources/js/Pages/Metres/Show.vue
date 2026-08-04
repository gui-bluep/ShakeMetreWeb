<script setup>
import { computed, reactive, ref, watch } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import Badge from '@/Components/Badge.vue';
import DangerButton from '@/Components/DangerButton.vue';
import Icon from '@/Components/Icon.vue';
import Modal from '@/Components/Modal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import StatTile from '@/Components/StatTile.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { useDebouncedRowSave } from '@/composables/useDebouncedRowSave';
import { localToday } from '@/localDate';

/**
 * One métré's own page. Header fields save as they are edited, through the same debounced
 * mechanism as the grids; the totals and the real ratio are read-only, materialized by
 * RecalculateMetreTotals and MET_Metre::Ratio_c respectively.
 *
 * La date d'accord est un champ modifiable ET une valeur posée automatiquement quand on coche
 * « Accepté » : les deux sont normales ici, ce qui explique que `toggle()` réapplique toute la
 * réponse plutôt que le seul champ qu'on vient de changer.
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
 * Les deux drapeaux ne sont pas de simples étiquettes : ils conditionnent les totaux stockés
 * côté serveur, et cocher « Accepté » date l'accord (la décocher vide la date). L'enregistrement
 * est donc envoyé tout de suite au lieu d'attendre les 500 ms, et la réponse est réappliquée en
 * entier — sans quoi la date resterait celle d'avant à l'écran alors qu'elle a changé en base.
 *
 * La date posée est celle du navigateur, donc le jour de la personne qui coche, quel que soit le
 * fuseau du serveur. Les deux champs partent dans le même PATCH (`queue` les groupe par ligne)
 * et le champ se remplit à l'écran sans attendre la réponse. Le serveur applique la même règle
 * en repli, à son horloge, pour tout appel qui n'enverrait pas la date.
 *
 * Les quatre tuiles, elles, ne bougent pas avec les drapeaux : elles lisent les colonnes non
 * conditionnées (voir MetreController::payload()).
 */
async function toggle(field, checked) {
    edit(field, checked);

    if (field === 'is_accepted_b') {
        edit('date_agreement', checked ? localToday() : null);
    }

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
 * Rendered as disabled rows rather than omitted: the set of documents and views a métré
 * offers is part of what this screen is, and hiding them would make the page look finished
 * when it is not. Each is enabled as it gets built.
 *
 * Chacun porte l'étiquette « Bientôt » plutôt qu'un simple bouton grisé : grisé seul, l'écran
 * se lit comme cassé ou comme un droit manquant.
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

/**
 * `href` is set only for the views that exist. The rest render disabled, so the page shows the
 * full set it will eventually offer instead of looking finished with three of them missing.
 */
const VIEWS = [
    { label: 'Achats — Ventes', href: null },
    { label: 'Achats — Commandes', href: null },
    { label: 'Achats — Ventes — Commandes', href: `/metres/${props.metre.id}/lines/achats-ventes-commandes` },
    { label: 'Ventes', href: null },
];

// --- display ------------------------------------------------------------------------------

const currency = new Intl.NumberFormat('fr-BE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

function money(value) {
    return value === null || value === undefined ? '—' : `${currency.format(value)} €`;
}

/**
 * Les quatre totaux, dans le code couleur du domaine : argile pour les achats, olive pour les
 * ventes, mauve pour les commandes — les mêmes que les trois blocs de la grille de lignes.
 */
const TOTALS = [
    { label: 'Total des achats', key: 'purchases', tone: 'clay' },
    { label: 'Total des ventes', key: 'sales', tone: 'olive' },
    { label: 'Total des commandes', key: 'ordered', tone: 'mallow' },
    { label: 'Gains', key: 'gain', tone: 'accent' },
];

const COMMENTS = [
    { label: 'Commentaires client', key: 'comment_client' },
    { label: 'Commentaires fournisseur', key: 'comment_supplier' },
    { label: 'Commentaires internes', key: 'comment_internal' },
];

/** Un état actif se voit : bordure et fond marqués, plutôt qu'une case cochée de 14 px. */
function statusClasses(active) {
    return active
        ? 'border-accent-500 bg-accent-100 text-sand-900'
        : 'border-sand-300 bg-white text-sand-700 hover:border-sand-400';
}
</script>

<template>
    <Head :title="form.name || 'Métré'" />

    <AuthenticatedLayout
        :title="form.name || 'Métré sans nom'"
        :breadcrumbs="[
            { label: 'Projets', href: route('dashboard') },
            { label: project.name || 'Projet sans nom', href: project.id ? `/projects/${project.id}` : null },
            { label: form.name || 'Métré sans nom' },
        ]"
    >
        <template #meta>
            <Badge :tone="form.is_accepted_b ? 'success' : 'neutral'">
                <Icon v-if="form.is_accepted_b" name="check" :size="3" />
                {{ form.is_accepted_b ? 'Accepté' : 'Non accepté' }}
            </Badge>
            <Badge v-if="form.is_status_site_b" tone="info">Site</Badge>
            <span>{{ lineCount }} ligne{{ lineCount === 1 ? '' : 's' }}</span>
            <span>Ratio réel {{ form.ratio ?? '—' }}</span>
        </template>

        <template #actions>
            <Link v-if="project.id" :href="`/projects/${project.id}`" class="btn btn-secondary">
                <Icon name="arrow-left" :size="4" />
                Retour au projet
            </Link>
        </template>

        <div class="space-y-4">
            <p v-if="error" class="banner banner-danger">
                <Icon name="alert" :size="4" class="mt-px" />
                <span>{{ error }}</span>
            </p>

            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <StatTile
                    v-for="total in TOTALS"
                    :key="total.key"
                    :label="total.label"
                    :value="money(form.totals[total.key])"
                    :numeric="form.totals[total.key]"
                    :tone="total.tone"
                />
            </div>

            <div class="grid gap-4 lg:grid-cols-12">
                <!-- Informations -->
                <AppCard title="Informations" class="lg:col-span-5">
                    <div class="space-y-3">
                        <div>
                            <label class="field-label" for="metre-name">Nom du métré</label>
                            <input
                                id="metre-name"
                                type="text"
                                :value="form.name"
                                :disabled="readOnly"
                                class="block w-full px-2.5 py-1.5"
                                @input="text('name', $event.target.value)"
                                @blur="flushField"
                            />
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="field-label" for="metre-ratio">Ratio par défaut</label>
                                <input
                                    id="metre-ratio"
                                    type="number"
                                    step="any"
                                    :value="form.ratio_markup"
                                    :disabled="readOnly"
                                    class="block w-full px-2.5 py-1.5 text-right tabular-nums"
                                    @input="number('ratio_markup', $event.target.value)"
                                    @blur="flushField"
                                />
                            </div>

                            <div>
                                <label class="field-label" for="metre-language">Langue</label>
                                <select
                                    id="metre-language"
                                    :value="form.language"
                                    :disabled="readOnly"
                                    class="block w-full px-2.5 py-1.5"
                                    @change="edit('language', $event.target.value || null); flushField()"
                                >
                                    <option value="">—</option>
                                    <option v-for="lang in languages" :key="lang" :value="lang">{{ lang }}</option>
                                </select>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="field-label" for="metre-agreement">Date accord</label>
                                <input
                                    id="metre-agreement"
                                    type="date"
                                    :value="form.date_agreement"
                                    :disabled="readOnly"
                                    class="block w-full px-2.5 py-1.5"
                                    @change="text('date_agreement', $event.target.value); flushField()"
                                />
                            </div>

                            <!-- MET_Metre::Ratio_c - a calculation, so there is nothing to write.
                                 Volontairement dessiné autrement qu'un champ désactivé : le pointillé
                                 dit « calculé », le gris dirait « momentanément bloqué ». -->
                            <div>
                                <span class="field-label flex items-center gap-1">
                                    Ratio réel
                                    <Icon name="lock" :size="3" class="text-sand-400" />
                                </span>
                                <p
                                    class="readonly-value"
                                    title="Calculé depuis les totaux du métré — non modifiable"
                                >
                                    {{ form.ratio ?? '—' }}
                                </p>
                            </div>
                        </div>

                        <div>
                            <span class="field-label">Statut</span>
                            <div class="grid grid-cols-2 gap-2">
                                <label
                                    class="flex cursor-pointer items-center gap-2 rounded-md border px-3 py-2 text-[13px] transition-colors"
                                    :class="statusClasses(form.is_accepted_b)"
                                >
                                    <input
                                        type="checkbox"
                                        :checked="form.is_accepted_b"
                                        :disabled="readOnly"
                                        class="size-4"
                                        @change="toggle('is_accepted_b', $event.target.checked)"
                                    />
                                    Accepté
                                </label>

                                <label
                                    class="flex cursor-pointer items-center gap-2 rounded-md border px-3 py-2 text-[13px] transition-colors"
                                    :class="statusClasses(form.is_status_site_b)"
                                >
                                    <input
                                        type="checkbox"
                                        :checked="form.is_status_site_b"
                                        :disabled="readOnly"
                                        class="size-4"
                                        @change="toggle('is_status_site_b', $event.target.checked)"
                                    />
                                    Site
                                </label>
                            </div>
                        </div>
                    </div>
                </AppCard>

                <!-- Vues du métré : la raison d'être de l'écran, donc la carte la plus en avant. -->
                <AppCard title="Vues du métré" flush class="lg:col-span-4">
                    <ul class="divide-y divide-sand-200/70">
                        <li v-for="view in VIEWS" :key="view.label">
                            <Link
                                v-if="view.href"
                                :href="view.href"
                                class="group flex items-center justify-between gap-2 px-4 py-2.5 text-[13px] text-sand-900 transition-colors hover:bg-accent-100"
                                style="font-variation-settings: 'wght' 550"
                            >
                                <span class="flex min-w-0 items-center gap-2">
                                    <Icon name="table" :size="4" class="text-sand-500" />
                                    <span class="truncate">{{ view.label }}</span>
                                </span>
                                <Icon
                                    name="chevron-right"
                                    :size="4"
                                    class="text-sand-400 transition-transform group-hover:translate-x-0.5"
                                />
                            </Link>

                            <div
                                v-else
                                class="flex items-center justify-between gap-2 px-4 py-2.5 text-[13px] text-sand-500"
                                title="Pas encore disponible"
                            >
                                <span class="flex min-w-0 items-center gap-2">
                                    <Icon name="table" :size="4" class="text-sand-300" />
                                    <span class="truncate">{{ view.label }}</span>
                                </span>
                                <Badge tone="soon">Bientôt</Badge>
                            </div>
                        </li>
                    </ul>
                </AppCard>

                <!-- Actions -->
                <AppCard title="Actions" class="lg:col-span-3">
                    <div class="flex flex-col gap-2">
                        <SecondaryButton class="btn-block" disabled title="Pas encore disponible">
                            <Icon name="document" :size="4" />
                            Nouvelle offre client
                            <Badge tone="soon" class="ml-auto">Bientôt</Badge>
                        </SecondaryButton>

                        <SecondaryButton
                            v-if="!readOnly"
                            class="btn-block"
                            :disabled="busy"
                            @click="duplicate"
                        >
                            <Icon name="copy" :size="4" />
                            Dupliquer le métré
                        </SecondaryButton>

                        <!-- Variante sourde : le rouge plein est gardé pour la confirmation. -->
                        <button
                            v-if="!readOnly"
                            type="button"
                            class="btn btn-danger-quiet btn-block"
                            :disabled="busy"
                            @click="confirmingDelete = true"
                        >
                            <Icon name="trash" :size="4" />
                            Supprimer le métré
                        </button>

                        <p v-if="readOnly" class="text-[13px] text-sand-600">
                            Aucune action disponible : votre compte est en lecture seule.
                        </p>
                    </div>
                </AppCard>

                <!-- Documents -->
                <AppCard title="Documents" flush class="lg:col-span-8">
                    <!-- Grille séparée par le fond plutôt que par `divide-y` : sur deux colonnes,
                         `divide-y` ne trace des filets qu'entre frères successifs et laisse la
                         grille dépareillée. -->
                    <ul class="grid gap-px bg-sand-200 sm:grid-cols-2">
                        <li
                            v-for="document in DOCUMENTS"
                            :key="document"
                            class="flex items-center justify-between gap-2 bg-white px-4 py-2.5 text-[13px] text-sand-500"
                            title="Pas encore disponible"
                        >
                            <span class="flex min-w-0 items-center gap-2">
                                <Icon name="document" :size="4" class="text-sand-300" />
                                <span class="truncate">{{ document }}</span>
                            </span>
                            <Badge tone="soon">Bientôt</Badge>
                        </li>

                        <!-- Sept documents sur deux colonnes : sans ce bouche-trou, la case
                             manquante laisse voir le fond qui sert de filet et se lit comme un
                             bloc gris posé là par erreur. -->
                        <li
                            v-if="DOCUMENTS.length % 2 === 1"
                            class="hidden bg-white sm:block"
                            aria-hidden="true"
                        />
                    </ul>
                </AppCard>

                <!-- Fournisseur - deliberately empty for now. -->
                <AppCard title="Fournisseur" class="lg:col-span-4">
                    <div class="flex flex-col items-center gap-1.5 py-6 text-center">
                        <Icon name="user" :size="6" class="text-sand-300" />
                        <p class="text-[13px] text-sand-600">Aucun fournisseur lié à ce métré.</p>
                    </div>
                </AppCard>

                <!-- Commentaires : rassemblés dans une carte plutôt que posés nus sur le fond,
                     sinon trois zones de texte flottent sans qu'on sache à quoi elles tiennent. -->
                <AppCard title="Commentaires" class="lg:col-span-12">
                    <div class="grid gap-4 lg:grid-cols-3">
                        <div v-for="comment in COMMENTS" :key="comment.key">
                            <label class="field-label" :for="`metre-${comment.key}`">{{ comment.label }}</label>
                            <textarea
                                :id="`metre-${comment.key}`"
                                rows="4"
                                :value="form[comment.key]"
                                :disabled="readOnly"
                                class="block w-full px-2.5 py-1.5"
                                @input="text(comment.key, $event.target.value)"
                                @blur="flushField"
                            />
                        </div>
                    </div>
                </AppCard>
            </div>
        </div>

        <!-- Deleting a métré destroys its lines too, so the count is stated rather than left
             to be discovered afterwards. -->
        <Modal :show="confirmingDelete" max-width="md" @close="confirmingDelete = false">
            <div>
                <header class="surface-head">
                    <h2 class="text-[15px] text-sand-900" style="font-variation-settings: 'wght' 600">Supprimer le métré</h2>
                </header>

                <div class="flex gap-3 p-5">
                    <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-danger-100 text-danger-600">
                        <Icon name="alert" :size="5" />
                    </span>

                    <p class="text-[13px] text-sand-700">
                        Supprimer définitivement
                        <span class="text-sand-900" style="font-variation-settings: 'wght' 600">
                            {{ form.name || 'ce métré' }}</span><span v-if="lineCount > 0">
                            et ses {{ lineCount }} ligne{{ lineCount === 1 ? '' : 's' }} de métré</span>
                        ? Cette action est irréversible.
                    </p>
                </div>

                <div class="flex justify-end gap-2 border-t border-sand-200 bg-sand-50 px-5 py-3">
                    <SecondaryButton @click="confirmingDelete = false">Annuler</SecondaryButton>
                    <DangerButton :disabled="busy" @click="destroy">Supprimer</DangerButton>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
