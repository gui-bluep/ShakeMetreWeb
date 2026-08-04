<script setup>
import { computed } from 'vue';
import AppTopBar from '@/Components/AppTopBar.vue';

/**
 * La coquille des écrans de page : barre supérieure encre, barre de page blanche, contenu sur
 * le fond sable.
 *
 * Trois niveaux, chacun avec son rôle, et jamais deux niveaux qui répètent la même chose :
 *
 *   1. la barre encre  — où je suis dans l'application (marque, fil d'Ariane, compte)
 *   2. la barre blanche — quoi est à l'écran (titre, méta) et ce que je peux en faire (actions)
 *   3. le contenu
 *
 * Le titre et le fil d'Ariane arrivent en props plutôt qu'en slot : c'est la même information
 * pour toutes les pages, et une page ne doit pas pouvoir décider seule de la composer autrement.
 */
const props = defineProps({
    title: { type: String, default: null },
    /** `[{ label, href }]` pour le fil d'Ariane de la barre supérieure. */
    breadcrumbs: { type: Array, default: () => [] },
    /**
     * `wide` pour les écrans de tableau, `narrow` pour une recherche ou un formulaire — un
     * champ de recherche étalé sur 1600 px ne se lit pas.
     */
    width: { type: String, default: 'wide' },
});

const container = computed(() =>
    props.width === 'narrow'
        ? 'mx-auto w-full max-w-3xl px-4 sm:px-6'
        : 'mx-auto w-full max-w-screen-2xl px-4 sm:px-6 lg:px-8'
);
</script>

<template>
    <div class="flex min-h-screen flex-col bg-sand-100">
        <AppTopBar :breadcrumbs="breadcrumbs" />

        <div v-if="title || $slots.actions || $slots.meta" class="border-b border-sand-200 bg-white">
            <div :class="container">
                <div class="flex min-h-[3.5rem] flex-wrap items-center justify-between gap-x-4 gap-y-2 py-2.5">
                    <div class="min-w-0">
                        <h1
                            class="truncate text-[17px] leading-tight text-sand-900"
                            style="font-variation-settings: 'wght' 600"
                            :title="title ?? undefined"
                        >
                            {{ title }}
                        </h1>
                        <div
                            v-if="$slots.meta"
                            class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-sand-600"
                        >
                            <slot name="meta" />
                        </div>
                    </div>

                    <div v-if="$slots.actions" class="flex shrink-0 items-center gap-2">
                        <slot name="actions" />
                    </div>
                </div>
            </div>
        </div>

        <main class="flex-1">
            <div :class="[container, 'py-5']">
                <slot />
            </div>
        </main>
    </div>
</template>
