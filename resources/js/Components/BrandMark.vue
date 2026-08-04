<script setup>
/**
 * Le logotype de l'application.
 *
 * Composé en texte plutôt qu'en SVG : la marque n'est pas un dessin mais un mot posé dans la
 * police de la maison, et le composer en texte garantit qu'il reste exactement dans la même
 * fonte que le reste de l'interface. « SHAKE » à l'encre ou en blanc selon le fond, « MÉTRÉ »
 * en lime — le module dans la couleur de la marque.
 */
defineProps({
    /** `dark` pour la barre supérieure encre, `light` pour un fond clair (connexion). */
    tone: { type: String, default: 'dark' },
    /**
     * `bar` dans la barre supérieure, `hero` sur l'écran de connexion. Une prop plutôt qu'une
     * classe passée de l'extérieur : deux utilitaires `text-*` sur le même élément se
     * départagent par l'ordre de la feuille générée, pas par l'ordre écrit dans l'attribut.
     */
    size: { type: String, default: 'bar' },
});

const SIZES = {
    bar: 'text-[15px]',
    hero: 'text-[22px]',
};
</script>

<template>
    <span
        class="inline-flex select-none items-baseline gap-[0.3em] uppercase leading-none tracking-[0.14em]"
        :class="SIZES[size] ?? SIZES.bar"
        style="font-variation-settings: 'wght' 700"
    >
        <span :class="tone === 'dark' ? 'text-white' : 'text-sand-950'">Shake</span>
        <span :class="tone === 'dark' ? 'text-accent-400' : 'text-accent-700'">Métré</span>
    </span>
</template>
