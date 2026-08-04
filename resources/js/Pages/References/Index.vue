<script setup>
import { computed, ref, watch } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DangerButton from '@/Components/DangerButton.vue';
import Icon from '@/Components/Icon.vue';
import Modal from '@/Components/Modal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { useDebouncedRowSave } from '@/composables/useDebouncedRowSave';

/**
 * Le référentiel de postes : sections, sous-sections, articles.
 *
 * Trois colonnes plutôt que les trois onglets de FileMaker (Tab_REF / Tab_REFS / Tab_REFSL) : sur
 * un écran large, garder le chemin visible évite de se demander où l'on est, ce que des onglets
 * qui masquent le niveau précédent ne permettent pas.
 *
 * Créer, c'est ajouter une ligne vide au bon niveau et la remplir ensuite — REF_New fait
 * exactement cela, curseur posé dans le champ Code. Le remplissage passe donc par la même
 * sauvegarde différée que partout ailleurs, et non par un formulaire séparé.
 *
 * Supprimer cascade et le dit : la confirmation nomme ce qui part, comme REF_Delete
 * (« la référence ainsi que ses sous-références et postes liés »). Les métrés ne sont pas
 * touchés : une ligne garde sa propre copie de la section, donc retirer un poste du catalogue
 * ne réécrit pas un devis déjà envoyé.
 */
const props = defineProps({
    references: { type: Array, required: true },
});

const page = usePage();
const readOnly = computed(() => page.props.auth?.canWrite === false);

/** Copie locale : les modifications atterrissent ici, le serveur confirme ensuite. */
const references = ref(props.references.map(clone));

function clone(reference) {
    return {
        ...reference,
        sub_references: reference.sub_references.map((sub) => ({ ...sub, lines: sub.lines.map((l) => ({ ...l })) })),
    };
}

watch(() => props.references, (value) => { references.value = value.map(clone); });

const error = ref(null);
const busy = ref(false);

const openReference = ref(props.references[0]?.id ?? null);
const openSubReference = ref(null);

const reference = computed(() => references.value.find((r) => r.id === openReference.value) ?? null);
const subReference = computed(
    () => reference.value?.sub_references.find((s) => s.id === openSubReference.value) ?? null
);

const counts = computed(() => ({
    references: references.value.length,
    subReferences: references.value.reduce((n, r) => n + r.sub_references.length, 0),
    lines: references.value.reduce((n, r) => n + r.sub_references.reduce((m, s) => m + s.lines.length, 0), 0),
}));

// --- édition ------------------------------------------------------------------------------

/**
 * Un enregistreur par niveau : `useDebouncedRowSave` construit son URL en collant l'identifiant
 * de la ligne à son endpoint, et les trois niveaux n'ont pas le même.
 */
function saver(endpoint) {
    return useDebouncedRowSave({
        endpoint,
        delay: 500,
        onError: ({ message }) => { error.value = message; },
    });
}

const savers = {
    reference: saver('/api/references'),
    subReference: saver('/api/sub-references'),
    line: saver('/api/sub-reference-lines'),
};

function edit(level, row, field, rawValue, type = 'text') {
    if (readOnly.value) {
        return;
    }

    const value = rawValue === '' ? null : type === 'number' ? Number(rawValue) : rawValue;
    const previous = row[field];

    if (value === previous) {
        return;
    }

    row[field] = value;
    // La colonne de gauche affiche le titre français ; il doit suivre la frappe.
    if (field === 'title_fr') {
        row.title = value;
    }

    error.value = null;
    savers[level].queue(row.id, field, value, previous);
}

function flush(level, row) {
    savers[level].flush(row.id);
}

function status(level, row) {
    return savers[level].status[row.id] ?? null;
}

// --- création -----------------------------------------------------------------------------

async function addReference() {
    const created = await post('/api/references');

    if (created) {
        references.value.push({ ...created, sub_references: [] });
        openReference.value = created.id;
        openSubReference.value = null;
    }
}

async function addSubReference() {
    if (! reference.value) {
        return;
    }

    const created = await post(`/api/references/${reference.value.id}/sub-references`);

    if (created) {
        reference.value.sub_references.push({ ...created, lines: [] });
        openSubReference.value = created.id;
    }
}

async function addLine() {
    if (! subReference.value) {
        return;
    }

    const created = await post(`/api/sub-references/${subReference.value.id}/lines`);

    if (created) {
        subReference.value.lines.push(created);
    }
}

// --- suppression --------------------------------------------------------------------------

/** `{ level, row, label, warning }` de la suppression en attente de confirmation. */
const pendingDelete = ref(null);

function askDelete(level, row) {
    if (readOnly.value) {
        return;
    }

    const warnings = {
        reference: 'Ses sous-sections et tous leurs articles seront supprimés avec elle.',
        subReference: 'Tous ses articles seront supprimés avec elle.',
        line: null,
    };

    pendingDelete.value = {
        level,
        row,
        label: [row.code, row.title || row.title_fr].filter((part) => part !== null && part !== '').join(' — '),
        warning: warnings[level],
    };
}

async function confirmDelete() {
    const { level, row } = pendingDelete.value;
    const endpoints = {
        reference: `/api/references/${row.id}`,
        subReference: `/api/sub-references/${row.id}`,
        line: `/api/sub-reference-lines/${row.id}`,
    };

    busy.value = true;

    try {
        await request(endpoints[level], 'DELETE');

        if (level === 'reference') {
            references.value = references.value.filter((r) => r.id !== row.id);
            openReference.value = references.value[0]?.id ?? null;
            openSubReference.value = null;
        } else if (level === 'subReference') {
            reference.value.sub_references = reference.value.sub_references.filter((s) => s.id !== row.id);
            openSubReference.value = null;
        } else {
            subReference.value.lines = subReference.value.lines.filter((l) => l.id !== row.id);
        }

        pendingDelete.value = null;
    } catch (e) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}

// --- transport ----------------------------------------------------------------------------

async function post(url) {
    if (readOnly.value || busy.value) {
        return null;
    }

    busy.value = true;
    error.value = null;

    try {
        return (await request(url, 'POST')).data;
    } catch (e) {
        error.value = e.message;

        return null;
    } finally {
        busy.value = false;
    }
}

async function request(url, method) {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': csrfToken(),
        },
    });

    if (response.status === 204) {
        return {};
    }

    const body = await response.json().catch(() => null);

    if (! response.ok) {
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
    <Head title="Références" />

    <AuthenticatedLayout
        title="Référentiel de postes"
        :breadcrumbs="[{ label: 'Projets', href: route('dashboard') }, { label: 'Références' }]"
    >
        <template #meta>
            <span>{{ counts.references }} section{{ counts.references === 1 ? '' : 's' }}</span>
            <span>{{ counts.subReferences }} sous-section{{ counts.subReferences === 1 ? '' : 's' }}</span>
            <span>{{ counts.lines }} article{{ counts.lines === 1 ? '' : 's' }}</span>
        </template>

        <div class="space-y-3">
            <p v-if="error" class="banner banner-danger">
                <Icon name="alert" :size="4" class="mt-px" />
                <span>{{ error }}</span>
            </p>

            <p class="text-[13px] text-sand-700">
                Le prix d'un article est un prix <strong>d'achat</strong> : c'est celui que reprend une ligne
                de métré créée depuis le catalogue. Renommer ou supprimer un poste ne touche aucun métré
                existant — chaque ligne garde le libellé et le code qu'elle avait à sa création.
            </p>

            <div class="grid gap-3 xl:grid-cols-12">
                <!-- Sections -->
                <section class="surface xl:col-span-3">
                    <header class="surface-head">
                        <h2 class="eyebrow">Sections</h2>
                        <button
                            v-if="!readOnly"
                            type="button"
                            class="btn btn-secondary btn-sm ml-auto"
                            :disabled="busy"
                            @click="addReference"
                        >
                            <Icon name="plus" :size="3.5" />
                            Section
                        </button>
                    </header>

                    <ul class="max-h-[32rem] divide-y divide-sand-200/70 overflow-y-auto">
                        <li
                            v-for="ref in references"
                            :key="ref.id"
                            class="flex items-center gap-1.5 px-2 py-1.5 transition-colors"
                            :class="ref.id === openReference ? 'bg-accent-100' : 'hover:bg-sand-100'"
                        >
                            <input
                                type="number"
                                :value="ref.code"
                                :disabled="readOnly"
                                class="w-14 shrink-0 px-1.5 py-1 text-right text-xs tabular-nums"
                                title="Code de section"
                                @focus="openReference = ref.id; openSubReference = null"
                                @input="edit('reference', ref, 'code', $event.target.value, 'number')"
                                @blur="flush('reference', ref)"
                            />
                            <input
                                type="text"
                                :value="ref.title_fr"
                                :disabled="readOnly"
                                placeholder="Titre de la section"
                                class="min-w-0 flex-1 px-1.5 py-1 text-xs"
                                @focus="openReference = ref.id; openSubReference = null"
                                @input="edit('reference', ref, 'title_fr', $event.target.value)"
                                @blur="flush('reference', ref)"
                            />
                            <span class="w-2 shrink-0">
                                <span
                                    v-if="status('reference', ref) === 'saving'"
                                    class="block size-1.5 rounded-full bg-info-500"
                                    title="Enregistrement…"
                                />
                                <span
                                    v-else-if="status('reference', ref) === 'saved'"
                                    class="block size-1.5 rounded-full bg-success-500"
                                    title="Enregistré"
                                />
                            </span>
                            <button
                                v-if="!readOnly"
                                type="button"
                                class="btn btn-ghost shrink-0 rounded px-1 py-0.5 text-danger-600"
                                title="Supprimer la section"
                                @click="askDelete('reference', ref)"
                            >
                                <Icon name="trash" :size="3.5" />
                            </button>
                        </li>
                        <li v-if="references.length === 0" class="px-3 py-8 text-center text-[13px] text-sand-600">
                            Le référentiel est vide.
                        </li>
                    </ul>
                </section>

                <!-- Sous-sections -->
                <section class="surface xl:col-span-4">
                    <header class="surface-head">
                        <h2 class="eyebrow">
                            Sous-sections
                            <span v-if="reference" class="text-sand-500">— {{ reference.title || 'sans titre' }}</span>
                        </h2>
                        <button
                            v-if="!readOnly && reference"
                            type="button"
                            class="btn btn-secondary btn-sm ml-auto"
                            :disabled="busy"
                            @click="addSubReference"
                        >
                            <Icon name="plus" :size="3.5" />
                            Sous-section
                        </button>
                    </header>

                    <ul class="max-h-[32rem] divide-y divide-sand-200/70 overflow-y-auto">
                        <li
                            v-for="sub in reference?.sub_references ?? []"
                            :key="sub.id"
                            class="flex items-center gap-1.5 px-2 py-1.5 transition-colors"
                            :class="sub.id === openSubReference ? 'bg-accent-100' : 'hover:bg-sand-100'"
                        >
                            <span class="code-chip shrink-0">{{ reference.code }}.{{ sub.code ?? '?' }}</span>
                            <input
                                type="number"
                                :value="sub.code"
                                :disabled="readOnly"
                                class="w-12 shrink-0 px-1.5 py-1 text-right text-xs tabular-nums"
                                title="Code de sous-section"
                                @focus="openSubReference = sub.id"
                                @input="edit('subReference', sub, 'code', $event.target.value, 'number')"
                                @blur="flush('subReference', sub)"
                            />
                            <input
                                type="text"
                                :value="sub.title_fr"
                                :disabled="readOnly"
                                placeholder="Titre de la sous-section"
                                class="min-w-0 flex-1 px-1.5 py-1 text-xs"
                                @focus="openSubReference = sub.id"
                                @input="edit('subReference', sub, 'title_fr', $event.target.value)"
                                @blur="flush('subReference', sub)"
                            />
                            <span class="shrink-0 text-[10px] text-sand-500">{{ sub.lines.length }}</span>
                            <button
                                v-if="!readOnly"
                                type="button"
                                class="btn btn-ghost shrink-0 rounded px-1 py-0.5 text-danger-600"
                                title="Supprimer la sous-section"
                                @click="askDelete('subReference', sub)"
                            >
                                <Icon name="trash" :size="3.5" />
                            </button>
                        </li>
                        <li v-if="reference && reference.sub_references.length === 0" class="px-3 py-8 text-center text-[13px] text-sand-600">
                            Cette section n'a aucune sous-section.
                        </li>
                        <li v-if="!reference" class="px-3 py-8 text-center text-[13px] text-sand-600">
                            Choisissez une section.
                        </li>
                    </ul>
                </section>

                <!-- Articles -->
                <section class="surface xl:col-span-5">
                    <header class="surface-head">
                        <h2 class="eyebrow">
                            Articles
                            <span v-if="subReference" class="text-sand-500">— {{ subReference.title || 'sans titre' }}</span>
                        </h2>
                        <button
                            v-if="!readOnly && subReference"
                            type="button"
                            class="btn btn-secondary btn-sm ml-auto"
                            :disabled="busy"
                            @click="addLine"
                        >
                            <Icon name="plus" :size="3.5" />
                            Article
                        </button>
                    </header>

                    <div v-if="subReference" class="max-h-[32rem] overflow-y-auto">
                        <div
                            class="grid gap-1.5 border-b border-sand-200 bg-sand-100 px-2 py-1 text-[10px] uppercase tracking-[0.06em] text-sand-600"
                            style="grid-template-columns: 3rem minmax(8rem, 1fr) 4rem 6rem 0.5rem 1.75rem"
                        >
                            <div class="text-right">Code</div>
                            <div>Titre</div>
                            <div>Unité</div>
                            <div class="text-right">Prix achat (€)</div>
                            <div />
                            <div />
                        </div>

                        <div
                            v-for="line in subReference.lines"
                            :key="line.id"
                            class="grid items-center gap-1.5 border-b border-sand-200/70 px-2 py-1.5"
                            style="grid-template-columns: 3rem minmax(8rem, 1fr) 4rem 6rem 0.5rem 1.75rem"
                        >
                            <input
                                type="number"
                                :value="line.code"
                                :disabled="readOnly"
                                class="px-1 py-1 text-right text-xs tabular-nums"
                                @input="edit('line', line, 'code', $event.target.value, 'number')"
                                @blur="flush('line', line)"
                            />
                            <input
                                type="text"
                                :value="line.title_fr"
                                :disabled="readOnly"
                                placeholder="Titre de l'article"
                                class="min-w-0 px-1.5 py-1 text-xs"
                                @input="edit('line', line, 'title_fr', $event.target.value)"
                                @blur="flush('line', line)"
                            />
                            <input
                                type="text"
                                :value="line.unit"
                                :disabled="readOnly"
                                placeholder="—"
                                class="px-1 py-1 text-xs"
                                title="Unité, reprise telle quelle sur la ligne de métré"
                                @input="edit('line', line, 'unit', $event.target.value)"
                                @blur="flush('line', line)"
                            />
                            <input
                                type="number"
                                step="any"
                                :value="line.price"
                                :disabled="readOnly"
                                class="px-1 py-1 text-right text-xs tabular-nums"
                                title="Prix d'achat"
                                @input="edit('line', line, 'price', $event.target.value, 'number')"
                                @blur="flush('line', line)"
                            />
                            <span>
                                <span
                                    v-if="status('line', line) === 'saving'"
                                    class="block size-1.5 rounded-full bg-info-500"
                                    title="Enregistrement…"
                                />
                                <span
                                    v-else-if="status('line', line) === 'saved'"
                                    class="block size-1.5 rounded-full bg-success-500"
                                    title="Enregistré"
                                />
                            </span>
                            <button
                                v-if="!readOnly"
                                type="button"
                                class="btn btn-ghost rounded px-1 py-0.5 text-danger-600"
                                title="Supprimer l'article"
                                @click="askDelete('line', line)"
                            >
                                <Icon name="trash" :size="3.5" />
                            </button>
                        </div>

                        <p v-if="subReference.lines.length === 0" class="px-3 py-8 text-center text-[13px] text-sand-600">
                            Cette sous-section n'a aucun article.
                        </p>
                    </div>

                    <p v-else class="px-3 py-8 text-center text-[13px] text-sand-600">
                        Choisissez une sous-section.
                    </p>
                </section>
            </div>
        </div>

        <!-- La confirmation nomme ce qui part, comme REF_Delete. -->
        <Modal :show="pendingDelete !== null" max-width="md" @close="pendingDelete = null">
            <div class="p-5">
                <h2 class="text-[15px] text-sand-900" style="font-variation-settings: 'wght' 600">Supprimer</h2>
                <p class="mt-2 text-[13px] text-sand-700">
                    Supprimer définitivement
                    <span class="text-sand-900" style="font-variation-settings: 'wght' 550">
                        {{ pendingDelete?.label || 'cet élément sans code ni titre' }}
                    </span> ?
                </p>
                <p v-if="pendingDelete?.warning" class="mt-2 text-[13px] text-danger-700">
                    {{ pendingDelete.warning }}
                </p>
                <p class="mt-2 text-[12px] text-sand-600">
                    Les métrés existants ne changent pas : leurs lignes gardent le code et le libellé
                    qu'elles portent déjà.
                </p>

                <div class="mt-5 flex justify-end gap-2">
                    <SecondaryButton @click="pendingDelete = null">Annuler</SecondaryButton>
                    <DangerButton :disabled="busy" @click="confirmDelete">Supprimer</DangerButton>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
