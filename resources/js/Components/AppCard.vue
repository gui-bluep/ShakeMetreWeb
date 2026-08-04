<script setup>
/**
 * Un panneau : titre en surtitre, actions à droite, contenu dessous.
 *
 * Toutes les cartes de l'application passent par ici, pour qu'aucune n'invente sa propre
 * hauteur d'en-tête ni son propre gris de titre.
 */
defineProps({
    title: { type: String, default: null },
    /** Retire la marge intérieure : pour une carte qui contient un tableau ou une liste. */
    flush: { type: Boolean, default: false },
});
</script>

<template>
    <section class="surface flex flex-col">
        <header v-if="title || $slots.actions" class="surface-head">
            <h2 class="eyebrow truncate">{{ title }}</h2>
            <div v-if="$slots.actions" class="flex shrink-0 items-center gap-1">
                <slot name="actions" />
            </div>
        </header>

        <div :class="flush ? 'min-h-0 flex-1' : 'min-h-0 flex-1 p-4'">
            <slot />
        </div>
    </section>
</template>
