<script setup>
import { computed, ref, watch } from 'vue';
import Icon from '@/Components/Icon.vue';
import Modal from '@/Components/Modal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

/**
 * « Depuis le catalogue » — le sélecteur d'articles du référentiel, repris du navigateur à trois
 * onglets de FileMaker (REF → REFS → REFSL) et de son insertion multiple.
 *
 * Trois colonnes plutôt que trois onglets : sur un écran large, voir la section, la sous-section
 * et les articles en même temps évite de perdre le fil du chemin parcouru — l'écran d'origine
 * masquait le niveau précédent à chaque descente.
 *
 * La sélection est multiple et traverse les sous-sections : on peut cocher deux articles ici, un
 * autre ailleurs, et tout insérer d'un coup. C'est le geste de METL_New_Multi, qui reçoit une
 * liste de zkp et crée une ligne par article. L'ordre de cochage est conservé, parce que c'est
 * l'ordre dans lequel on s'attend à voir les lignes arriver.
 *
 * Le même article peut être coché deux fois — deux portes identiques à deux étages sont deux
 * lignes — donc le compteur compte les occurrences, pas les articles distincts.
 */
const props = defineProps({
    show: { type: Boolean, default: false },
    /** Empêche l'insertion : compte en lecture seule ou métré verrouillé. */
    readOnly: { type: Boolean, default: false },
    busy: { type: Boolean, default: false },
});

const emit = defineEmits(['close', 'insert']);

const catalogue = ref([]);
const loading = ref(false);
const error = ref(null);

const openReference = ref(null);
const openSubReference = ref(null);
const search = ref('');

/** Les identifiants cochés, dans l'ordre de cochage, doublons compris. */
const picked = ref([]);

// Chargé à la première ouverture seulement : 19 sections, 118 sous-sections, 507 articles
// arrivent en un seul appel, donc rien ne justifie de le refaire à chaque ouverture.
watch(
    () => props.show,
    (show) => {
        if (show && catalogue.value.length === 0 && ! loading.value) {
            load();
        }
    }
);

async function load() {
    loading.value = true;
    error.value = null;

    try {
        const response = await fetch('/api/references/catalogue', {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });

        if (! response.ok) {
            throw new Error(`Le catalogue n'a pas pu être chargé (HTTP ${response.status})`);
        }

        catalogue.value = (await response.json()).data ?? [];
        openReference.value = catalogue.value[0]?.id ?? null;
        openSubReference.value = null;
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
}

const reference = computed(() => catalogue.value.find((r) => r.id === openReference.value) ?? null);
const subReference = computed(
    () => reference.value?.sub_references.find((s) => s.id === openSubReference.value) ?? null
);

/**
 * La recherche porte sur les articles de toutes les sections : c'est ainsi qu'on retrouve un
 * poste dont on connaît le libellé sans savoir dans quel chapitre il est rangé. Hors recherche,
 * on ne voit que les articles de la sous-section ouverte.
 */
const results = computed(() => {
    const term = search.value.trim().toLowerCase();

    if (term === '') {
        return (subReference.value?.lines ?? []).map((line) => ({ ...line, path: null }));
    }

    const matches = [];

    for (const ref of catalogue.value) {
        for (const sub of ref.sub_references) {
            for (const line of sub.lines) {
                if ((line.title ?? '').toLowerCase().includes(term)) {
                    matches.push({ ...line, path: `${ref.code}.${sub.code} ${sub.title ?? ''}`.trim() });
                }
            }
        }
    }

    return matches;
});

function countPicked(id) {
    return picked.value.filter((candidate) => candidate === id).length;
}

function toggle(id) {
    if (props.readOnly) {
        return;
    }

    picked.value = countPicked(id) > 0
        ? picked.value.filter((candidate) => candidate !== id)
        : [...picked.value, id];
}

/** Un deuxième exemplaire du même article, pour les lignes qui se répètent. */
function addAgain(id) {
    if (! props.readOnly) {
        picked.value = [...picked.value, id];
    }
}

function insert() {
    if (! props.readOnly && picked.value.length > 0) {
        emit('insert', [...picked.value]);
    }
}

function close() {
    picked.value = [];
    search.value = '';
    emit('close');
}

const currency = new Intl.NumberFormat('fr-BE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

function money(value) {
    return value === null || value === undefined ? '—' : `${currency.format(value)} €`;
}
</script>

<template>
    <Modal :show="show" max-width="6xl" @close="close">
        <header class="surface-head">
            <h2 class="text-[15px] text-sand-900" style="font-variation-settings: 'wght' 600">
                Ajouter des lignes depuis le catalogue
            </h2>
            <span class="ml-auto text-[11px] text-sand-600">
                Le prix du catalogue arrive en prix d'achat, avec l'unité et le libellé.
            </span>
        </header>

        <div class="p-4">
            <div class="relative mb-3">
                <Icon
                    name="search"
                    :size="3.5"
                    class="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-sand-400"
                />
                <input
                    type="search"
                    :value="search"
                    placeholder="Rechercher un article dans tout le catalogue…"
                    class="block w-full py-1.5 pl-8 pr-2.5 text-xs"
                    @input="search = $event.target.value"
                />
            </div>

            <p v-if="error" class="banner banner-danger mb-3">
                <Icon name="alert" :size="4" class="mt-px" />
                <span>{{ error }}</span>
            </p>

            <p v-if="loading" class="py-10 text-center text-[13px] text-sand-600">Chargement du catalogue…</p>

            <div v-else class="grid gap-3 lg:grid-cols-12">
                <!-- Sections -->
                <div v-if="search.trim() === ''" class="lg:col-span-3">
                    <p class="eyebrow mb-1">Sections</p>
                    <ul class="max-h-80 overflow-y-auto rounded-md border border-sand-200">
                        <li v-for="ref in catalogue" :key="ref.id">
                            <button
                                type="button"
                                class="flex w-full items-center gap-1.5 px-2 py-1.5 text-left text-xs transition-colors"
                                :class="ref.id === openReference ? 'bg-accent-100 text-sand-900' : 'hover:bg-sand-100'"
                                @click="openReference = ref.id; openSubReference = null"
                            >
                                <span class="code-chip shrink-0">{{ ref.code }}</span>
                                <span class="min-w-0 truncate">{{ ref.title || '—' }}</span>
                            </button>
                        </li>
                    </ul>
                </div>

                <!-- Sous-sections -->
                <div v-if="search.trim() === ''" class="lg:col-span-4">
                    <p class="eyebrow mb-1">Sous-sections</p>
                    <ul class="max-h-80 overflow-y-auto rounded-md border border-sand-200">
                        <li v-for="sub in reference?.sub_references ?? []" :key="sub.id">
                            <button
                                type="button"
                                class="flex w-full items-center gap-1.5 px-2 py-1.5 text-left text-xs transition-colors"
                                :class="sub.id === openSubReference ? 'bg-accent-100 text-sand-900' : 'hover:bg-sand-100'"
                                @click="openSubReference = sub.id"
                            >
                                <span class="code-chip shrink-0">{{ reference.code }}.{{ sub.code }}</span>
                                <span class="min-w-0 truncate">{{ sub.title || '—' }}</span>
                                <span class="ml-auto shrink-0 text-[10px] text-sand-500">{{ sub.lines.length }}</span>
                            </button>
                        </li>
                        <li v-if="(reference?.sub_references ?? []).length === 0" class="px-2 py-6 text-center text-xs text-sand-600">
                            Cette section n'a aucune sous-section.
                        </li>
                    </ul>
                </div>

                <!-- Articles -->
                <div :class="search.trim() === '' ? 'lg:col-span-5' : 'lg:col-span-12'">
                    <p class="eyebrow mb-1">
                        Articles
                        <span v-if="search.trim() !== ''" class="text-sand-500">— résultats de la recherche</span>
                    </p>
                    <ul class="max-h-80 overflow-y-auto rounded-md border border-sand-200">
                        <li
                            v-for="line in results"
                            :key="line.id"
                            class="flex items-center gap-2 border-b border-sand-100 px-2 py-1.5 last:border-b-0"
                            :class="countPicked(line.id) > 0 ? 'bg-accent-100/60' : ''"
                        >
                            <input
                                type="checkbox"
                                class="size-3.5 shrink-0"
                                :checked="countPicked(line.id) > 0"
                                :disabled="readOnly"
                                @change="toggle(line.id)"
                            />
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-xs text-sand-900">{{ line.title || '—' }}</span>
                                <span v-if="line.path" class="block truncate text-[10px] text-sand-500">{{ line.path }}</span>
                            </span>
                            <span class="shrink-0 text-[11px] text-sand-600">{{ line.unit || '—' }}</span>
                            <span class="num shrink-0 text-[11px] text-sand-700">{{ money(line.price) }}</span>
                            <button
                                v-if="countPicked(line.id) > 0"
                                type="button"
                                class="btn btn-ghost shrink-0 rounded px-1 py-0.5 text-[10px]"
                                title="Ajouter un exemplaire de plus de cet article"
                                @click="addAgain(line.id)"
                            >
                                ×{{ countPicked(line.id) }} +
                            </button>
                        </li>
                        <li v-if="results.length === 0" class="px-2 py-6 text-center text-xs text-sand-600">
                            {{ search.trim() === ''
                                ? 'Choisissez une sous-section pour voir ses articles.'
                                : 'Aucun article ne correspond.' }}
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="flex items-center gap-2 border-t border-sand-200 bg-sand-50 px-4 py-3">
            <span class="text-[11px] text-sand-600">
                {{ picked.length }} ligne{{ picked.length === 1 ? '' : 's' }} à créer
            </span>
            <button
                v-if="picked.length"
                type="button"
                class="btn btn-ghost btn-sm"
                @click="picked = []"
            >
                Vider
            </button>
            <div class="ml-auto flex gap-2">
                <SecondaryButton @click="close">Annuler</SecondaryButton>
                <button
                    type="button"
                    class="btn btn-accent"
                    :disabled="readOnly || busy || picked.length === 0"
                    @click="insert"
                >
                    <Icon name="plus" :size="4" />
                    Ajouter {{ picked.length ? picked.length : '' }}
                </button>
            </div>
        </div>
    </Modal>
</template>
