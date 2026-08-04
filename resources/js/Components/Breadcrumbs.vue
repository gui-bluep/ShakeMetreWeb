<script setup>
import { Link } from '@inertiajs/vue3';
import Icon from '@/Components/Icon.vue';

/**
 * Le chemin projet → métré → vue, dans la barre supérieure.
 *
 * C'est la navigation réelle de l'application : il n'y a pas de menu à explorer, on descend
 * une hiérarchie et on en remonte. Chaque niveau au-dessus du dernier est cliquable ; le
 * dernier est la page courante et n'est donc pas un lien.
 */
defineProps({
    /** `[{ label, href }]`, du plus général au plus précis. `href` absent ⇒ non cliquable. */
    items: { type: Array, default: () => [] },
});
</script>

<template>
    <nav v-if="items.length" class="flex min-w-0 items-center gap-1 text-[13px]" aria-label="Fil d'Ariane">
        <template v-for="(item, index) in items" :key="index">
            <Icon
                v-if="index > 0"
                name="chevron-right"
                :size="3.5"
                class="shrink-0 text-sand-600"
            />

            <Link
                v-if="item.href && index < items.length - 1"
                :href="item.href"
                class="max-w-[16rem] truncate rounded px-1 py-0.5 text-sand-300 transition-colors hover:bg-white/10 hover:text-white"
                :title="item.label"
            >
                {{ item.label }}
            </Link>

            <span
                v-else
                class="max-w-[22rem] truncate px-1 py-0.5 text-white"
                :title="item.label"
                :aria-current="index === items.length - 1 ? 'page' : undefined"
                style="font-variation-settings: 'wght' 550"
            >
                {{ item.label }}
            </span>
        </template>
    </nav>
</template>
