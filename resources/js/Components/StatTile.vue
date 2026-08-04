<script setup>
import { computed } from 'vue';

/**
 * Un chiffre-clé : le libellé en surtitre, la valeur en gros, un filet de couleur à gauche.
 *
 * Le filet reprend le code couleur du domaine — argile pour les achats, olive pour les ventes,
 * mauve pour les commandes — le même que les trois blocs des grilles de lignes. Un total du
 * métré et sa colonne dans la grille portent ainsi la même couleur d'un écran à l'autre.
 *
 * Un montant négatif passe en rouge : sur ces écrans il s'agit d'une perte, et c'est la seule
 * information qu'on doit pouvoir attraper sans lire.
 */
const props = defineProps({
    label: { type: String, required: true },
    /** Déjà formatée par l'appelant : la tuile ne décide pas de la devise ni des décimales. */
    value: { type: String, required: true },
    /** Sert uniquement à colorer un montant négatif ; laisser à null pour un chiffre neutre. */
    numeric: { type: Number, default: null },
    tone: { type: String, default: 'neutral' },
    hint: { type: String, default: null },
});

const RULES = {
    neutral: 'bg-sand-300',
    clay: 'bg-clay-500',
    olive: 'bg-olive-500',
    mallow: 'bg-mallow-500',
    accent: 'bg-accent-500',
};

const negative = computed(() => props.numeric !== null && props.numeric < 0);
</script>

<template>
    <div class="surface flex items-stretch overflow-hidden">
        <span class="w-[3px] shrink-0" :class="RULES[tone] ?? RULES.neutral" aria-hidden="true" />

        <div class="min-w-0 px-3.5 py-2.5">
            <p class="eyebrow truncate" :title="hint ?? label">{{ label }}</p>
            <p
                class="mt-1 text-[19px] leading-tight tabular-nums"
                :class="negative ? 'text-danger-600' : 'text-sand-900'"
                style="font-variation-settings: 'wght' 600"
            >
                {{ value }}
            </p>
        </div>
    </div>
</template>
