<script setup>
/**
 * Une étiquette d'état. Les variantes vivent dans app.css (`.badge-*`) pour qu'un badge posé
 * directement en classe dans un gabarit soit identique à celui-ci.
 */
defineProps({
    tone: { type: String, default: 'neutral' },
});

const TONES = {
    neutral: 'badge-neutral',
    accent: 'badge-accent',
    success: 'badge-success',
    warning: 'badge-warning',
    danger: 'badge-danger',
    info: 'badge-info',
    /** Ce qui est prévu mais pas encore branché. */
    soon: 'badge-soon',
};
</script>

<template>
    <span class="badge" :class="TONES[tone] ?? TONES.neutral">
        <slot />
    </span>
</template>
