<script setup>
import { ref, watch } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Icon from '@/Components/Icon.vue';
import { Head, Link, router } from '@inertiajs/vue3';

/**
 * The home screen: a project search, empty until the user actually searches. Projects live
 * in ShakeDesign, not in this database, so every keystroke (debounced) asks
 * GET /api/projects/search rather than filtering a list already on the page.
 *
 * L'écran ne fait qu'une chose, donc il est composé autour de ce champ : c'est le point
 * d'entrée de toute l'application, et la seule question qu'il pose est « quel projet ».
 */
const query = ref('');
const projects = ref([]);
const loading = ref(false);
const error = ref(null);

let debounceTimer = null;
let requestToken = 0;

function onInput(value) {
    query.value = value;
    clearTimeout(debounceTimer);

    const term = value.trim();

    if (term === '') {
        // No search, no project - not even the previous results.
        projects.value = [];
        loading.value = false;
        error.value = null;

        return;
    }

    debounceTimer = setTimeout(() => search(term), 300);
}

async function search(term) {
    const thisRequest = ++requestToken;
    loading.value = true;
    error.value = null;

    try {
        const response = await fetch(`/api/projects/search?q=${encodeURIComponent(term)}`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });

        if (!response.ok) {
            throw new Error(`Recherche impossible (HTTP ${response.status})`);
        }

        const body = await response.json();

        // A slower, earlier request landing after a faster, later one must not overwrite it.
        if (thisRequest === requestToken) {
            projects.value = body.data;
        }
    } catch (e) {
        if (thisRequest === requestToken) {
            error.value = e.message;
            projects.value = [];
        }
    } finally {
        if (thisRequest === requestToken) {
            loading.value = false;
        }
    }
}

// --- navigation au clavier ------------------------------------------------------------------

/**
 * Flèches et Entrée depuis le champ, sans repasser à la souris : la recherche est le geste le
 * plus répété de l'application, et on y arrive les mains sur le clavier.
 */
const highlighted = ref(-1);
const rowEls = ref([]);

watch(projects, () => {
    highlighted.value = projects.value.length > 0 ? 0 : -1;
    rowEls.value = [];
});

// `$el` parce qu'un `ref` posé sur `<Link>` renvoie l'instance du composant, pas le noeud.
function keepRow(el, index) {
    rowEls.value[index] = el?.$el ?? el;
}

watch(highlighted, (index) => {
    rowEls.value[index]?.scrollIntoView({ block: 'nearest' });
});

function onKeydown(event) {
    const total = projects.value.length;

    if (total === 0) {
        return;
    }

    if (event.key === 'ArrowDown') {
        event.preventDefault();
        highlighted.value = (highlighted.value + 1) % total;
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        highlighted.value = (highlighted.value - 1 + total) % total;
    } else if (event.key === 'Enter') {
        const project = projects.value[highlighted.value];

        if (project) {
            event.preventDefault();
            router.visit(`/projects/${project.id}`);
        }
    }
}
</script>

<template>
    <Head title="Projets" />

    <AuthenticatedLayout title="Projets" width="narrow" :breadcrumbs="[{ label: 'Projets' }]">
        <template #meta>
            <span>Les projets viennent de ShakeDesign</span>
        </template>

        <div class="surface overflow-hidden">
            <!-- Le champ -->
            <div class="border-b border-sand-200 p-4">
                <label for="project-search" class="field-label">Rechercher un projet</label>

                <div class="relative">
                    <Icon
                        name="search"
                        :size="4"
                        class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sand-400"
                    />
                    <input
                        id="project-search"
                        type="search"
                        :value="query"
                        placeholder="Nom ou numéro de projet…"
                        autofocus
                        autocomplete="off"
                        class="block w-full py-2.5 pl-9 pr-3 text-[15px]"
                        @input="onInput($event.target.value)"
                        @keydown="onKeydown"
                    />
                </div>

                <p class="mt-2 text-xs text-sand-600">
                    <template v-if="projects.length">
                        <kbd class="code-chip">↑</kbd>
                        <kbd class="code-chip ml-1">↓</kbd>
                        pour parcourir,
                        <kbd class="code-chip">Entrée</kbd>
                        pour ouvrir
                    </template>
                    <template v-else>
                        La recherche porte sur le nom et le numéro du projet.
                    </template>
                </p>
            </div>

            <!-- Les résultats, ou ce qui en tient lieu -->
            <div class="min-h-[9rem]">
                <div v-if="query.trim() === ''" class="flex flex-col items-center gap-2 px-6 py-12 text-center">
                    <Icon name="project" :size="6" class="text-sand-300" />
                    <p class="text-[13px] text-sand-600">Recherchez un projet par son nom ou son numéro.</p>
                </div>

                <!-- Squelettes plutôt qu'un mot « Recherche… » : la page ne bouge pas quand les
                     résultats arrivent, et l'attente se lit sans être relue. -->
                <div v-else-if="loading" class="divide-y divide-sand-200/70">
                    <div v-for="row in 3" :key="row" class="flex items-center gap-3 px-4 py-3">
                        <div class="h-4 w-14 animate-pulse rounded bg-sand-200" />
                        <div class="h-4 flex-1 animate-pulse rounded bg-sand-200" :style="{ maxWidth: `${60 - row * 10}%` }" />
                    </div>
                </div>

                <div v-else-if="error" class="p-4">
                    <p class="banner banner-danger">
                        <Icon name="alert" :size="4" class="mt-px" />
                        <span>{{ error }}</span>
                    </p>
                </div>

                <div v-else-if="projects.length === 0" class="flex flex-col items-center gap-2 px-6 py-12 text-center">
                    <Icon name="search" :size="6" class="text-sand-300" />
                    <p class="text-[13px] text-sand-700">Aucun projet ne correspond à « {{ query }} ».</p>
                    <p class="text-xs text-sand-600">Essayez le numéro de projet, ou une partie du nom.</p>
                </div>

                <ul v-else class="divide-y divide-sand-200/70">
                    <li v-for="(project, index) in projects" :key="project.id">
                        <Link
                            :ref="(el) => keepRow(el, index)"
                            :href="`/projects/${project.id}`"
                            class="group flex items-center gap-3 px-4 py-2.5 transition-colors"
                            :class="highlighted === index ? 'bg-accent-100' : 'hover:bg-sand-50'"
                            @mouseenter="highlighted = index"
                        >
                            <span v-if="project.number" class="code-chip shrink-0">{{ project.number }}</span>

                            <span class="min-w-0 flex-1 truncate text-[13px] text-sand-900" style="font-variation-settings: 'wght' 550">
                                {{ project.name || 'Projet sans nom' }}
                            </span>

                            <span v-if="project.status" class="badge badge-neutral shrink-0">{{ project.status }}</span>

                            <Icon
                                name="chevron-right"
                                :size="4"
                                class="shrink-0 text-sand-400 transition-colors group-hover:text-sand-700"
                            />
                        </Link>
                    </li>
                </ul>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
