<script setup>
import { ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

/**
 * The home screen: a project search, empty until the user actually searches. Projects live
 * in ShakeDesign, not in this database, so every keystroke (debounced) asks
 * GET /api/projects/search rather than filtering a list already on the page.
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
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Projets</h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-4xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <div class="border-b border-gray-200 p-6">
                        <label for="project-search" class="block text-sm font-medium text-gray-700">
                            Rechercher un projet
                        </label>
                        <input
                            id="project-search"
                            type="search"
                            :value="query"
                            placeholder="Nom ou numéro de projet…"
                            autofocus
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            @input="onInput($event.target.value)"
                        />
                    </div>

                    <div class="divide-y divide-gray-100">
                        <p v-if="query.trim() === ''" class="p-6 text-center text-sm text-gray-400">
                            Recherchez un projet par son nom ou son numéro.
                        </p>

                        <p v-else-if="loading" class="p-6 text-center text-sm text-gray-400">Recherche…</p>

                        <p v-else-if="error" class="p-6 text-center text-sm text-red-600">{{ error }}</p>

                        <p v-else-if="projects.length === 0" class="p-6 text-center text-sm text-gray-400">
                            Aucun projet ne correspond à « {{ query }} ».
                        </p>

                        <Link
                            v-for="project in projects"
                            :key="project.id"
                            :href="`/projects/${project.id}`"
                            class="flex items-center justify-between px-6 py-3 hover:bg-gray-50"
                        >
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-gray-900">
                                    {{ project.name || 'Projet sans nom' }}
                                </p>
                                <p class="text-xs text-gray-500">
                                    <span v-if="project.number">N° {{ project.number }}</span>
                                    <span v-if="project.number && project.status"> · </span>
                                    <span v-if="project.status">{{ project.status }}</span>
                                </p>
                            </div>
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
